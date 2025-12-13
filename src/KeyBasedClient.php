<?php

namespace gCore\GSD;

use gCore\GSD\Storage\StorageInterface;
use gCore\GSD\Exception\GSDException;
use gCore\GSD\Exception\ConnectionException;

/**
 * KeyBasedClient - High-performance key/value client for GSD
 *
 * NOTE: For production use, prefer KeyBasedClientLuaEnabled which adds
 * automatic metrics tracking and Lua function optimization.
 * @see KeyBasedClientLuaEnabled CANONICAL client with Lua metrics
 *
 * Architecture: Key-based SET/GET + XADD to per-environment streams
 * - Request: SET {site}:req:{id} → XADD gsd:compute:{env}
 * - Response: Daemon processes → SET {site}:res:{id} → Client GET
 * - Performance: 114ms → 10ms per request (11× faster than legacy streams)
 *
 * Key schema:
 * - Cache: {site_id}:gsd:cache:{command}:{param_hash}
 * - Request: {{site_id}}:req:{request_id}
 * - Response: {{site_id}}:res:{request_id}
 * - Bundle: {site_id}:gsd:bundle:full
 * - Compute stream: gsd:compute:{environment}
 *
 * @package gCore\GSD
 * @version 2.1.0
 */
class KeyBasedClient extends Client
{
    /**
     * Send command using key-based protocol
     *
     * Flow:
     * 1. Check cache (GET cache key)
     * 2. If HIT: return immediately (<2ms)
     * 3. If MISS: Request compute (SET request → PUBLISH notify → poll GET response)
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return array|null Response or null on timeout
     * @throws GSDException On communication errors
     */
    protected function sendCommand(string $command, array $parameters = []): ?array
    {
        // 1. Check if command result is cacheable
        $cacheKey = $this->getCacheKey($command, $parameters);
        if ($cacheKey && $this->isCommandCacheable($command)) {
            $cached = $this->storage->get($cacheKey);
            if ($cached !== false) {
                $this->debug("Cache HIT for {$command}: {$cacheKey}");
                $decoded = json_decode($cached, true);
                if ($decoded !== null) {
                    return $decoded;
                }
                // Cache corruption, continue to compute
                $this->debug("Cache data corrupted, recomputing");
            }
            $this->debug("Cache MISS for {$command}: {$cacheKey}");
        }

        // 2. Generate unique request ID
        $requestId = uniqid($this->siteId . ':', true);

        // 3. Build request payload
        $request = [
            'command' => $command,
            'parameters' => $parameters,
            'requested_at' => microtime(true),
            'timeout_ms' => $this->timeout,
            'site_id' => $this->siteId
        ];

        // 4. Store request in ValKey (transient, 10s TTL)
        $requestKey = $this->getRequestKey($requestId);
        $stored = $this->storage->set($requestKey, json_encode($request), 10);

        if (!$stored) {
            $this->debug("Failed to store request key: {$requestKey}");
            return null;
        }

        // 5. Add request to per-environment compute stream (XADD)
        // Stream: gsd:compute:{environment} (e.g., gsd:compute:production)
        $computeStream = $this->getComputeStream();
        $streamFields = [
            'site_id' => $this->siteId,
            'request_id' => $requestId,
            'command' => $command,
            'priority' => $this->getCommandPriority($command),
            'ts' => (string)(microtime(true) * 1000)  // Milliseconds timestamp
        ];

        $this->storage->xAdd($computeStream, '*', $streamFields);
        $this->debug("Sent compute request to {$computeStream}: {$requestId} for command: {$command}");

        // 6. Poll for response (non-blocking, short intervals)
        $response = $this->pollForResponse($requestId, $this->timeout);

        // 7. Store result in cache if successful
        if ($response && $cacheKey && isset($response['status']) && $response['status'] === 'ok') {
            $ttl = $this->getCacheTTL($command);
            $this->storage->setex($cacheKey, $ttl, json_encode($response));
            $this->debug("Cached result for {$command}, TTL={$ttl}s");
        }

        // 8. Clean up request key
        $this->storage->del($requestKey);

        return $response;
    }

    /**
     * Poll for response using GET with exponential backoff
     *
     * @param string $requestId Request identifier
     * @param int $timeoutMs Maximum time to wait in milliseconds
     * @return array|null Response or null on timeout
     */
    protected function pollForResponse(string $requestId, int $timeoutMs): ?array
    {
        $responseKey = $this->getResponseKey($requestId);
        $startTime = microtime(true);
        $endTime = $startTime + ($timeoutMs / 1000);

        $pollInterval = 0.001; // Start with 1ms
        $maxPollInterval = 0.01; // Max 10ms
        $attempt = 0;

        while (microtime(true) < $endTime) {
            // Try to GET response
            $response = $this->storage->get($responseKey);

            if ($response !== false) {
                $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                $this->debug("Response received after " . (++$attempt) . " attempts, {$elapsed}ms");

                // Delete response key (consumed)
                $this->storage->del($responseKey);

                $decoded = json_decode($response, true);
                if ($decoded === null) {
                    $this->debug("Response JSON decode error: " . json_last_error_msg());
                    return null;
                }

                return $decoded;
            }

            // Exponential backoff: 1ms, 2ms, 4ms, 8ms, 10ms (max)
            $pollInterval = min($pollInterval * 2, $maxPollInterval);
            usleep((int)($pollInterval * 1000000));
            $attempt++;
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        $this->debug("Timeout after {$attempt} attempts, {$elapsed}ms");
        return null;
    }

    /**
     * Get entire site bundle (410KB → 120KB compressed)
     *
     * @param bool $decompress Whether to decompress (default: true)
     * @return array|string|null Bundle data or null if not available
     */
    public function getBundle(bool $decompress = true)
    {
        $bundleKey = "{{$this->siteId}}:gsd:bundle:full";
        $compressed = $this->storage->get($bundleKey);

        if ($compressed === false) {
            $this->debug("Bundle MISS, requesting rebuild");
            $this->requestBundleRebuild();
            return null;
        }

        if (!$decompress) {
            return $compressed;
        }

        // Decompress using gzip (gzdecode for gzip format, not gzuncompress which is zlib)
        $json = @gzdecode($compressed);
        if ($json === false) {
            $this->debug("Bundle decompression failed");
            return null;
        }

        // Parse JSON
        $bundle = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->debug("Bundle JSON parse error: " . json_last_error_msg());
            return null;
        }

        $compressedSize = strlen($compressed);
        $uncompressedSize = strlen($json);
        $this->debug("Bundle retrieved: {$compressedSize} bytes compressed, {$uncompressedSize} bytes uncompressed");

        return $bundle;
    }

    /**
     * Request GSD daemon to rebuild bundle immediately
     *
     * @param string $priority "high" or "normal"
     */
    protected function requestBundleRebuild(string $priority = 'high'): void
    {
        $this->storage->publish("{{$this->siteId}}:events:invalidate", json_encode([
            'event' => 'bundle_rebuild_requested',
            'reason' => 'cache_miss',
            'timestamp' => microtime(true),
            'rebuild_priority' => $priority
        ]));
    }

    /**
     * Get specific data from bundle without full parse
     *
     * @param string $path Dot-notation path (e.g., "faces.0.html")
     * @return mixed Data at path or null
     */
    public function getBundled(string $path)
    {
        $bundle = $this->getBundle();
        if (!$bundle) {
            return null;
        }

        // Navigate path using dot notation
        $parts = explode('.', $path);
        $current = $bundle;

        foreach ($parts as $part) {
            if (!isset($current[$part])) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * Convenience method: Get face HTML from bundle
     *
     * @param int $faceId Face ID (0-5)
     * @return string|null Face HTML or null
     */
    public function getFaceHtml(int $faceId): ?string
    {
        return $this->getBundled("faces.{$faceId}.html");
    }

    /**
     * Convenience method: Get posts list from bundle
     *
     * @return array|null Posts list or null
     */
    public function getPostsList(): ?array
    {
        return $this->getBundled("posts.list");
    }

    /**
     * Convenience method: Get site metadata from bundle
     *
     * @return array|null Site metadata or null
     */
    public function getSiteMetadata(): ?array
    {
        return $this->getBundled("metadata");
    }

    /**
     * Convenience method: Get navigation menu from bundle
     *
     * @return array|null Navigation menu or null
     */
    public function getNavigationMenu(): ?array
    {
        return $this->getBundled("navigation.menu");
    }

    /**
     * Execute batch of commands using key-based protocol
     *
     * Unlike stream batching (1× overhead for N commands),
     * key-based batching checks cache for each command individually.
     *
     * @param array $commands Array of [command, parameters] pairs
     * @return array Results indexed by command position
     */
    public function executeBatch(array $commands): array
    {
        $results = [];
        $pendingCommands = [];

        // 1. Check cache for each command
        foreach ($commands as $index => $cmdData) {
            list($command, $parameters) = $cmdData;
            $cacheKey = $this->getCacheKey($command, $parameters);

            if ($cacheKey && $this->isCommandCacheable($command)) {
                $cached = $this->storage->get($cacheKey);
                if ($cached !== false) {
                    $decoded = json_decode($cached, true);
                    if ($decoded !== null) {
                        $results[$index] = $decoded;
                        $this->debug("Batch cache HIT [{$index}]: {$command}");
                        continue;
                    }
                }
            }

            // Cache miss, add to pending
            $pendingCommands[$index] = [$command, $parameters];
        }

        // 2. If all cached, return immediately
        if (empty($pendingCommands)) {
            $this->debug("Batch fully cached: " . count($commands) . " commands");
            return $results;
        }

        $this->debug("Batch partial cache: " . count($results) . " hits, " .
                    count($pendingCommands) . " misses");

        // 3. Send pending commands (parallel request creation)
        $requestIds = [];
        foreach ($pendingCommands as $index => [$command, $parameters]) {
            $requestId = uniqid($this->siteId . ":{$index}:", true);
            $requestIds[$index] = $requestId;

            $request = [
                'command' => $command,
                'parameters' => $parameters,
                'requested_at' => microtime(true),
                'timeout_ms' => $this->timeout,
                'batch_index' => $index,
                'site_id' => $this->siteId
            ];

            $requestKey = $this->getRequestKey($requestId);
            $this->storage->setex($requestKey, 10, json_encode($request));
        }

        // 4. Add batch notification to per-environment compute stream (XADD)
        $computeStream = $this->getComputeStream();
        $this->storage->xAdd($computeStream, '*', [
            'site_id' => $this->siteId,
            'type' => 'batch',
            'request_ids' => json_encode(array_values($requestIds)),
            'count' => (string)count($requestIds),
            'priority' => 'normal',
            'ts' => (string)(microtime(true) * 1000)
        ]);

        // 5. Poll for all responses in parallel
        $responses = $this->pollForBatchResponses($requestIds, $this->timeout);

        // 6. Merge responses with cached results
        foreach ($responses as $index => $response) {
            $results[$index] = $response;

            // Cache successful responses
            if ($response && isset($response['status']) && $response['status'] === 'ok') {
                list($command, $parameters) = $pendingCommands[$index];
                $cacheKey = $this->getCacheKey($command, $parameters);
                if ($cacheKey) {
                    $ttl = $this->getCacheTTL($command);
                    $this->storage->setex($cacheKey, $ttl, json_encode($response));
                }
            }
        }

        // 7. Fill in errors for missing responses
        foreach ($pendingCommands as $index => $_) {
            if (!isset($results[$index])) {
                $results[$index] = [
                    'status' => 'error',
                    'error' => 'Timeout waiting for response',
                    'index' => $index
                ];
            }
        }

        return $results;
    }

    /**
     * Poll for multiple responses efficiently
     *
     * @param array $requestIds Map of index => requestId
     * @param int $timeoutMs Maximum time to wait
     * @return array Map of index => response
     */
    protected function pollForBatchResponses(array $requestIds, int $timeoutMs): array
    {
        $responses = [];
        $pending = $requestIds;  // Track which IDs we're still waiting for

        $startTime = microtime(true);
        $endTime = $startTime + ($timeoutMs / 1000);

        $pollInterval = 0.001; // 1ms
        $maxPollInterval = 0.01; // 10ms

        while (!empty($pending) && microtime(true) < $endTime) {
            // Build MGET command for all pending response keys
            $responseKeys = array_map(
                function($id) { return $this->getResponseKey($id); },
                $pending
            );

            // Batch GET using MGET
            $batchResults = $this->storage->mget($responseKeys);

            // Process results
            $pendingArray = array_values($pending);
            foreach ($pending as $index => $requestId) {
                $keyIndex = array_search($requestId, $pendingArray);
                $response = $batchResults[$keyIndex] ?? false;

                if ($response !== false) {
                    $decoded = json_decode($response, true);
                    if ($decoded !== null) {
                        $responses[$index] = $decoded;
                        unset($pending[$index]);

                        // Clean up response key
                        $this->storage->del($this->getResponseKey($requestId));
                    }
                }
            }

            if (empty($pending)) {
                break;
            }

            // Exponential backoff
            $pollInterval = min($pollInterval * 2, $maxPollInterval);
            usleep((int)($pollInterval * 1000000));
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->debug("Batch polling completed: " . count($responses) . "/" .
                    count($requestIds) . " responses in {$duration}ms");

        return $responses;
    }

    /**
     * Invalidate cache for specific command or pattern
     *
     * @param string|null $pattern Key pattern (null = invalidate all)
     * @return int Number of keys deleted
     */
    public function invalidateCache(?string $pattern = null): int
    {
        if ($pattern === null) {
            // Invalidate entire site cache
            $pattern = "{$this->siteId}:cache:*";
        } elseif (strpos($pattern, ':') === false) {
            // Auto-prefix with site ID if no colon
            $pattern = "{$this->siteId}:cache:{$pattern}";
        }

        // Use SCAN to find matching keys (safe for production)
        $deleted = 0;
        $cursor = 0;

        do {
            $result = $this->storage->scan($cursor, $pattern, 100);
            if ($result === false) {
                break;
            }

            list($cursor, $keys) = $result;

            if (!empty($keys)) {
                $deleted += call_user_func_array([$this->storage, 'del'], $keys);
            }
        } while ($cursor != 0);

        $this->debug("Invalidated {$deleted} keys matching pattern: {$pattern}");

        // Publish invalidation event
        $this->storage->publish("{{$this->siteId}}:events:invalidate", json_encode([
            'event' => 'cache_invalidated',
            'pattern' => $pattern,
            'deleted_count' => $deleted,
            'timestamp' => microtime(true)
        ]));

        return $deleted;
    }

    /**
     * Invalidate bundle (forces rebuild)
     *
     * @return bool True if bundle was deleted
     */
    public function invalidateBundle(): bool
    {
        $bundleKey = "{{$this->siteId}}:gsd:bundle:full";
        $result = $this->storage->del($bundleKey);

        $this->storage->publish("{{$this->siteId}}:events:invalidate", json_encode([
            'event' => 'bundle_invalidated',
            'reason' => 'manual_invalidation',
            'timestamp' => microtime(true),
            'rebuild_priority' => 'high'
        ]));

        return $result > 0;
    }

    /**
     * Get cache statistics
     *
     * @return array Stats: key_count, total_size_bytes, total_size_mb, site_id
     */
    public function getCacheStats(): array
    {
        $pattern = "{$this->siteId}:*";
        $keys = [];
        $cursor = 0;

        do {
            $result = $this->storage->scan($cursor, $pattern, 100);
            if ($result === false) {
                break;
            }
            list($cursor, $scannedKeys) = $result;
            $keys = array_merge($keys, $scannedKeys);
        } while ($cursor != 0);

        $totalSize = 0;
        foreach ($keys as $key) {
            $size = $this->storage->strlen($key);
            if ($size !== false) {
                $totalSize += $size;
            }
        }

        return [
            'key_count' => count($keys),
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'site_id' => $this->siteId
        ];
    }

    /**
     * Generate cache key for command
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return string|null Cache key or null if not cacheable
     */
    protected function getCacheKey(string $command, array $parameters): ?string
    {
        if (!$this->isCommandCacheable($command)) {
            return null;
        }

        // Normalize parameters (sort keys for consistent hashing)
        ksort($parameters);
        $paramHash = md5(json_encode($parameters, JSON_UNESCAPED_SLASHES));

        return "{$this->siteId}:cache:{$command}:{$paramHash}";
    }

    /**
     * Get request key for request ID
     *
     * @param string $requestId Request identifier
     * @return string Request key
     */
    protected function getRequestKey(string $requestId): string
    {
        return "{$this->siteId}:req:{$requestId}";
    }

    /**
     * Get response key for request ID
     *
     * @param string $requestId Request identifier
     * @return string Response key
     */
    protected function getResponseKey(string $requestId): string
    {
        return "{$this->siteId}:res:{$requestId}";
    }

    /**
     * Check if command is cacheable
     *
     * @param string $command Command name
     * @return bool True if cacheable
     */
    protected function isCommandCacheable(string $command): bool
    {
        $cacheableCommands = [
            'renderTemplate',
            'renderTemplateString',
            'findServices',
            'getServiceDetails',
            'geometric_discover',
            'geometric_dimensions',
            'getLoadSequence',
            'getCapabilityDimensions',
            'content_retrieve',
            'asset_bundle',
            'template_fragment',
            'get_site_info',
            'get_node_info',
        ];

        return in_array($command, $cacheableCommands, true);
    }

    /**
     * Get TTL for command result
     *
     * @param string $command Command name
     * @return int TTL in seconds
     */
    protected function getCacheTTL(string $command): int
    {
        return match($command) {
            'renderTemplate', 'renderTemplateString' => 300,  // 5 minutes
            'findServices', 'getServiceDetails' => 300,  // 5 minutes
            'content_retrieve' => 900,  // 15 minutes
            'asset_bundle', 'template_fragment' => 600,  // 10 minutes
            'get_site_info', 'get_node_info' => 3600,  // 1 hour
            'geometric_discover', 'geometric_dimensions' => 300,  // 5 minutes
            'getLoadSequence', 'getCapabilityDimensions' => 300,  // 5 minutes
            default => 300  // Default: 5 minutes
        };
    }

    /**
     * Get command priority for GSD daemon
     *
     * @param string $command Command name
     * @return string "high", "normal", or "low"
     */
    protected function getCommandPriority(string $command): string
    {
        return match($command) {
            'ping', 'health_check' => 'high',
            'renderTemplate', 'content_retrieve' => 'normal',
            'registerService', 'content_store' => 'normal',
            default => 'low'
        };
    }

    /**
     * Debug logging (inherits from parent Client class)
     *
     * @param string $message Debug message
     */
    protected function debug(string $message): void
    {
        if (isset($this->config['debug']) && $this->config['debug']) {
            error_log("[KeyBasedClient] {$message}");
        }
    }
}
