<?php
declare(strict_types=1);

namespace gCore\gNode;

use gCore\gNode\Storage\StorageInterface;
use gCore\gNode\Exception\KeyBasedException;

/**
 * KeyBasedHandler - Handler for key-based ValKey operations
 *
 * Provides helper methods for key-based gNode operations:
 * - Key generation with consistent patterns
 * - Atomic operations (get/set/publish)
 * - TTL management
 * - Pattern scanning
 * - Bundle operations
 *
 * @package gCore\gNode
 * @version 2.0.0
 */
class KeyBasedHandler
{
    /** @var StorageInterface Storage interface */
    protected $storage;

    /** @var string Site identifier */
    protected $siteId;

    /** @var array Configuration */
    protected $config;

    /** @var bool Debug mode */
    protected $debug = false;

    /**
     * Constructor
     *
     * @param StorageInterface $storage Storage interface
     * @param string $siteId Site identifier
     * @param array $config Configuration options
     */
    public function __construct(StorageInterface $storage, string $siteId, array $config = [])
    {
        $this->storage = $storage;
        $this->siteId = $siteId;
        $this->config = $config;
        $this->debug = $config['debug'] ?? false;
    }

    /**
     * Get value from cache with automatic JSON decode
     *
     * @param string $key Cache key
     * @param bool $decode Whether to JSON decode (default: true)
     * @return mixed Value or false on miss
     */
    public function get(string $key, bool $decode = true)
    {
        $value = $this->storage->get($key);

        if ($value === false) {
            return false;
        }

        if (!$decode) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->debug("JSON decode error for key {$key}: " . json_last_error_msg());
            return false;
        }

        return $decoded;
    }

    /**
     * Set value in cache with automatic JSON encode
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds (0 = no expiration)
     * @return bool Success
     */
    public function set(string $key, $value, int $ttl = 0): bool
    {
        $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);

        if ($ttl > 0) {
            return $this->storage->setex($key, $ttl, $encoded);
        }

        return $this->storage->set($key, $encoded);
    }

    /**
     * Get multiple values in one operation (MGET)
     *
     * @param array $keys Array of keys
     * @param bool $decode Whether to JSON decode (default: true)
     * @return array Map of key => value (missing keys have false value)
     */
    public function mget(array $keys, bool $decode = true): array
    {
        $values = $this->storage->mget($keys);
        $result = [];

        foreach ($keys as $index => $key) {
            $value = $values[$index] ?? false;

            if ($value === false) {
                $result[$key] = false;
                continue;
            }

            if (!$decode) {
                $result[$key] = $value;
                continue;
            }

            $decoded = json_decode($value, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                $result[$key] = false;
            } else {
                $result[$key] = $decoded;
            }
        }

        return $result;
    }

    /**
     * Delete one or more keys
     *
     * @param string ...$keys Keys to delete
     * @return int Number of keys deleted
     */
    public function del(string ...$keys): int
    {
        if (empty($keys)) {
            return 0;
        }

        return call_user_func_array([$this->storage, 'del'], $keys);
    }

    /**
     * Publish message to channel
     *
     * @param string $channel Channel name
     * @param mixed $message Message (will be JSON encoded if not string)
     * @return int Number of subscribers that received the message
     */
    public function publish(string $channel, $message): int
    {
        $payload = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_SLASHES);
        return $this->storage->publish($channel, $payload);
    }

    /**
     * Scan for keys matching pattern
     *
     * @param string $pattern Key pattern (e.g., "{site}:cache:*")
     * @param int $limit Maximum number of keys to return (0 = unlimited)
     * @return array Array of matching keys
     */
    public function scan(string $pattern, int $limit = 0): array
    {
        $keys = [];
        $cursor = 0;
        $count = 0;

        do {
            $result = $this->storage->scan($cursor, $pattern, 100);
            if ($result === false) {
                break;
            }

            list($cursor, $scannedKeys) = $result;
            $keys = array_merge($keys, $scannedKeys);
            $count += count($scannedKeys);

            if ($limit > 0 && $count >= $limit) {
                $keys = array_slice($keys, 0, $limit);
                break;
            }
        } while ($cursor != 0);

        return $keys;
    }

    /**
     * Check if key exists
     *
     * @param string $key Key to check
     * @return bool True if exists
     */
    public function exists(string $key): bool
    {
        return $this->storage->exists($key) > 0;
    }

    /**
     * Get TTL of key
     *
     * @param string $key Key to check
     * @return int TTL in seconds, -1 if no expiration, -2 if key doesn't exist
     */
    public function ttl(string $key): int
    {
        return $this->storage->ttl($key);
    }

    /**
     * Set expiration on existing key
     *
     * @param string $key Key to expire
     * @param int $seconds Seconds until expiration
     * @return bool Success
     */
    public function expire(string $key, int $seconds): bool
    {
        return $this->storage->expire($key, $seconds);
    }

    /**
     * Get key size in bytes
     *
     * @param string $key Key to measure
     * @return int Size in bytes, 0 if key doesn't exist
     */
    public function strlen(string $key): int
    {
        $size = $this->storage->strlen($key);
        return $size !== false ? $size : 0;
    }

    /**
     * Generate cache key for command
     *
     * @param string $command Command name
     * @param array $parameters Parameters
     * @return string Cache key
     */
    public function generateCacheKey(string $command, array $parameters): string
    {
        ksort($parameters);
        $paramHash = md5(json_encode($parameters, JSON_UNESCAPED_SLASHES));
        // Hash-tag braces keep a site's keys in one cluster slot (matches the
        // daemon's build_request_key/build_response_key and getUnifiedStreamKey).
        return "{{$this->siteId}}:cache:{$command}:{$paramHash}";
    }

    /**
     * Generate request key
     *
     * @param string $requestId Request ID
     * @return string Request key
     */
    public function generateRequestKey(string $requestId): string
    {
        // Braced to match the daemon's build_request_key ({site}:req:{id}).
        return "{{$this->siteId}}:req:{$requestId}";
    }

    /**
     * Generate response key
     *
     * @param string $requestId Request ID
     * @return string Response key
     */
    public function generateResponseKey(string $requestId): string
    {
        // Braced to match the daemon's build_response_key ({site}:res:{id}).
        return "{{$this->siteId}}:res:{$requestId}";
    }

    /**
     * Get bundle key
     *
     * @param string|null $variant Bundle variant (null = "full")
     * @return string Bundle key
     */
    public function getBundleKey(?string $variant = null): string
    {
        $variant = $variant ?? 'full';
        // Braced for cluster-slot consistency. This is a SELF-CONTAINED cache:
        // storeBundle()/retrieveBundle() write and read THIS same key with gzip
        // (gzencode/gzdecode) — the ecosystem-standard framing (daemon
        // asset_builder GzEncoder, gNodeClient gzdecode). It is a separate
        // namespace from the daemon's manifest bundle
        // (`{site}:gnode:bundle:{manifest_id}`); consuming those would need only
        // the `:gnode:` key segment (the compression already matches).
        return "{{$this->siteId}}:bundle:{$variant}";
    }

    /**
     * Get invalidation channel name
     *
     * @return string Channel name
     */
    public function getInvalidationChannel(): string
    {
        // Pub/sub channel — intentionally UNbraced: the daemon subscribes to
        // the bare `{site}:events:invalidate` (asset_builder.rs), and regular
        // pub/sub is not slot-bound. Do NOT add hash-tag braces here.
        return "{$this->siteId}:events:invalidate";
    }

    /**
     * Get compute request channel name
     *
     * @return string Channel name
     */
    public function getComputeRequestChannel(): string
    {
        // Pub/sub channel — intentionally UNbraced, same rationale as
        // getInvalidationChannel(). Do NOT add hash-tag braces here.
        return "{$this->siteId}:events:compute_request";
    }

    /**
     * Store compressed bundle
     *
     * @param array $bundle Bundle data
     * @param int $ttl TTL in seconds (default: 300 = 5 minutes)
     * @param int $compressionLevel Compression level 1-9 (default: 9)
     * @return bool Success
     * @throws KeyBasedException On compression failure
     */
    public function storeBundle(array $bundle, int $ttl = 300, int $compressionLevel = 9): bool
    {
        $json = json_encode($bundle, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw KeyBasedException::jsonParseError(json_last_error_msg());
        }

        // gzip framing — the ecosystem standard (daemon asset_builder GzEncoder,
        // gNodeClient gzdecode). Keep it gzip so this cache never diverges from
        // the rest of the stack.
        $compressed = gzencode($json, $compressionLevel);
        if ($compressed === false) {
            throw new KeyBasedException("Bundle compression failed");
        }

        $bundleKey = $this->getBundleKey();
        $result = $this->storage->setex($bundleKey, $ttl, $compressed);

        if ($result) {
            $originalSize = strlen($json);
            $compressedSize = strlen($compressed);
            $ratio = round(($compressedSize / $originalSize) * 100, 1);
            $this->debug("Bundle stored: {$originalSize} → {$compressedSize} bytes ({$ratio}%)");
        }

        return $result;
    }

    /**
     * Retrieve and decompress bundle
     *
     * @return array|null Bundle data or null if not available
     * @throws KeyBasedException On decompression or parse failure
     */
    public function retrieveBundle(): ?array
    {
        $bundleKey = $this->getBundleKey();
        $compressed = $this->storage->get($bundleKey);

        if ($compressed === false) {
            return null;
        }

        $json = @gzdecode($compressed);
        if ($json === false) {
            throw KeyBasedException::decompressionFailed($this->siteId);
        }

        $bundle = json_decode($json, true);
        if ($bundle === null && json_last_error() !== JSON_ERROR_NONE) {
            throw KeyBasedException::jsonParseError(json_last_error_msg());
        }

        return $bundle;
    }

    /**
     * Get statistics for site keys
     *
     * @return array Statistics
     */
    public function getStats(): array
    {
        // Braced to match the braced data keys above (cache/req/res/bundle);
        // the unbraced `:events:` pub/sub channels are not keyspace entries.
        $pattern = "{{$this->siteId}}:*";
        $keys = $this->scan($pattern);

        $cacheKeys = 0;
        $bundleKeys = 0;
        $requestKeys = 0;
        $responseKeys = 0;
        $otherKeys = 0;
        $totalSize = 0;

        foreach ($keys as $key) {
            $size = $this->strlen($key);
            $totalSize += $size;

            if (strpos($key, ':cache:') !== false) {
                $cacheKeys++;
            } elseif (strpos($key, ':bundle:') !== false) {
                $bundleKeys++;
            } elseif (strpos($key, ':req:') !== false) {
                $requestKeys++;
            } elseif (strpos($key, ':res:') !== false) {
                $responseKeys++;
            } else {
                $otherKeys++;
            }
        }

        return [
            'site_id' => $this->siteId,
            'total_keys' => count($keys),
            'cache_keys' => $cacheKeys,
            'bundle_keys' => $bundleKeys,
            'request_keys' => $requestKeys,
            'response_keys' => $responseKeys,
            'other_keys' => $otherKeys,
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 3),
        ];
    }

    /**
     * Clean up stale request/response keys
     *
     * @param int $olderThanSeconds Remove keys older than this (default: 60)
     * @return int Number of keys cleaned up
     */
    public function cleanupStaleKeys(int $olderThanSeconds = 60): int
    {
        $patterns = [
            "{{$this->siteId}}:req:*",
            "{{$this->siteId}}:res:*"
        ];

        $deleted = 0;
        foreach ($patterns as $pattern) {
            $keys = $this->scan($pattern);

            foreach ($keys as $key) {
                $ttl = $this->ttl($key);
                // If no TTL set (-1) or expired (-2), or TTL is very short, delete it
                if ($ttl === -1 || $ttl === -2 || $ttl < 10) {
                    $deleted += $this->del($key);
                }
            }
        }

        if ($deleted > 0) {
            $this->debug("Cleaned up {$deleted} stale request/response keys");
        }

        return $deleted;
    }

    /**
     * Debug logging
     *
     * @param string $message Debug message
     */
    protected function debug(string $message): void
    {
        if ($this->debug) {
            error_log("[KeyBasedHandler] {$message}");
        }
    }
}
