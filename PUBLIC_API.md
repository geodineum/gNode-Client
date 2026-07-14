# gNode-Client — Public API

> **Generated — do not edit by hand.** Regenerate with `php scripts/gen-public-api.php`.
> The complete callable contract of `gNodeClient` plus the supporting public types.
> Methods marked _premium_ are real and callable, but return a `premium: true`
> response until their Chapter-2 extension is installed. [`CONTRACT.md`](CONTRACT.md)
> is the authoritative prose; where the two differ, the code (and this index) win.

## Client — the gNode wire surface

### `gNodeClient`
<sub>`src/gNodeClient.php`</sub>

- `forSite(string $siteId, string $environment = 'production', array $overrides = []): self` — Create a production client for a specific site
- `fromEnvironment(array $overrides = []): self` — Create a production client using only environment variables
- `getComputeStream(): string` — Get the compute stream name for the current site/environment
- `getHealthStream(): string` — Get the health stream name for reporting metrics to the daemon
- `getBroadcastStream(): string` — Get the broadcast stream name for pub-sub messaging
- `postToGeodineum(string $messageType, array $data): ?string` — Post a message to the Geodineum orchestration stream
- `ensureConsumerGroups(): array` — Ensure consumer groups exist for the site's streams
- `getStreamStatus(): array` — Get status information about this client's streams
- `getCommsStream(): string` — Get the comms stream key for this site
- `queueCommsMessage( string $type, array $sender, array $content, array $metadata = [], int $priority = 3, array $channels = ['email'] ): string|false` — Queue a message to the comms stream for gNode-COMMS to process
- `queueContactForm( string $name, string $email, string $subject, string $message, array $metadata = [] ): string|false` — Queue a contact form submission to the comms stream
- `isConnected(): bool` — Check if connected to storage
- `getStorage(): StorageInterface` — Get the storage interface for direct access
- `ping()` — Ping the daemon to check connectivity
- `getEnvironment(): string` — Get the current environment (DTAP)
- `luaGet(string $key)` — Get value using FCALL (GNODE_CACHE_GET)
- `luaSet(string $key, $value, ?int $ttl = null, ?string $mode = null): bool` — Set value using FCALL (GNODE_CACHE_SET)
- `luaDel(string $key): bool` — Delete value using FCALL (GNODE_CACHE_DEL)
- `luaExists(string $key): bool` — Check if key exists using FCALL (GNODE_CACHE_EXISTS)
- `luaIncrBy(string $key, int $by = 1): int` — Increment key value using FCALL (GNODE_CACHE_INCR)
- `luaDecrBy(string $key, int $by = 1): int` — Decrement key value using FCALL (GNODE_CACHE_DECR)
- `luaHSet(string $key, string $field, $value): bool` — Set hash field using FCALL (GNODE_HASH_HSET)
- `luaHGet(string $key, string $field)` — Get hash field using FCALL (GNODE_HASH_HGET)
- `luaHGetAll(string $key): array` — Get all hash fields using FCALL (GNODE_HASH_HGETALL)
- `keys(string $pattern, int $limit = 1000): array` — Get keys matching pattern using FCALL (GNODE_KEYS_PATTERN)
- `fcall(string $function, array $keys, array $args)` — Call a registered Lua function
- `publish(string $channel, string $message): int` — Publish message to a channel
- `batchCacheGet(array $keys, ?string $group = null): array` — Batch cache GET operations
- `batchCacheSet(array $data, int $ttl = 0, ?string $group = null): bool` — Batch cache SET operations
- `batchCacheDel(array $keys, ?string $group = null): int` — Batch cache DELETE operations
- `executeBatch(array $commands): array` — Execute batch of commands
- `checkRateLimit(string $operation, int $limit = 100, int $window = 60, array $metadata = []): array` — Check rate limit using GNODE_SERVICE_RATE_LIMIT Lua function
- `getCacheStats(): array` — Get cache statistics
- `invalidateCache(?string $pattern = null): int` — Invalidate cache
- `geometricStoreTopology(array $topology, int $dimensions = 8): bool` — Store topology information
- `geometricDiscover(array $capabilities, int $limit = 10, int $dimensions = 0, int $distance = 0): array` — Discover services based on geometric requirements
- `discoverRange(array $criteria): array` — Discover services using range query operators
- `getCapabilityDimensions(): array` — Get registered capability dimensions
- `getBundle(bool $decompress = true)` — Get entire site bundle
- `getBundled(string $key, bool $decompress = true)` — Get a specific manifest bundle by key (instant key-based retrieval)
- `invalidateBundle(): bool` — Invalidate the full site bundle
- `invalidateManifestBundle(string $manifestId): bool` — Invalidate a specific manifest bundle
- `manifestSet(string $manifestId, array $manifest): array` — Create or update a bundle manifest definition
- `manifestGet(string $manifestId): ?array` — Retrieve a manifest definition
- `manifestDelete(string $manifestId): bool` — Delete a manifest definition
- `manifestList(): array` — List all manifests for this site
- `bundleBuildStatus(string $manifestId): array` — Check bundle build status for a manifest
- `assetStore(string $assetId, string $content, string $contentType = 'text/html', int $ttl = 0): array` — Store an asset for bundle assembly
- `assetGet(string $assetId): ?array` — Retrieve an asset by ID
- `assetDelete(string $assetId): bool` — Delete an asset
- `assetList(?string $contentType = null): array` — List assets for this site
- `depRegister(string $topologyKey, array $serviceDefinition): array`  · _premium (gNode-TOPO)_
- `depValidate(string $topologyKey, string $fromId, string $toId): array`  · _premium (gNode-TOPO)_
- `depLoadOrder(string $topologyKey, bool $asLevels = false): array`  · _premium (gNode-TOPO)_
- `depProviders(string $topologyKey, string $capability, string $forService = ''): array`  · _premium (gNode-TOPO)_
- `depMissing(string $topologyKey, string $serviceId): array`  · _premium (gNode-TOPO)_
- `depGetService(string $topologyKey, string $serviceId): ?array`  · _premium (gNode-TOPO)_
- `depDeregister(string $topologyKey, string $serviceId): bool`  · _premium (gNode-TOPO)_
- `depChain(string $topologyKey, string $serviceId, string $direction = 'down'): array`  · _premium (gNode-TOPO)_
- `depStats(string $topologyKey): array`  · _premium (gNode-TOPO)_
- `registryCreate(): array`  · _premium (gNode-TOPO)_
- `registryAdd(array $topologyDefinition): array`  · _premium (gNode-TOPO)_
- `registryGet(string $topologyId): ?array`  · _premium (gNode-TOPO)_
- `registryList(string $filterType = ''): array`  · _premium (gNode-TOPO)_
- `registryRemove(string $topologyId, bool $deleteData = false): bool`  · _premium (gNode-TOPO)_
- `registryMapEntity(string $entityId, string $topologyId, string $entityKeyInTopo): array`  · _premium (gNode-TOPO)_
- `registryEntityTopologies(string $entityId): array`  · _premium (gNode-TOPO)_
- `registryStats(): array`  · _premium (gNode-TOPO)_
- `registryUpdate(string $topologyId, array $updates): array`  · _premium (gNode-TOPO)_
- `registrySearch(string $query, string $searchType = 'name'): array`  · _premium (gNode-TOPO)_
- `crossIntersection(array $topologyIds): array`
- `crossUnion(array $topologyIds): array`
- `crossEntityView(string $entityId): array`
- `crossFindRelated(string $entityId, string $direction = 'both'): array`
- `crossMultiQuery(array $query): array`
- `crossComparePositions(string $entityId): array`
- `crossTopologyDiff(string $topologyId1, string $topologyId2): array`
- `featureSet(string $featureName, bool $enabled = true, int $rolloutPercentage = 100, string $description = '', array $targetingRules = []): array`
- `featureEvaluate(string $featureName, string $userId, array $userContext = []): array`
- `featureList(): array`
- `featureDelete(string $featureName): bool`
- `experimentCreate(string $experimentName, array $variants, string $description = ''): array`
- `experimentAssign(string $experimentName, string $userId): array`
- `experimentConvert(string $experimentName, string $userId, string $conversionType = 'default'): array`
- `experimentResults(string $experimentName): array`
- `sessionCreate(string $userId, int $ttl = 3600, array $data = []): array`
- `sessionGet(string $sessionId, bool $extend = true): ?array`
- `sessionUpdate(string $sessionId, array $data): bool`
- `sessionDestroy(string $sessionId): bool`
- `sessionListUser(string $userId): array`
- `traceStart(string $serviceName, string $operationName, float $sampleRate = 1.0, int $ttl = 3600): array`
- `traceSpanStart(string $traceId, string $parentSpanId, string $serviceName, string $operationName, int $ttl = 3600): array`
- `traceSpanEnd(string $traceId, string $spanId, string $status = 'ok', string $errorMessage = ''): array`
- `traceGet(string $traceId): array`
- `traceSearch(string $serviceName, string $startTime = '', string $endTime = '', int $limit = 20): array`
- `traceStats(): array`
- `traceSpanLog(string $traceId, string $spanId, string $message, string $level = 'info', int $ttl = 3600): array`
- `traceSpanTag(string $traceId, string $spanId, array $tags): array`
- `traceBaggageSet(string $traceId, string $key, string $value): array`
- `traceBaggageGet(string $traceId, string $key): ?string`
- `traceBaggageAll(string $traceId): array`
- `endpointRegister(string $serviceId, array $endpointDefinition): array`
- `endpointGet(string $endpointId): ?array`
- `endpointList(string $serviceId = ''): array`
- `endpointTranslate(string $sourceEndpointId, string $targetEndpointId, array $message, string $direction = 'inbound'): array`
- `endpointFind(string $path, string $method = ''): array`
- `endpointTranslateToInternal(string $endpointId, array $message): array`
- `endpointTranslateFromInternal(string $endpointId, array $message): array`
- `endpointDeregister(string $endpointId): bool`
- `endpointRegisterTranslationRule(string $sourceEndpoint, string $targetEndpoint, array $rule): array`
- `endpointGetSchema(): array`
- `parseFormat(string $formatName, string $formatVersion, array $message): array`
- `parseConvert(string $sourceFormat, string $sourceVersion, string $targetFormat, string $targetVersion, array $message): array`
- `parseRegisterFormat(array $formatDefinition): array`
- `parseListFormats(): array`
- `parseGetFormat(string $formatName, string $formatVersion = ''): ?array`
- `parseDetectFormat(string $message): array`
- `executeCommand(string $command, array $parameters = []): ?array` — Execute a generic command
- `templateFragment(string $templateId, string $content, array $dependencies = [], array $variables = [], ?int $ttl = null): array` — Store a template fragment with dependencies and variables
- `renderTemplate(string $templateId, array $variables = [], array $config = []): string` — Render a template with variables
- `getTemplateManager(): \gCore\gNode\Template\TemplateManager` — Get the template manager

## Supporting types

### `BroadcastMessage`
<sub>`src/Broadcast/BroadcastMessage.php`</sub>

- `getMessage(): ?string` — Get message content (user-facing message)
- `getField(string $fieldName, $default = null)` — Get custom field value
- `hasField(string $fieldName): bool` — Check if message has a specific field
- `isStale(int $maxAgeMs, ?int $nowMs = null): bool` — Check if message age exceeds threshold
- `getAgeSeconds(?int $nowMs = null): float` — Get message age in seconds
- `matchesType($types): bool` — Check if message matches type filter
- `toArray(): array` — Convert to associative array
- `toJson(): string` — Convert to JSON string

### `BroadcastReader`
<sub>`src/Broadcast/BroadcastReader.php`</sub>

- `read(int $count = 100, int $blockMs = 0, ?string $typeFilter = null): array` — Read broadcast messages using ValKey function or direct XREAD
- `write(string $messageType, array $fields = []): string` — Write broadcast message using GNODE_BROADCAST_WRITE ValKey function
- `trim(int $retentionSeconds = 300): int` — Trim broadcast stream by retention time using GNODE_BROADCAST_TRIM
- `resetToNewMessages(): void` — Reset position to read only new messages (from now)
- `setPosition(string $messageId): void` — Set position to specific message ID
- `getPosition(): string` — Get current position (last-seen message ID)
- `getStatistics(): array` — Get reader statistics

### `HealthMetrics`
<sub>`src/Health/HealthMetrics.php`</sub>

- `isStale(int $now): bool` — Check if metrics are stale based on TTL
- `isHealthy(): bool` — Check if service is healthy based on load and error thresholds
- `calculateScore(): float` — Calculate composite score for ranking (lower is better)
- `toCompressedFormat(): array` — Convert to compressed message format for health stream
- `toArray(): array` — Convert to associative array (verbose format)
- `validate(): array` — Validate metrics values

### `HealthStreamWriter`
<sub>`src/Health/HealthStreamWriter.php`</sub>

- `publishMetrics(HealthMetrics $metrics): string` — Publish health metrics to the health stream

### `ValKeyStorage`
<sub>`src/Storage/ValKeyStorage.php`</sub>

- `fcall(string $function, array $keys, array $args)`
- `xAdd(string $key, string $id, array $fields): string` — Add a message to a stream

