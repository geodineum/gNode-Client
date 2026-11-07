<?php
declare(strict_types=1);

namespace gCore\gNode;

use gCore\gNode\Storage\StorageInterface;
use gCore\gNode\Exception\StorageException;
use gCore\gNode\JsonHelper;

/**
 * ConsumerGroupHandler - stream operations via ValKey consumer groups.
 *
 * Activated by `gNodeClient::enableConsumerGroups()` for callers that
 * prefer direct consumer-group XREADGROUP flow over the FCALL/key-based
 * path. Native mode (`enableNativeMode`) is consumed by
 * gCore\Modules\Managers\Base\CacheManager\CacheManager.
 *
 * Commit 1.12.c (NC-D1.06) resolved the previous `@deprecated` tag that
 * pointed at KeyBasedClientLuaEnabled as the "canonical implementation".
 * That class was itself a backward-compat shim (now deleted per the
 * project's pre-launch no-back-compat policy), and this class is the
 * live infrastructure for the consumer-group flow. Tag removed.
 *
 * @package gCore\gNode
 */
class ConsumerGroupHandler
{
    /** @var StorageInterface Storage interface */
    protected $storage;

    /** @var string Site identifier */
    protected $siteId;

    /** @var string Node identifier */
    protected $nodeId;

    /** @var string DTAP environment */
    protected $environment;

    /** @var string Stream prefix */
    protected $streamPrefix;

    /** @var string Unified stream name */
    protected $unifiedStream;

    /** @var string Client consumer group name */
    protected $clientGroup = 'gnode-client';

    /** @var string Daemon consumer group name */
    protected $daemonGroup = 'gnode-daemon';

    /** @var string Client consumer name */
    protected $consumerName;

    /** @var string Client identifier */
    protected $clientId;

    /** @var array Configuration */
    protected $config;

    /** @var int Batch size for reading/writing messages */
    protected $batchSize = 100;

    /** @var int Max idle time for pending message claiming (ms) */
    protected $maxIdleTime = 30000;

    /** @var int Stream trim threshold */
    protected $trimThreshold = 10000;

    /** @var bool Debug mode */
    protected $debug = false;

    /** @var bool Use native RESP3 format (bypass encoding/decoding) */
    protected $nativeMode = false;

    /** @var int Sequence counter for command ordering */
    protected $sequenceCounter = 0;

    /**
     * Get stable client ID based on process ID
     *
     * This ensures that each PHP worker (PHP-FPM/Apache MPM) maintains
     * a consistent consumer identity across requests, preventing
     * ephemeral consumer pollution in the consumer group.
     *
     * @return string Stable client identifier
     */
    protected static function getStableClientId(): string
    {
        // Use hostname + PID for uniqueness across multiple servers
        // Each PHP worker process maintains same PID for its lifetime
        $hostname = gethostname() ?: 'localhost';
        $pid = getmypid();

        // Format: hostname-pid (e.g., "web01-12345")
        return sprintf('%s-%d', $hostname, $pid);
    }

    /**
     * Constructor
     *
     * @param StorageInterface $storage Storage interface
     * @param string $siteId Site identifier
     * @param string $nodeId Node identifier (used for consumer name uniqueness)
     * @param array $config Configuration (should include 'environment' for DTAP isolation)
     */
    public function __construct(
        StorageInterface $storage,
        string $siteId = 'default',
        string $nodeId = 'default',
        array $config = []
    ) {
        $this->storage = $storage;
        $this->siteId = $siteId;
        $this->nodeId = $nodeId;

        $this->config = array_merge([
            'stream_prefix' => 'gnode',
            'environment' => 'production',  // NEW: DTAP environment for stream isolation
            'debug' => false,
            'batch_size' => 100,
            'max_idle_time' => 30000,
            'trim_threshold' => 10000,
            'client_id' => self::getStableClientId(),  // Use stable PID-based ID
            'native_mode' => false
        ], $config);

        $this->debug = $this->config['debug'];
        $this->batchSize = $this->config['batch_size'];
        $this->maxIdleTime = $this->config['max_idle_time'];
        $this->trimThreshold = $this->config['trim_threshold'];
        $this->streamPrefix = $this->config['stream_prefix'];
        $this->environment = $this->config['environment'];  // NEW: Store environment
        $this->clientId = $this->config['client_id'];
        $this->nativeMode = $this->config['native_mode'];

        // Set up unified stream name with braces for hash distribution
        // Pattern: {site_id}:gnode:unified:{environment}
        // The {} ensures all keys for a site hash to the same slot in Redis Cluster
        $this->unifiedStream = sprintf(
            '{%s}:%s:unified:%s',
            $this->siteId,
            $this->streamPrefix,
            $this->environment  // FIX: Was nodeId - now uses environment for DTAP isolation
        );

        // Set up consumer name with stable ID (hostname-pid ensures uniqueness per worker)
        // Format: client-{siteId}-{hostname-pid}
        $this->consumerName = sprintf(
            'client-%s-%s',
            $this->siteId,
            $this->clientId  // Now stable: hostname-pid (e.g., "web01-12345")
        );

        $this->debug("ConsumerGroupHandler initialized with unified stream: {$this->unifiedStream}");
    }

    /**
     * Initialize stream and consumer groups
     *
     * @return bool Success
     */
    public function initialize(): bool
    {
        try {
            $this->debug("Initializing unified stream and consumer groups");

            // PERFORMANCE OPTIMIZATION: Cache verification result for 5 minutes
            // This prevents expensive consumer group verification on every wp-load.php
            $cacheKey = "gnode:consumer_groups_verified:{$this->siteId}:{$this->nodeId}";
            $cachedVerification = $this->storage->get($cacheKey);

            if ($cachedVerification === '1') {
                $this->debug("Consumer groups already verified recently (cache hit)");
                return true;
            }

            // Verify existing consumer groups first
            $needsRecreation = $this->verifyConsumerGroups();

            if ($needsRecreation) {
                $this->debug("Consumer groups need recreation with proper settings");

                // Delete the stream to start fresh
                try {
                    $this->storage->delete($this->unifiedStream);
                    $this->debug("Deleted existing unified stream");
                } catch (\Exception $e) {
                    $this->debug("Error deleting stream (may not exist): " . $e->getMessage());
                }

                // Add initial message to stream (required for consumer group creation)
                try {
                    $this->storage->xAdd($this->unifiedStream, '*', ['init' => 'true', 't' => 'i', 'ss' => $this->siteId, 'sn' => 'system', 'ts' => (string)(time() * 1000), '_gh' => 'none']);
                    $this->debug("Added initial message to stream");
                } catch (\Exception $e) {
                    $this->debug("Error adding initial message to stream: " . $e->getMessage());
                }

                // Destroy existing groups to be safe
                try {
                    $this->storage->xGroupDestroy($this->unifiedStream, $this->clientGroup);
                    $this->debug("Destroyed existing client consumer group");
                } catch (\Exception $e) {
                    $this->debug("Error destroying client group (may not exist): " . $e->getMessage());
                }

                try {
                    $this->storage->xGroupDestroy($this->unifiedStream, $this->daemonGroup);
                    $this->debug("Destroyed existing daemon consumer group");
                } catch (\Exception $e) {
                    $this->debug("Error destroying daemon group (may not exist): " . $e->getMessage());
                }
            }

            // Create client consumer group with exact ID 0
            try {
                $groupCreated = $this->storage->xGroupCreate(
                    $this->unifiedStream,
                    $this->clientGroup,
                    '0', // Start from beginning (critical for protocol v2)
                    true // Create stream if not exists
                );
                $this->debug("Client consumer group creation: {$groupCreated}");
            } catch (\Exception $e) {
                // Ignore BUSYGROUP error (group already exists)
                if (!strpos($e->getMessage(), 'BUSYGROUP')) {
                    error_log('[gNode-Client ConsumerGroupHandler] FATAL: xGroupCreate failed: ' . $e->getMessage());
                    throw $e;
                }
                $this->debug("Client consumer group already exists");
            }

            // Ensure daemon consumer group exists as well
            try {
                $daemonGroupCreated = $this->storage->xGroupCreate(
                    $this->unifiedStream,
                    $this->daemonGroup,
                    '0', // Start from beginning (critical for protocol v2)
                    true // Create stream if not exists
                );
                $this->debug("Daemon consumer group creation: {$daemonGroupCreated}");
            } catch (\Exception $e) {
                // Ignore BUSYGROUP error (group already exists)
                if (!strpos($e->getMessage(), 'BUSYGROUP')) {
                    error_log('[gNode-Client ConsumerGroupHandler] FATAL: xGroupCreate daemon failed: ' . $e->getMessage());
                    throw $e;
                }
                $this->debug("Daemon consumer group already exists");
            }

            // Verify again to ensure proper configuration
            $stillNeedsRecreation = $this->verifyConsumerGroups();
            if ($stillNeedsRecreation) {
                $this->debug("Warning: Consumer groups still not properly configured after recreation attempt");
            } else {
                $this->debug("Consumer groups successfully configured with ID '0'");
            }

            // PERFORMANCE OPTIMIZATION: Cache successful verification for 5 minutes
            // This prevents repeating expensive verification on every wp-load.php
            $cacheKey = "gnode:consumer_groups_verified:{$this->siteId}:{$this->nodeId}";
            $this->storage->set($cacheKey, '1', 300); // 5 minutes TTL
            $this->debug("Cached verification result for 5 minutes");

            return true;
        } catch (\Exception $e) {
            error_log('[gNode-Client ConsumerGroupHandler] FATAL: Initialize failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify consumer groups exist
     *
     * @return bool True if groups need creation, false if they exist
     */
    protected function verifyConsumerGroups(): bool
    {
        try {
            // Check if stream exists
            if (!$this->storage->exists($this->unifiedStream)) {
                $this->debug("Stream does not exist, groups will be created");
                return false; // Groups will be created by xGroupCreate with mkstream flag
            }

            // Get information about existing groups
            $groups = $this->storage->xInfo('GROUPS', $this->unifiedStream);

            if (empty($groups)) {
                $this->debug("No consumer groups exist for stream, will create");
                return false; // Will create groups, no need to destroy anything
            }

            // Check if both groups exist
            $clientGroupExists = false;
            $daemonGroupExists = false;

            foreach ($groups as $group) {
                if ($group['name'] === $this->clientGroup) {
                    $clientGroupExists = true;
                    $this->debug("Client group exists with last-delivered-id: " . $group['last-delivered-id']);
                }
                if ($group['name'] === $this->daemonGroup) {
                    $daemonGroupExists = true;
                    $this->debug("Daemon group exists with last-delivered-id: " . $group['last-delivered-id']);
                }
            }

            // Only return false (don't recreate) - groups that exist should not be destroyed
            // NOTE: last-delivered-id naturally increments as messages are processed!
            // It's WRONG to recreate groups just because they've processed messages.
            return false;
        } catch (\Exception $e) {
            error_log('[gNode-Client ConsumerGroupHandler] FATAL: verifyConsumerGroups failed: ' . $e->getMessage());
            return false; // Don't recreate on error, just let create handle it
        }
    }

    /** @var array Map of request IDs to message IDs */
    protected $requestToMessageIds = [];

    /** @var array Map of request IDs to sequence numbers */
    protected $requestToSequenceNumbers = [];

    /**
     * Send a command to the daemon via unified stream
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @param string $requestId Request ID
     * @return string Message ID
     * @throws StorageException If sending fails
     */
    public function sendCommand(string $command, array $parameters, string $requestId): string
    {
        // If native mode is enabled, use direct RESP3 format
        if ($this->nativeMode) {
            return $this->sendCommandNative($command, $parameters, $requestId);
        }

        $this->debug("Sending command: {$command} with ID: {$requestId}");

        // Prepare message fields using RESP3 protocol format
        // CRITICAL: Use full command names (not abbreviated) as per gNode daemon spec
        $sequenceNum = ++$this->sequenceCounter;
        $fields = [
            't' => 'c',                     // Type: command
            'c' => $command,                // Full command name (REQUIRED by daemon)
            'p' => json_encode($parameters, JSON_UNESCAPED_SLASHES), // Parameters
            's' => $sequenceNum             // Sequence number (REQUIRED by daemon)
        ];

        $this->debug("Adding command message: {$command} with ID: {$requestId}, seq: {$sequenceNum} to unified stream (direct method)");

        // Add message to unified stream
        $messageId = $this->storage->xAdd($this->unifiedStream, '*', $fields);

        // Store the mapping of request ID to message ID and sequence number
        $this->requestToMessageIds[$requestId] = $messageId;
        $this->requestToSequenceNumbers[$requestId] = $sequenceNum;

        // Trim stream if needed
        $this->tryTrimStream($this->unifiedStream);

        return $messageId;
    }

    /**
     * Send a command using native RESP3 format (no encoding)
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @param string $requestId Request ID
     * @return string Message ID
     * @throws StorageException If sending fails
     */
    public function sendCommandNative(string $command, array $parameters, string $requestId): string
    {
        $this->debug("Sending command in native RESP3 format: {$command} with ID: {$requestId}");

        // Prepare message fields in native RESP3 format
        // CRITICAL: Use full command names (not abbreviated) and proper sequence field
        $sequenceNum = ++$this->sequenceCounter;
        $fields = [
            't' => 'c',                     // Type: command
            'c' => $command,                // Full command name (REQUIRED by daemon)
            'p' => json_encode($parameters, JSON_UNESCAPED_SLASHES), // Parameters as JSON
            's' => $sequenceNum             // Sequence number (REQUIRED by daemon)
        ];

        // Add message directly to unified stream
        $messageId = $this->storage->xAdd($this->unifiedStream, '*', $fields);

        $this->debug("Native message sent with ID: {$messageId}, seq: {$sequenceNum}");

        // Store the mapping of request ID to message ID and sequence number
        $this->requestToMessageIds[$requestId] = $messageId;
        $this->requestToSequenceNumbers[$requestId] = $sequenceNum;

        // Trim stream if needed
        $this->tryTrimStream($this->unifiedStream);

        return $messageId;
    }

    /**
     * Send raw RESP3 fields directly to the stream
     *
     * @param array $fields Raw fields to send
     * @param string|null $requestId Optional request ID for tracking
     * @return string Message ID
     * @throws StorageException If sending fails
     */
    public function sendRawMessage(array $fields, ?string $requestId = null): string
    {
        $this->debug("Sending raw RESP3 message to stream");

        // Add message directly to unified stream
        $messageId = $this->storage->xAdd($this->unifiedStream, '*', $fields);

        $this->debug("Raw message sent with ID: {$messageId}");

        // Store the mapping if request ID provided
        if ($requestId !== null) {
            $this->requestToMessageIds[$requestId] = $messageId;
        }

        // Trim stream if needed
        $this->tryTrimStream($this->unifiedStream);

        return $messageId;
    }

    /**
     * Enable native RESP3 mode
     *
     * @return void
     */
    public function enableNativeMode(): void
    {
        $this->nativeMode = true;
        $this->debug("Native RESP3 mode enabled");
    }

    /**
     * Disable native RESP3 mode
     *
     * @return void
     */
    public function disableNativeMode(): void
    {
        $this->nativeMode = false;
        $this->debug("Native RESP3 mode disabled");
    }

    /**
     * Check if native mode is enabled
     *
     * @return bool
     */
    public function isNativeMode(): bool
    {
        return $this->nativeMode;
    }

    /**
     * Send a batch of commands to the daemon via unified stream
     *
     * @param array $batchCommands Array of command arrays [command, parameters, requestId]
     * @param string $batchId Unique batch identifier
     * @return string Message ID
     * @throws StorageException If sending fails
     */
    public function sendBatchCommand(array $batchCommands, string $batchId): string
    {
        $this->debug("Sending batch command with ID: {$batchId}, containing " . count($batchCommands) . " commands");

        // Format batch messages
        $batchMessages = [];
        foreach ($batchCommands as $cmdIndex => $cmdData) {
            // cmdData format from Client.php: [type, command, params_json, sequence]
            // We only need: [type, command, params_json, sequence]
            if (count($cmdData) >= 4) {
                // Already in correct format from Client.php executeBatch
                $batchMessages[] = $cmdData;

                // Generate request ID for tracking (not sent to daemon)
                $requestId = uniqid($this->siteId . ':' . $cmdIndex . ':seq:' . $cmdData[3] . ':', true);
                $this->requestToSequenceNumbers[$requestId] = $cmdData[3]; // Track sequence number
                $this->requestToMessageIds[$requestId] = $batchId;
            } else {
                // Legacy format: [command, parameters, requestId]
                list($command, $parameters, $requestId) = $cmdData;

                $batchMessages[] = [
                    'c',
                    $command,
                    json_encode($parameters, JSON_UNESCAPED_SLASHES),
                    $cmdIndex
                ];

                $this->requestToSequenceNumbers[$requestId] = $cmdIndex; // Track sequence number
                $this->requestToMessageIds[$requestId] = $batchId;
            }
        }

        // Also store the batch ID itself for direct lookup
        $this->requestToMessageIds[$batchId] = $batchId;

        // NOTE: GNODE_PROTOCOL_ENCODE expands batch commands into individual messages,
        // which defeats the purpose of batching. Use direct XADD instead.
        // Prepare batch message fields using optimized field names
        $fields = [
            't' => 'bc',                    // Type: batch command (UPDATED for protocol v2)
            'bi' => $batchId,               // Batch ID
            'tc' => count($batchMessages),  // Total count
            'm' => json_encode($batchMessages, JSON_UNESCAPED_SLASHES), // Messages
            'ss' => $this->siteId,          // Source site
            'sn' => $this->nodeId,          // Source node
            'ts' => (string)(int)(microtime(true) * 1000) // Timestamp (ms — daemon parses i64)
            // Note: Do not add _gh or _cr for command messages as they're for responses
        ];

        $this->debug("Adding batch command with ID: {$batchId} to unified stream (direct method)");

        // Add message to unified stream
        $messageId = $this->storage->xAdd($this->unifiedStream, '*', $fields);

        // Trim stream if needed
        $this->tryTrimStream($this->unifiedStream);

        return $messageId;
    }

    /**
     * Ensure the consumer group exists
     *
     * Creates the consumer group if it doesn't exist.
     *
     * @return void
     */
    protected function ensureConsumerGroup(): void
    {
        try {
            $this->storage->xGroupCreate(
                $this->unifiedStream,
                $this->clientGroup,
                '0',  // Start from beginning
                true  // Create stream if not exists
            );
            $this->debug("Consumer group created: {$this->clientGroup}");
        } catch (\Exception $e) {
            // Ignore BUSYGROUP error (group already exists)
            if (!strpos($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
            $this->debug("Consumer group already exists: {$this->clientGroup}");
        }
    }

    /**
     * Flush pending messages for this consumer
     *
     * Acknowledges and removes all pending messages from the consumer group
     * to prevent receiving stale responses.
     *
     * @return int Number of messages flushed
     */
    public function flushPending(): int
    {
        $flushed = 0;

        try {
            // Ensure consumer group exists
            $this->ensureConsumerGroup();

            // Read and acknowledge all pending messages
            for ($i = 0; $i < 100; $i++) { // Max 100 iterations
                $messages = $this->storage->xReadGroup(
                    $this->clientGroup,
                    $this->consumerName,
                    [$this->unifiedStream => '>'],
                    50,  // Read up to 50 messages at once
                    100  // 100ms timeout
                );

                if (empty($messages[$this->unifiedStream])) {
                    break; // No more pending messages
                }

                // Acknowledge all messages
                $ids = array_keys($messages[$this->unifiedStream]);
                foreach ($ids as $id) {
                    $this->storage->xAck($this->unifiedStream, $this->clientGroup, [$id]);
                    $flushed++;
                }

                if (count($ids) < 50) {
                    break; // Got less than requested, no more pending
                }
            }

            if ($flushed > 0) {
                $this->debug("Flushed {$flushed} pending messages from consumer group");
            }

            return $flushed;

        } catch (\Exception $e) {
            $this->debug("Error flushing pending messages: " . $e->getMessage());
            return $flushed;
        }
    }

    /**
     * Check and claim pending messages
     *
     * @return array Claimed messages
     */
    protected function checkPendingMessages(): array
    {
        try {
            // Get information about pending messages
            $pendingInfo = $this->storage->xPending(
                $this->unifiedStream,
                $this->clientGroup
            );

            // If there are pending messages
            if (!empty($pendingInfo) && isset($pendingInfo[0]) && $pendingInfo[0] > 0) {
                $pendingCount = $pendingInfo[0];
                $this->debug("Found {$pendingCount} pending messages in unified stream");

                // Get details about pending messages
                $pendingDetails = $this->storage->xPending(
                    $this->unifiedStream,
                    $this->clientGroup,
                    '-',
                    '+',
                    min($pendingCount, $this->batchSize)
                );

                if (empty($pendingDetails)) {
                    return [];
                }

                // Collect message IDs to claim
                $messageIds = [];
                foreach ($pendingDetails as $detail) {
                    if (isset($detail[0])) {
                        $messageIds[] = $detail[0];
                    }
                }

                if (empty($messageIds)) {
                    return [];
                }

                // Claim messages
                $claimedMessages = $this->storage->xClaim(
                    $this->unifiedStream,
                    $this->clientGroup,
                    $this->consumerName,
                    $this->maxIdleTime,
                    $messageIds
                );

                $result = [];
                foreach ($claimedMessages as $id => $fields) {
                    $result[$id] = $fields;
                }

                return $result;
            }
        } catch (\Exception $e) {
            $this->debug("Error checking pending messages: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Try to trim a stream if it exceeds the threshold
     *
     * @param string $stream Stream name
     * @return int Number of messages deleted
     */
    protected function tryTrimStream(string $stream): int
    {
        try {
            // Only trim occasionally based on a random factor to avoid too frequent trimming
            if (rand(1, 100) > 95) {
                return $this->storage->xTrim($stream, $this->trimThreshold, true);
            }
        } catch (\Exception $e) {
            $this->debug("Error trimming stream {$stream}: " . $e->getMessage());
        }

        return 0;
    }

    /**
     * Get consumer name
     *
     * @return string Consumer name
     */
    public function getConsumerName(): string
    {
        return $this->consumerName;
    }

    /**
     * Get unified stream name
     *
     * @return string Unified stream name
     */
    public function getUnifiedStream(): string
    {
        return $this->unifiedStream;
    }

    /**
     * Log debug message
     *
     * @param string $message Debug message
     */
    protected function debug(string $message): void
    {
        if ($this->debug) {
            error_log("[gNode ConsumerGroupHandler] {$message}");
        }
    }
}
