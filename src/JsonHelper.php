<?php
declare(strict_types=1);

namespace gCore\gNode;

/**
 * JsonHelper - Optimized JSON parsing with multiple strategies
 *
 * Provides JSON decoding with:
 * - simdjson support (when extension available)
 * - PHP 8.3+ json_validate() for fast validation without parsing
 * - Parse result caching to avoid repeated decoding
 * - Pre-allocation strategies
 * - Graceful fallback to standard json_decode
 *
 * @package gCore\gNode
 */
class JsonHelper
{
    /** @var bool Whether simdjson extension is available */
    private static $hasSimdJson = null;

    /** @var bool Whether json_validate is available (PHP 8.3+) */
    private static $hasJsonValidate = null;

    /** @var array<string, mixed> LRU cache for parsed JSON */
    private static $parseCache = [];

    /** @var int Maximum cache size */
    private static $maxCacheSize = 100;

    /** @var array Statistics for monitoring */
    private static $stats = [
        'decode_calls' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'simdjson_used' => 0,
        'standard_used' => 0,
        'validation_calls' => 0,
    ];

    /**
     * Initialize and detect available JSON functions
     */
    private static function init(): void
    {
        if (self::$hasSimdJson === null) {
            self::$hasSimdJson = extension_loaded('simdjson') && function_exists('simdjson_decode');
        }

        if (self::$hasJsonValidate === null) {
            self::$hasJsonValidate = function_exists('json_validate');
        }
    }

    /**
     * Decode JSON with optimizations
     *
     * Uses the fastest available method:
     * 1. Check cache first
     * 2. Try simdjson if available
     * 3. Fall back to standard json_decode
     *
     * @param string $json JSON string to decode
     * @param bool $assoc Return associative array (default: true)
     * @param int $depth Maximum nesting depth
     * @param int $flags JSON decode flags
     * @return mixed Decoded data
     * @throws \JsonException If JSON is invalid and exceptions enabled
     */
    public static function decode(
        string $json,
        bool $assoc = true,
        int $depth = 512,
        int $flags = 0
    ) {
        self::init();
        self::$stats['decode_calls']++;

        // Check cache first (for repeated parses in batch responses)
        $cacheKey = self::getCacheKey($json, $assoc, $depth, $flags);
        if (isset(self::$parseCache[$cacheKey])) {
            self::$stats['cache_hits']++;
            return self::$parseCache[$cacheKey];
        }
        self::$stats['cache_misses']++;

        // Try simdjson if available
        if (self::$hasSimdJson) {
            self::$stats['simdjson_used']++;
            try {
                $result = simdjson_decode($json, $assoc, $depth);
                self::cacheResult($cacheKey, $result);
                return $result;
            } catch (\Exception $e) {
                // Fall back to standard if simdjson fails
            }
        }

        // Standard json_decode with optimizations
        self::$stats['standard_used']++;

        // Use JSON_THROW_ON_ERROR in PHP 7.3+ for better error handling
        $decodeFlags = $flags;
        if (defined('JSON_THROW_ON_ERROR')) {
            $decodeFlags |= JSON_THROW_ON_ERROR;
        }

        try {
            $result = json_decode($json, $assoc, $depth, $decodeFlags);

            // Check for errors if not using JSON_THROW_ON_ERROR
            if (!($decodeFlags & JSON_THROW_ON_ERROR) && json_last_error() !== JSON_ERROR_NONE) {
                throw new \JsonException(json_last_error_msg(), json_last_error());
            }

            self::cacheResult($cacheKey, $result);
            return $result;
        } catch (\JsonException $e) {
            // Clear cache on error
            unset(self::$parseCache[$cacheKey]);
            throw $e;
        }
    }

    /**
     * Validate JSON efficiently without parsing
     *
     * Uses json_validate() in PHP 8.3+ for fast validation,
     * falls back to json_decode() on older versions.
     *
     * @param string $json JSON string to validate
     * @return bool True if valid JSON
     */
    public static function isValid(string $json): bool
    {
        self::init();
        self::$stats['validation_calls']++;

        // Try simdjson_is_valid if available (fastest)
        if (self::$hasSimdJson && function_exists('simdjson_is_valid')) {
            return simdjson_is_valid($json);
        }

        // Use json_validate() in PHP 8.3+ (fast, no parsing)
        if (self::$hasJsonValidate) {
            return json_validate($json);
        }

        // Fallback: parse and check for errors
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Decode batch messages with optimized parsing
     *
     * Specialized for gNode batch response format where the same
     * structure is repeated many times.
     *
     * @param string $json JSON string containing array of messages
     * @return array|null Decoded array or null on error
     */
    public static function decodeBatchMessages(string $json): ?array
    {
        // Validate first to avoid wasted parse attempts
        if (!self::isValid($json)) {
            return null;
        }

        // Decode the outer array
        try {
            $messages = self::decode($json, true, 512, 0);

            if (!is_array($messages)) {
                return null;
            }

            return $messages;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Decode response data with type inference
     *
     * Handles the case where response data might be:
     * - JSON string -> decode it
     * - Already decoded -> return as-is
     * - Scalar -> wrap in result field
     *
     * @param mixed $data Response data to decode
     * @return array Decoded response
     */
    public static function decodeResponseData($data): array
    {
        // Already an array
        if (is_array($data)) {
            return $data;
        }

        // Try to decode as JSON string
        if (is_string($data)) {
            // Quick check: does it look like JSON?
            $firstChar = $data[0] ?? '';
            if ($firstChar === '{' || $firstChar === '[') {
                try {
                    $decoded = self::decode($data, true);

                    // Successfully decoded to array
                    if (is_array($decoded)) {
                        return $decoded;
                    }

                    // Decoded to scalar, wrap it
                    return ['result' => $decoded];
                } catch (\Exception $e) {
                    // Not valid JSON, treat as string result
                    return ['result' => $data];
                }
            }

            // Doesn't look like JSON, wrap as result
            return ['result' => $data];
        }

        // Scalar value (bool, int, float, null)
        return ['result' => $data];
    }

    /**
     * Generate cache key for parsed result
     */
    private static function getCacheKey(string $json, bool $assoc, int $depth, int $flags): string
    {
        // Use first 64 chars + length + params as key (faster than full hash)
        $prefix = substr($json, 0, 64);
        return sprintf('%s:%d:%d:%d:%d', $prefix, strlen($json), (int)$assoc, $depth, $flags);
    }

    /**
     * Cache parsed result with LRU eviction
     */
    private static function cacheResult(string $key, $value): void
    {
        // Add to cache
        self::$parseCache[$key] = $value;

        // Evict oldest if cache too large (simple FIFO)
        if (count(self::$parseCache) > self::$maxCacheSize) {
            array_shift(self::$parseCache);
        }
    }

    /**
     * Clear the parse cache
     */
    public static function clearCache(): void
    {
        self::$parseCache = [];
    }

    /**
     * Get statistics for monitoring
     *
     * @return array<string, int>
     */
    public static function getStats(): array
    {
        return self::$stats;
    }

    /**
     * Reset statistics
     */
    public static function resetStats(): void
    {
        self::$stats = [
            'decode_calls' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'simdjson_used' => 0,
            'standard_used' => 0,
            'validation_calls' => 0,
        ];
    }

    /**
     * Check if simdjson extension is available
     */
    public static function hasSimdJson(): bool
    {
        self::init();
        return self::$hasSimdJson;
    }

    /**
     * Check if json_validate is available
     */
    public static function hasJsonValidate(): bool
    {
        self::init();
        return self::$hasJsonValidate;
    }

    /**
     * Get cache hit rate
     */
    public static function getCacheHitRate(): float
    {
        $total = self::$stats['cache_hits'] + self::$stats['cache_misses'];
        return $total > 0 ? (self::$stats['cache_hits'] / $total) : 0.0;
    }
}
