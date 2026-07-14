<?php
declare(strict_types=1);

namespace gCore\gNode;

use Redis;
use gCore\gNode\Exception\StorageException;

/**
 * ConnectionPool - Manages persistent Redis connections
 *
 * Uses PHP persistent connections (pconnect) to maintain connection pools
 * across requests in PHP-FPM/Apache environments, eliminating connection
 * overhead and reducing latency.
 *
 * @package gCore\gNode
 */
class ConnectionPool
{
    /** @var array<string, Redis> Pool of persistent connections indexed by "host:port" */
    private static $connections = [];

    /** @var array<string, int> Connection usage counter for monitoring */
    private static $usageStats = [];

    /**
     * Get a persistent Redis connection from the pool
     *
     * Creates a new persistent connection if one doesn't exist for the
     * given host:port combination. Persistent connections survive request
     * boundaries and are reused across multiple requests handled by the
     * same PHP worker process.
     *
     * @param string $host Redis host
     * @param int $port Redis port
     * @param string|null $user ACL username (optional, for ACL authentication)
     * @param string|null $password Authentication password
     * @param float $timeout Connection timeout in seconds
     * @param int $retryInterval Retry interval in milliseconds
     * @param float $readTimeout Read timeout in seconds
     * @param int $database Database number
     * @return Redis Persistent Redis connection
     * @throws StorageException If connection fails
     */
    public static function getConnection(
        string $host = '127.0.0.1',
        int $port = 47445,
        ?string $user = null,
        ?string $password = null,
        float $timeout = 2.5,
        int $retryInterval = 100,
        float $readTimeout = 2.5,
        int $database = 0
    ): Redis {
        $key = sprintf('%s:%d:%d', $host, $port, $database);

        // Return existing connection if available and still connected
        if (isset(self::$connections[$key])) {
            $redis = self::$connections[$key];

            // Verify connection is still alive with a quick ping
            // Use very short timeout to prevent blocking
            try {
                // Set a short read timeout for the ping check
                $originalTimeout = $redis->getOption(\Redis::OPT_READ_TIMEOUT);
                $redis->setOption(\Redis::OPT_READ_TIMEOUT, 0.5); // 500ms max for ping

                if (@$redis->ping()) {
                    // Restore original timeout
                    $redis->setOption(\Redis::OPT_READ_TIMEOUT, $originalTimeout);
                    self::$usageStats[$key] = (self::$usageStats[$key] ?? 0) + 1;
                    return $redis;
                }

                // Restore original timeout even on failure
                $redis->setOption(\Redis::OPT_READ_TIMEOUT, $originalTimeout);
            } catch (\Exception $e) {
                // Connection died, remove from pool and create new one
                unset(self::$connections[$key]);
            }
        }

        // Create new persistent connection
        $redis = new Redis();

        try {
            // Use pconnect for persistent connections (survives request end)
            // The persistent_id is the connection key to ensure same worker reuses same connection
            $connected = @$redis->pconnect(
                $host,
                $port,
                $timeout,
                $key,  // persistent_id - workers reuse connection with this ID
                $retryInterval,
                $readTimeout
            );

            if (!$connected) {
                throw new StorageException(
                    sprintf('Failed to establish persistent connection to Redis at %s:%d', $host, $port)
                );
            }

            // Authenticate if password provided
            if ($password !== null && $password !== '') {
                if ($user !== null && $user !== '') {
                    // ACL mode: AUTH username password
                    if (!$redis->auth([$user, $password])) {
                        throw new StorageException('ValKey ACL authentication failed');
                    }
                } else {
                    // Requirepass mode: AUTH password (backward compatible)
                    if (!$redis->auth($password)) {
                        throw new StorageException('ValKey authentication failed');
                    }
                }
            }

            // Select database if not default
            if ($database !== 0) {
                if (!$redis->select($database)) {
                    throw new StorageException(sprintf('Failed to select Redis database %d', $database));
                }
            }

            // Store in pool
            self::$connections[$key] = $redis;
            self::$usageStats[$key] = 1;

            return $redis;

        } catch (\Exception $e) {
            throw new StorageException(
                sprintf('Connection pool error: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Get connection pool statistics for monitoring
     *
     * @return array{connections: int, stats: array<string, int>}
     */
    public static function getStats(): array
    {
        return [
            'connections' => count(self::$connections),
            'stats' => self::$usageStats,
        ];
    }

    /**
     * Close all connections in the pool
     *
     * This is primarily for testing purposes. In production,
     * persistent connections are managed by PHP-FPM/Apache.
     *
     * @return void
     */
    public static function closeAll(): void
    {
        foreach (self::$connections as $redis) {
            try {
                $redis->close();
            } catch (\Exception $e) {
                // Ignore close errors
            }
        }

        self::$connections = [];
        self::$usageStats = [];
    }

    /**
     * Remove a specific connection from the pool
     *
     * @param string $host Redis host
     * @param int $port Redis port
     * @param int $database Database number
     * @return void
     */
    public static function removeConnection(
        string $host = '127.0.0.1',
        int $port = 47445,
        int $database = 0
    ): void {
        $key = sprintf('%s:%d:%d', $host, $port, $database);

        if (isset(self::$connections[$key])) {
            try {
                self::$connections[$key]->close();
            } catch (\Exception $e) {
                // Ignore close errors
            }

            unset(self::$connections[$key]);
            unset(self::$usageStats[$key]);
        }
    }
}
