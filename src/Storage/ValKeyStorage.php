<?php
declare(strict_types=1);

namespace gCore\gNode\Storage;

use gCore\gNode\Exception\StorageException;
use gCore\gNode\ConnectionPool;
use Redis;

/**
 * ValKeyStorage - ValKey implementation of StorageInterface
 *
 * @package gCore\gNode\Storage
 */
class ValKeyStorage implements StorageInterface
{
    /** @var Redis Redis client */
    public $redis;

    /** @var array ValKey connection config */
    protected $config;

    /** @var bool Connection status */
    protected $connected = false;

    /**
     * Commit 1.13.c (NC-D3.06): retryable error patterns for transient
     * ValKey conditions where a short backoff + retry is the right
     * answer instead of immediate failure. Matched case-insensitively
     * against the exception message (substring match).
     *
     *   CLUSTERDOWN     — cluster lost majority, may recover quickly
     *   MASTERDOWN      — replica saw master failure, sentinel reroute
     *   TRYAGAIN        — multi-key op crossed slot during migration
     *   MOVED           — slot has moved (we don't auto-redirect, but
     *                      retry once in case the topology stabilized)
     *   ASK             — slot mid-migration ask redirection
     *   LOADING         — replica still loading dataset
     *   BUSY            — server busy running script (call SCRIPT KILL
     *                      or wait); brief retry can succeed
     *   connection lost / read error / timeout — socket-level transients
     *
     * Non-retryable errors (NOAUTH, WRONGPASS, NOPERM, NOSCRIPT,
     * MOVED-after-retry, malformed args, etc.) propagate as
     * StorageException on the first failure. We don't try to be
     * clever about distinguishing every one; if it's not in this
     * list, we throw.
     */
    private const RETRYABLE_PATTERNS = [
        'CLUSTERDOWN',
        'MASTERDOWN',
        'TRYAGAIN',
        'LOADING',
        'BUSY',
        'connection lost',
        'read error',
        'timeout',
        'went away',
    ];

    private const MAX_RETRIES = 3;

    /**
     * Run a redis op with retry+exponential-backoff+jitter on
     * transient errors. Non-retryable errors (or retries-exhausted)
     * propagate as StorageException. The caller-provided $opName
     * names the operation in error messages so callers don't need
     * their own try/catch wrapping.
     *
     * Initial delay: 50ms; doubles each retry; bounded by jitter [0,
     * delay_ms] additive.
     *
     * @template T
     * @param  callable():T $op
     * @param  string       $opName
     * @return T
     */
    private function withRetry(callable $op, string $opName)
    {
        $attempt = 0;
        $delayMs = 50;
        while (true) {
            try {
                return $op();
            } catch (\Exception $e) {
                $attempt++;
                $message = $e->getMessage();
                $retryable = false;
                foreach (self::RETRYABLE_PATTERNS as $pattern) {
                    if (stripos($message, $pattern) !== false) {
                        $retryable = true;
                        break;
                    }
                }
                if (!$retryable || $attempt > self::MAX_RETRIES) {
                    throw new StorageException(
                        "Redis {$opName} error (attempts={$attempt}): {$message}",
                        0,
                        $e
                    );
                }
                // Exponential backoff with additive jitter
                $jitter = mt_rand(0, $delayMs);
                usleep(($delayMs + $jitter) * 1000);
                $delayMs *= 2;
            }
        }
    }

    /**
     * Constructor
     *
     * @param array $config ValKey connection config
     * @throws StorageException If Redis extension is not loaded
     */
    public function __construct(array $config = [])
    {
        if (!extension_loaded('redis')) {
            throw new StorageException('Redis extension not loaded');
        }

        $this->config = array_merge([
            'host' => '127.0.0.1',
            'port' => 47445,
            'timeout' => 2.5,  // Default timeout for persistent connections
            'retry_interval' => 100,
            'read_timeout' => 2.5,
            'password' => null,
            'database' => 0,
            'prefix' => '',
        ], $config);

        // Use ConnectionPool for persistent connection reuse across requests
        $this->connect();
    }

    /**
     * Connect to Redis using ConnectionPool for persistent connections
     *
     * Uses the ConnectionPool to obtain a persistent connection that survives
     * request boundaries, eliminating connection overhead in PHP-FPM/Apache.
     *
     * @throws StorageException If connection fails
     */
    protected function connect(): void
    {
        try {
            // Get persistent connection from pool
            // ConnectionPool handles authentication and database selection
            $this->redis = ConnectionPool::getConnection(
                (string) $this->config['host'],
                (int) $this->config['port'],
                $this->config['user'] ?? null,      // ACL username (optional)
                $this->config['password'] ?? null,   // Password (required for auth)
                (float) $this->config['timeout'],
                (int) $this->config['retry_interval'],
                (float) $this->config['read_timeout'],
                (int) $this->config['database']
            );

            // Set prefix if provided
            if (!empty($this->config['prefix'])) {
                $this->redis->setOption(Redis::OPT_PREFIX, $this->config['prefix']);
            }

            $this->connected = true;
        } catch (\Exception $e) {
            throw new StorageException(
                "ValKey connection error: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get direct access to the Redis instance
     *
     * @deprecated SECURITY WARNING: Direct Redis access bypasses FCALL security model.
     *             All operations should use fcall() method to ensure ACL restrictions
     *             are enforced. This method will be removed in v3.0.
     *
     *             Attack scenario: If PHP is compromised, getRedis() allows arbitrary
     *             Redis commands, bypassing ACL user restrictions. FCALL-only mode
     *             limits attackers to pre-defined Lua functions only.
     *
     *             Use fcall() instead:
     *             - $storage->fcall('GNODE_CACHE_GET', [], [$key, $siteId])
     *             - $storage->fcall('GNODE_CACHE_SET', [], [$key, $value, $ttl, $siteId])
     *             - $storage->fcall('GNODE_STREAM_ADD', [$streamKey], [$fieldsJson, $maxLen])
     *
     * @return Redis Redis instance
     * @throws StorageException Always throws - use fcall() instead
     */
    public function getRedis(): Redis
    {
        // SECURITY: Log access attempt for audit trail — but only ONCE
        // per (process, caller-file) pair. The previous unconditional
        // error_log() flooded logs during fcall-heavy paths (every
        // luaGet/luaDel writes via recordFcallMetrics() → getRedis()),
        // generating thousands of identical warnings per request and
        // amplifying the perceived "hang" during CLI registration loops.
        // Once-per-caller is enough to surface the audit trail without
        // turning log volume into a measurable cost.
        static $loggedCallers = [];
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[1]['file'] ?? 'unknown';
        if (!isset($loggedCallers[$caller])) {
            $loggedCallers[$caller] = true;
            error_log(
                '[gNode-Client SECURITY] getRedis() called from '
                . $caller . ' — this method is deprecated and insecure. '
                . 'Use fcall() instead. (Logged once per caller per process.)'
            );
        }

        throw new StorageException(
            'getRedis() is removed for security reasons — the raw connection '
            . 'bypasses the FCALL allowlist. Use fcall()/the typed storage API instead.'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->redis->isConnected();
    }

    /**
     * {@inheritdoc}
     */
    public function ping(): bool
    {
        try {
            $result = $this->redis->ping();
            // Modern Redis/ValKey can return true, 1, or '+PONG'
            return $result === true || $result === 1 || $result === '+PONG';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function incr(string $key)
    {
        return $this->withRetry(fn() => $this->redis->incr($key), 'incr');
    }

    /**
     * {@inheritdoc}
     */
    public function pfAdd(string $key, array $elements): bool
    {
        return (bool)$this->withRetry(fn() => $this->redis->pfAdd($key, $elements), 'pfAdd');
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key)
    {
        return $this->withRetry(fn() => $this->redis->get($key), 'get');
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, $value, ?int $expiration = null): bool
    {
        return $this->withRetry(function () use ($key, $value, $expiration) {
            if ($expiration !== null) {
                return $this->redis->setex($key, $expiration, $value);
            }
            return $this->redis->set($key, $value);
        }, 'set');
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        return $this->withRetry(fn() => $this->redis->del($key) > 0, 'delete');
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $key): bool
    {
        return $this->withRetry(fn() => $this->redis->exists($key) > 0, 'exists');
    }

    /**
     * {@inheritdoc}
     */
    public function hGet(string $key, string $field)
    {
        return $this->withRetry(fn() => $this->redis->hGet($key, $field), 'hGet');
    }

    /**
     * {@inheritdoc}
     */
    public function hSet(string $key, string $field, $value): bool
    {
        return $this->withRetry(
            fn() => $this->redis->hSet($key, $field, $value) !== false,
            'hSet'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function hGetAll(string $key): array
    {
        return $this->withRetry(function () use ($key) {
            $result = $this->redis->hGetAll($key);
            return $result ?: [];
        }, 'hGetAll');
    }

    /**
     * {@inheritdoc}
     */
    public function eval(string $script, array $keys, array $args)
    {
        return $this->withRetry(function () use ($script, $keys, $args) {
            $numKeys = count($keys);
            $allArgs = array_merge($keys, $args);
            return $this->redis->eval($script, $allArgs, $numKeys);
        }, 'eval');
    }

    /**
     * {@inheritdoc}
     */
    public function evalSha(string $sha, array $keys, array $args)
    {
        return $this->withRetry(function () use ($sha, $keys, $args) {
            $numKeys = count($keys);
            $allArgs = array_merge($keys, $args);
            return $this->redis->evalSha($sha, $allArgs, $numKeys);
        }, 'evalSha');
    }

    /**
     * {@inheritdoc}
     */
    public function scriptLoad(string $script): string
    {
        return $this->withRetry(
            fn() => $this->redis->script('LOAD', $script),
            'scriptLoad'
        );
    }

    /**
     * {@inheritdoc}
     * @api
     */
    public function fcall(string $function, array $keys, array $args)
    {
        return $this->withRetry(function () use ($function, $keys, $args) {
            // Ensure all args are scalar - JSON-encode arrays/objects.
            // rawCommand() only accepts scalar values.
            $scalarArgs = array_map(function ($arg) {
                if (is_array($arg) || is_object($arg)) {
                    return json_encode($arg);
                }
                return $arg;
            }, $args);

            $command = ['FCALL', $function, count($keys)];
            $command = array_merge($command, $keys, $scalarArgs);
            return $this->redis->rawCommand(...$command);
        }, 'FCALL');
    }

    /**
     * {@inheritdoc}
     */
    public function xGroupCreate(string $key, string $group, string $id, bool $mkStream = false): bool
    {
        try {
            $args = [$key, $group, $id];
            if ($mkStream) {
                $args[] = 'MKSTREAM';
            }

            $result = $this->redis->xGroup('CREATE', ...$args);

            // Handle "BUSYGROUP" error (group already exists)
            if (!$result && $this->redis->getLastError() && strpos($this->redis->getLastError(), 'BUSYGROUP') !== false) {
                $this->redis->clearLastError();
                return true;
            }

            return $result;
        } catch (\Exception $e) {
            throw new StorageException("Redis xGroupCreate error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Add a message to a stream
     *
     * @param string $key Stream key
     * @param string $id Message ID (use * for auto-generation)
     * @param array $fields Message fields
     * @return string Message ID
     * @throws StorageException If operation fails
     * @api
     */
    public function xAdd(string $key, string $id, array $fields): string
    {
        try {
            return $this->redis->xAdd($key, $id, $fields);
        } catch (\Exception $e) {
            throw new StorageException("Redis xAdd error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Read messages from a stream
     *
     * @param array $streams Array of stream => ID pairs
     * @param int $count Maximum number of messages to return
     * @param int|null $block Block milliseconds (0 = indefinitely, null = don't block)
     * @return array Messages
     * @throws StorageException If operation fails
     */
    public function xRead(array $streams, int $count = 1, ?int $block = null): array
    {
        try {
            // phpredis xRead expects count and block as separate parameters, not options array
            // xRead(array $streams, int $count = null, int $block = null)
            $result = $this->redis->xRead($streams, $count > 1 ? $count : null, $block);
            return $result ?: [];
        } catch (\Exception $e) {
            throw new StorageException("Redis xRead error: {$e->getMessage()}", 0, $e);
        }
    }

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
     * @throws StorageException If operation fails
     */
    public function xReadGroup(
        string $group,
        string $consumer,
        array $streams,
        int $count = 1,
        ?int $block = null,
        bool $noAck = false
    ): array {
        try {
            $options = [];
            if ($count > 1) {
                $options['COUNT'] = $count;
            }
            if ($block !== null) {
                $options['BLOCK'] = $block;
            }
            if ($noAck) {
                $options['NOACK'] = true;
            }

            // Extract options properly for Redis extension parameters
            $count = $options['COUNT'] ?? null;
            $block = $options['BLOCK'] ?? null;

            // Call xReadGroup with correct parameter types
            $result = $this->redis->xReadGroup($group, $consumer, $streams, $count, $block);
            return $result ?: [];
        } catch (\Exception $e) {
            throw new StorageException("Redis xReadGroup error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Acknowledge messages in a consumer group
     *
     * @param string $key Stream key
     * @param string $group Group name
     * @param array $ids Message IDs to acknowledge
     * @return int Number of messages acknowledged
     * @throws StorageException If operation fails
     */
    public function xAck(string $key, string $group, array $ids): int
    {
        try {
            return $this->redis->xAck($key, $group, $ids);
        } catch (\Exception $e) {
            throw new StorageException("Redis xAck error: {$e->getMessage()}", 0, $e);
        }
    }

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
     * @throws StorageException If operation fails
     */
    public function xPending(
        string $key,
        string $group,
        string $start = '-',
        string $end = '+',
        int $count = 10,
        ?string $consumer = null
    ): array {
        try {
            $args = [$key, $group];

            // If we only want counts, use simple format
            if ($start === '-' && $end === '+' && $count === 10 && $consumer === null) {
                return $this->redis->xPending(...$args);
            }

            // Otherwise use extended format
            $args = array_merge($args, [$start, $end, $count]);
            if ($consumer !== null) {
                $args[] = $consumer;
            }

            $result = $this->redis->xPending(...$args);
            return $result ?: [];
        } catch (\Exception $e) {
            throw new StorageException("Redis xPending error: {$e->getMessage()}", 0, $e);
        }
    }

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
     * @throws StorageException If operation fails
     */
    public function xClaim(
        string $key,
        string $group,
        string $consumer,
        int $minIdleTime,
        array $ids,
        array $options = []
    ): array {
        try {
            $args = [$key, $group, $consumer, $minIdleTime, $ids];

            // Add options if provided
            if (!empty($options)) {
                foreach ($options as $name => $value) {
                    $args[] = $name;
                    if ($value !== null) {
                        $args[] = $value;
                    }
                }
            }

            $result = $this->redis->xClaim(...$args);
            return $result ?: [];
        } catch (\Exception $e) {
            throw new StorageException("Redis xClaim error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Delete messages from a stream
     *
     * @param string $key Stream key
     * @param array $ids Message IDs to delete
     * @return int Number of messages deleted
     * @throws StorageException If operation fails
     */
    public function xDel(string $key, array $ids): int
    {
        try {
            return $this->redis->xDel($key, $ids);
        } catch (\Exception $e) {
            throw new StorageException("Redis xDel error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get messages from a stream by ID range
     *
     * @param string $key Stream key
     * @param string $start Start ID
     * @param string $end End ID
     * @param int|null $count Maximum number of messages to return
     * @return array Messages
     * @throws StorageException If operation fails
     */
    public function xRange(string $key, string $start = '-', string $end = '+', ?int $count = null): array
    {
        try {
            if ($count !== null) {
                $result = $this->redis->xRange($key, $start, $end, $count);
            } else {
                $result = $this->redis->xRange($key, $start, $end);
            }
            return $result ?: [];
        } catch (\Exception $e) {
            throw new StorageException("Redis xRange error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Read messages from a stream in reverse order (newest first)
     *
     * @param string $key Stream key
     * @param string $end End ID (highest, e.g., '+')
     * @param string $start Start ID (lowest, e.g., '-')
     * @param int|null $count Maximum number of messages to return
     * @return array Messages
     * @throws StorageException If operation fails
     */
    public function xRevRange(string $key, string $end = '+', string $start = '-', ?int $count = null): array
    {
        try {
            if ($count !== null) {
                $result = $this->redis->xRevRange($key, $end, $start, $count);
            } else {
                $result = $this->redis->xRevRange($key, $end, $start);
            }
            return $result ?: [];
        } catch (\Exception $e) {
            throw new StorageException("Redis xRevRange error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Trim a stream to a certain length
     *
     * @param string $key Stream key
     * @param int $maxlen Maximum length to maintain
     * @param bool $approximate Use approximate trimming (~)
     * @return int Number of messages deleted
     * @throws StorageException If operation fails
     */
    public function xTrim(string $key, int $maxlen, bool $approximate = false): int
    {
        try {
            if ($approximate) {
                return $this->redis->xTrim($key, $maxlen, true);  // Use approximate trimming
            } else {
                return $this->redis->xTrim($key, $maxlen);  // Exact trimming
            }
        } catch (\Exception $e) {
            throw new StorageException("Redis xTrim error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get information about a stream
     *
     * @param string $subcommand Subcommand (STREAM, GROUPS, CONSUMERS)
     * @param string $key Stream key
     * @param string|null $group Group name (for CONSUMERS subcommand)
     * @return array Stream information
     * @throws StorageException If operation fails
     */
    public function xInfo(string $subcommand, string $key, ?string $group = null): array
    {
        try {
            $args = [$subcommand, $key];
            if ($subcommand === 'CONSUMERS' && $group !== null) {
                $args[] = $group;
            }

            $result = $this->redis->xInfo(...$args);
            return $result ?: [];
        } catch (\Exception $e) {
            throw new StorageException("Redis xInfo error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Destroy a consumer group
     *
     * @param string $key Stream key
     * @param string $group Group name
     * @return bool True if successful
     * @throws StorageException If operation fails
     */
    public function xGroupDestroy(string $key, string $group): bool
    {
        try {
            $result = $this->redis->xGroup('DESTROY', $key, $group);
            return (bool)$result;
        } catch (\Exception $e) {
            throw new StorageException("Redis xGroupDestroy error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Publish message to a ValKey pub/sub channel
     *
     * @param string $channel Channel name
     * @param string $message Message to publish
     * @return int Number of subscribers that received the message
     * @throws StorageException If operation fails
     */
    public function publish(string $channel, string $message): int
    {
        try {
            return (int) $this->redis->publish($channel, $message);
        } catch (\Exception $e) {
            throw new StorageException("Redis publish error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Set a key with expiration time (SETEX)
     *
     * @param string $key Key name
     * @param int $ttl Time to live in seconds
     * @param mixed $value Value to store
     * @return bool True if successful
     * @throws StorageException If operation fails
     */
    public function setex(string $key, int $ttl, $value): bool
    {
        try {
            return $this->redis->setex($key, $ttl, $value);
        } catch (\Exception $e) {
            throw new StorageException("Redis setex error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get multiple keys at once (MGET)
     *
     * @param array $keys Array of keys
     * @return array Array of values (false for missing keys)
     * @throws StorageException If operation fails
     */
    public function mget(array $keys): array
    {
        try {
            $result = $this->redis->mget($keys);
            return $result ?: [];
        } catch (\Exception $e) {
            throw new StorageException("Redis mget error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Set multiple keys at once (MSET)
     *
     * @param array $keyValues Associative array of key => value pairs
     * @return bool True if successful
     * @throws StorageException If operation fails
     */
    public function mset(array $keyValues): bool
    {
        try {
            return $this->redis->mset($keyValues);
        } catch (\Exception $e) {
            throw new StorageException("Redis mset error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Set expiration on an existing key
     *
     * @param string $key Key name
     * @param int $ttl Time to live in seconds
     * @return bool True if successful
     * @throws StorageException If operation fails
     */
    public function expire(string $key, int $ttl): bool
    {
        try {
            return $this->redis->expire($key, $ttl);
        } catch (\Exception $e) {
            throw new StorageException("Redis expire error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get the time to live for a key in seconds
     *
     * @param string $key Key name
     * @return int TTL in seconds, -1 if no TTL, -2 if key doesn't exist
     * @throws StorageException If operation fails
     */
    public function ttl(string $key): int
    {
        try {
            return $this->redis->ttl($key);
        } catch (\Exception $e) {
            throw new StorageException("Redis ttl error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Find all keys matching a pattern
     *
     * @param string $pattern Key pattern (e.g., "user:*")
     * @return array Array of matching keys
     * @throws StorageException If operation fails
     */
    public function keys(string $pattern): array
    {
        // Ch.1.1 §C.6: cluster-safe pattern enumeration via SCAN cursor.
        // Caller-supplied $pattern MUST be hash-tagged for multi-key sweeps
        // in cluster mode (e.g., "{site_id}:cache:*"). Bare patterns will
        // only sweep the connected shard — caller's responsibility to
        // hash-tag. SCAN is non-blocking + cluster-aware; replaces
        // blocking KEYS.
        try {
            $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            $iter = null;
            $keys = [];
            while ($batch = $this->redis->scan($iter, $pattern, 500)) {
                $keys = array_merge($keys, $batch);
            }
            return $keys;
        } catch (\Exception $e) {
            throw new StorageException("Redis scan error: {$e->getMessage()}", 0, $e);
        }
    }
}
