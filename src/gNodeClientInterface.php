<?php
declare(strict_types=1);

namespace gCore\gNode;

use gCore\gNode\Storage\StorageInterface;

/**
 * gNodeClientInterface - Contract for gNode Client implementations
 *
 * This interface defines the canonical methods required for gNode client operations.
 * All implementations must provide FCALL-only operations (no direct Redis commands)
 * to maintain security through ACL-based access control.
 *
 * @package gCore\gNode
 * @version 3.0.0
 */
interface gNodeClientInterface
{
    //=========================================================================
    // CORE OPERATIONS
    //=========================================================================

    /**
     * Check if connected to storage
     *
     * @return bool True if connected
     */
    public function isConnected(): bool;

    /**
     * Get the storage interface for direct access
     *
     * @return StorageInterface
     */
    public function getStorage(): StorageInterface;

    /**
     * Ping the daemon to check connectivity
     *
     * @return bool True if ping succeeded
     */
    public function ping();

    /**
     * Get the current environment (DTAP)
     *
     * @return string Environment name (production/staging/testing/acceptance)
     */
    public function getEnvironment(): string;

    //=========================================================================
    // FCALL CACHE OPERATIONS
    //=========================================================================

    /**
     * Get value using FCALL (GNODE_CACHE_GET)
     *
     * @param string $key Cache key
     * @return mixed Value or false if not found
     */
    public function luaGet(string $key);

    /**
     * Set value using FCALL (GNODE_CACHE_SET)
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int|null $ttl Time to live in seconds
     * @param string|null $mode 'NX' (set if not exists) or 'XX' (set if exists)
     * @return bool True if successful
     */
    public function luaSet(string $key, $value, ?int $ttl = null, ?string $mode = null): bool;

    /**
     * Delete value using FCALL (GNODE_CACHE_DEL)
     *
     * @param string $key Cache key
     * @return bool True if deleted
     */
    public function luaDel(string $key): bool;

    /**
     * Check if key exists using FCALL (GNODE_CACHE_EXISTS)
     *
     * @param string $key Cache key
     * @return bool True if key exists
     */
    public function luaExists(string $key): bool;

    /**
     * Increment key value using FCALL (GNODE_CACHE_INCR)
     *
     * @param string $key Cache key
     * @param int $by Amount to increment by
     * @return int New value
     */
    public function luaIncrBy(string $key, int $by = 1): int;

    /**
     * Decrement key value using FCALL (GNODE_CACHE_DECR)
     *
     * @param string $key Cache key
     * @param int $by Amount to decrement by
     * @return int New value
     */
    public function luaDecrBy(string $key, int $by = 1): int;

    //=========================================================================
    // FCALL HASH OPERATIONS
    //=========================================================================

    /**
     * Set hash field using FCALL (GNODE_HASH_HSET)
     *
     * @param string $key Hash key
     * @param string $field Field name
     * @param mixed $value Field value
     * @return bool True if new field was created
     */
    public function luaHSet(string $key, string $field, $value): bool;

    /**
     * Get hash field using FCALL (GNODE_HASH_HGET)
     *
     * @param string $key Hash key
     * @param string $field Field name
     * @return mixed Field value or null if not found
     */
    public function luaHGet(string $key, string $field);

    /**
     * Get all hash fields using FCALL (GNODE_HASH_HGETALL)
     *
     * @param string $key Hash key
     * @return array Associative array of field => value pairs
     */
    public function luaHGetAll(string $key): array;

    /**
     * Get keys matching pattern using FCALL (GNODE_KEYS_PATTERN)
     *
     * @param string $pattern Key pattern (e.g., "user:*")
     * @param int $limit Maximum number of keys to return
     * @return array Array of matching keys
     */
    public function keys(string $pattern, int $limit = 1000): array;

    //=========================================================================
    // GENERIC FCALL INTERFACE
    //=========================================================================

    /**
     * Call a registered Lua function
     *
     * @param string $function Function name
     * @param array $keys Keys to pass
     * @param array $args Arguments to pass
     * @return mixed Function result
     */
    public function fcall(string $function, array $keys, array $args);

    /**
     * Publish message to a channel
     *
     * @param string $channel Channel name
     * @param string $message Message to publish
     * @return int Number of subscribers that received the message
     */
    public function publish(string $channel, string $message): int;

    //=========================================================================
    // BATCH OPERATIONS
    //=========================================================================

    /**
     * Batch cache GET operations
     *
     * @param array $keys Array of cache keys
     * @param string|null $group Optional cache group
     * @return array Associative array of key => value
     */
    public function batchCacheGet(array $keys, ?string $group = null): array;

    /**
     * Batch cache SET operations
     *
     * @param array $data Associative array of key => value
     * @param int $ttl TTL in seconds (applies to all keys)
     * @param string|null $group Optional cache group
     * @return bool True if all sets succeeded
     */
    public function batchCacheSet(array $data, int $ttl = 0, ?string $group = null): bool;

    /**
     * Batch cache DELETE operations
     *
     * @param array $keys Array of cache keys to delete
     * @param string|null $group Optional cache group
     * @return int Number of keys deleted
     */
    public function batchCacheDel(array $keys, ?string $group = null): int;

    /**
     * Execute batch of commands
     *
     * @param array $commands Array of commands to execute
     * @return array Results indexed by command position
     */
    public function executeBatch(array $commands): array;

    //=========================================================================
    // RATE LIMITING
    //=========================================================================

    /**
     * Check rate limit using GNODE_SERVICE_RATE_LIMIT Lua function
     *
     * @param string $operation Operation identifier
     * @param int $limit Maximum requests allowed in window
     * @param int $window Time window in seconds
     * @param array $metadata Optional metadata for metrics tracking
     * @return array ['allowed' => bool, 'current' => int, 'limit' => int, 'remaining' => int]
     */
    public function checkRateLimit(string $operation, int $limit = 100, int $window = 60, array $metadata = []): array;

    //=========================================================================
    // CACHE STATISTICS
    //=========================================================================

    /**
     * Get cache statistics
     *
     * @return array Stats including hits, misses, writes, hit_ratio, etc.
     */
    public function getCacheStats(): array;

    /**
     * Invalidate cache
     *
     * @param string|null $pattern Key pattern (null = invalidate all)
     * @return int Number of keys deleted
     */
    public function invalidateCache(?string $pattern = null): int;

    //=========================================================================
    // GEOMETRIC TOPOLOGY (gCore Integration)
    //=========================================================================


    /**
     * Store topology information
     *
     * @param array $topology Topology structure to store
     * @param int $dimensions Number of dimensions
     * @return bool Success status
     */
    public function geometricStoreTopology(array $topology, int $dimensions = 8): bool;

    /**
     * Discover services based on geometric requirements
     *
     * @param array $capabilities Required capabilities with minimum values or capability names
     * @param int $limit Maximum number of services to return
     * @param int $dimensions Number of dimensions to consider
     * @param int $distance Maximum distance threshold
     * @return array Array of matching services
     */
    public function geometricDiscover(array $capabilities, int $limit = 10, int $dimensions = 0, int $distance = 0): array;

    /**
     * Discover services using range query operators
     *
     * @param array $criteria Associative array of dimension => constraint
     * @return array Array of service IDs matching all criteria
     */
    public function discoverRange(array $criteria): array;

    /**
     * Get registered capability dimensions
     *
     * @return array Map of capability names to dimensions
     */
    public function getCapabilityDimensions(): array;

    //=========================================================================
    // BUNDLE OPERATIONS
    //=========================================================================

    /**
     * Get entire site bundle
     *
     * @param bool $decompress Whether to decompress (default: true)
     * @return array|string|null Bundle data or null if not available
     */
    public function getBundle(bool $decompress = true);

    /**
     * Get a specific manifest bundle by key (instant key-based retrieval)
     *
     * @param string $key Bundle key (manifest ID)
     * @param bool $decompress Whether to decompress gzip bundles
     * @return array|string|null Bundle data or null
     */
    public function getBundled(string $key, bool $decompress = true);

    /**
     * Invalidate the full site bundle
     *
     * @return bool True if bundle was deleted
     */
    public function invalidateBundle(): bool;

    /**
     * Invalidate a specific manifest bundle
     *
     * @param string $manifestId Manifest identifier
     * @return bool True if bundle was deleted
     */
    public function invalidateManifestBundle(string $manifestId): bool;

    //=========================================================================
    // ASSET & MANIFEST OPERATIONS
    //=========================================================================

    /**
     * Create or update a bundle manifest definition
     *
     * @param string $manifestId Manifest identifier
     * @param array $manifest Manifest definition
     * @return array Result with manifest_id, layout, slot_count
     */
    public function manifestSet(string $manifestId, array $manifest): array;

    /**
     * Retrieve a manifest definition
     *
     * @param string $manifestId Manifest identifier
     * @return array|null Manifest or null
     */
    public function manifestGet(string $manifestId): ?array;

    /**
     * Delete a manifest definition
     *
     * @param string $manifestId Manifest identifier
     * @return bool True if deleted
     */
    public function manifestDelete(string $manifestId): bool;

    /**
     * List all manifests for this site
     *
     * @return array Manifest summaries
     */
    public function manifestList(): array;

    /**
     * Check bundle build status for a manifest
     *
     * @param string $manifestId Manifest identifier
     * @return array Status with built, stale, built_at, size
     */
    public function bundleBuildStatus(string $manifestId): array;

    /**
     * Store an asset for bundle assembly
     *
     * @param string $assetId Asset identifier
     * @param string $content Asset content
     * @param string $contentType MIME type
     * @param int $ttl TTL in seconds (0 = no expiry)
     * @return array Result with asset_id, size
     */
    public function assetStore(string $assetId, string $content, string $contentType = 'text/html', int $ttl = 0): array;

    /**
     * Retrieve an asset by ID
     *
     * @param string $assetId Asset identifier
     * @return array|null Asset data or null
     */
    public function assetGet(string $assetId): ?array;

    /**
     * Delete an asset
     *
     * @param string $assetId Asset identifier
     * @return bool True if deleted
     */
    public function assetDelete(string $assetId): bool;

    /**
     * List assets for this site
     *
     * @param string|null $contentType Optional content-type filter
     * @return array Asset summaries
     */
    public function assetList(?string $contentType = null): array;

    //=========================================================================
    // DEPENDENCY TOPOLOGY (gNode-Multi-Topology-Pro)
    //=========================================================================

    public function depRegister(string $topologyKey, array $serviceDefinition): array;
    public function depValidate(string $topologyKey, string $fromId, string $toId): array;
    public function depLoadOrder(string $topologyKey, bool $asLevels = false): array;
    public function depProviders(string $topologyKey, string $capability, string $forService = ''): array;
    public function depMissing(string $topologyKey, string $serviceId): array;
    public function depGetService(string $topologyKey, string $serviceId): ?array;
    public function depDeregister(string $topologyKey, string $serviceId): bool;
    public function depChain(string $topologyKey, string $serviceId, string $direction = 'down'): array;
    public function depStats(string $topologyKey): array;

    //=========================================================================
    // TOPOLOGY REGISTRY (gNode-Multi-Topology-Pro)
    //=========================================================================

    public function registryCreate(): array;
    public function registryAdd(array $topologyDefinition): array;
    public function registryGet(string $topologyId): ?array;
    public function registryList(string $filterType = ''): array;
    public function registryRemove(string $topologyId, bool $deleteData = false): bool;
    public function registryMapEntity(string $entityId, string $topologyId, string $entityKeyInTopo): array;
    public function registryEntityTopologies(string $entityId): array;
    public function registryStats(): array;
    public function registryUpdate(string $topologyId, array $updates): array;
    public function registrySearch(string $query, string $searchType = 'name'): array;

    //=========================================================================
    // CROSS-TOPOLOGY (gNode-Multi-Topology-Pro)
    //=========================================================================

    public function crossIntersection(array $topologyIds): array;
    public function crossUnion(array $topologyIds): array;
    public function crossEntityView(string $entityId): array;
    public function crossFindRelated(string $entityId, string $direction = 'both'): array;
    public function crossMultiQuery(array $query): array;
    public function crossComparePositions(string $entityId): array;
    public function crossTopologyDiff(string $topologyId1, string $topologyId2): array;

    //=========================================================================
    // FEATURE FLAGS & A/B TESTING (gNode-Observability-Pro)
    //=========================================================================

    public function featureSet(string $featureName, bool $enabled = true, int $rolloutPercentage = 100, string $description = '', array $targetingRules = []): array;
    public function featureEvaluate(string $featureName, string $userId, array $userContext = []): array;
    public function featureList(): array;
    public function featureDelete(string $featureName): bool;
    public function experimentCreate(string $experimentName, array $variants, string $description = ''): array;
    public function experimentAssign(string $experimentName, string $userId): array;
    public function experimentConvert(string $experimentName, string $userId, string $conversionType = 'default'): array;
    public function experimentResults(string $experimentName): array;

    //=========================================================================
    // SESSION MANAGEMENT (gNode-Observability-Pro)
    //=========================================================================

    public function sessionCreate(string $userId, int $ttl = 3600, array $data = []): array;
    public function sessionGet(string $sessionId, bool $extend = true): ?array;
    public function sessionUpdate(string $sessionId, array $data): bool;
    public function sessionDestroy(string $sessionId): bool;
    public function sessionListUser(string $userId): array;

    //=========================================================================
    // DISTRIBUTED TRACING (gNode-Observability-Pro)
    //=========================================================================

    public function traceStart(string $serviceName, string $operationName, float $sampleRate = 1.0, int $ttl = 3600): array;
    public function traceSpanStart(string $traceId, string $parentSpanId, string $serviceName, string $operationName, int $ttl = 3600): array;
    public function traceSpanEnd(string $traceId, string $spanId, string $status = 'ok', string $errorMessage = ''): array;
    public function traceGet(string $traceId): array;
    public function traceSearch(string $serviceName, string $startTime = '', string $endTime = '', int $limit = 20): array;
    public function traceStats(): array;
    public function traceSpanLog(string $traceId, string $spanId, string $message, string $level = 'info', int $ttl = 3600): array;
    public function traceSpanTag(string $traceId, string $spanId, array $tags): array;
    public function traceBaggageSet(string $traceId, string $key, string $value): array;
    public function traceBaggageGet(string $traceId, string $key): ?string;
    public function traceBaggageAll(string $traceId): array;

    //=========================================================================
    // ENDPOINT MANAGEMENT (gNode-Message-Broker-Pro)
    //=========================================================================

    public function endpointRegister(string $serviceId, array $endpointDefinition): array;
    public function endpointGet(string $endpointId): ?array;
    public function endpointList(string $serviceId = ''): array;
    public function endpointTranslate(string $sourceEndpointId, string $targetEndpointId, array $message, string $direction = 'inbound'): array;
    public function endpointFind(string $path, string $method = ''): array;
    public function endpointTranslateToInternal(string $endpointId, array $message): array;
    public function endpointTranslateFromInternal(string $endpointId, array $message): array;
    public function endpointDeregister(string $endpointId): bool;
    public function endpointRegisterTranslationRule(string $sourceEndpoint, string $targetEndpoint, array $rule): array;
    public function endpointGetSchema(): array;

    //=========================================================================
    // MESSAGE PARSING (gNode-Message-Broker-Pro)
    //=========================================================================

    public function parseFormat(string $formatName, string $formatVersion, array $message): array;
    public function parseConvert(string $sourceFormat, string $sourceVersion, string $targetFormat, string $targetVersion, array $message): array;
    public function parseRegisterFormat(array $formatDefinition): array;
    public function parseListFormats(): array;
    public function parseGetFormat(string $formatName, string $formatVersion = ''): ?array;
    public function parseDetectFormat(string $message): array;

    //=========================================================================
    // COMMAND EXECUTION (Stream-based)
    //=========================================================================

    /**
     * Execute a generic command
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return array|null Response data or null on failure
     */
    public function executeCommand(string $command, array $parameters = []): ?array;

    //=========================================================================
    // TEMPLATE OPERATIONS
    //=========================================================================

    /**
     * Store a template fragment with dependencies and variables
     *
     * @param string $templateId Template identifier
     * @param string $content Template content
     * @param array $dependencies Template dependencies
     * @param array $variables Template variables
     * @param int|null $ttl Time to live in seconds
     * @return array Response from daemon
     */
    public function templateFragment(string $templateId, string $content, array $dependencies = [], array $variables = [], ?int $ttl = null): array;

    /**
     * Render a template with variables
     *
     * @param string $templateId Template identifier
     * @param array $variables Template variables
     * @param array $config Render configuration
     * @return string Rendered HTML
     */
    public function renderTemplate(string $templateId, array $variables = [], array $config = []): string;

    /**
     * Get the template manager
     *
     * @return \gCore\gNode\Template\TemplateManager Template manager instance
     */
    public function getTemplateManager(): \gCore\gNode\Template\TemplateManager;
}
