<?php

namespace gCore\GSD;

use gCore\GSD\Storage\StorageInterface;
use gCore\GSD\Exception\StorageException;
use gCore\GSD\JsonHelper;

/**
 * ConsumerGroupHandler - Handles stream operations using consumer groups
 *
 * This class manages communication with the GSD daemon using direct consumer group
 * operations rather than script-based polling for maximum throughput and efficiency.
 *
 * @deprecated This class is part of the legacy stream-based architecture.
 *             Use KeyBasedClientLuaEnabled instead, which uses the faster key-based
 *             architecture with per-environment streams (gsd:compute:{env}).
 *             Will be removed in v3.0.
 * @see KeyBasedClientLuaEnabled The canonical client implementation
 *
 * @package gCore\GSD
 */
class ConsumerGroupHandler
{
    /** @var StorageInterface Storage interface */
    protected $storage;

    /** @var string Site identifier */
    protected $siteId;

    /** @var string Node identifier */
    protected $nodeId;

    /** @var string Stream prefix */
    protected $streamPrefix;

    /** @var string Unified stream name */
    protected $unifiedStream;

    /** @var string Client consumer group name */
    protected $clientGroup = 'gsd-client';

    /** @var string Daemon consumer group name */
    protected $daemonGroup = 'gsd-daemon';

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

    /** @var int Maximum retries for XREAD loops (prevents infinite loops on ACL errors) */
    protected $maxRetries = 10;

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
     * @param string $nodeId Node identifier
     * @param array $config Configuration
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
            'stream_prefix' => 'gsd',
            'debug' => false,
            'batch_size' => 100,
            'max_idle_time' => 30000,
            'trim_threshold' => 10000,
            'client_id' => self::getStableClientId(),  // Use stable PID-based ID
            'native_mode' => false,
            'max_retries' => 10  // NEW: Limit retry loops (default 10 = 5 seconds at 500ms/retry)
        ], $config);

        $this->debug = $this->config['debug'];
        $this->batchSize = $this->config['batch_size'];
        $this->maxIdleTime = $this->config['max_idle_time'];
        $this->trimThreshold = $this->config['trim_threshold'];
        $this->streamPrefix = $this->config['stream_prefix'];
        $this->clientId = $this->config['client_id'];
        $this->nativeMode = $this->config['native_mode'];
        $this->maxRetries = $this->config['max_retries'];

        // Set up unified stream name with braces for hash distribution
        $this->unifiedStream = sprintf(
            '{%s}:%s:unified:%s',
            $this->siteId,
            $this->streamPrefix,
            $this->nodeId
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
            $cacheKey = "gsd:consumer_groups_verified:{$this->siteId}:{$this->nodeId}";
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
                    error_log('[GSD-Client ConsumerGroupHandler] FATAL: xGroupCreate failed: ' . $e->getMessage());
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
                    error_log('[GSD-Client ConsumerGroupHandler] FATAL: xGroupCreate daemon failed: ' . $e->getMessage());
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
            $cacheKey = "gsd:consumer_groups_verified:{$this->siteId}:{$this->nodeId}";
            $this->storage->set($cacheKey, '1', 300); // 5 minutes TTL
            $this->debug("Cached verification result for 5 minutes");

            return true;
        } catch (\Exception $e) {
            error_log('[GSD-Client ConsumerGroupHandler] FATAL: Initialize failed: ' . $e->getMessage());
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
            error_log('[GSD-Client ConsumerGroupHandler] FATAL: verifyConsumerGroups failed: ' . $e->getMessage());
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
        // CRITICAL: Use full command names (not abbreviated) as per GSD daemon spec
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

        // NOTE: GSD_PROTOCOL_ENCODE expands batch commands into individual messages,
        // which defeats the purpose of batching. Use direct XADD instead.
        // Prepare batch message fields using optimized field names
        $fields = [
            't' => 'bc',                    // Type: batch command (UPDATED for protocol v2)
            'bi' => $batchId,               // Batch ID
            'tc' => count($batchMessages),  // Total count
            'm' => json_encode($batchMessages, JSON_UNESCAPED_SLASHES), // Messages
            'ss' => $this->siteId,          // Source site
            'sn' => $this->nodeId,          // Source node
            'ts' => (string)microtime(true) // Timestamp
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
     * Read response for a specific request
     *
     * ARCHITECTURAL FIX: Uses XREAD instead of XREADGROUP to eliminate round-robin routing issues.
     * Consumer groups distribute messages round-robin, which breaks request-response patterns.
     * XREAD allows each client to sequentially scan the stream for its own responses.
     *
     * @param string $requestId Request ID to look for
     * @param int $timeoutMs Timeout in milliseconds
     * @return array|null Response or null if timeout
     */
    public function readResponse(string $requestId, int $timeoutMs): ?array
    {
        $this->debug("Waiting for response to request {$requestId}, timeout: {$timeoutMs}ms");

        // ARCHITECTURAL FIX: Use XREAD instead of XREADGROUP
        // This eliminates consumer group round-robin routing issues
        $startTime = microtime(true);
        $endTime = $startTime + ($timeoutMs / 1000);

        // Get the message ID where we sent the command (approximately)
        // We'll read from a bit before this to catch the response
        $startId = $this->getStartReadId();

        // CRITICAL FIX: Loop and retry XREAD until response found or timeout
        // XREAD may return before response arrives, so we need to keep polling
        // MAX RETRIES: Prevent infinite loops on ACL NOPERM errors
        $lastMessageId = $startId;
        $retryCount = 0;

        while (microtime(true) < $endTime && $retryCount < $this->maxRetries) {
            $remainingTime = (int)(($endTime - microtime(true)) * 1000);
            if ($remainingTime <= 0) {
                $this->debug("Timeout waiting for response to request {$requestId} (total time exceeded)");
                return null;
            }

            // Use smaller block time for retries (500ms) to allow checking multiple times
            $blockTime = min($remainingTime, 500);

            // Use XREAD with blocking to wait for responses
            $messages = $this->fetchResponseMessagesXRead($lastMessageId, $this->batchSize, $blockTime);

            $retryCount++;
            $this->debug("XREAD attempt {$retryCount}: fetched " . count($messages) . " messages");

            if (empty($messages)) {
                // No messages yet, continue loop to retry
                $this->debug("No new messages on attempt {$retryCount}, retrying...");
                continue;
            }

            // Update lastMessageId to continue from where we left off
            $messageIds = array_keys($messages);
            $lastMessageId = end($messageIds);
            $this->debug("Updated lastMessageId to {$lastMessageId} for next iteration");

            // Process all messages received
            $this->debug("Processing " . count($messages) . " messages for correlation");
            foreach ($messages as $id => $data) {
                // CORRELATION LOGIC (Protocol v2)
            // Daemon uses STREAM MESSAGE ID as request ID, NOT custom field
                $messageType = $data['t'] ?? '';

                // Only process response types
                if ($messageType !== 'r' && $messageType !== 'br') {
                    $this->debug("Skipping message {$id}, type={$messageType} (not a response)");
                    // CRITICAL FIX: ACK skipped messages to prevent infinite loop
                    $this->acknowledgeMessage($this->unifiedStream, $this->clientGroup, $id);
                    continue; // Skip commands, only process responses
                }

                // Get the message ID we sent (stored when we called XADD)
                $ourMessageId = $this->requestToMessageIds[$requestId] ?? null;

                $this->debug("Processing message type={$messageType}, ri=" . ($data['ri'] ?? 'none') . ", ourMessageId={$ourMessageId}");

                // PRIMARY CORRELATION: Check if ri matches our message ID
                $isMatch = false;

                // For regular response ('r'): Check if ri matches our sent message ID
                if ($messageType === 'r') {
                    $isMatch = isset($data['ri']) && $ourMessageId && $data['ri'] === $ourMessageId;
                    $this->debug("Response correlation: ri={$data['ri']}, ourMessageId={$ourMessageId}, match=" . ($isMatch ? "YES" : "NO"));
                    if (!$isMatch) {
                        $this->debug("NO MATCH - ACKing orphaned response {$id}");
                        $this->acknowledgeMessage($this->unifiedStream, $this->clientGroup, $id);
                    }
                }

                // For batch response ('br'): Check batch ID or search messages for sequence
                if ($messageType === 'br') {
                    // Check if batch ID matches our message ID
                    if (isset($data['bi']) && $ourMessageId && $data['bi'] === $ourMessageId) {
                        $isMatch = true;
                        $this->debug("Batch response: bi matches ourMessageId");
                    } else {
                        // Check if our sequence number is in the batch
                        if (isset($this->requestToSequenceNumbers[$requestId])) {
                            $isMatch = true; // We'll search the batch in processing below
                            $this->debug("Batch response: will search for sequence {$this->requestToSequenceNumbers[$requestId]}");
                        }
                    }
                }

            if ($isMatch) {
                $this->debug("Found matching response for request ID: {$requestId}");

                // Handle regular response ('r' type)
                if ($messageType === 'r') {
                    // Parse and return response
                    if (isset($data['r'])) {
                        $responseValue = $data['r'];
                        $parsedData = null;

                        // Handle boolean values directly
                        if ($responseValue === true || $responseValue === "true") {
                            $parsedData = true;
                        } elseif ($responseValue === false || $responseValue === "false") {
                            $parsedData = false;
                        } else {
                            // Try to parse JSON
                            $parsedData = $this->parseJsonResponse($responseValue);
                            if ($parsedData === null) {
                                // If not valid JSON, use as-is
                                $parsedData = $responseValue;
                            }
                        }

                        // Build response with proper structure: status + result + error
                        // ALWAYS use the stream's status field (st/s) for command success/failure
                        $response = [
                            'status' => isset($data['s']) ? $data['s'] : (isset($data['st']) ? $data['st'] : 'ok'),
                            'result' => $parsedData
                        ];

                        // Extract error field (key 'e' from daemon)
                        if (isset($data['e'])) {
                            $response['error'] = $data['e'];
                        } elseif (isset($data['err'])) {
                            $response['error'] = $data['err'];
                        } elseif (isset($data['error'])) {
                            $response['error'] = $data['error'];
                        }

                        // Add message for backward compatibility
                        if (isset($data['m'])) {
                            $response['message'] = $data['m'];
                        } elseif (isset($data['msg'])) {
                            $response['message'] = $data['msg'];
                        }

                        // Acknowledge the message to remove it from pending
                        $this->acknowledgeMessage($this->unifiedStream, $this->clientGroup, $id);

                        return $response;
                    } else {
                        // Construct response from field data directly
                        $result = null;
                        if (isset($data['r'])) {
                            $result = is_string($data['r']) ? JsonHelper::decode($data['r'], true) : $data['r'];
                        }
                        // Acknowledge the message before returning
                        $this->acknowledgeMessage($this->unifiedStream, $this->clientGroup, $id);

                        // Build response with error extraction
                        $response = [
                            'status' => $data['s'] ?? $data['st'] ?? 'ok',
                            'result' => $result,
                            'message' => $data['m'] ?? $data['msg'] ?? null
                        ];

                        // Extract error field (key 'e' from daemon)
                        if (isset($data['e'])) {
                            $response['error'] = $data['e'];
                        } elseif (isset($data['err'])) {
                            $response['error'] = $data['err'];
                        } elseif (isset($data['error'])) {
                            $response['error'] = $data['error'];
                        }

                        return $response;
                    }
                }
                // Handle batch response ('br' type)
                elseif ($messageType === 'br') {
                    $this->debug("Processing batch response message");

                    // Extract batch data
                    $batchId = $data['bi'] ?? '';
                    $batchMessages = [];

                    // Parse batch messages array
                    if (isset($data['m'])) {
                        // Try to decode messages array using optimized JsonHelper
                        if (is_string($data['m'])) {
                            $batchMessages = JsonHelper::decodeBatchMessages($data['m']);
                            if ($batchMessages === null) {
                                // Try unescaping if there are issues
                                $unescaped = stripslashes($data['m']);
                                $batchMessages = JsonHelper::decodeBatchMessages($unescaped);
                            }
                        } elseif (is_array($data['m'])) {
                            $batchMessages = $data['m'];
                        }
                    }

                    $this->debug("Batch response contains " . count($batchMessages) . " messages");

                    // Look for sequence number from our tracking array
                    // This is more reliable than batch_id matching since the daemon may generate
                    // a different batch_id than what the client sent
                    $sequenceNumber = null;
                    if (isset($this->requestToSequenceNumbers[$requestId])) {
                        $sequenceNumber = $this->requestToSequenceNumbers[$requestId];
                        $this->debug("Found tracked sequence number {$sequenceNumber} for request ID: {$requestId}");
                    } elseif (preg_match('/:seq:(\d+)/', $requestId, $matches)) {
                        // Fallback: try to extract from request ID (for batch commands)
                        $sequenceNumber = (int)$matches[1];
                        $this->debug("Extracted sequence number {$sequenceNumber} from request ID: {$requestId}");
                    }

                    // Check if we can find the specific command in the batch by sequence number
                    if ($sequenceNumber !== null) {
                        foreach ($batchMessages as $batchMsg) {
                            if (is_array($batchMsg) && count($batchMsg) >= 3) {
                                // Batch message format: [type, status, result, sequence]
                                // Index 0: type ("r")
                                // Index 1: status code ("0" = success)
                                // Index 2: result data
                                // Index 3: sequence number (echoed from command)
                                $msgSeqNum = isset($batchMsg[3]) ? $batchMsg[3] : $batchMsg[1];
                                $this->debug("Checking batch message seq: {$msgSeqNum} against expected: {$sequenceNumber}");

                                if ($msgSeqNum == $sequenceNumber) { // Use == for type-flexible comparison
                                    $responseData = $batchMsg[2];
                                    $this->debug("Found matching sequence number {$sequenceNumber} in batch response");

                                    // Parse response data using optimized JsonHelper
                                    $parsedResponse = JsonHelper::decodeResponseData($responseData);

                                    if ($parsedResponse && is_array($parsedResponse)) {
                                        if (!isset($parsedResponse['status'])) {
                                            $parsedResponse['status'] = 'ok';
                                        }
                                        // Acknowledge before returning
                                        $this->acknowledgeMessage($this->unifiedStream, $this->clientGroup, $id);
                                        return $parsedResponse;
                                    }
                                }
                            }
                        }
                    }

                    // Last resort: search ALL batch messages for our sequence number (even if batch ID doesn't match)
                    if (isset($this->requestToSequenceNumbers[$requestId])) {
                        $sequenceNumber = $this->requestToSequenceNumbers[$requestId];
                        $this->debug("Searching batch for sequence number {$sequenceNumber} (fallback mode)");

                        foreach ($batchMessages as $batchMsg) {
                            if (is_array($batchMsg) && count($batchMsg) >= 3) {
                                $msgSeqNum = isset($batchMsg[3]) ? $batchMsg[3] : $batchMsg[1];
                                $this->debug("Fallback: checking batch message seq: {$msgSeqNum} against expected: {$sequenceNumber}");

                                if ($msgSeqNum == $sequenceNumber) {
                                    $responseData = $batchMsg[2];
                                    $this->debug("Found matching sequence {$sequenceNumber} in batch (fallback)");

                                    // Parse response data using optimized JsonHelper
                                    $parsedResponse = JsonHelper::decodeResponseData($responseData);

                                    if ($parsedResponse && is_array($parsedResponse)) {
                                        if (!isset($parsedResponse['status'])) {
                                            $parsedResponse['status'] = 'ok';
                                        }
                                        // Acknowledge before returning
                                        $this->acknowledgeMessage($this->unifiedStream, $this->clientGroup, $id);
                                        return $parsedResponse;
                                    }
                                }
                            }
                        }
                    }

                    // If we couldn't find a specific message, return the whole batch
                    // Acknowledge before returning
                    $this->acknowledgeMessage($this->unifiedStream, $this->clientGroup, $id);
                    return [
                        'status' => 'ok',
                        'batch_id' => $batchId,
                        'messages' => $batchMessages,
                        'total_count' => $data['tc'] ?? count($batchMessages)
                    ];
                }
            }
            // End of foreach ($messages as $id => $data)
        }

            // No match found in this batch, continue while loop to retry
            $this->debug("No matching response in this batch (attempt {$retryCount}), continuing...");
        }
        // End of while (microtime(true) < $endTime)

        // Timeout after all retry attempts
        $this->debug("Timeout waiting for response to request {$requestId} after {$retryCount} attempts");
        return null;
    }

    /**
     * Get starting ID for XREAD
     *
     * Returns a recent message ID to start reading from.
     * For request-response pattern, we want to read recent messages where our response might be.
     *
     * @return string Stream ID to start from
     */
    protected function getStartReadId(): string
    {
        try {
            // Read the last few messages using xRevRange (reverse direction, from end)
            // Get the most recent 50 messages to find a starting point
            $recentMessages = $this->storage->xRevRange($this->unifiedStream, '+', '-', 50);

            if (!empty($recentMessages)) {
                // Get the last (oldest of recent) message from this batch
                // xRevRange returns newest first, so we want the last one
                $messageIds = array_keys($recentMessages);
                $startId = end($messageIds); // Last in reversed batch = oldest of recent messages
                $this->debug("Starting XREAD from recent message: {$startId}");
                return $startId;
            }
        } catch (\Exception $e) {
            $this->debug("Error getting start ID: " . $e->getMessage());
        }

        // Fallback: read only new messages from now
        $this->debug("Starting XREAD from current position ($)");
        return '$'; // '$' means "only new messages from now"
    }

    /**
     * Fetch response messages using XREAD (no consumer groups)
     *
     * ARCHITECTURAL FIX: Use XREAD instead of XREADGROUP to eliminate round-robin routing.
     * Each client reads sequentially from the stream and filters for its own responses.
     *
     * @param string $startId Stream ID to start reading from
     * @param int $count Maximum number of messages to read
     * @param int $blockMs How long to block in milliseconds (0 = don't block)
     * @return array Messages keyed by ID
     */
    protected function fetchResponseMessagesXRead(string $startId, int $count = 0, int $blockMs = 0): array
    {
        $fetchCount = $count > 0 ? $count : $this->batchSize;

        try {
            // FIXED: Use XREADGROUP for proper message isolation
            // First try to read pending messages (already delivered but not ACKed)
            $messages = $this->storage->xReadGroup(
                $this->clientGroup,
                $this->consumerName,
                [$this->unifiedStream => '0'],  // Check pending messages first
                $fetchCount,
                0 // Don't block for pending
            );

            // If no pending, read new messages
            if (empty($messages[$this->unifiedStream])) {
                $messages = $this->storage->xReadGroup(
                    $this->clientGroup,
                    $this->consumerName,
                    [$this->unifiedStream => '>'],  // Read new messages
                    $fetchCount,
                    $blockMs // Block for new messages
                );
            }

            if (empty($messages[$this->unifiedStream])) {
                $this->debug("No messages available (XREADGROUP blocking completed)");
                return [];
            }

            $result = [];
            foreach ($messages[$this->unifiedStream] as $id => $fields) {
                $result[$id] = $fields;
            }

            $this->debug("Fetched " . count($result) . " messages using XREADGROUP (consumer: {$this->consumerName})");
            return $result;

        } catch (\Exception $e) {
            // FAIL FAST on ACL NOPERM errors (don't retry indefinitely)
            $errorMsg = $e->getMessage();
            if (stripos($errorMsg, 'NOPERM') !== false || stripos($errorMsg, 'NOAUTH') !== false) {
                error_log('[GSD-Client ConsumerGroupHandler] FATAL: ACL permission denied - ' . $errorMsg);
                throw new StorageException('ACL permission denied: ' . $errorMsg, 0, $e);
            }
            $this->debug("Error fetching response messages with XREADGROUP: " . $errorMsg);
            return [];
        }
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
     * Acknowledge a message in a consumer group
     *
     * @param string $stream Stream name
     * @param string $group Group name
     * @param string $messageId Message ID
     * @return bool Success
     */
    protected function acknowledgeMessage(string $stream, string $group, string $messageId): bool
    {
        try {
            // Try to use the ValKey protocol ACK function if available
            try {
                $this->debug("Trying to acknowledge message {$messageId} using GSD_PROTOCOL_ACK function");

                // Use the GSD_PROTOCOL_ACK function
                $result = $this->storage->fcall(
                    'GSD_PROTOCOL_ACK',
                    [$stream],
                    [$group, $messageId]
                );

                if ($result && $result > 0) {
                    $this->debug("Successfully acknowledged message {$messageId} using protocol function");
                    return true;
                }
            } catch (\Exception $e) {
                $this->debug("Protocol function error: " . $e->getMessage() . ", falling back to direct method");
            }

            // Fallback: Acknowledge message directly
            $ackResult = $this->storage->xAck($stream, $group, [$messageId]);

            // We no longer delete messages from unified stream after acknowledgment
            // as they need to remain for other consumer groups

            return ($ackResult > 0);
        } catch (\Exception $e) {
            $this->debug("Error acknowledging message {$messageId}: " . $e->getMessage());
            return false;
        }
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
     * Parse JSON response
     *
     * @param string|bool $response JSON response string or boolean value
     * @return array|null Parsed response or null on error
     */
    protected function parseJsonResponse($response): ?array
    {
        // Handle boolean responses (e.g., from ping)
        if (is_bool($response)) {
            return ['result' => $response];
        }

        // Handle non-string responses
        if (!is_string($response)) {
            $this->debug("Response is not a string or boolean: " . gettype($response));
            return null;
        }

        try {
            // Use JsonHelper for optimized parsing with simdjson
            $parsed = JsonHelper::decode($response, true);

            // Handle double-encoded JSON (ValKey functions return wrapped JSON)
            // If parsed result is a STRING, try decoding it again
            if (is_string($parsed)) {
                $this->debug("Detected double-encoded JSON, decoding again");
                try {
                    $doubleParsed = JsonHelper::decode($parsed, true);
                    if (is_array($doubleParsed)) {
                        return $doubleParsed;
                    }
                } catch (\Exception $e) {
                    // If second decode fails, return the string as-is
                }
                return ['result' => $parsed];
            }

            return $parsed;
        } catch (\Exception $e) {
            $this->debug("Error parsing response: " . $e->getMessage());
            return null;
        }
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
            error_log("[GSD ConsumerGroupHandler] {$message}");
        }
    }
}
