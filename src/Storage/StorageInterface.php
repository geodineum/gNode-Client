<?php
declare(strict_types=1);

namespace gCore\gNode\Storage;

/**
 * StorageInterface - Interface for storage backends
 *
 * Defines the methods required by a storage backend for gNode client.
 *
 * @package gCore\gNode\Storage
 */
interface StorageInterface
{
    /**
     * Check if connected to storage
     *
     * @return bool Connection status
     */
    public function isConnected(): bool;

    /**
     * Ping the storage
     *
     * @return bool True if ping succeeded
     */
    public function ping(): bool;

    /**
     * Increment an integer key (metrics counters)
     *
     * @param string $key Key to increment
     * @return int|false New value, or false on failure
     */
    public function incr(string $key);

    /**
     * Add elements to a HyperLogLog (unique-visitor metrics)
     *
     * @param string $key HLL key
     * @param array $elements Elements to add
     * @return bool True if the HLL was modified
     */
    public function pfAdd(string $key, array $elements): bool;

    /**
     * Get a value from storage
     *
     * @param string $key Key to get
     * @return mixed Value or null if not found
     */
    public function get(string $key);

    /**
     * Set a value in storage
     *
     * @param string $key Key to set
     * @param mixed $value Value to set
     * @param int|null $expiration Optional expiration time in seconds
     * @return bool True if set succeeded
     */
    public function set(string $key, $value, ?int $expiration = null): bool;

    /**
     * Delete a key from storage
     *
     * @param string $key Key to delete
     * @return bool True if delete succeeded
     */
    public function delete(string $key): bool;

    /**
     * Check if a key exists in storage
     *
     * @param string $key Key to check
     * @return bool True if key exists
     */
    public function exists(string $key): bool;

    /**
     * Get a hash field
     *
     * @param string $key Hash key
     * @param string $field Field name
     * @return mixed Field value or null if not found
     */
    public function hGet(string $key, string $field);

    /**
     * Set a hash field
     *
     * @param string $key Hash key
     * @param string $field Field name
     * @param mixed $value Field value
     * @return bool True if set succeeded
     */
    public function hSet(string $key, string $field, $value): bool;

    /**
     * Get all hash fields
     *
     * @param string $key Hash key
     * @return array Associative array of field => value pairs
     */
    public function hGetAll(string $key): array;

    /**
     * Evaluate a Lua script
     *
     * @param string $script Script content
     * @param array $keys Keys to pass to the script
     * @param array $args Arguments to pass to the script
     * @return mixed Script result
     */
    public function eval(string $script, array $keys, array $args);

    /**
     * Evaluate a Lua script by SHA
     *
     * @param string $sha Script SHA
     * @param array $keys Keys to pass to the script
     * @param array $args Arguments to pass to the script
     * @return mixed Script result
     */
    public function evalSha(string $sha, array $keys, array $args);

    /**
     * Load a Lua script
     *
     * @param string $script Script content
     * @return string Script SHA
     */
    public function scriptLoad(string $script): string;

    /**
     * Call a Redis function (FCALL)
     *
     * @param string $function Function name
     * @param array $keys Keys to pass to the function
     * @param array $args Arguments to pass to the function
     * @return mixed Function result
     */
    public function fcall(string $function, array $keys, array $args);

    /**
     * Call a read-only Redis function (FCALL_RO)
     *
     * Only valid for functions registered with the `no-writes` flag. Distinct
     * from fcall() because FCALL_RO is permitted on a replica, so read paths
     * that use it do not have to reach the master.
     *
     * @param string $function Function name
     * @param array $keys Keys to pass to the function
     * @param array $args Arguments to pass to the function
     * @return mixed Function result
     */
    public function fcallRo(string $function, array $keys, array $args);

    /**
     * Create a stream consumer group
     *
     * @param string $key Stream key
     * @param string $group Group name
     * @param string $id Start ID
     * @param bool $mkStream Create stream if not exists
     * @return bool True if created successfully
     */
    public function xGroupCreate(string $key, string $group, string $id, bool $mkStream = false): bool;

    /**
     * Add a message to a stream
     *
     * @param string $key Stream key
     * @param string $id Message ID (use * for auto-generation)
     * @param array $fields Message fields
     * @return string Message ID
     */
    public function xAdd(string $key, string $id, array $fields): string;

    /**
     * Read messages from a stream
     *
     * @param array $streams Array of stream => ID pairs
     * @param int $count Maximum number of messages to return
     * @param int|null $block Block milliseconds (0 = indefinitely, null = don't block)
     * @return array Messages
     */
    public function xRead(array $streams, int $count = 1, ?int $block = null): array;

    /**
     * Read messages from a stream as part of a consumer group
     *
     * @param string $group Group name
     * @param string $consumer Consumer name
     * @param array $streams Array of stream => ID pairs
     * @param int $count Maximum number of messages to return
     * @param int|null $block Block milliseconds (0 = indefinitely, null = don't block)
     * @param bool $noAck Don't automatically acknowledge fetched messages
     * @return array Messages
     */
    public function xReadGroup(
        string $group,
        string $consumer,
        array $streams,
        int $count = 1,
        ?int $block = null,
        bool $noAck = false
    ): array;

    /**
     * Acknowledge messages in a consumer group
     *
     * @param string $key Stream key
     * @param string $group Group name
     * @param array $ids Message IDs to acknowledge
     * @return int Number of messages acknowledged
     */
    public function xAck(string $key, string $group, array $ids): int;

    /**
     * Get pending messages for a consumer group
     *
     * @param string $key Stream key
     * @param string $group Group name
     * @param string $start Start ID
     * @param string $end End ID
     * @param int $count Maximum number of messages to return
     * @param string|null $consumer Filter by consumer name
     * @return array Pending messages
     */
    public function xPending(
        string $key,
        string $group,
        string $start = '-',
        string $end = '+',
        int $count = 10,
        ?string $consumer = null
    ): array;

    /**
     * Claim pending messages for a consumer
     *
     * @param string $key Stream key
     * @param string $group Group name
     * @param string $consumer Consumer name
     * @param int $minIdleTime Minimum idle time in milliseconds
     * @param array $ids Message IDs to claim
     * @param array $options Additional options
     * @return array Claimed messages
     */
    public function xClaim(
        string $key,
        string $group,
        string $consumer,
        int $minIdleTime,
        array $ids,
        array $options = []
    ): array;

    /**
     * Delete messages from a stream
     *
     * @param string $key Stream key
     * @param array $ids Message IDs to delete
     * @return int Number of messages deleted
     */
    public function xDel(string $key, array $ids): int;

    /**
     * Get messages from a stream by ID range
     *
     * @param string $key Stream key
     * @param string $start Start ID
     * @param string $end End ID
     * @param int|null $count Maximum number of messages to return
     * @return array Messages
     */
    public function xRange(string $key, string $start = '-', string $end = '+', ?int $count = null): array;

    /**
     * Read messages from a stream in reverse order (newest first)
     *
     * @param string $key Stream key
     * @param string $end End ID (highest, e.g., '+')
     * @param string $start Start ID (lowest, e.g., '-')
     * @param int|null $count Maximum number of messages to return
     * @return array Messages
     */
    public function xRevRange(string $key, string $end = '+', string $start = '-', ?int $count = null): array;

    /**
     * Trim a stream to a certain length
     *
     * @param string $key Stream key
     * @param int $maxlen Maximum length to maintain
     * @param bool $approximate Use approximate trimming (~)
     * @return int Number of messages deleted
     */
    public function xTrim(string $key, int $maxlen, bool $approximate = false): int;

    /**
     * Get information about a stream
     *
     * @param string $subcommand Subcommand (STREAM, GROUPS, CONSUMERS)
     * @param string $key Stream key
     * @param string|null $group Group name (for CONSUMERS subcommand)
     * @return array Stream information
     */
    public function xInfo(string $subcommand, string $key, ?string $group = null): array;

    /**
     * Destroy a consumer group
     *
     * @param string $key Stream key
     * @param string $group Group name
     * @return bool True if successful
     */
    public function xGroupDestroy(string $key, string $group): bool;
}
