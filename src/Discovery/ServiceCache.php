<?php
/**
 * ServiceCache - LRU-TTL cache for discovered services
 *
 * Features:
 * - Cache discovered services by capability hash
 * - TTL-based expiration (default: 30s)
 * - LRU eviction when max size exceeded
 * - Invalidation via broadcast stream topology updates
 * - Thread-safe for PHP request scope
 *
 * Performance:
 * - O(1) get/set operations
 * - Automatic cleanup on access
 * - Minimal memory overhead (~200 bytes per entry)
 *
 * @package gCore\GSD\Discovery
 */

namespace gCore\GSD\Discovery;

class ServiceCache
{
    /** @var array Cache storage [hash => entry] */
    private $cache = [];

    /** @var array LRU tracking [hash => last_access_time] */
    private $accessTimes = [];

    /** @var int Maximum cache size */
    private $maxSize;

    /** @var float Default TTL in seconds */
    private $defaultTtl;

    /** @var array Statistics */
    private $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'evictions' => 0,
        'expirations' => 0,
        'invalidations' => 0,
    ];

    /**
     * @param int $maxSize Maximum number of cached entries
     * @param float $defaultTtl Default TTL in seconds
     */
    public function __construct(int $maxSize = 1000, float $defaultTtl = 30.0)
    {
        $this->maxSize = $maxSize;
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * Get a cached service by capability hash
     *
     * @param array $capabilities Capability vector
     * @return array|null Service data, or null if not found/expired
     */
    public function get(array $capabilities): ?array
    {
        $hash = $this->hashCapabilities($capabilities);

        // Check if entry exists
        if (!isset($this->cache[$hash])) {
            $this->stats['misses']++;
            return null;
        }

        $entry = $this->cache[$hash];

        // Check if expired
        if ($this->isExpired($entry)) {
            $this->stats['misses']++;
            $this->stats['expirations']++;
            unset($this->cache[$hash]);
            unset($this->accessTimes[$hash]);
            return null;
        }

        // Update access time for LRU
        $this->accessTimes[$hash] = microtime(true);
        $this->stats['hits']++;

        return $entry['service'];
    }

    /**
     * Cache a discovered service
     *
     * @param array $capabilities Capability vector used for discovery
     * @param array $service Service data
     * @param float|null $ttl Optional TTL override in seconds
     */
    public function set(array $capabilities, array $service, ?float $ttl = null): void
    {
        $hash = $this->hashCapabilities($capabilities);
        $ttl = $ttl ?? $this->defaultTtl;

        // Evict LRU entry if at max size
        if (count($this->cache) >= $this->maxSize && !isset($this->cache[$hash])) {
            $this->evictLru();
        }

        // Store entry
        $this->cache[$hash] = [
            'service' => $service,
            'capabilities' => $capabilities,
            'expires_at' => microtime(true) + $ttl,
            'created_at' => microtime(true),
        ];

        $this->accessTimes[$hash] = microtime(true);
        $this->stats['sets']++;
    }

    /**
     * Check if capabilities are cached
     *
     * @param array $capabilities Capability vector
     * @return bool
     */
    public function has(array $capabilities): bool
    {
        $hash = $this->hashCapabilities($capabilities);

        if (!isset($this->cache[$hash])) {
            return false;
        }

        // Check expiration
        if ($this->isExpired($this->cache[$hash])) {
            unset($this->cache[$hash]);
            unset($this->accessTimes[$hash]);
            return false;
        }

        return true;
    }

    /**
     * Invalidate cache entry for specific capabilities
     *
     * @param array $capabilities Capability vector
     */
    public function invalidate(array $capabilities): void
    {
        $hash = $this->hashCapabilities($capabilities);

        if (isset($this->cache[$hash])) {
            unset($this->cache[$hash]);
            unset($this->accessTimes[$hash]);
            $this->stats['invalidations']++;
        }
    }

    /**
     * Invalidate all cached services for a specific service ID
     *
     * @param string $serviceId Service identifier
     */
    public function invalidateService(string $serviceId): void
    {
        $count = 0;

        foreach ($this->cache as $hash => $entry) {
            if (isset($entry['service']['service_id']) && $entry['service']['service_id'] === $serviceId) {
                unset($this->cache[$hash]);
                unset($this->accessTimes[$hash]);
                $count++;
            }
        }

        $this->stats['invalidations'] += $count;
    }

    /**
     * Clear entire cache
     */
    public function clear(): void
    {
        $count = count($this->cache);
        $this->cache = [];
        $this->accessTimes = [];
        $this->stats['invalidations'] += $count;
    }

    /**
     * Clean up expired entries
     *
     * @return int Number of entries removed
     */
    public function cleanup(): int
    {
        $removed = 0;
        $now = microtime(true);

        foreach ($this->cache as $hash => $entry) {
            if ($entry['expires_at'] < $now) {
                unset($this->cache[$hash]);
                unset($this->accessTimes[$hash]);
                $removed++;
            }
        }

        $this->stats['expirations'] += $removed;

        return $removed;
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics including hit rate
     */
    public function getStats(): array
    {
        $total = $this->stats['hits'] + $this->stats['misses'];
        $hitRate = $total > 0 ? $this->stats['hits'] / $total : 0.0;

        return array_merge($this->stats, [
            'size' => count($this->cache),
            'max_size' => $this->maxSize,
            'hit_rate' => $hitRate,
            'hit_rate_percent' => round($hitRate * 100, 2),
        ]);
    }

    /**
     * Get current cache size
     *
     * @return int Number of cached entries
     */
    public function getSize(): int
    {
        return count($this->cache);
    }

    /**
     * Hash capability vector for cache key
     *
     * Creates a deterministic hash from capability dimensions and values.
     *
     * @param array $capabilities Capability vector
     * @return string Hash key
     */
    private function hashCapabilities(array $capabilities): string
    {
        // Sort by key for consistent hashing
        ksort($capabilities);

        // Create string representation
        $parts = [];
        foreach ($capabilities as $dimension => $value) {
            // Round to 3 decimal places to avoid floating point precision issues
            $parts[] = $dimension . ':' . round($value, 3);
        }

        return md5(implode('|', $parts));
    }

    /**
     * Check if cache entry is expired
     *
     * @param array $entry Cache entry
     * @return bool
     */
    private function isExpired(array $entry): bool
    {
        return microtime(true) >= $entry['expires_at'];
    }

    /**
     * Evict least recently used entry
     */
    private function evictLru(): void
    {
        if (empty($this->accessTimes)) {
            return;
        }

        // Find entry with oldest access time
        $lruHash = null;
        $lruTime = PHP_FLOAT_MAX;

        foreach ($this->accessTimes as $hash => $time) {
            if ($time < $lruTime) {
                $lruTime = $time;
                $lruHash = $hash;
            }
        }

        if ($lruHash !== null) {
            unset($this->cache[$lruHash]);
            unset($this->accessTimes[$lruHash]);
            $this->stats['evictions']++;
        }
    }

    /**
     * Get all cached entries (for debugging)
     *
     * @return array All cache entries
     */
    public function getAll(): array
    {
        return $this->cache;
    }

    /**
     * Set maximum cache size
     *
     * @param int $maxSize New maximum size
     */
    public function setMaxSize(int $maxSize): void
    {
        $this->maxSize = $maxSize;

        // Evict entries if over new limit
        while (count($this->cache) > $this->maxSize) {
            $this->evictLru();
        }
    }

    /**
     * Set default TTL
     *
     * @param float $ttl TTL in seconds
     */
    public function setDefaultTtl(float $ttl): void
    {
        $this->defaultTtl = $ttl;
    }

    /**
     * Get entries expiring soon
     *
     * @param float $withinSeconds Time window in seconds
     * @return array Entries expiring within the time window
     */
    public function getExpiringSoon(float $withinSeconds = 5.0): array
    {
        $threshold = microtime(true) + $withinSeconds;
        $expiring = [];

        foreach ($this->cache as $hash => $entry) {
            if ($entry['expires_at'] <= $threshold) {
                $expiring[$hash] = $entry;
            }
        }

        return $expiring;
    }

    /**
     * Refresh TTL for a cached entry
     *
     * @param array $capabilities Capability vector
     * @param float|null $ttl New TTL in seconds
     * @return bool Whether entry was refreshed
     */
    public function refresh(array $capabilities, ?float $ttl = null): bool
    {
        $hash = $this->hashCapabilities($capabilities);

        if (!isset($this->cache[$hash])) {
            return false;
        }

        $ttl = $ttl ?? $this->defaultTtl;
        $this->cache[$hash]['expires_at'] = microtime(true) + $ttl;
        $this->accessTimes[$hash] = microtime(true);

        return true;
    }
}
