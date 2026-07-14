<?php
declare(strict_types=1);

namespace gCore\gNode\Broadcast;

use gCore\gNode\Storage\StorageInterface;
use gCore\gNode\Exception\StorageException;

/**
 * BroadcastReader - Client for reading gNode broadcast streams
 *
 * This class reads broadcast messages from the global broadcast stream using
 * ValKey functions (gnode_broadcast.lua) for optimal performance.
 *
 * Stream: {site_id}:gnode:broadcast:global
 * Pattern: XREAD (no consumer groups, each reader tracks own position)
 * Throughput: Optimized for infrequent reads (not high-frequency like health)
 *
 * Architecture:
 * - No consumer groups (no PEL, no XACK)
 * - Time-based retention via XTRIM
 * - Each reader tracks its own last-seen-ID
 * - Perfect for topology updates, service registrations, announcements
 *
 * Usage:
 * ```php
 * $reader = new BroadcastReader($storage, 'production', 'node1');
 *
 * // Read new messages since last check
 * $messages = $reader->read();
 * foreach ($messages as $msg) {
 *     if ($msg->type === 'topology_update') {
 *         // Handle topology update
 *     }
 * }
 *
 * // Write broadcast message
 * $reader->write('config_changed', ['config_key' => 'timeout', 'value' => 30]);
 * ```
 *
 * @package gCore\gNode\Broadcast
 */
class BroadcastReader
{
    /** @var StorageInterface Storage interface for ValKey operations */
    private $storage;

    /** @var string Site identifier */
    private $siteId;

    /** @var string Node identifier */
    private $nodeId;

    /** @var string Broadcast stream key */
    private $broadcastStream;

    /** @var bool Debug mode */
    private $debug;

    /** @var string Last seen message ID (for position tracking) */
    private $lastSeenId = '$';

    /** @var int Message counter for diagnostics */
    private $messageCount = 0;

    /** @var bool Use ValKey functions (FCALL) vs direct XREAD */
    private $useValkeyFunctions = true;

    /**
     * Constructor
     *
     * @param StorageInterface $storage Storage interface
     * @param string $siteId Site identifier (default: 'default')
     * @param string $nodeId Node identifier (default: 'default')
     * @param array $config Configuration options
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
        $this->debug = $config['debug'] ?? false;
        $this->useValkeyFunctions = $config['use_valkey_functions'] ?? true;

        // Broadcast stream naming convention: {site_id}:gnode:broadcast:global
        // Using braces for proper hash distribution in ValKey
        $streamPrefix = $config['stream_prefix'] ?? 'gnode';
        $this->broadcastStream = sprintf(
            '{%s}:%s:broadcast:global',
            $this->siteId,
            $streamPrefix
        );

        // Initialize last-seen position from config or use '$' for new messages only
        $this->lastSeenId = $config['last_seen_id'] ?? '$';

        $this->debug("BroadcastReader initialized for stream: {$this->broadcastStream}");
    }

    /**
     * Read broadcast messages using ValKey function or direct XREAD
     *
     * This reads messages from the broadcast stream starting from the last-seen position.
     * The position is automatically updated after successful read.
     *
     * @param int $count Maximum number of messages to read (default: 100)
     * @param int $blockMs Block timeout in milliseconds (0 = don't block, default: 0)
     * @param string|null $typeFilter Filter by message type (null = all types)
     * @return BroadcastMessage[] Array of broadcast messages
     * @throws StorageException If storage operation fails
     * @api
     */
    public function read(int $count = 100, int $blockMs = 0, ?string $typeFilter = null): array
    {
        if (!$this->storage->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }

        $startTime = microtime(true);

        try {
            $messages = [];

            if ($this->useValkeyFunctions) {
                // Use GNODE_BROADCAST_READ ValKey function (tier 1)
                $messages = $this->readWithValkeyFunction($count, $blockMs);
            } else {
                // Fall back to direct XREAD (tier 2)
                $messages = $this->readWithXread($count, $blockMs);
            }

            // Apply type filter if specified
            if ($typeFilter !== null) {
                $messages = array_filter($messages, function ($msg) use ($typeFilter) {
                    return $msg->matchesType($typeFilter);
                });
                $messages = array_values($messages); // Re-index
            }

            // Update last-seen ID if we got messages
            if (!empty($messages)) {
                $lastMessage = end($messages);
                $this->lastSeenId = $lastMessage->id;
                $this->messageCount += count($messages);
            }

            $duration = microtime(true) - $startTime;

            $this->debug(sprintf(
                "Read %d broadcast messages (filter: %s, duration: %.2fms)",
                count($messages),
                $typeFilter ?? 'none',
                $duration * 1000
            ));

            return $messages;
        } catch (\Exception $e) {
            $this->debug("Failed to read broadcast messages: " . $e->getMessage());
            throw new StorageException("Failed to read broadcast messages: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Read broadcast messages using GNODE_BROADCAST_READ ValKey function
     *
     * @param int $count Maximum messages to read
     * @param int $blockMs Block timeout in milliseconds
     * @return BroadcastMessage[] Array of messages
     * @throws StorageException If FCALL fails
     */
    private function readWithValkeyFunction(int $count, int $blockMs): array
    {
        try {
            // Call GNODE_BROADCAST_READ via FCALL
            // FCALL GNODE_BROADCAST_READ 1 {stream_key} {last_id} {count} {block_ms}
            $result = $this->storage->fcall(
                'GNODE_BROADCAST_READ',
                [$this->broadcastStream],
                [$this->lastSeenId, $count, $blockMs]
            );

            // Result is msgpack-encoded array of messages
            // ValKey returns msgpack, but phpredis may auto-decode to array
            if (!is_array($result)) {
                // If not already decoded, decode msgpack manually
                if (function_exists('msgpack_unpack')) {
                    $result = msgpack_unpack($result);
                } else {
                    // msgpack extension not available, fall back to direct XREAD
                    $this->debug("msgpack extension not available, falling back to direct XREAD");
                    throw new \Exception("msgpack extension not available");
                }
            }

            // Convert to BroadcastMessage objects
            $messages = [];
            foreach ($result as $msgData) {
                if (is_array($msgData)) {
                    $messages[] = BroadcastMessage::fromValkeyResult($msgData);
                }
            }

            return $messages;
        } catch (\Exception $e) {
            $this->debug("ValKey function read failed, falling back to direct XREAD: " . $e->getMessage());
            // Fall back to direct XREAD
            return $this->readWithXread($count, $blockMs);
        }
    }

    /**
     * Read broadcast messages using direct XREAD
     *
     * @param int $count Maximum messages to read
     * @param int $blockMs Block timeout in milliseconds
     * @return BroadcastMessage[] Array of messages
     * @throws StorageException If XREAD fails
     */
    private function readWithXread(int $count, int $blockMs): array
    {
        try {
            // Build XREAD command parameters
            $params = [];

            if ($count > 0) {
                $params['COUNT'] = $count;
            }

            if ($blockMs > 0) {
                $params['BLOCK'] = $blockMs;
            }

            // Execute XREAD: XREAD [COUNT count] [BLOCK milliseconds] STREAMS key [key ...] id [id ...]
            $streams = [$this->broadcastStream => $this->lastSeenId];
            $result = $this->storage->xRead($streams, $count, $blockMs);

            if (empty($result) || !isset($result[$this->broadcastStream])) {
                return [];
            }

            // Convert stream entries to BroadcastMessage objects
            $messages = [];
            foreach ($result[$this->broadcastStream] as $id => $fields) {
                $messages[] = BroadcastMessage::fromStreamEntry($id, $fields);
            }

            return $messages;
        } catch (\Exception $e) {
            throw new StorageException("XREAD failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Write broadcast message using GNODE_BROADCAST_WRITE ValKey function
     *
     * This publishes a message to the broadcast stream. All readers will receive it.
     *
     * @param string $messageType Message type (e.g., "topology_update")
     * @param array $fields Additional message fields
     * @return string Message ID from XADD
     * @throws StorageException If storage operation fails
     * @api
     */
    public function write(string $messageType, array $fields = []): string
    {
        if (!$this->storage->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }

        $startTime = microtime(true);

        try {
            // Add site_id to fields if not present
            if (!isset($fields['site_id']) && !isset($fields['ss'])) {
                $fields['site_id'] = $this->siteId;
            }

            // Convert fields to JSON
            $fieldsJson = json_encode($fields, JSON_UNESCAPED_SLASHES);

            if ($this->useValkeyFunctions) {
                // Use GNODE_BROADCAST_WRITE ValKey function
                $messageId = $this->writeWithValkeyFunction($messageType, $fieldsJson);
            } else {
                // Fall back to direct XADD
                $messageId = $this->writeWithXadd($messageType, $fields);
            }

            $duration = microtime(true) - $startTime;

            $this->debug(sprintf(
                "Wrote broadcast message type '%s' (msg_id: %s, duration: %.2fms)",
                $messageType,
                $messageId,
                $duration * 1000
            ));

            return $messageId;
        } catch (\Exception $e) {
            $this->debug("Failed to write broadcast message: " . $e->getMessage());
            throw new StorageException("Failed to write broadcast message: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Write broadcast message using GNODE_BROADCAST_WRITE ValKey function
     *
     * @param string $messageType Message type
     * @param string $fieldsJson JSON-encoded fields
     * @return string Message ID
     * @throws StorageException If FCALL fails
     */
    private function writeWithValkeyFunction(string $messageType, string $fieldsJson): string
    {
        try {
            // Call GNODE_BROADCAST_WRITE via FCALL
            // FCALL GNODE_BROADCAST_WRITE 1 {stream_key} {message_type} {fields_json}
            $messageId = $this->storage->fcall(
                'GNODE_BROADCAST_WRITE',
                [$this->broadcastStream],
                [$messageType, $fieldsJson]
            );

            return (string)$messageId;
        } catch (\Exception $e) {
            $this->debug("ValKey function write failed, falling back to direct XADD: " . $e->getMessage());
            // Fall back to direct XADD
            $fields = json_decode($fieldsJson, true) ?? [];
            return $this->writeWithXadd($messageType, $fields);
        }
    }

    /**
     * Write broadcast message using direct XADD
     *
     * @param string $messageType Message type
     * @param array $fields Additional fields
     * @return string Message ID
     * @throws StorageException If XADD fails
     */
    private function writeWithXadd(string $messageType, array $fields): string
    {
        try {
            // Add required fields
            $streamFields = [
                't' => $messageType,
                'ss' => $fields['site_id'] ?? $this->siteId
            ];

            // Add timestamp if not present
            if (!isset($fields['ts']) && !isset($fields['timestamp'])) {
                $streamFields['ts'] = (string)(microtime(true) * 1000);
            }

            // Merge with additional fields
            foreach ($fields as $key => $value) {
                if ($key !== 'site_id') { // Already added as 'ss'
                    $streamFields[$key] = is_scalar($value) ? (string)$value : json_encode($value);
                }
            }

            // Execute XADD
            $messageId = $this->storage->xAdd($this->broadcastStream, '*', $streamFields);

            return $messageId;
        } catch (\Exception $e) {
            throw new StorageException("XADD failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Trim broadcast stream by retention time using GNODE_BROADCAST_TRIM
     *
     * This removes old messages to keep stream size manageable.
     * Uses MAXLEN with approximate trim for efficiency.
     *
     * @param int $retentionSeconds Keep messages newer than this (default: 300 = 5 minutes)
     * @return int Number of messages trimmed
     * @throws StorageException If operation fails
     * @api
     */
    public function trim(int $retentionSeconds = 300): int
    {
        if (!$this->storage->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }

        try {
            if ($this->useValkeyFunctions) {
                // Use GNODE_BROADCAST_TRIM ValKey function
                $trimmed = $this->storage->fcall(
                    'GNODE_BROADCAST_TRIM',
                    [$this->broadcastStream],
                    [$retentionSeconds]
                );

                $this->debug("Trimmed broadcast stream: removed {$trimmed} messages");
                return (int)$trimmed;
            } else {
                // Fall back to direct XTRIM with MAXLEN
                $estimatedRate = 10; // messages per second
                $maxMessages = max($retentionSeconds * $estimatedRate, 1000);

                $trimmed = $this->storage->xTrim($this->broadcastStream, $maxMessages, true); // approximate

                $this->debug("Trimmed broadcast stream: removed {$trimmed} messages");
                return (int)$trimmed;
            }
        } catch (\Exception $e) {
            $this->debug("Failed to trim broadcast stream: " . $e->getMessage());
            throw new StorageException("Failed to trim broadcast stream: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get broadcast stream metadata using GNODE_BROADCAST_INFO
     *
     * @return array Stream info with length, first_id, last_id
     * @throws StorageException If operation fails
     */
    public function getStreamInfo(): array
    {
        if (!$this->storage->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }

        try {
            if ($this->useValkeyFunctions) {
                try {
                    // Use GNODE_BROADCAST_INFO ValKey function
                    $result = $this->storage->fcall(
                        'GNODE_BROADCAST_INFO',
                        [$this->broadcastStream],
                        []
                    );

                    // Result is msgpack-encoded info map
                    if (!is_array($result)) {
                        if (function_exists('msgpack_unpack')) {
                            $result = msgpack_unpack($result);
                        } else {
                            // msgpack extension not available, fall back to XINFO STREAM
                            $this->debug("msgpack extension not available, falling back to XINFO STREAM");
                            throw new \Exception("msgpack extension not available");
                        }
                    }

                    return $result;
                } catch (\Exception $e) {
                    // ValKey function failed, fall back to XINFO STREAM
                    $this->debug("ValKey function failed, falling back to XINFO STREAM: " . $e->getMessage());
                    return $this->storage->xInfo('STREAM', $this->broadcastStream);
                }
            } else {
                // Fall back to XINFO STREAM
                return $this->storage->xInfo('STREAM', $this->broadcastStream);
            }
        } catch (\Exception $e) {
            $this->debug("Failed to get stream info: " . $e->getMessage());
            throw new StorageException("Failed to get stream info: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Reset position to read all messages (from beginning)
     *
     * @return void
     */
    public function resetPosition(): void
    {
        $this->lastSeenId = '0';
        $this->debug("Reset position to beginning of stream");
    }

    /**
     * Reset position to read only new messages (from now)
     *
     * @return void
     * @api
     */
    public function resetToNewMessages(): void
    {
        $this->lastSeenId = '$';
        $this->debug("Reset position to new messages only");
    }

    /**
     * Set position to specific message ID
     *
     * @param string $messageId Message ID to start from
     * @return void
     * @api
     */
    public function setPosition(string $messageId): void
    {
        $this->lastSeenId = $messageId;
        $this->debug("Set position to message ID: {$messageId}");
    }

    /**
     * Get current position (last-seen message ID)
     *
     * @return string Current position
     * @api
     */
    public function getPosition(): string
    {
        return $this->lastSeenId;
    }

    /**
     * Get broadcast stream name
     *
     * @return string Broadcast stream key
     */
    public function getBroadcastStream(): string
    {
        return $this->broadcastStream;
    }

    /**
     * Get site ID
     *
     * @return string Site identifier
     */
    public function getSiteId(): string
    {
        return $this->siteId;
    }

    /**
     * Get node ID
     *
     * @return string Node identifier
     */
    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    /**
     * Get reader statistics
     *
     * @return array Statistics about read operations
     * @api
     */
    public function getStatistics(): array
    {
        return [
            'broadcast_stream' => $this->broadcastStream,
            'site_id' => $this->siteId,
            'node_id' => $this->nodeId,
            'messages_read' => $this->messageCount,
            'last_seen_id' => $this->lastSeenId,
            'using_valkey_functions' => $this->useValkeyFunctions
        ];
    }

    /**
     * Reset statistics
     *
     * @return void
     */
    public function resetStatistics(): void
    {
        $this->messageCount = 0;
    }

    /**
     * Log debug message
     *
     * @param string $message Debug message
     * @return void
     */
    private function debug(string $message): void
    {
        if ($this->debug) {
            error_log("[BroadcastReader] {$message}");
        }
    }
}
