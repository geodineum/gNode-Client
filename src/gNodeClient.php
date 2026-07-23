<?php
declare(strict_types=1);

namespace gCore\gNode;

use gCore\gNode\Storage\StorageInterface;
use gCore\gNode\Storage\ValKeyStorage;
use gCore\gNode\Config\gNodeConfig;
use gCore\gNode\Config\CredentialResolver;
use gCore\gNode\Exception\gNodeException;
use gCore\gNode\Exception\ConnectionException;
use gCore\gNode\Exception\ConfigException;
use gCore\gNode\Exception\StorageException;
use gCore\gNode\Fallback\FallbackHandler;

/**
 * gNodeClient - Standalone FCALL-Only gNode Client
 *
 * THIS IS THE CANONICAL gNode CLIENT FOR ALL gCore APPLICATIONS.
 *
 * Architecture: Standalone FCALL-only implementation (no inheritance chain)
 * - All operations use FCALL exclusively for security (ACL-compliant)
 * - No direct Redis/ValKey commands exposed
 * - Supports key-based SET/GET + XADD to per-environment streams
 *
 * Key Features:
 * - FCALL-only operations (GNODE_CACHE_*, GNODE_HASH_*, etc.)
 * - Geometric topology for service discovery
 * - Batch operations with automatic routing
 * - Rate limiting with metrics tracking
 * - Template rendering via gNode daemon
 * - Bundle compression/decompression
 *
 * Usage:
 * ```php
 * $client = gNodeClient::forSite('staging_example_com', 'staging');
 *
 * // Cache operations
 * $client->luaSet('key', 'value', 300);
 * $value = $client->luaGet('key');
 *
 * // Batch operations
 * $results = $client->batchCacheGet(['key1', 'key2', 'key3']);
 *
 * // Geometric discovery
 * $services = $client->geometricDiscover(['security' => 0.8]);
 * ```
 *
 * @package gCore\gNode
 * @version 3.0.0
 */
class gNodeClient implements gNodeClientInterface
{
    /**
     * Commit 1.13.b (NC-D2.03): per-response size cap for FCALL /
     * rawCommand return values. Reciprocal of GN-D2.02 (gNode-side
     * input cap at 64 KiB). On this side, FCALL responses can
     * legitimately be larger (bundles, topology dumps), so we set
     * 4 MiB — comfortably above realistic responses but bounded
     * enough that a rogue/bugged daemon returning multi-MB JSON
     * cannot OOM php-fpm.
     */
    private const MAX_JSON_BYTES = 4 * 1024 * 1024;

    /**
     * Commit 1.13.b (NC-D2.03): size-capped, depth-bounded,
     * exception-free json_decode for FCALL / rawCommand response
     * processing. Use everywhere a daemon-supplied JSON string is
     * decoded; never use raw `$this->safeJsonDecode(...)` on attacker-
     * controllable input. Returns `$default` on:
     *   - response over MAX_JSON_BYTES
     *   - depth exceeded (64 levels — defends against deeply-nested
     *     input that would otherwise stack-blow during decode)
     *   - malformed JSON (caught JsonException)
     *
     * @param  mixed $json  string|null|other; non-strings return
     *                      $default verbatim
     * @param  ?bool $assoc associative-array flag (matches json_decode)
     * @param  mixed $default returned on any failure
     * @return mixed
     */
    private function safeJsonDecode($json, ?bool $assoc = null, $default = null)
    {
        if (!is_string($json)) {
            return $default;
        }
        if (strlen($json) > self::MAX_JSON_BYTES) {
            $this->debug("safeJsonDecode: response " . strlen($json) . " bytes > MAX_JSON_BYTES " . self::MAX_JSON_BYTES . ", returning default");
            return $default;
        }
        try {
            return json_decode($json, $assoc, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->debug("safeJsonDecode: parse failed: " . $e->getMessage());
            return $default;
        }
    }

    //=========================================================================
    // PROPERTIES
    //=========================================================================

    /** @var StorageInterface Storage for communication */
    protected $storage;

    /** @var string Site identifier */
    protected $siteId;

    /** @var string Node identifier */
    protected $nodeId;

    /** @var string Environment (production/staging/testing/acceptance) */
    protected $environment = 'production';

    /** @var string Topology namespace for shared service registration
     * All services across all sites register to {topology_namespace}:gnode:topology
     * Default: "geodineum" → creates key {geodineum}:gnode:topology
     */
    protected $topologyNamespace = 'geodineum';

    /** @var array Configuration */
    protected $config;

    /** @var bool Connection state */
    protected $connected = false;

    /** @var string Unified stream name */
    protected $unifiedStream;

    /** @var string Client identifier */
    protected $clientId;

    /** @var FallbackHandler Fallback implementation */
    protected $fallback;

    /** @var bool Using fallback mode */
    protected $usingFallback = false;

    /** @var array Command response cache */
    protected $responseCache = [];

    /** @var int Request timeout in milliseconds */
    protected $timeout = 5000;

    /**
     * Sticky "daemon is not answering" flag.
     *
     * Set the first time pollForResponse() times out without ever seeing
     * a response key materialise. Once true, subsequent sendCommand()
     * calls in the same request bail immediately with a clear exception
     * instead of paying another full timeout — so a 13-template
     * registration loop no longer compounds 13 × 5s of silent waits.
     *
     * Reset on a successful response (a healthy daemon can recover
     * mid-request, e.g. systemd restart between operations).
     *
     * @var bool
     */
    protected $daemonUnreachable = false;

    /**
     * Path the password was loaded from (env VALKEY_PASSWORD_FILE,
     * autodiscovered glob match, etc.). Captured for diagnostic logging
     * — never log the password value itself.
     *
     * @var string|null
     */
    protected $credentialSource = null;

    /** @var int Cache expiration in seconds */
    protected $cacheExpiration = 300;

    /** @var bool Enable Lua functions */
    protected $luaEnabled = true;

    /** @var int Metrics tracking level (0=none, 1=basic, 2=detailed) */
    protected $metricsLevel = 2;

    /** @var array Cached capability dimensions */
    protected $capabilityDimensions = [];

    /** @var int Sequence counter for command ordering */
    protected $sequenceCounter = 0;

    /** @var \gCore\gNode\Template\TemplateManager|null Template manager */
    protected $templateManager = null;

    /** @var \gCore\gNode\Health\HealthStreamWriter|null Health stream writer */
    protected $healthWriter = null;

    /** @var \gCore\gNode\Format\FormatManager|null Format manager */
    protected $formatManager = null;

    /** @var \gCore\gNode\Broadcast\BroadcastReader|null Broadcast reader */
    protected $broadcastReader = null;

    /**
     * Commands that REQUIRE daemon processing (use stream path)
     * All other commands should use Lua batch for maximum performance
     */
    protected const DAEMON_REQUIRED_COMMANDS = [
        'geometric_discover',
        'geometric_store_topology',
        'geometric_load_sequence',
        'geometric_distance',
        'geometric_dimensions',
        'discover',
        'render_template',
        'render_template_string',
        'register_template',
        'delete_template',
        'register_service',
        'deregister_service',
        'load_update',
        'inference',
        'ai_chat',
        'ai_complete',
        'register_format',
        'convert_format',
        'detect_format',
    ];

    /**
     * Default TTL constants (in seconds)
     * These match the Lua function defaults in gnode_cache.lua
     *
     * The Lua function enforces these as safety defaults if TTL=0 is passed,
     * but explicitly passing TTL is recommended for clarity.
     */
    public const TTL_REQUEST = 60;           // Request tracking keys: 1 minute
    public const TTL_ERROR = 604800;         // Error cache: 7 days (86400 * 7)
    public const TTL_CACHE_DEFAULT = 3600;   // General cache: 1 hour
    public const TTL_CACHE_SHORT = 300;      // Short-lived cache: 5 minutes
    public const TTL_CACHE_LONG = 86400;     // Long-lived cache: 24 hours
    public const TTL_SESSION = 1800;         // Session data: 30 minutes
    public const TTL_MAX = 2592000;          // Maximum TTL: 30 days (86400 * 30)
    // Daily analytics rollups. Matches the ~81-day expiry already carried by
    // the pageviews/visits/referrers/visitors family, so the whole metrics
    // namespace ages out together instead of one member leaking.
    public const METRICS_TTL_SECONDS = 7005600; // 81 days

    //=========================================================================
    // CONSTRUCTOR & FACTORY METHODS
    //=========================================================================

    /**
     * Constructor
     *
     * @param StorageInterface $storage Storage implementation
     * @param string $siteId Site identifier
     * @param string $nodeId Node identifier
     * @param array $config Configuration options
     */
    public function __construct(
        StorageInterface $storage,
        string $siteId,
        string $nodeId = 'default',
        array $config = []
    ) {
        $this->storage = $storage;
        $this->siteId = $siteId;
        $this->nodeId = $nodeId;

        $this->config = array_merge([
            'stream_prefix' => 'gnode',
            'environment' => 'production',
            'debug' => false,
            'use_fallback' => true,
            'timeout' => 5.0,
            'retry_count' => 3,
            'retry_delay' => 0.1,
            'cache_expiration' => 300,
            'allow_local_execution' => false,
            'lua_enabled' => true,
            'metrics_level' => 2,
            'skip_connection_check' => false,
            // Topology namespace - shared across all sites for unified service discovery
            // All services register to {topology_namespace}:gnode:topology
            'topology_namespace' => 'geodineum',
        ], $config);

        $this->clientId = $config['client_id'] ?? uniqid('', true);
        $this->luaEnabled = $this->config['lua_enabled'];
        $this->metricsLevel = $this->config['metrics_level'];
        $this->timeout = intval($this->config['timeout'] * 1000);
        $this->cacheExpiration = intval($this->config['cache_expiration']);
        $this->environment = $this->config['environment'];
        $this->topologyNamespace = $this->config['topology_namespace'];

        // Stash credential-source hint for diagnostics on first timeout.
        // Order of preference matches what callers typically pass:
        //   1. explicit 'credential_source' in config (set by gNodeConfig::forSite)
        //   2. VALKEY_PASSWORD_FILE env (the install-script + Apache SetEnv path)
        //   3. fall through to "(unspecified)"
        $this->credentialSource = $this->config['credential_source']
            ?? (getenv('VALKEY_PASSWORD_FILE') ?: null);

        // Set up unified stream name
        // Pattern: {site_id}:gnode:unified:{environment}
        // The {} around siteId is for Redis Cluster hash tag routing
        $this->unifiedStream = sprintf(
            '{%s}:%s:unified:%s',
            $this->siteId,
            $this->config['stream_prefix'],
            $this->environment  // Was: $this->nodeId (WRONG - should be environment for DTAP isolation)
        );

        // Initialize fallback if enabled
        if ($this->config['use_fallback']) {
            $this->fallback = new FallbackHandler($this->config['allow_local_execution']);
        }

        // Attempt connection (skip if requested)
        if (!$this->config['skip_connection_check']) {
            try {
                $this->connect();
            } catch (\Exception $e) {
                $this->debug("Connection error: {$e->getMessage()}");
                if ($this->config['use_fallback']) {
                    $this->usingFallback = true;
                } else {
                    throw new ConnectionException("Failed to connect: {$e->getMessage()}");
                }
            }
        } else {
            $this->connected = true;
        }

        $this->debug("gNodeClient initialized (lua=" . ($this->luaEnabled ? 'ON' : 'OFF') .
                    ", metrics={$this->metricsLevel}, env={$this->environment})");
    }

    /**
     * Create a production client for a specific site
     *
     * This is the CANONICAL way to create a gNode client from gCore.
     *
     * @param string $siteId Site identifier (e.g., 'staging_example_com')
     * @param string $environment DTAP environment (testing/staging/acceptance/production)
     * @param array $overrides Optional configuration overrides
     * @return static Client instance
     * @throws ConfigException If credentials cannot be resolved
     * @api
     */
    public static function forSite(string $siteId, string $environment = 'production', array $overrides = []): self
    {
        $config = gNodeConfig::forSite($siteId, $environment, $overrides);

        if (!$config->isValid()) {
            throw new ConfigException(
                "Invalid gNode configuration: " . implode(', ', $config->getValidationErrors())
            );
        }

        $storage = new ValKeyStorage($config->getValKeyConfig());
        // FIX: Use actual site_id for streams, not environment
        // The environment is passed separately in config for DTAP stream isolation
        $streamSiteId = $siteId;  // Was: $environment (WRONG - caused site_id/env conflation)
        $nodeId = $overrides['node_id'] ?? $config->get('node_id', 'default');

        return new static(
            $storage,
            $streamSiteId,
            $nodeId,
            array_merge($config->toArray(), [
                'environment' => $environment,
                'site_id' => $siteId,  // Explicit site_id in config (renamed from actual_site_id)
                'lua_enabled' => $overrides['lua_enabled'] ?? true,
                'metrics_level' => $overrides['metrics_level'] ?? 2,
            ], $overrides)
        );
    }

    /**
     * Create a production client using only environment variables
     *
     * @param array $overrides Optional configuration overrides
     * @return static Client instance
     * @throws ConfigException If required configuration is missing
     * @api
     */
    public static function fromEnvironment(array $overrides = []): self
    {
        $config = gNodeConfig::fromEnvironment();

        if (!empty($overrides)) {
            $config = new gNodeConfig(array_merge($config->toArray(), $overrides));
        }

        if (!$config->isValid()) {
            throw new ConfigException(
                "Invalid gNode configuration from environment: " . implode(', ', $config->getValidationErrors())
            );
        }

        $storage = new ValKeyStorage($config->getValKeyConfig());
        $environment = $config->get('environment', 'production');

        return new static(
            $storage,
            $environment,
            $config->get('node_id', 'default'),
            array_merge($config->toArray(), [
                'lua_enabled' => $overrides['lua_enabled'] ?? true,
                'metrics_level' => $overrides['metrics_level'] ?? 2,
            ], $overrides)
        );
    }

    /**
     * Get debug info about credential resolution
     *
     * @param string $siteId Site identifier
     * @return array Debug information
     */
    public static function getCredentialDebugInfo(string $siteId): array
    {
        $user = 'gnode_client_' . $siteId;
        return CredentialResolver::getDebugInfo($user);
    }

    //=========================================================================
    // CONNECTION MANAGEMENT
    //=========================================================================

    /**
     * Connect to storage
     *
     * @return bool Success status
     */
    protected function connect(): bool
    {
        if (!$this->storage->isConnected()) {
            throw new StorageException('Not connected to storage');
        }

        // Test connection with a simple FCALL
        try {
            $this->storage->fcall('GNODE_CACHE_GET', [], ['__ping__', $this->siteId]);
            $this->connected = true;
            $this->debug("Successfully connected to storage");
            return true;
        } catch (\Exception $e) {
            $this->debug("Connection test failed: {$e->getMessage()}");
            if ($this->fallback && $this->config['use_fallback']) {
                $this->usingFallback = true;
                return true;
            }
            return false;
        }
    }

    /**
     * Check if connected to storage
     *
     * @return bool True if connected
     */
    public function isConnected(): bool
    {
        try {
            return $this->storage->isConnected() || $this->usingFallback;
        } catch (\Exception $e) {
            return $this->usingFallback;
        }
    }

    /**
     * Check if using fallback mode
     *
     * @return bool Fallback status
     */
    public function isUsingFallback(): bool
    {
        return $this->usingFallback;
    }

    /**
     * Get the storage interface
     *
     * @return StorageInterface
     */
    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }

    /**
     * Get the current environment (DTAP)
     *
     * @return string Environment name
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * Set the environment and update stream names
     *
     * Use this to switch DTAP environments (testing/staging/acceptance/production)
     * without creating a new client instance.
     *
     * @param string $environment New DTAP environment
     * @return self For method chaining
     */
    public function setEnvironment(string $environment): self
    {
        $validEnvironments = ['testing', 'staging', 'acceptance', 'production'];
        if (!in_array($environment, $validEnvironments, true)) {
            $this->debug("Warning: Non-standard environment '{$environment}'. Expected one of: " . implode(', ', $validEnvironments));
        }

        $this->environment = $environment;
        $this->config['environment'] = $environment;

        // Rebuild unified stream name with new environment
        $this->unifiedStream = sprintf(
            '{%s}:%s:unified:%s',
            $this->siteId,
            $this->config['stream_prefix'] ?? 'gnode',
            $this->environment
        );

        $this->debug("Environment changed to: {$environment}, stream: {$this->unifiedStream}");

        return $this;
    }

    /**
     * Get the actual site ID
     *
     * @return string Site identifier (e.g., 'staging_example_com')
     */
    public function getSiteId(): string
    {
        return $this->siteId;
    }

    /**
     * Get the compute stream name for the current site/environment
     *
     * NEW: Returns per-site unified stream instead of global compute stream.
     * The daemon listens on {site_id}:gnode:unified:{environment} streams.
     *
     * @return string Stream name (e.g., "{staging_example_com}:gnode:unified:production")
     * @api
     */
    public function getComputeStream(): string
    {
        // Return the unified stream - this is what the daemon listens on
        return $this->unifiedStream;
    }

    /**
     * Get the health stream name for reporting metrics to the daemon
     *
     * Pattern: {site_id}:gnode:health:{environment}
     *
     * @return string Health stream key
     * @api
     */
    public function getHealthStream(): string
    {
        return sprintf(
            '{%s}:%s:health:%s',
            $this->siteId,
            $this->config['stream_prefix'] ?? 'gnode',
            $this->environment
        );
    }

    /**
     * Get the broadcast stream name for pub-sub messaging
     *
     * Pattern: {site_id}:gnode:broadcast:global
     *
     * @return string Broadcast stream key
     * @api
     */
    public function getBroadcastStream(): string
    {
        return sprintf(
            '{%s}:%s:broadcast:global',
            $this->siteId,
            $this->config['stream_prefix'] ?? 'gnode'
        );
    }

    //=========================================================================
    // GEODINEUM ORCHESTRATION METHODS
    //=========================================================================

    /**
     * Post a message to the Geodineum orchestration stream
     *
     * This is write-only for sites - the daemon listens on this stream for
     * cross-site coordination and multi-node orchestration messages.
     *
     * @param string $messageType Message type (e.g., 'site_registered', 'health_update', 'service_request')
     * @param array $data Message data payload
     * @return string|null Message ID if successful, null on failure
     * @api
     */
    public function postToGeodineum(string $messageType, array $data): ?string
    {
        $orchestrationStream = 'geodineum:gnode:orchestration';

        try {
            $messageId = $this->storage->xAdd($orchestrationStream, '*', [
                'type'        => $messageType,
                'site_id'     => $this->siteId,
                'environment' => $this->environment,
                'data'        => json_encode($data),
                'timestamp'   => (string) time(),
            ]);

            $this->debug("Posted to Geodineum: type={$messageType}, id={$messageId}");

            return $messageId;
        } catch (\Exception $e) {
            $this->debug("Failed to post to Geodineum: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Ensure consumer groups exist for the site's streams
     *
     * Creates the gnode-daemon command consumer group on unified and health
     * streams. This is idempotent - safe to call multiple times.
     *
     * Note: gnode-client (the former response group) is retired. Responses are
     * delivered by keyed rendezvous ({ss}:res:{id}), not through a consumer
     * group, so no response group is created.
     *
     * @return array Result with created/existing groups
     * @api
     */
    public function ensureConsumerGroups(): array
    {
        $results = [];
        $groups = ['gnode-daemon'];

        $streams = [
            'unified' => $this->unifiedStream,
            'health' => $this->getHealthStream(),
        ];

        foreach ($streams as $type => $streamKey) {
            try {
                $result = $this->storage->fcall(
                    'GNODE_STREAM_ENSURE_CONSUMER_GROUPS',
                    [$streamKey],
                    [json_encode($groups), '0']
                );

                $results[$type] = $this->safeJsonDecode($result, true) ?? ['raw' => $result];
            } catch (\Exception $e) {
                $results[$type] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Get status information about this client's streams
     *
     * Returns information about stream keys, consumer groups,
     * and connection status for debugging and monitoring.
     *
     * @return array Status information
     * @api
     */
    public function getStreamStatus(): array
    {
        return [
            'site_id' => $this->siteId,
            'environment' => $this->environment,
            'node_id' => $this->nodeId,
            'streams' => [
                'unified' => $this->unifiedStream,
                'health' => $this->getHealthStream(),
                'broadcast' => $this->getBroadcastStream(),
            ],
            'consumer_groups' => [
                'daemon' => 'gnode-daemon',
            ],
            'connected' => $this->connected,
            'using_fallback' => $this->usingFallback,
        ];
    }

    //=========================================================================
    // CORE FCALL CACHE OPERATIONS
    //=========================================================================

    /**
     * Get value using FCALL (GNODE_CACHE_GET) - FCALL-only for security
     *
     * @param string $key Cache key
     * @return mixed Value or false if not found
     */
    public function luaGet(string $key)
    {
        $result = $this->storage->fcall(
            'GNODE_CACHE_GET',
            [],
            [$key, $this->siteId]
        );

        return $result === null ? false : $result;
    }

    /**
     * Set value using FCALL (GNODE_CACHE_SET) - FCALL-only for security
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int|null $ttl Time to live in seconds
     * @param string|null $mode 'NX' or 'XX'
     * @return bool True if successful
     */
    public function luaSet(string $key, $value, ?int $ttl = null, ?string $mode = null): bool
    {
        $result = $this->storage->fcall(
            'GNODE_CACHE_SET',
            [],
            [$key, $value, $ttl ?? 0, $this->siteId, $mode ?? '']
        );

        // rawCommand returns true (boolean) on success, not 'OK' string
        return $result === true || $result === 'OK';
    }

    /**
     * Delete value using FCALL (GNODE_CACHE_DEL) - FCALL-only for security
     *
     * @param string $key Cache key
     * @return bool True if deleted
     */
    public function luaDel(string $key): bool
    {
        $result = $this->storage->fcall(
            'GNODE_CACHE_DEL',
            [],
            [$key, $this->siteId]
        );

        return $result > 0;
    }

    /**
     * Check if key exists using FCALL (GNODE_CACHE_EXISTS)
     *
     * @param string $key Cache key
     * @return bool True if key exists
     */
    public function luaExists(string $key): bool
    {
        try {
            $result = $this->storage->fcall(
                'GNODE_CACHE_EXISTS',
                [],
                [$key, $this->siteId]
            );
            return $result > 0;
        } catch (\Exception $e) {
            $this->debug("FCALL EXISTS error for key: {$key} ({$e->getMessage()})");
            return false;
        }
    }

    /**
     * Increment key value using FCALL (GNODE_CACHE_INCR)
     *
     * @param string $key Cache key
     * @param int $by Amount to increment by
     * @return int New value
     */
    public function luaIncrBy(string $key, int $by = 1): int
    {
        try {
            $result = $this->storage->fcall(
                'GNODE_CACHE_INCR',
                [],
                [$key, $by, $this->siteId]
            );
            return (int) $result;
        } catch (\Exception $e) {
            $this->debug("FCALL INCR error for key: {$key} ({$e->getMessage()})");
            throw $e;
        }
    }

    /**
     * Decrement key value using FCALL (GNODE_CACHE_DECR)
     *
     * @param string $key Cache key
     * @param int $by Amount to decrement by
     * @return int New value
     */
    public function luaDecrBy(string $key, int $by = 1): int
    {
        try {
            $result = $this->storage->fcall(
                'GNODE_CACHE_DECR',
                [],
                [$key, $by, $this->siteId]
            );
            return (int) $result;
        } catch (\Exception $e) {
            $this->debug("FCALL DECR error for key: {$key} ({$e->getMessage()})");
            throw $e;
        }
    }

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
    public function luaHSet(string $key, string $field, $value): bool
    {
        try {
            $result = $this->storage->fcall(
                'GNODE_HASH_HSET',
                [$key],
                [$field, $value, $this->siteId]
            );
            // Lua function returns 1 for success
            return $result === 1 || $result === true;
        } catch (\Exception $e) {
            $this->debug("FCALL HSET error for key: {$key} ({$e->getMessage()})");
            throw $e;
        }
    }

    /**
     * Get hash field using FCALL (GNODE_HASH_HGET)
     *
     * @param string $key Hash key
     * @param string $field Field name
     * @return mixed Field value or null if not found
     */
    public function luaHGet(string $key, string $field)
    {
        try {
            $value = $this->storage->fcall(
                'GNODE_HASH_HGET',
                [$key],
                [$field, $this->siteId]
            );
            return ($value === false || $value === null) ? null : $value;
        } catch (\Exception $e) {
            $this->debug("FCALL HGET error for key: {$key} ({$e->getMessage()})");
            throw $e;
        }
    }

    /**
     * Get all hash fields using FCALL (GNODE_HASH_HGETALL)
     *
     * @param string $key Hash key
     * @return array Associative array of field => value pairs
     */
    public function luaHGetAll(string $key): array
    {
        try {
            $result = $this->storage->fcall(
                'GNODE_HASH_HGETALL',
                [$key],
                [$this->siteId]
            );
            if (!is_array($result)) {
                return [];
            }
            // Check if it's already associative (string keys)
            if (!array_is_list($result)) {
                return $result;
            }
            // Convert flat array [field1, value1, field2, value2] to associative
            $assoc = [];
            for ($i = 0; $i < count($result) - 1; $i += 2) {
                $assoc[$result[$i]] = $result[$i + 1];
            }
            return $assoc;
        } catch (\Exception $e) {
            $this->debug("FCALL HGETALL error for key: {$key} ({$e->getMessage()})");
            throw $e;
        }
    }

    /**
     * Get keys matching pattern using FCALL (GNODE_KEYS_PATTERN)
     *
     * @param string $pattern Key pattern
     * @param int $limit Maximum number of keys
     * @return array Array of matching keys
     */
    public function keys(string $pattern, int $limit = 1000): array
    {
        try {
            $result = $this->storage->fcall(
                'GNODE_KEYS_PATTERN',
                [],
                [$pattern, $this->siteId, $limit, 100]
            );

            if (is_string($result)) {
                $keys = $this->safeJsonDecode($result, true);
                return is_array($keys) ? $keys : [];
            }
            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            $this->debug("FCALL KEYS error for pattern: {$pattern} ({$e->getMessage()})");
            return [];
        }
    }

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
    public function fcall(string $function, array $keys, array $args)
    {
        // Commit 1.12.b (NC-D2.07): general allowlist on the FCALL
        // function name. gCore's assumption that "gNodeClient enforces
        // the GNODE_* surface" was previously unfounded — any string
        // passed through to `rawCommand('FCALL', …)` would invoke any
        // Lua function reachable via the ACL (including the ~100
        // unwrapped GNODE_* helpers + anything else an adversary can
        // cause to be registered). Gate on the canonical naming
        // convention before touching the wire.
        if (!preg_match('/\A(?:GNODE|GCUBE|COMMS|GC)_[A-Z0-9_]+\z/', $function)) {
            throw new \InvalidArgumentException(
                "fcall: function name {$function} does not match the canonical ^(GNODE|GCUBE|COMMS|GC)_[A-Z0-9_]+$ allowlist (NC-D2.07)"
            );
        }

        // Ensure all args are scalar - JSON-encode arrays/objects
        // rawCommand() only accepts scalar values
        $scalarArgs = array_map(function ($arg) {
            if (is_array($arg) || is_object($arg)) {
                return json_encode($arg);
            }
            return $arg;
        }, $args);

        // Pre-FCALL validate (Commit 0.5.e). For config-mutation FCALLs
        // (GNODE_CONFIG_SET and any future *_CONFIG_SET variant), fetch
        // the component's config_schema from ValKey and validate the
        // value against the declared type.
        $this->validatePreFcall($function, $keys, $scalarArgs);

        // Ch.1.A 1.10 — gDash dashboard instrumentation. Counters live at
        // hash-tagged {site_id}:metrics:* keys (P-DEEP-52 invariant).
        // Best-effort, swallowed on error — instrumentation MUST NOT break
        // the request path. Gated on metrics_level so cold paths can opt
        // out. Latency budget: <100µs per call (DASH-G10).
        if ($this->metricsLevel >= 1) {
            $this->recordFcallMetrics($function);
        }

        return $this->storage->fcall($function, $keys, $scalarArgs);
    }

    /**
     * Record per-fcall metrics for the gDash dashboard.
     *
     * Three writes per call (all hash-tagged to the site_id slot so they
     * land on a single shard):
     *   - INCR {site_id}:metrics:requests:total
     *   - INCR {site_id}:metrics:requests:fcalls:<function>
     *   - PFADD {site_id}:metrics:clients:hll:YYYY-MM-DD <client_fingerprint>
     *
     * The HLL key rotates daily and is expired at METRICS_TTL_SECONDS, matching
     * the rest of the metrics family. Retention is this writer's
     * responsibility: nothing else sweeps these.
     */
    private function recordFcallMetrics(string $function): void
    {
        try {
            $site = $this->siteId;
            if ($site === '') {
                return;
            }
            $tagged = '{' . $site . '}:metrics:requests:';

            $this->storage->incr($tagged . 'total');
            $this->storage->incr($tagged . 'fcalls:' . $function);

            $client = $this->resolveClientFingerprint();
            if ($client !== '') {
                $day = gmdate('Y-m-d');
                $hllKey = '{' . $site . '}:metrics:clients:hll:' . $day;
                $this->storage->pfAdd($hllKey, [$client]);
                // One new key per site per day, so it MUST carry a TTL or it
                // leaks forever. Every sibling in this family already expires
                // at ~81 days; this key was missed when those were applied and
                // was found with ttl = -1 across 250 live keys.
                $this->storage->expire($hllKey, self::METRICS_TTL_SECONDS);
            }
        } catch (\Throwable $e) {
            // Instrumentation is best-effort — never let a metrics write
            // break the calling site. Log only when a debug flag is set.
            if (!empty($this->config['debug'])) {
                error_log(
                    '[gNode-Client] dashboard metrics write failed: ' . $e->getMessage()
                );
            }
        }
    }

    /**
     * Build the client fingerprint used for the daily HLL cardinality
     * estimate. Prefers REMOTE_ADDR; falls back to empty (skipping the
     * PFADD) when running outside an HTTP request (CLI, cron, daemon).
     */
    private function resolveClientFingerprint(): string
    {
        if (PHP_SAPI === 'cli') {
            return '';
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '';
        return $ip;
    }

    /**
     * Per-request cache of config_schema HGETALL lookups by component.
     * Avoids repeat round-trips if a single request fires multiple
     * GNODE_CONFIG_SET calls against the same category.
     *
     * @var array<string, array<string, array>>  component => key => entry-array
     */
    private array $configSchemaCache = [];

    /**
     * Validate an FCALL invocation against the config_schema surface
     * published at Commit 0.5. Currently only exercises the path for
     * GNODE_CONFIG_SET (site_id, category, key, value). Throws
     * InvalidArgumentException on shape drift, and lets the FCALL proceed
     * when no schema is published for the target category (dev boxes
     * where the installer one-shot has not run).
     */

    /**
     * Commit 1.12.b (NC-D2.02): validate a PID string read from ValKey
     * before shell interpolation. A compromised sibling-ACL client could
     * write `0; rm -rf / ;#` to the pid key. Accept only non-empty
     * digit strings; return '' for everything else.
     */
    private static function safePid($raw): string
    {
        if (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
            return $raw;
        }
        return '';
    }

    private function validatePreFcall(string $function, array $keys, array $args): void
    {
        if ($function === '') {
            throw new \InvalidArgumentException('fcall(): empty function name');
        }

        // Config-mutation path. GNODE_CONFIG_SET signature:
        //   args[0] = site_id, args[1] = category (== component),
        //   args[2] = config key, args[3] = value (stringified)
        if ($function !== 'GNODE_CONFIG_SET') {
            return;
        }
        if (count($args) < 4) {
            throw new \InvalidArgumentException(
                'fcall(GNODE_CONFIG_SET): expected (site_id, category, key, value); got ' . count($args) . ' args'
            );
        }

        $category = (string)$args[1];
        $key = (string)$args[2];
        $value = (string)$args[3];

        $entry = $this->getConfigSchemaEntry($category, $key);
        if ($entry === null) {
            // Schema not yet published for this category (pre-install, dev)
            // OR the key is not covered by the schema. Log and pass through —
            // enforcing here would break bootstrapping. Ch.1.1 tightens this
            // by requiring every settable key to be schema-declared.
            $this->debug("fcall validate: no config_schema entry for {$category}.{$key}; allowing");
            return;
        }

        if (isset($entry['mutable']) && $entry['mutable'] === false) {
            throw new \InvalidArgumentException(
                "fcall(GNODE_CONFIG_SET): key '{$key}' in component '{$category}' is declared mutable:false"
            );
        }

        $type = isset($entry['type']) ? (string)$entry['type'] : 'string';
        $reason = $this->validateScalarAgainstType($value, $type, $entry['values'] ?? null);
        if ($reason !== null) {
            throw new \InvalidArgumentException(
                "fcall(GNODE_CONFIG_SET): {$category}.{$key} {$reason}"
            );
        }
    }

    /**
     * Fetch a single config_schema entry for (component, key).
     * Returns null when the entry is absent (either the component isn't
     * published or the key is not declared).
     *
     * @return array<string, mixed>|null
     */
    private function getConfigSchemaEntry(string $component, string $key): ?array
    {
        if (!isset($this->configSchemaCache[$component])) {
            try {
                $raw = $this->storage->hGetAll("geodineum:config_schema:{$component}");
            } catch (\Exception $e) {
                $this->debug("config_schema HGETALL error for {$component}: {$e->getMessage()}");
                $raw = [];
            }
            $parsed = [];
            foreach ($raw as $k => $v) {
                $decoded = $this->safeJsonDecode((string)$v, true);
                if (is_array($decoded)) {
                    $parsed[(string)$k] = $decoded;
                }
            }
            $this->configSchemaCache[$component] = $parsed;
        }
        return $this->configSchemaCache[$component][$key] ?? null;
    }

    /**
     * Mirror of geodineum-schema's validate_value_against_schema (Rust).
     * Returns a human-readable failure reason on mismatch, or null on ok.
     *
     * @param mixed $values  Optional enum values from the config_schema entry.
     */
    private function validateScalarAgainstType(string $value, string $type, $values = null): ?string
    {
        switch ($type) {
            case 'int':
            case 'integer':
                if (!is_numeric($value) || (string)(int)$value !== $value) {
                    return "expected int, got: {$value}";
                }
                return null;
            case 'bool':
            case 'boolean':
                if (!in_array($value, ['true', 'false', '0', '1'], true)) {
                    return "expected bool, got: {$value}";
                }
                return null;
            case 'enum':
                if (!is_array($values) || empty($values)) {
                    return 'enum type declared without `values` list';
                }
                if (!in_array($value, array_map('strval', $values), true)) {
                    return "value '{$value}' not in allowed set: " . implode('|', array_map('strval', $values));
                }
                return null;
            case 'path':
                if ($value === '') {
                    return 'path type must not be empty';
                }
                return null;
            default:
                // 'string' or unknown type — accept any string.
                return null;
        }
    }

    /**
     * Publish message to a channel
     *
     * @param string $channel Channel name
     * @param string $message Message to publish
     * @return int Number of subscribers
     */
    public function publish(string $channel, string $message): int
    {
        try {
            return $this->storage->publish($channel, $message);
        } catch (\Exception $e) {
            $this->debug("PUBLISH error: {$e->getMessage()}");
            throw $e;
        }
    }

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
    public function batchCacheGet(array $keys, ?string $group = null): array
    {
        if (empty($keys)) {
            return [];
        }

        $startTime = microtime(true);

        try {
            $args = [$this->siteId];
            if ($group !== null) {
                $args[] = $group;
            }

            $values = $this->storage->fcall(
                'GNODE_BATCH_MGET_RESP3',
                $keys,
                $args
            );

            $results = [];
            foreach ($keys as $i => $key) {
                $results[$key] = $values[$i] ?? null;
            }

            $latency = round((microtime(true) - $startTime) * 1000, 3);
            $hits = count(array_filter($values, fn($v) => $v !== null));

            $this->trackMetric('batch_cache_get', count($keys), [
                'latency_ms' => $latency,
                'hits' => $hits,
                'misses' => count($keys) - $hits,
            ]);

            return $results;
        } catch (\Exception $e) {
            $this->debug("batchCacheGet error: {$e->getMessage()}");
            $results = [];
            foreach ($keys as $key) {
                $results[$key] = $this->luaGet($key);
            }
            return $results;
        }
    }

    /**
     * Batch cache SET operations
     *
     * @param array $data Associative array of key => value
     * @param int $ttl TTL in seconds
     * @param string|null $group Optional cache group
     * @return bool True if all sets succeeded
     */
    public function batchCacheSet(array $data, int $ttl = 0, ?string $group = null): bool
    {
        if (empty($data)) {
            return true;
        }

        $startTime = microtime(true);

        try {
            $keys = array_keys($data);
            $values = array_values($data);

            $args = [$this->siteId, $ttl, $group ?? ''];
            $args = array_merge($args, $values);

            // Use GNODE_BATCH_MSET (not RESP3 variant) - works without cluster mode
            $result = $this->storage->fcall(
                'GNODE_BATCH_MSET',
                $keys,
                $args
            );

            $latency = round((microtime(true) - $startTime) * 1000, 3);

            $this->trackMetric('batch_cache_set', count($data), [
                'latency_ms' => $latency,
                'ttl' => $ttl
            ]);

            // GNODE_BATCH_MSET returns count of keys set, or true/OK on success
            return is_numeric($result) ? $result > 0 : ($result === true || $result === 'OK');
        } catch (\Exception $e) {
            $this->debug("batchCacheSet error: {$e->getMessage()}");
            foreach ($data as $key => $value) {
                $this->luaSet($key, $value, $ttl);
            }
            return true;
        }
    }

    /**
     * Batch cache DELETE operations
     *
     * @param array $keys Array of cache keys to delete
     * @param string|null $group Optional cache group
     * @return int Number of keys deleted
     */
    public function batchCacheDel(array $keys, ?string $group = null): int
    {
        if (empty($keys)) {
            return 0;
        }

        try {
            $args = [$this->siteId];
            if ($group !== null) {
                $args[] = $group;
            }

            $deleted = $this->storage->fcall(
                'GNODE_BATCH_MDEL_RESP3',
                $keys,
                $args
            );

            $this->trackMetric('batch_cache_del', count($keys), [
                'deleted' => $deleted
            ]);

            return (int)$deleted;
        } catch (\Exception $e) {
            $this->debug("batchCacheDel error: {$e->getMessage()}");
            $deleted = 0;
            foreach ($keys as $key) {
                if ($this->luaDel($key)) {
                    $deleted++;
                }
            }
            return $deleted;
        }
    }

    /**
     * Execute batch operations using direct Lua
     *
     * @param array $operations Array of operations
     * @return array Results array
     */
    public function batchExec(array $operations): array
    {
        if (empty($operations)) {
            return [];
        }

        if (!$this->luaEnabled) {
            return $this->batchExecFallback($operations);
        }

        $startTime = microtime(true);

        try {
            $operationsJson = json_encode($operations);

            $resultJson = $this->storage->fcall(
                'GNODE_BATCH_EXEC',
                [],
                [$this->siteId, $operationsJson]
            );

            $results = $this->safeJsonDecode($resultJson, true);
            if ($results === null && $resultJson !== 'null') {
                throw new gNodeException("Failed to decode batch results: " . json_last_error_msg());
            }

            $latency = round((microtime(true) - $startTime) * 1000, 3);
            $this->trackMetric('batch_exec', count($operations), [
                'latency_ms' => $latency,
            ]);

            return $results ?? [];
        } catch (\Exception $e) {
            $this->debug("batchExec error: {$e->getMessage()}");
            return $this->batchExecFallback($operations);
        }
    }

    /**
     * Fallback for batch execution using individual FCALL operations
     *
     * @param array $operations Operations to execute
     * @return array Results
     */
    protected function batchExecFallback(array $operations): array
    {
        $results = [];

        foreach ($operations as $i => $op) {
            $cmd = strtoupper($op[0] ?? '');
            $key = $op[1] ?? '';

            try {
                switch ($cmd) {
                    case 'GET':
                        $results[$i] = $this->storage->fcall('GNODE_CACHE_GET', [], [$key, $this->siteId]);
                        break;
                    case 'SET':
                        $value = $op[2] ?? '';
                        $ttl = isset($op[3]) ? (int)$op[3] : 0;
                        $results[$i] = $this->storage->fcall('GNODE_CACHE_SET', [], [$key, $value, $ttl, $this->siteId]);
                        break;
                    case 'DEL':
                        $results[$i] = $this->storage->fcall('GNODE_CACHE_DEL', [], [$key, $this->siteId]);
                        break;
                    case 'EXISTS':
                        $results[$i] = $this->storage->fcall('GNODE_CACHE_EXISTS', [], [$key, $this->siteId]);
                        break;
                    case 'INCR':
                        $results[$i] = $this->storage->fcall('GNODE_CACHE_INCR', [], [$key, 1, $this->siteId]);
                        break;
                    case 'DECR':
                        $results[$i] = $this->storage->fcall('GNODE_CACHE_DECR', [], [$key, 1, $this->siteId]);
                        break;
                    case 'HGET':
                        $field = $op[2] ?? '';
                        $fullKey = "{{$this->siteId}}:{$key}";
                        $results[$i] = $this->storage->fcall('GNODE_HASH_HGET', [$fullKey], [$field, $this->siteId]);
                        break;
                    case 'HSET':
                        $field = $op[2] ?? '';
                        $value = $op[3] ?? '';
                        $fullKey = "{{$this->siteId}}:{$key}";
                        $results[$i] = $this->storage->fcall('GNODE_HASH_HSET', [$fullKey], [$field, $value, $this->siteId]);
                        break;
                    default:
                        $results[$i] = ['error' => "Unsupported command: {$cmd}"];
                }
            } catch (\Exception $e) {
                $results[$i] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Execute batch of commands
     *
     * @param array $commands Array of commands to execute
     * @return array Results indexed by command position
     */
    public function executeBatch(array $commands): array
    {
        $startTime = microtime(true);
        $results = [];
        $pendingCommands = [];

        // Check cache for each command
        foreach ($commands as $index => $cmdData) {
            if (isset($cmdData['cmd'])) {
                $command = $cmdData['cmd'];
                $parameters = $cmdData['params'] ?? [];
            } else {
                list($command, $parameters) = $cmdData;
            }

            $cacheKey = $this->getCacheKey($command, $parameters);

            if ($cacheKey && $this->isCommandCacheable($command)) {
                $cached = $this->luaGet($cacheKey);
                if ($cached !== false) {
                    $decoded = $this->safeJsonDecode($cached, true);
                    if ($decoded !== null) {
                        $results[$index] = $decoded;
                        continue;
                    }
                }
            }

            $pendingCommands[$index] = [$command, $parameters];
        }

        // Return immediately if all cached
        if (empty($pendingCommands)) {
            return $results;
        }

        // Send pending commands
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
            $this->luaSet($requestKey, json_encode($request), 10);
        }

        // Add batch notification to compute stream
        $computeStream = $this->getComputeStream();
        $this->storage->xAdd($computeStream, '*', [
            'site_id' => $this->siteId,
            'type' => 'batch',
            'request_ids' => json_encode(array_values($requestIds)),
            'count' => (string)count($requestIds),
            'priority' => 'normal',
            'ts' => (string)(microtime(true) * 1000)
        ]);

        // Poll for responses
        $responses = $this->pollForBatchResponses($requestIds, $this->timeout);

        // Merge responses with cached results
        foreach ($responses as $index => $response) {
            $results[$index] = $response;

            if ($response && isset($response['status']) && $response['status'] === 'ok') {
                list($command, $parameters) = $pendingCommands[$index];
                $cacheKey = $this->getCacheKey($command, $parameters);
                if ($cacheKey) {
                    $ttl = $this->getCacheTTL($command);
                    $this->luaSet($cacheKey, json_encode($response), $ttl);
                }
            }
        }

        // Fill in errors for missing responses
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

    //=========================================================================
    // RATE LIMITING
    //=========================================================================

    /**
     * Check rate limit using GNODE_SERVICE_RATE_LIMIT Lua function
     *
     * @param string $operation Operation identifier
     * @param int $limit Maximum requests allowed
     * @param int $window Time window in seconds
     * @param array $metadata Optional metadata
     * @return array Rate limit result
     */
    public function checkRateLimit(string $operation, int $limit = 100, int $window = 60, array $metadata = []): array
    {
        $origin = $metadata['origin'] ?? 'unknown';

        if (!$this->luaEnabled) {
            return $this->checkRateLimitDirect($operation, $limit, $window);
        }

        try {
            $result = $this->storage->fcall(
                'GNODE_SERVICE_RATE_LIMIT',
                [],
                [$this->siteId, $operation, $limit, $window]
            );

            $allowed = ($result === 1 || $result === '1');

            $rateKey = "ratelimit:{$operation}";
            $currentValue = $this->storage->fcall('GNODE_CACHE_GET', [], [$rateKey, $this->siteId]);
            $current = (int) ($currentValue ?: 0);

            $this->trackMetric('ratelimit:check', 1, array_merge($metadata, [
                'operation' => $operation,
                'allowed' => $allowed,
                'method' => 'lua'
            ]));

            if (!$allowed) {
                $this->trackMetric('ratelimit:exceeded', 1, [
                    'origin' => $origin,
                    'operation' => $operation,
                ]);
            }

            return [
                'allowed' => $allowed,
                'current' => $current,
                'limit' => $limit,
                'remaining' => max(0, $limit - $current),
                'window' => $window,
                'method' => 'lua',
            ];
        } catch (\Exception $e) {
            $this->debug("Lua rate limit fallback: {$e->getMessage()}");
            return $this->checkRateLimitDirect($operation, $limit, $window);
        }
    }

    /**
     * Direct rate limit check using FCALL
     *
     * @param string $operation Operation identifier
     * @param int $limit Maximum requests
     * @param int $window Time window in seconds
     * @return array Rate limit result
     */
    protected function checkRateLimitDirect(string $operation, int $limit, int $window): array
    {
        $rateKey = "ratelimit:{$operation}";

        try {
            $current = $this->storage->fcall('GNODE_CACHE_INCR', [], [$rateKey, 1, $this->siteId]);
            $current = (int) $current;
            $allowed = $current <= $limit;

            return [
                'allowed' => $allowed,
                'current' => $current,
                'limit' => $limit,
                'remaining' => max(0, $limit - $current),
                'window' => $window,
                'method' => 'fcall_fallback'
            ];
        } catch (\Exception $e) {
            $this->debug("FCALL rate limit error: {$e->getMessage()}");
            return [
                'allowed' => true,
                'current' => 0,
                'limit' => $limit,
                'remaining' => $limit,
                'window' => $window,
                'method' => 'error',
            ];
        }
    }

    //=========================================================================
    // CACHE STATISTICS
    //=========================================================================

    /**
     * Get cache statistics using Lua function
     *
     * @return array Stats
     */
    public function getCacheStats(): array
    {
        try {
            $statsJson = $this->storage->fcall('GNODE_CACHE_STATS', [], [$this->siteId]);
            $stats = $this->safeJsonDecode($statsJson, true);
            return $stats ?? [];
        } catch (\Exception $e) {
            $this->debug("Lua stats error: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Invalidate cache
     *
     * @param string|null $pattern Key pattern
     * @return int Number of keys deleted
     */
    public function invalidateCache(?string $pattern = null): int
    {
        if ($pattern === null) {
            $pattern = "{$this->siteId}:cache:*";
        } elseif (strpos($pattern, ':') === false) {
            $pattern = "{$this->siteId}:cache:{$pattern}";
        }

        // Use FCALL-based key scanning
        $keys = $this->keys($pattern);
        $deleted = 0;

        foreach ($keys as $key) {
            if ($this->luaDel($key)) {
                $deleted++;
            }
        }

        $this->trackMetric('cache_invalidated', $deleted, [
            'pattern' => $pattern,
        ]);

        $this->debug("Invalidated {$deleted} keys matching pattern: {$pattern}");

        return $deleted;
    }

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
    public function geometricStoreTopology(array $topology, int $dimensions = 8): bool
    {
        $response = $this->sendCommand('geometric_store_topology', [
            'data' => $topology
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return true;
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new gNodeException($response['error'] ?? 'Unknown error storing topology');
        }

        return false;
    }

    /**
     * Discover services based on geometric requirements
     *
     * @param array $capabilities Required capabilities
     * @param int $limit Maximum number of services to return
     * @param int $dimensions Number of dimensions to consider
     * @param int $distance Maximum distance threshold
     * @return array Array of matching services
     */
    public function geometricDiscover(array $capabilities, int $limit = 10, int $dimensions = 0, int $distance = 0): array
    {
        if (!isset($capabilities[0]) && count($capabilities) > 0) {
            $capabilityNames = array_keys($capabilities);
        } else {
            $capabilityNames = $capabilities;
        }

        $response = $this->sendCommand('geometric_discover', [
            'capabilities' => $capabilityNames,
            'limit' => $limit,
            'dimensions' => $dimensions,
            'distance' => $distance
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result']['services'] ?? [];
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new gNodeException($response['error'] ?? 'Unknown error in geometric discovery');
        }

        return [];
    }

    /**
     * Discover services using range query operators
     *
     * @param array $criteria Associative array of dimension => constraint
     * @return array Array of service IDs matching all criteria
     */
    public function discoverRange(array $criteria): array
    {
        $requirements = [];

        foreach ($criteria as $dimension => $constraint) {
            if ($dimension === 'dimensions') {
                foreach ($constraint as $dynDim => $dynConstraint) {
                    $dimIndex = $this->getDimensionIndex($dynDim);
                    $requirements[(string)$dimIndex] = $this->normalizeConstraint($dynConstraint);
                }
            } else {
                $dimIndex = $this->getDimensionIndex($dimension);
                $requirements[(string)$dimIndex] = $this->normalizeConstraint($constraint);
            }
        }

        $result = $this->executeCommand('geometric_discover_range', [
            'requirements' => $requirements
        ]);

        return $result ?? [];
    }

    /**
     * Get registered capability dimensions
     *
     * @return array Map of capability names to dimensions
     */
    public function getCapabilityDimensions(): array
    {
        $response = $this->sendCommand('geometric_dimensions', []);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            $this->capabilityDimensions = $response['result'] ?? [];
            return $this->capabilityDimensions;
        }

        return $this->capabilityDimensions;
    }

    /**
     * Normalize constraint to operator format
     *
     * @param mixed $constraint Constraint value or operator array
     * @return array Normalized operator array
     */
    protected function normalizeConstraint($constraint): array
    {
        if (!is_array($constraint)) {
            return ['eq' => $constraint];
        }
        return $constraint;
    }

    /**
     * Get dimension index for a dimension name
     *
     * @param string $name Dimension name
     * @return int Dimension index
     */
    protected function getDimensionIndex(string $name): int
    {
        // 23-dimension semantic topology schema (v2.0)
        // Discovery dims 0-18: used for bucket key hashing (76 chars)
        // Storage-only dims 19-22: visual topology + temporal
        $static = [
            // Layer 1: Interface Identity (0-3)
            'protocol' => 0,
            'native_format' => 1,
            'api_version' => 2,
            'contract_stability' => 3,
            // Layer 2: Access Control (4-6)
            'clearance_required' => 4,
            'auth_method' => 5,
            'data_sensitivity' => 6,
            // Layer 3: Service Scope (7)
            'service_scope' => 7,
            // Layer 4: Functional Domain (8-10)
            'domain_primary' => 8,
            'domain_secondary' => 9,
            'specialization' => 10,
            // Layer 5: Performance Profile (11-13)
            'throughput_tier' => 11,
            'latency_class' => 12,
            'reliability_tier' => 13,
            // Layer 6: Workflow Context (14-15)
            'pipeline_stage' => 14,
            'execution_priority' => 15,
            // Layer 7: Runtime State (16)
            'current_load' => 16,
            // Layer 8: Classification (17-18)
            'service_tier' => 17,
            'environment' => 18,
            // Layer 9: Visual Topology (19-21)
            'user_x' => 19,
            'user_y' => 20,
            'user_z' => 21,
            // Layer 10: Temporal (22)
            'registration_order' => 22,
        ];

        if (isset($static[$name])) {
            return $static[$name];
        }

        $topology = $this->getTopology();
        if (isset($topology['capability_dimensions'][$name])) {
            return (int)$topology['capability_dimensions'][$name];
        }

        throw new \InvalidArgumentException("Unknown dimension: {$name}");
    }

    /**
     * Get full topology
     *
     * Reads the derived snapshot hash {topology_namespace}:gnode:topology:services
     * (field = service_id, value = {point, metadata}) — a read-projection of the
     * canonical per-service capability entities, maintained on register/deregister.
     * Default namespace is "geodineum".
     *
     * @return array Full topology, shaped as ['services' => [id => {point, metadata}]]
     */
    public function getTopology(): array
    {
        static $cachedTopology = null;

        if ($cachedTopology !== null) {
            return $cachedTopology;
        }

        try {
            $snapshotKey = "{{$this->topologyNamespace}}:gnode:topology:services";
            $entries = $this->luaHGetAll($snapshotKey);
            if (!empty($entries)) {
                $services = [];
                foreach ($entries as $id => $json) {
                    $services[$id] = is_array($json)
                        ? $json
                        : ($this->safeJsonDecode($json, true) ?? []);
                }
                $cachedTopology = ['services' => $services];
                return $cachedTopology;
            }
        } catch (\Exception $e) {
            $this->debug('Failed to read topology snapshot: ' . $e->getMessage());
        }

        $cachedTopology = ['services' => []];
        return $cachedTopology;
    }

    //=========================================================================
    // BUNDLE OPERATIONS
    //=========================================================================

    /**
     * Get entire site bundle
     *
     * @param bool $decompress Whether to decompress
     * @return array|string|null Bundle data
     */
    public function getBundle(bool $decompress = true)
    {
        $startTime = microtime(true);
        $bundleKey = "{{$this->siteId}}:gnode:bundle:full";

        $compressed = $this->luaGet($bundleKey);

        if ($compressed === false) {
            $this->debug("Bundle MISS, requesting rebuild");
            $this->trackMetric('bundle_miss', 1);
            $this->requestBundleRebuild();
            return null;
        }

        $latency = round((microtime(true) - $startTime) * 1000, 3);
        $this->trackMetric('bundle_hit', 1, [
            'latency_ms' => $latency,
            'compressed_size' => strlen($compressed)
        ]);

        if (!$decompress) {
            return $compressed;
        }

        $json = @gzdecode($compressed);
        if ($json === false) {
            $this->debug("Bundle decompression failed");
            $this->trackMetric('bundle_decompress_error', 1);
            return null;
        }

        $bundle = $this->safeJsonDecode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->debug("Bundle JSON parse error: " . json_last_error_msg());
            $this->trackMetric('bundle_parse_error', 1);
            return null;
        }

        return $bundle;
    }

    /**
     * Request gNode daemon to rebuild bundle immediately
     *
     * @param string $priority "high" or "normal"
     */
    protected function requestBundleRebuild(string $priority = 'high'): void
    {
        $this->publishInvalidationEvent('bundle_rebuild_requested', 'cache_miss', [
            'rebuild_priority' => $priority
        ]);
    }

    /**
     * Invalidate bundle
     *
     * @return bool True if bundle was deleted
     */
    public function invalidateBundle(): bool
    {
        $bundleKey = "{{$this->siteId}}:gnode:bundle:full";
        $result = $this->luaDel($bundleKey);

        $this->publishInvalidationEvent('bundle_invalidated', 'manual_invalidation');

        $this->trackMetric('bundle_invalidated', 1);

        return $result;
    }

    /**
     * Invalidate a specific manifest bundle
     *
     * Deletes the pre-built bundle and its metadata, then signals the daemon
     * to rebuild via the invalidation PubSub channel.
     *
     * @param string $manifestId Manifest identifier
     * @return bool True if bundle was deleted
     */
    public function invalidateManifestBundle(string $manifestId): bool
    {
        $bundleKey = "{{$this->siteId}}:gnode:bundle:{$manifestId}";
        $metaKey = "{{$this->siteId}}:gnode:bundle:{$manifestId}:meta";

        $result = $this->luaDel($bundleKey);
        $this->luaDel($metaKey);

        $this->publishInvalidationEvent('manifest_bundle_invalidated', 'manual_invalidation', [
            'manifest_id' => $manifestId
        ]);

        $this->trackMetric('manifest_bundle_invalidated', 1, ['manifest_id' => $manifestId]);

        return $result;
    }

    /**
     * Publish an invalidation event to the daemon via PubSub
     *
     * PubSub channels do NOT use hash tags (no braces around site_id).
     *
     * @param string $event Event name
     * @param string $reason Reason for invalidation
     * @param array $extra Additional event data
     */
    protected function publishInvalidationEvent(string $event, string $reason, array $extra = []): void
    {
        $this->storage->publish("{$this->siteId}:events:invalidate", json_encode(array_merge([
            'event' => $event,
            'reason' => $reason,
            'timestamp' => microtime(true),
            'rebuild_priority' => 'high'
        ], $extra)));
    }

    //=========================================================================
    // ASSET & MANIFEST OPERATIONS (FCALL — key-based, no stream roundtrip)
    //=========================================================================

    /**
     * Create or update a bundle manifest definition
     *
     * Manifests define what assets are bundled together. The daemon's background
     * builder reads manifests and assembles pre-built bundles for instant retrieval.
     *
     * @param string $manifestId Unique manifest identifier (e.g., 'nav:about', 'face:home')
     * @param array $manifest Manifest definition with layout, slots, sections, build_options
     * @return array Result with manifest_id, layout, slot_count, stored_at
     * @throws Exception\gNodeException On storage failure
     */
    public function manifestSet(string $manifestId, array $manifest): array
    {
        $result = $this->storage->fcall(
            'GNODE_ASSET_MANIFEST_SET',
            [],
            [$manifestId, json_encode($manifest, JSON_UNESCAPED_SLASHES), $this->siteId]
        );

        if ($result === false) {
            throw new Exception\gNodeException("Failed to set manifest '{$manifestId}': GNODE_ASSET_MANIFEST_SET function not available");
        }

        $decoded = is_string($result) ? $this->safeJsonDecode($result, true) : $result;
        if (!is_array($decoded)) {
            $decoded = ['ok' => true, 'manifest_id' => $manifestId];
        }

        $this->publishInvalidationEvent('manifest_updated', 'manifest_set', [
            'manifest_id' => $manifestId
        ]);

        $this->trackMetric('manifest_set', 1, ['manifest_id' => $manifestId]);
        $this->debug("Manifest set: {$manifestId}");

        return $decoded;
    }

    /**
     * Retrieve a manifest definition
     *
     * @param string $manifestId Manifest identifier
     * @return array|null Manifest definition or null if not found
     */
    public function manifestGet(string $manifestId): ?array
    {
        $result = $this->storage->fcall(
            'GNODE_ASSET_MANIFEST_GET',
            [],
            [$manifestId, $this->siteId]
        );

        if ($result === null || $result === false) {
            return null;
        }

        $decoded = is_string($result) ? $this->safeJsonDecode($result, true) : $result;

        // Lua returns {ok:false, error:...} for not-found
        if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
            return null;
        }

        return is_array($decoded) ? ($decoded['manifest'] ?? $decoded) : null;
    }

    /**
     * Delete a manifest definition
     *
     * Also invalidates any pre-built bundle for this manifest.
     *
     * @param string $manifestId Manifest identifier
     * @return bool True if deleted
     */
    public function manifestDelete(string $manifestId): bool
    {
        $result = $this->storage->fcall(
            'GNODE_ASSET_MANIFEST_DELETE',
            [],
            [$manifestId, $this->siteId]
        );

        $this->invalidateManifestBundle($manifestId);
        $this->debug("Manifest deleted: {$manifestId}");

        if (is_string($result)) {
            $decoded = $this->safeJsonDecode($result, true);
            return is_array($decoded) && !empty($decoded['ok']);
        }

        return $result === 1 || $result === true;
    }

    /**
     * List all manifest definitions for this site
     *
     * @return array Array of manifest summaries
     */
    public function manifestList(): array
    {
        $result = $this->storage->fcall(
            'GNODE_ASSET_MANIFEST_LIST',
            [],
            [$this->siteId]
        );

        if ($result === null || $result === false) {
            return [];
        }

        $decoded = is_string($result) ? $this->safeJsonDecode($result, true) : $result;
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Check bundle build status for a manifest
     *
     * Returns whether the bundle exists, if it's stale (manifest updated after
     * last build), and build metadata (size, asset count, TTL).
     *
     * @param string $manifestId Manifest identifier
     * @return array Status with built, stale, built_at, size, compressed_size, asset_count, ttl
     */
    public function bundleBuildStatus(string $manifestId): array
    {
        $result = $this->storage->fcall(
            'GNODE_ASSET_BUILD_STATUS',
            [],
            [$manifestId, $this->siteId]
        );

        if ($result === null || $result === false) {
            return ['manifest_id' => $manifestId, 'built' => false, 'stale' => true];
        }

        $decoded = is_string($result) ? $this->safeJsonDecode($result, true) : $result;
        return is_array($decoded) ? $decoded : ['manifest_id' => $manifestId, 'built' => false, 'stale' => true];
    }

    /**
     * Store an asset in ValKey for bundle assembly
     *
     * Assets are individual content pieces (HTML, CSS, JS) that manifests reference.
     * The daemon's bundle builder reads assets by ID when assembling bundles.
     *
     * @param string $assetId Unique asset identifier (e.g., 'wp_page_42', 'css_main')
     * @param string $content Asset content
     * @param string $contentType MIME type (text/html, text/css, application/javascript)
     * @param int $ttl Time-to-live in seconds (0 = no expiry)
     * @return array Result with asset_id, size, content_type, stored_at
     * @throws Exception\gNodeException On storage failure
     */
    public function assetStore(string $assetId, string $content, string $contentType = 'text/html', int $ttl = 0): array
    {
        $result = $this->storage->fcall(
            'GNODE_ASSET_STORE',
            [],
            [$assetId, $content, $contentType, $ttl, $this->siteId, '1', 'false']
        );

        if ($result === false) {
            throw new Exception\gNodeException("Failed to store asset '{$assetId}': GNODE_ASSET_STORE function not available");
        }

        $decoded = is_string($result) ? $this->safeJsonDecode($result, true) : $result;
        if (!is_array($decoded)) {
            $decoded = ['ok' => true, 'asset_id' => $assetId, 'size' => strlen($content)];
        }

        $this->trackMetric('asset_store', 1, ['asset_id' => $assetId, 'size' => strlen($content)]);
        $this->debug("Asset stored: {$assetId} ({$contentType}, " . strlen($content) . " bytes)");

        return $decoded;
    }

    /**
     * Retrieve an asset by ID
     *
     * @param string $assetId Asset identifier
     * @return array|null Asset data with content, content_type, metadata, or null
     */
    public function assetGet(string $assetId): ?array
    {
        $result = $this->storage->fcall(
            'GNODE_ASSET_GET',
            [],
            [$assetId, $this->siteId]
        );

        if ($result === null || $result === false) {
            return null;
        }

        $decoded = is_string($result) ? $this->safeJsonDecode($result, true) : $result;
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Delete an asset
     *
     * @param string $assetId Asset identifier
     * @return bool True if deleted
     */
    public function assetDelete(string $assetId): bool
    {
        $result = $this->storage->fcall(
            'GNODE_ASSET_DELETE',
            [],
            [$assetId, $this->siteId]
        );

        $this->debug("Asset deleted: {$assetId}");

        if (is_string($result)) {
            $decoded = $this->safeJsonDecode($result, true);
            return is_array($decoded) && !empty($decoded['ok']);
        }

        return $result === 1 || $result === true;
    }

    /**
     * List assets for this site
     *
     * @param string|null $contentType Optional content-type filter (e.g., 'text/html')
     * @return array Array of asset summaries
     */
    public function assetList(?string $contentType = null): array
    {
        $result = $this->storage->fcall(
            'GNODE_ASSET_LIST',
            [],
            [$this->siteId, $contentType ?? '']
        );

        if ($result === null || $result === false) {
            return [];
        }

        $decoded = is_string($result) ? $this->safeJsonDecode($result, true) : $result;
        return is_array($decoded) ? $decoded : [];
    }

    //=========================================================================
    // DEPENDENCY TOPOLOGY (gNode-Multi-Topology-Pro: gnode_dependency.lua)
    //=========================================================================

    /**
     * Register a service in the dependency topology
     *
     * @param string $topologyKey Topology key
     * @param array $serviceDefinition Service with id, provides[], requires[], position
     * @return array Registration result with service_id, position, voxel_key
     */
    public function depRegister(string $topologyKey, array $serviceDefinition): array
    {
        return $this->fcallDecode('GNODE_DEP_REGISTER', [$topologyKey], [json_encode($serviceDefinition, JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * Validate a dependency edge between two services
     *
     * @param string $topologyKey Topology key
     * @param string $fromId Source service ID
     * @param string $toId Target service ID
     * @return array Validation result with valid, reason, from_z, to_z
     */
    public function depValidate(string $topologyKey, string $fromId, string $toId): array
    {
        return $this->fcallDecode('GNODE_DEP_VALIDATE', [$topologyKey], [$fromId, $toId]);
    }

    /**
     * Get topologically sorted load order for the dependency graph
     *
     * @param string $topologyKey Topology key
     * @param bool $asLevels Return grouped by Z-level (true) or flat order (false)
     * @return array Load order with levels[] or order[]
     */
    public function depLoadOrder(string $topologyKey, bool $asLevels = false): array
    {
        return $this->fcallDecode('GNODE_DEP_LOAD_ORDER', [$topologyKey], [$asLevels ? '1' : '0']);
    }

    /**
     * Find providers of a capability
     *
     * @param string $topologyKey Topology key
     * @param string $capability Capability name
     * @param string $forService Service requesting the capability
     * @return array Providers list
     */
    public function depProviders(string $topologyKey, string $capability, string $forService = ''): array
    {
        return $this->fcallDecode('GNODE_DEP_PROVIDERS', [$topologyKey], [$capability, $forService]);
    }

    /**
     * Detect missing dependencies for a service
     *
     * @param string $topologyKey Topology key
     * @param string $serviceId Service ID
     * @return array Missing deps with is_satisfied flag
     */
    public function depMissing(string $topologyKey, string $serviceId): array
    {
        return $this->fcallDecode('GNODE_DEP_MISSING', [$topologyKey], [$serviceId]);
    }

    /**
     * Get a service from the dependency graph
     *
     * @param string $topologyKey Topology key
     * @param string $serviceId Service ID
     * @return array|null Service data or null
     */
    public function depGetService(string $topologyKey, string $serviceId): ?array
    {
        $result = $this->fcallDecode('GNODE_DEP_GET_SERVICE', [$topologyKey], [$serviceId]);
        return !empty($result) ? $result : null;
    }

    /**
     * Deregister a service from the dependency graph
     *
     * @param string $topologyKey Topology key
     * @param string $serviceId Service ID
     * @return bool True if deregistered
     */
    public function depDeregister(string $topologyKey, string $serviceId): bool
    {
        $result = $this->fcallDecode('GNODE_DEP_DEREGISTER', [$topologyKey], [$serviceId]);
        return !empty($result['success']);
    }

    /**
     * Follow a dependency chain from a service
     *
     * @param string $topologyKey Topology key
     * @param string $serviceId Starting service ID
     * @param string $direction 'up' (dependents) or 'down' (dependencies)
     * @return array Chain with service_id, direction, chain[], chain_length
     */
    public function depChain(string $topologyKey, string $serviceId, string $direction = 'down'): array
    {
        return $this->fcallDecode('GNODE_DEP_CHAIN', [$topologyKey], [$serviceId, $direction]);
    }

    /**
     * Get dependency topology statistics
     *
     * @param string $topologyKey Topology key
     * @return array Stats with service_count, edge_count, z_distribution
     */
    public function depStats(string $topologyKey): array
    {
        return $this->fcallDecode('GNODE_DEP_STATS', [$topologyKey]);
    }

    //=========================================================================
    // TOPOLOGY REGISTRY (gNode-Multi-Topology-Pro: gnode_topology_registry.lua)
    //=========================================================================

    /**
     * Create/initialize the topology registry for a site
     *
     * @return array Result with site_id, status, topology_count
     */
    public function registryCreate(): array
    {
        return $this->fcallDecode('GNODE_REGISTRY_CREATE', [$this->siteKey('topology:registry')]);
    }

    /**
     * Add a named topology to the registry
     *
     * @param array $topologyDefinition Topology with name, type, dimensions, description
     * @return array Result with topology_id, name, type, data_key
     */
    public function registryAdd(array $topologyDefinition): array
    {
        return $this->fcallDecode('GNODE_REGISTRY_ADD', [$this->siteKey('topology:registry')], [json_encode($topologyDefinition, JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * Get a topology from the registry
     *
     * @param string $topologyId Topology ID
     * @return array|null Topology definition or null
     */
    public function registryGet(string $topologyId): ?array
    {
        $result = $this->fcallDecode('GNODE_REGISTRY_GET', [$this->siteKey('topology:registry')], [$topologyId]);
        return !empty($result) ? $result : null;
    }

    /**
     * List topologies in the registry
     *
     * @param string $filterType Optional type filter
     * @return array Topology list
     */
    public function registryList(string $filterType = ''): array
    {
        return $this->fcallDecode('GNODE_REGISTRY_LIST', [$this->siteKey('topology:registry')], [$filterType]);
    }

    /**
     * Remove a topology from the registry
     *
     * @param string $topologyId Topology ID
     * @param bool $deleteData Also delete topology data
     * @return bool True if removed
     */
    public function registryRemove(string $topologyId, bool $deleteData = false): bool
    {
        $result = $this->fcallDecode('GNODE_REGISTRY_REMOVE', [$this->siteKey('topology:registry')], [$topologyId, $deleteData ? '1' : '0']);
        return !empty($result['success']);
    }

    /**
     * Map an entity to a topology (cross-reference)
     *
     * @param string $entityId Entity ID
     * @param string $topologyId Topology ID
     * @param string $entityKeyInTopo Entity's key within the topology
     * @return array Result with entity_id, topology_id, total_topologies
     */
    public function registryMapEntity(string $entityId, string $topologyId, string $entityKeyInTopo): array
    {
        return $this->fcallDecode('GNODE_REGISTRY_MAP_ENTITY', [$this->siteKey('topology:registry')], [$entityId, $topologyId, $entityKeyInTopo]);
    }

    /**
     * Find all topologies containing an entity
     *
     * @param string $entityId Entity ID
     * @return array Topologies list with topology_id, name, type
     */
    public function registryEntityTopologies(string $entityId): array
    {
        return $this->fcallDecode('GNODE_REGISTRY_ENTITY_TOPOLOGIES', [$this->siteKey('topology:registry')], [$entityId]);
    }

    /**
     * Get registry statistics
     *
     * @return array Stats with topology_count, entity_count, cross_refs
     */
    public function registryStats(): array
    {
        return $this->fcallDecode('GNODE_REGISTRY_STATS', [$this->siteKey('topology:registry')]);
    }

    /**
     * Update a topology definition in the registry
     *
     * @param string $topologyId Topology ID
     * @param array $updates Fields to update
     * @return array Result with topology_id, updated_fields
     */
    public function registryUpdate(string $topologyId, array $updates): array
    {
        return $this->fcallDecode('GNODE_REGISTRY_UPDATE', [$this->siteKey('topology:registry')], [$topologyId, json_encode($updates, JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * Search topologies by name or metadata
     *
     * @param string $query Search query
     * @param string $searchType Search type ('name', 'type', 'entity')
     * @return array Search results
     */
    public function registrySearch(string $query, string $searchType = 'name'): array
    {
        return $this->fcallDecode('GNODE_REGISTRY_SEARCH', [$this->siteKey('topology:registry')], [$query, $searchType]);
    }

    //=========================================================================
    // CROSS-TOPOLOGY (gNode-Multi-Topology-Pro: gnode_cross_topology.lua)
    //=========================================================================

    /**
     * Find entities present in ALL given topologies
     *
     * @param array $topologyIds Topology IDs
     * @return array Intersection result with entities[]
     */
    public function crossIntersection(array $topologyIds): array
    {
        return $this->fcallDecode('GNODE_CROSS_INTERSECTION', [$this->siteKey('topology:cross')], [json_encode($topologyIds)]);
    }

    /**
     * Union of entities across topologies
     *
     * @param array $topologyIds Topology IDs
     * @return array Union result with entities[]
     */
    public function crossUnion(array $topologyIds): array
    {
        return $this->fcallDecode('GNODE_CROSS_UNION', [$this->siteKey('topology:cross')], [json_encode($topologyIds)]);
    }

    /**
     * Unified view of an entity across all topologies
     *
     * @param string $entityId Entity ID
     * @return array Views with topology_id, topology_name, entity_data, edges
     */
    public function crossEntityView(string $entityId): array
    {
        return $this->fcallDecode('GNODE_CROSS_ENTITY_VIEW', [$this->siteKey('topology:cross')], [$entityId]);
    }

    /**
     * Find related entities across topology boundaries
     *
     * @param string $entityId Entity ID
     * @param string $direction 'outgoing', 'incoming', or 'both'
     * @return array Related entities
     */
    public function crossFindRelated(string $entityId, string $direction = 'both'): array
    {
        return $this->fcallDecode('GNODE_CROSS_FIND_RELATED', [$this->siteKey('topology:cross')], [$entityId, $direction]);
    }

    /**
     * Multi-topology query
     *
     * @param array $query Query with topologies[], entity_filter, edge_filter
     * @return array Query results
     */
    public function crossMultiQuery(array $query): array
    {
        return $this->fcallDecode('GNODE_CROSS_MULTI_QUERY', [$this->siteKey('topology:cross')], [json_encode($query, JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * Compare an entity's position across topologies
     *
     * @param string $entityId Entity ID
     * @return array Positions and correlations across topologies
     */
    public function crossComparePositions(string $entityId): array
    {
        return $this->fcallDecode('GNODE_CROSS_COMPARE_POSITIONS', [$this->siteKey('topology:cross')], [$entityId]);
    }

    /**
     * Diff two topologies — entities only in one or both
     *
     * @param string $topologyId1 First topology
     * @param string $topologyId2 Second topology
     * @return array Diff with only_in_1, only_in_2, in_both
     */
    public function crossTopologyDiff(string $topologyId1, string $topologyId2): array
    {
        return $this->fcallDecode('GNODE_CROSS_TOPOLOGY_DIFF', [$this->siteKey('topology:cross')], [$topologyId1, $topologyId2]);
    }

    //=========================================================================
    // FEATURE FLAGS & A/B TESTING (gNode-Observability-Pro: gnode_features.lua)
    //=========================================================================

    /**
     * Define or update a feature flag
     *
     * @param string $featureName Feature identifier
     * @param bool $enabled Whether the feature is enabled
     * @param int $rolloutPercentage Gradual rollout percentage (0-100)
     * @param string $description Feature description
     * @param array $targetingRules Targeting rules for conditional activation
     * @return array Feature definition
     */
    public function featureSet(string $featureName, bool $enabled = true, int $rolloutPercentage = 100, string $description = '', array $targetingRules = []): array
    {
        return $this->fcallDecode('GNODE_FEATURE_SET', [$this->siteKey("features:flag:{$featureName}")], [
            $this->siteId, $enabled ? '1' : '0', (string) $rolloutPercentage, $description,
            !empty($targetingRules) ? json_encode($targetingRules) : ''
        ]);
    }

    /**
     * Evaluate a feature flag for a specific user
     *
     * @param string $featureName Feature identifier
     * @param string $userId User identifier
     * @param array $userContext Additional context for targeting rules
     * @return array Evaluation result with enabled, reason, bucket
     */
    public function featureEvaluate(string $featureName, string $userId, array $userContext = []): array
    {
        return $this->fcallDecode('GNODE_FEATURE_EVALUATE', [$this->siteKey("features:flag:{$featureName}")], [
            $this->siteId, $userId, !empty($userContext) ? json_encode($userContext) : ''
        ]);
    }

    /**
     * List all feature flags
     *
     * @return array Feature flags map
     */
    public function featureList(): array
    {
        return $this->fcallDecode('GNODE_FEATURE_LIST', [$this->siteKey('features:flags')], [$this->siteId]);
    }

    /**
     * Delete a feature flag
     *
     * @param string $featureName Feature identifier
     * @return bool True if deleted
     */
    public function featureDelete(string $featureName): bool
    {
        $result = $this->fcallDecode('GNODE_FEATURE_DELETE', [$this->siteKey("features:flag:{$featureName}")], [$this->siteId]);
        return !empty($result['deleted']);
    }

    /**
     * Create an A/B experiment
     *
     * @param string $experimentName Experiment identifier
     * @param array $variants Variant definitions
     * @param string $description Experiment description
     * @return array Experiment definition
     */
    public function experimentCreate(string $experimentName, array $variants, string $description = ''): array
    {
        return $this->fcallDecode('GNODE_EXPERIMENT_CREATE', [$this->siteKey("features:experiment:{$experimentName}")], [
            $this->siteId, json_encode($variants), $description
        ]);
    }

    /**
     * Assign a user to an experiment variant (deterministic hash)
     *
     * @param string $experimentName Experiment identifier
     * @param string $userId User identifier
     * @return array Assignment with experiment, variant, cached
     */
    public function experimentAssign(string $experimentName, string $userId): array
    {
        return $this->fcallDecode('GNODE_EXPERIMENT_ASSIGN', [$this->siteKey("features:experiment:{$experimentName}")], [$this->siteId, $userId]);
    }

    /**
     * Record a conversion event for an experiment
     *
     * @param string $experimentName Experiment identifier
     * @param string $userId User identifier
     * @param string $conversionType Conversion type (e.g., 'click', 'purchase')
     * @return array Conversion result
     */
    public function experimentConvert(string $experimentName, string $userId, string $conversionType = 'default'): array
    {
        return $this->fcallDecode('GNODE_EXPERIMENT_CONVERT', [$this->siteKey("features:experiment:{$experimentName}")], [$this->siteId, $userId, $conversionType]);
    }

    /**
     * Get experiment results with conversion rates per variant
     *
     * @param string $experimentName Experiment identifier
     * @return array Results with variant→{assignments, conversions, conversion_rate}
     */
    public function experimentResults(string $experimentName): array
    {
        return $this->fcallDecode('GNODE_EXPERIMENT_RESULTS', [$this->siteKey("features:experiment:{$experimentName}")], [$this->siteId]);
    }

    //=========================================================================
    // SESSION MANAGEMENT (gNode-Observability-Pro: gnode_features.lua)
    //=========================================================================

    /**
     * Create a server-side session
     *
     * @param string $userId User identifier
     * @param int $ttl Session TTL in seconds
     * @param array $data Session data
     * @return array Session with session_id, expires_at
     */
    public function sessionCreate(string $userId, int $ttl = 3600, array $data = []): array
    {
        return $this->fcallDecode('GNODE_SESSION_CREATE', [$this->siteKey('sessions:create')], [
            $this->siteId, $userId, (string) $ttl, !empty($data) ? json_encode($data) : '{}'
        ]);
    }

    /**
     * Retrieve a session
     *
     * @param string $sessionId Session identifier
     * @param bool $extend Extend TTL on access (sliding window)
     * @return array|null Session data or null if invalid/expired
     */
    public function sessionGet(string $sessionId, bool $extend = true): ?array
    {
        $result = $this->fcallDecode('GNODE_SESSION_GET', [$this->siteKey("sessions:{$sessionId}")], [$this->siteId, $extend ? '1' : '0']);
        return (!empty($result['valid'])) ? $result : null;
    }

    /**
     * Update session data
     *
     * @param string $sessionId Session identifier
     * @param array $data Updated session data
     * @return bool True if updated
     */
    public function sessionUpdate(string $sessionId, array $data): bool
    {
        $result = $this->fcallDecode('GNODE_SESSION_UPDATE', [$this->siteKey("sessions:{$sessionId}")], [$this->siteId, json_encode($data)]);
        return !empty($result['updated']);
    }

    /**
     * Destroy a session
     *
     * @param string $sessionId Session identifier
     * @return bool True if destroyed
     */
    public function sessionDestroy(string $sessionId): bool
    {
        $result = $this->fcallDecode('GNODE_SESSION_DESTROY', [$this->siteKey("sessions:{$sessionId}")], [$this->siteId]);
        return !empty($result['destroyed']);
    }

    /**
     * List all sessions for a user
     *
     * @param string $userId User identifier
     * @return array Sessions list
     */
    public function sessionListUser(string $userId): array
    {
        return $this->fcallDecode('GNODE_SESSION_LIST_USER', [$this->siteKey('sessions:list')], [$this->siteId, $userId]);
    }

    //=========================================================================
    // DISTRIBUTED TRACING (gNode-Observability-Pro: gnode_tracing.lua)
    //=========================================================================

    /**
     * Start a new trace
     *
     * @param string $serviceName Service name (e.g., 'gCore', 'gCube')
     * @param string $operationName Operation (e.g., 'page_render', 'api_call')
     * @param float $sampleRate Sampling rate 0.0-1.0
     * @param int $ttl Trace TTL in seconds
     * @return array Trace with trace_id, span_id, sampled, trace_context
     */
    public function traceStart(string $serviceName, string $operationName, float $sampleRate = 1.0, int $ttl = 3600): array
    {
        return $this->fcallDecode('GNODE_TRACE_START', [], [
            $this->siteId, $serviceName, $operationName, (string) $sampleRate, (string) $ttl
        ]);
    }

    /**
     * Start a child span within a trace
     *
     * @param string $traceId Parent trace ID
     * @param string $parentSpanId Parent span ID
     * @param string $serviceName Service name
     * @param string $operationName Operation name
     * @param int $ttl Span TTL
     * @return array Span with trace_id, span_id, parent_span_id
     */
    public function traceSpanStart(string $traceId, string $parentSpanId, string $serviceName, string $operationName, int $ttl = 3600): array
    {
        return $this->fcallDecode('GNODE_TRACE_SPAN_START', [], [
            $this->siteId, $traceId, $parentSpanId, $serviceName, $operationName, (string) $ttl
        ]);
    }

    /**
     * End a span
     *
     * @param string $traceId Trace ID
     * @param string $spanId Span ID
     * @param string $status 'ok', 'error', 'timeout'
     * @param string $errorMessage Error message if status is 'error'
     * @return array Result with duration_us, status
     */
    public function traceSpanEnd(string $traceId, string $spanId, string $status = 'ok', string $errorMessage = ''): array
    {
        return $this->fcallDecode('GNODE_TRACE_SPAN_END', [], [
            $this->siteId, $traceId, $spanId, $status, $errorMessage
        ]);
    }

    /**
     * Get a complete trace with all spans
     *
     * @param string $traceId Trace ID
     * @return array Trace data with spans
     */
    public function traceGet(string $traceId): array
    {
        return $this->fcallDecode('GNODE_TRACE_GET', [], [$this->siteId, $traceId]);
    }

    /**
     * Search traces by service name and time range
     *
     * @param string $serviceName Service to search
     * @param string $startTime ISO-8601 start time
     * @param string $endTime ISO-8601 end time
     * @param int $limit Max results
     * @return array Matching traces
     */
    public function traceSearch(string $serviceName, string $startTime = '', string $endTime = '', int $limit = 20): array
    {
        return $this->fcallDecode('GNODE_TRACE_SEARCH', [], [
            $this->siteId, $serviceName, $startTime, $endTime, (string) $limit
        ]);
    }

    /**
     * Get tracing statistics
     *
     * @return array Metrics counts
     */
    public function traceStats(): array
    {
        return $this->fcallDecode('GNODE_TRACE_STATS', [], [$this->siteId]);
    }

    /**
     * Add a log event to a span
     *
     * @param string $traceId Trace ID
     * @param string $spanId Span ID
     * @param string $message Log message
     * @param string $level Log level (info, warn, error)
     * @param int $ttl Event TTL
     * @return array Result with logged, timestamp
     */
    public function traceSpanLog(string $traceId, string $spanId, string $message, string $level = 'info', int $ttl = 3600): array
    {
        return $this->fcallDecode('GNODE_TRACE_SPAN_LOG', [], [
            $this->siteId, $traceId, $spanId, $message, $level, (string) $ttl
        ]);
    }

    /**
     * Add tags to a span
     *
     * @param string $traceId Trace ID
     * @param string $spanId Span ID
     * @param array $tags Key-value tags
     * @return array Result with tagged, tags
     */
    public function traceSpanTag(string $traceId, string $spanId, array $tags): array
    {
        $args = [$this->siteId, $traceId, $spanId];
        foreach ($tags as $key => $value) {
            $args[] = (string) $key;
            $args[] = (string) $value;
        }
        return $this->fcallDecode('GNODE_TRACE_SPAN_TAG', [], $args);
    }

    /**
     * Set baggage on a trace (propagated to all child spans)
     *
     * @param string $traceId Trace ID
     * @param string $key Baggage key
     * @param string $value Baggage value
     * @return array Result
     */
    public function traceBaggageSet(string $traceId, string $key, string $value): array
    {
        return $this->fcallDecode('GNODE_TRACE_BAGGAGE_SET', [], [$this->siteId, $traceId, $key, $value]);
    }

    /**
     * Get a baggage value from a trace
     *
     * @param string $traceId Trace ID
     * @param string $key Baggage key
     * @return string|null Baggage value or null
     */
    public function traceBaggageGet(string $traceId, string $key): ?string
    {
        $result = $this->fcallDecode('GNODE_TRACE_BAGGAGE_GET', [], [$this->siteId, $traceId, $key]);
        return $result['value'] ?? null;
    }

    /**
     * Get all baggage for a trace
     *
     * @param string $traceId Trace ID
     * @return array Key-value baggage map
     */
    public function traceBaggageAll(string $traceId): array
    {
        return $this->fcallDecode('GNODE_TRACE_BAGGAGE_ALL', [], [$this->siteId, $traceId]);
    }

    //=========================================================================
    // ENDPOINT MANAGEMENT (gNode-Message-Broker-Pro: gnode_endpoint.lua)
    //=========================================================================

    /**
     * Register an API endpoint with field mapping
     *
     * @param string $serviceId Service ID
     * @param array $endpointDefinition Endpoint with path, method, field_mapping
     * @return array Registration result
     */
    public function endpointRegister(string $serviceId, array $endpointDefinition): array
    {
        $registryKey = "{{$this->siteId}}:endpoints";
        return $this->fcallDecode('GNODE_ENDPOINT_REGISTER', [$registryKey], [$serviceId, json_encode($endpointDefinition, JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * Get an endpoint definition
     *
     * @param string $endpointId Endpoint ID
     * @return array|null Endpoint definition or null
     */
    public function endpointGet(string $endpointId): ?array
    {
        $registryKey = "{{$this->siteId}}:endpoints";
        $result = $this->fcallDecode('GNODE_ENDPOINT_GET', [$registryKey], [$endpointId]);
        return (!empty($result['result'])) ? $result['result'] : null;
    }

    /**
     * List registered endpoints
     *
     * @param string $serviceId Optional service filter
     * @return array Endpoint list
     */
    public function endpointList(string $serviceId = ''): array
    {
        $registryKey = "{{$this->siteId}}:endpoints";
        return $this->fcallDecode('GNODE_ENDPOINT_LIST', [$registryKey], [$serviceId]);
    }

    /**
     * Translate a message between endpoint formats
     *
     * @param string $sourceEndpointId Source endpoint
     * @param string $targetEndpointId Target endpoint
     * @param array $message Message to translate
     * @param string $direction 'inbound' or 'outbound'
     * @return array Translated message
     */
    public function endpointTranslate(string $sourceEndpointId, string $targetEndpointId, array $message, string $direction = 'inbound'): array
    {
        $registryKey = "{{$this->siteId}}:endpoints";
        return $this->fcallDecode('GNODE_ENDPOINT_TRANSLATE', [$registryKey], [
            $sourceEndpointId, $targetEndpointId, json_encode($message, JSON_UNESCAPED_SLASHES), $direction
        ]);
    }

    /**
     * Find endpoints by path and optional method
     *
     * @param string $path URL path to match
     * @param string $method HTTP method filter (empty = any)
     * @return array Matching endpoints
     */
    public function endpointFind(string $path, string $method = ''): array
    {
        $registryKey = "{{$this->siteId}}:endpoints";
        return $this->fcallDecode('GNODE_ENDPOINT_FIND', [$registryKey], [$path, $method]);
    }

    /**
     * Translate a message to internal format
     *
     * @param string $endpointId Source endpoint
     * @param array $message Inbound message
     * @return array Translated internal message
     */
    public function endpointTranslateToInternal(string $endpointId, array $message): array
    {
        $registryKey = "{{$this->siteId}}:endpoints";
        return $this->fcallDecode('GNODE_ENDPOINT_TRANSLATE_TO_INTERNAL', [$registryKey], [
            $endpointId, json_encode($message, JSON_UNESCAPED_SLASHES)
        ]);
    }

    /**
     * Translate from internal format to endpoint format
     *
     * @param string $endpointId Target endpoint
     * @param array $message Internal message
     * @return array Translated outbound message
     */
    public function endpointTranslateFromInternal(string $endpointId, array $message): array
    {
        $registryKey = "{{$this->siteId}}:endpoints";
        return $this->fcallDecode('GNODE_ENDPOINT_TRANSLATE_FROM_INTERNAL', [$registryKey], [
            $endpointId, json_encode($message, JSON_UNESCAPED_SLASHES)
        ]);
    }

    /**
     * Deregister an endpoint
     *
     * @param string $endpointId Endpoint ID
     * @return bool True if deregistered
     */
    public function endpointDeregister(string $endpointId): bool
    {
        $registryKey = "{{$this->siteId}}:endpoints";
        $result = $this->fcallDecode('GNODE_ENDPOINT_DEREGISTER', [$registryKey], [$endpointId]);
        return !empty($result['status']) && $result['status'] === 'ok';
    }

    /**
     * Register a translation rule between two endpoints
     *
     * @param string $sourceEndpoint Source endpoint ID
     * @param string $targetEndpoint Target endpoint ID
     * @param array $rule Translation rule definition
     * @return array Result
     */
    public function endpointRegisterTranslationRule(string $sourceEndpoint, string $targetEndpoint, array $rule): array
    {
        $registryKey = "{{$this->siteId}}:endpoints";
        return $this->fcallDecode('GNODE_ENDPOINT_REGISTER_TRANSLATION_RULE', [$registryKey], [
            $sourceEndpoint, $targetEndpoint, json_encode($rule, JSON_UNESCAPED_SLASHES)
        ]);
    }

    /**
     * Get endpoint schema definition
     *
     * @return array Schema with endpoint_definition, available_transforms
     */
    public function endpointGetSchema(): array
    {
        $registryKey = "{{$this->siteId}}:endpoints";
        return $this->fcallDecode('GNODE_ENDPOINT_GET_SCHEMA', [$registryKey]);
    }

    //=========================================================================
    // MESSAGE PARSING (native base-tier FormatProcessor, via daemon commands)
    //=========================================================================
    //
    // Wire-format detection/conversion/registration is a BASE capability
    // implemented by the daemon's native Rust engine. PHP cannot call Rust
    // directly, so these route through the daemon command stream
    // (register_format / list_formats / detect_format / convert_format) rather
    // than the retired premium GNODE_*_FORMAT FCALLs.

    /**
     * Parse a message using a registered format.
     *
     * Parsing == converting the message to the canonical standard_json form
     * (which uses an identity field-mapping), so this rides convert_format.
     *
     * @param string $formatName Format name
     * @param string $formatVersion Format version
     * @param array $message Message to parse
     * @return array Parsed message with field mapping applied
     */
    public function parseFormat(string $formatName, string $formatVersion, array $message): array
    {
        return $this->executeCommand('convert_format', [
            'source_format'  => $formatName,
            'source_version' => $formatVersion !== '' ? $formatVersion : '1.0.0',
            'target_format'  => 'standard_json',
            'target_version' => '1.0.0',
            'message'        => json_encode($message, JSON_UNESCAPED_SLASHES),
        ]) ?? [];
    }

    /**
     * Convert message between parse formats
     *
     * @param string $sourceFormat Source format name
     * @param string $sourceVersion Source format version
     * @param string $targetFormat Target format name
     * @param string $targetVersion Target format version
     * @param array $message Message to convert
     * @return array Converted message
     */
    public function parseConvert(string $sourceFormat, string $sourceVersion, string $targetFormat, string $targetVersion, array $message): array
    {
        return $this->executeCommand('convert_format', [
            'source_format'  => $sourceFormat,
            'source_version' => $sourceVersion,
            'target_format'  => $targetFormat,
            'target_version' => $targetVersion,
            'message'        => json_encode($message, JSON_UNESCAPED_SLASHES),
        ]) ?? [];
    }

    /**
     * Register a message parse format
     *
     * @param array $formatDefinition Format definition with name, version, fields, transforms
     * @return array Registration result
     */
    public function parseRegisterFormat(array $formatDefinition): array
    {
        return $this->executeCommand('register_format', [
            'format_definition' => $formatDefinition,
        ]) ?? [];
    }

    /**
     * List all registered parse formats
     *
     * @return array List of format definitions
     */
    public function parseListFormats(): array
    {
        return $this->executeCommand('list_formats', []) ?? [];
    }

    /**
     * Get a parse format definition.
     *
     * Served by filtering the native list_formats output, which carries each
     * format's name, version, schema and detection patterns.
     *
     * @param string $formatName Format name
     * @param string $formatVersion Optional version (latest if empty)
     * @return array|null Format definition or null
     */
    public function parseGetFormat(string $formatName, string $formatVersion = ''): ?array
    {
        $formats = $this->executeCommand('list_formats', []) ?? [];
        foreach ($formats as $format) {
            if (!is_array($format) || ($format['name'] ?? null) !== $formatName) {
                continue;
            }
            if ($formatVersion !== '' && ($format['version'] ?? null) !== $formatVersion) {
                continue;
            }
            return $format;
        }
        return null;
    }

    /**
     * Auto-detect the format of a message
     *
     * @param string $message Raw message string
     * @return array Detection result with format_name, version, confidence
     */
    public function parseDetectFormat(string $message): array
    {
        return $this->executeCommand('detect_format', [
            'message' => $message,
        ]) ?? [];
    }

    //=========================================================================
    // FCALL HELPER
    //=========================================================================

    /**
     * Execute an FCALL and JSON-decode the result
     *
     * @param string $function ValKey function name
     * @param array $keys Keys for cluster routing
     * @param array $args Function arguments
     * @return array Decoded result or empty array
     */
    protected function fcallDecode(string $function, array $keys, array $args = []): array
    {
        // Premium gate — the ONE gating style. Base never invokes a Pro
        // extension's functions (base ⊅ premium); callers get the same
        // structured shape as the custom-topology precedent instead of a
        // raw "Function not found" from ValKey. Wrapper methods degrade
        // per their signatures (bool → false, ?array → null) for free.
        foreach (self::PREMIUM_FCALL_PREFIXES as $prefix => $ext) {
            if (strpos($function, $prefix) === 0) {
                return $this->premiumUnavailable($ext[0], $ext[1]);
            }
        }

        $result = $this->storage->fcall($function, $keys, $args);

        if ($result === false || $result === null) {
            return [];
        }

        if (is_string($result)) {
            $decoded = $this->safeJsonDecode($result, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($result) ? $result : [];
    }

    /**
     * Build a site-scoped key for FCALL keys[] array
     *
     * ValKey validates FCALL keys against ACL BEFORE executing the function.
     * Raw names like 'my_feature' don't match ACL patterns like ~{site_id}:*.
     * This wraps them: 'my_feature' → '{example_com}:features:my_feature'.
     *
     * @param string $suffix Key suffix (e.g., 'features:flag:dark_mode')
     * @return string Site-scoped key
     */
    protected function siteKey(string $suffix): string
    {
        return "{{$this->siteId}}:{$suffix}";
    }

    //=========================================================================
    // COMMAND EXECUTION
    //=========================================================================

    /**
     * Execute a generic command
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return array|null Response data
     */
    public function executeCommand(string $command, array $parameters = []): ?array
    {
        $response = $this->sendCommand($command, $parameters);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            if (isset($response['messages']) && !empty($response['messages'])) {
                $message = $response['messages'][0];
                if (isset($message[2])) {
                    $result = $this->safeJsonDecode($message[2], true);
                    return is_array($result) ? $result : [];
                }
            }

            if (isset($response['result'])) {
                if (is_bool($response['result'])) {
                    return [
                        'status' => 'ok',
                        'success' => $response['result'],
                        'result' => $response['result']
                    ];
                }
                // Ensure array return type
                return is_array($response['result']) ? $response['result'] : ['result' => $response['result']];
            }

            return [];
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new gNodeException($response['error'] ?? 'Unknown error executing command: ' . $command);
        }

        return null;
    }

    /**
     * Send a command to the daemon
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return array|null Response
     */
    protected function sendCommand(string $command, array $parameters = []): ?array
    {
        if ($this->usingFallback && $this->fallback) {
            return $this->executeFallbackCommand($command, $parameters);
        }

        // Fail-fast: if a previous command in this request already timed
        // out, the daemon is almost certainly unreachable for the duration
        // of this request. Eating another full poll-loop per call just
        // multiplies the latency penalty (a 13-template registration loop
        // would otherwise burn 13 × $timeout). Surface the same diagnostic
        // immediately so the caller knows why.
        if ($this->daemonUnreachable) {
            throw new gNodeException(
                "gNode daemon unreachable (cached from earlier timeout in this request). "
                . $this->getDiagnosticContext($command)
            );
        }

        // Check cache for read-only commands
        $cacheKey = null;
        $useCache = in_array($command, ['findServices', 'getLoadSequence', 'getCapabilityDimensions']);

        if ($useCache) {
            $cacheKey = md5($command . json_encode($parameters));
            if (isset($this->responseCache[$cacheKey]) &&
                (time() - $this->responseCache[$cacheKey]['time'] < $this->cacheExpiration)) {
                return $this->responseCache[$cacheKey]['data'];
            }
        }

        // Adapt parameters
        $parameters = $this->adaptCommandParameters($command, $parameters);

        try {
            $requestId = uniqid($this->siteId . ':', true);

            // Include _request_id in parameters so daemon can write response to polling key
            $paramsWithMeta = array_merge($parameters, [
                '_request_id' => $requestId
            ]);

            // Wire format: see gNode/COMMAND_SCHEMA.md "Canonical fields"
            // table. Field aliases are parsed identically by every code
            // path in the daemon (RESP3, script-format, key-based reader)
            // via daemon/src/utils.rs::field_names.
            $computeStream = $this->getComputeStream();
            $this->storage->xAdd($computeStream, '*', [
                't'  => 'c',                                    // TYPE = command
                'id' => $requestId,                             // ID (top-level so daemon doesn't have to peek into params)
                'c'  => $command,                               // CMD
                'p'  => json_encode($paramsWithMeta, JSON_UNESCAPED_SLASHES), // PARAMS
                'ss' => $this->siteId,                          // SOURCE_SITE
                'sn' => 'client',                               // SOURCE_NODE
                'ts' => (string)(microtime(true) * 1000),       // TIMESTAMP (ms)
            ]);

            // Poll for response. pollForResponse() now sets
            // $this->daemonUnreachable=true and emits a one-shot
            // diagnostic on the first timeout, so callers see exactly
            // where things went wrong (credential source, stream key,
            // response key, site/env) without having to dig.
            $response = $this->pollForResponse($requestId, $this->timeout, $command);

            // A non-null response means the daemon is reachable again —
            // clear the sticky flag so subsequent calls don't fail-fast
            // unnecessarily (e.g. after a transient hiccup).
            if ($response !== null) {
                $this->daemonUnreachable = false;
            }

            // Cache if successful
            if ($useCache && $cacheKey && $response && isset($response['status']) && $response['status'] === 'ok') {
                $this->responseCache[$cacheKey] = [
                    'time' => time(),
                    'data' => $response
                ];
            }

            return $response;
        } catch (\Exception $e) {
            $this->debug("Error sending command: {$e->getMessage()}");

            if ($this->fallback && $this->config['use_fallback']) {
                $this->usingFallback = true;
                return $this->executeFallbackCommand($command, $parameters);
            }

            throw new gNodeException("Error sending command: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Adapt command parameters to the format expected by the gNode daemon
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return array Adapted parameters
     */
    protected function adaptCommandParameters(string $command, array $parameters = []): array
    {
        switch ($command) {
            case 'geometric_store_topology':
                if (!isset($parameters['data']) && !empty($parameters)) {
                    return ['data' => $parameters];
                }
                break;

            case 'geometric_discover':
                if (isset($parameters['capabilities']) && is_array($parameters['capabilities']) &&
                    !isset($parameters['capabilities'][0]) && count($parameters['capabilities']) > 0) {
                    $parameters['capabilities'] = array_keys($parameters['capabilities']);
                }
                break;
        }

        return $parameters;
    }

    /**
     * Execute a command using the fallback implementation
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return array|null Result
     */
    protected function executeFallbackCommand(string $command, array $parameters = []): ?array
    {
        if (!$this->fallback) {
            throw new gNodeException("Fallback mode requested but not available");
        }

        $this->debug("Executing fallback command: {$command}");

        try {
            $result = $this->fallback->executeCommand($command, $parameters);
            return [
                'status' => 'ok',
                'result' => $result,
                'timestamp' => microtime(true)
            ];
        } catch (\Exception $e) {
            $this->debug("Fallback command error: {$e->getMessage()}");
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => microtime(true)
            ];
        }
    }

    /**
     * Poll for response using FCALL GET with exponential backoff
     *
     * @param string $requestId Request identifier
     * @param int $timeoutMs Maximum time to wait
     * @return array|null Response
     */
    protected function pollForResponse(string $requestId, int $timeoutMs, string $command = ''): ?array
    {
        $responseKey = $this->getResponseKey($requestId);
        $startTime = microtime(true);
        $endTime = $startTime + ($timeoutMs / 1000);

        $pollInterval = 0.001;
        $maxPollInterval = 0.01;
        $attempt = 0;

        while (microtime(true) < $endTime) {
            $response = $this->luaGet($responseKey);

            if ($response !== false) {
                $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                $this->debug("Response received after " . (++$attempt) . " attempts, {$elapsed}ms");

                $this->luaDel($responseKey);

                $decoded = $this->safeJsonDecode($response, true);
                if ($decoded === null) {
                    $this->debug("Response JSON decode error: " . json_last_error_msg());
                    return null;
                }

                return $decoded;
            }

            $pollInterval = min($pollInterval * 2, $maxPollInterval);
            usleep((int)($pollInterval * 1000000));
            $attempt++;
        }

        // First timeout in this request — emit a single, loud, actionable
        // diagnostic that says exactly which stream we wrote to, which
        // response key we polled, where credentials came from, and what
        // command/site/environment was in play. Set the sticky
        // $daemonUnreachable flag so subsequent sendCommand() calls in
        // this request bail immediately instead of compounding timeouts.
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        if (!$this->daemonUnreachable) {
            error_log(
                "[gNode-Client] daemon response timeout after {$attempt} polls "
                . "in {$elapsed}ms. " . $this->getDiagnosticContext($command, $responseKey)
            );
            $this->daemonUnreachable = true;
        } else {
            // Should not normally hit this branch (sendCommand short-circuits
            // when the flag is set), but if some caller bypasses
            // sendCommand and reaches pollForResponse directly, stay quiet.
            $this->debug("Timeout after {$attempt} attempts, {$elapsed}ms (silent — daemonUnreachable already set)");
        }

        return null;
    }

    /**
     * Build a one-line diagnostic string describing the current connection
     * state. Used by pollForResponse on first timeout and by sendCommand
     * on fail-fast short-circuit. Captures every piece of information an
     * operator needs to figure out *why* the daemon isn't answering,
     * without ever logging the password value itself.
     */
    protected function getDiagnosticContext(string $command = '', string $responseKey = ''): string
    {
        $cfg = $this->config;
        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? 47445;
        $user = $cfg['user'] ?? getenv('VALKEY_USER') ?: '(unspecified)';
        $credSource = $this->credentialSource ?? '(unspecified — no VALKEY_PASSWORD_FILE env, no explicit config)';
        $stream = $this->unifiedStream;
        $resKey = $responseKey !== '' ? $responseKey : '(see sendCommand context)';

        return sprintf(
            "command=%s site=%s env=%s host=%s:%s user=%s credentials_from=%s stream=%s response_key=%s",
            $command !== '' ? $command : '(unspecified)',
            $this->siteId,
            $this->environment,
            $host,
            $port,
            $user,
            $credSource,
            $stream,
            $resKey
        );
    }

    /**
     * Poll for multiple responses
     *
     * @param array $requestIds Map of index => requestId
     * @param int $timeoutMs Maximum time to wait
     * @return array Map of index => response
     */
    protected function pollForBatchResponses(array $requestIds, int $timeoutMs): array
    {
        $responses = [];
        $pending = $requestIds;

        $startTime = microtime(true);
        $endTime = $startTime + ($timeoutMs / 1000);

        $pollInterval = 0.001;
        $maxPollInterval = 0.01;

        while (!empty($pending) && microtime(true) < $endTime) {
            foreach ($pending as $index => $requestId) {
                $responseKey = $this->getResponseKey($requestId);
                $response = $this->luaGet($responseKey);

                if ($response !== false) {
                    $decoded = $this->safeJsonDecode($response, true);
                    if ($decoded !== null) {
                        $responses[$index] = $decoded;
                        unset($pending[$index]);
                        $this->luaDel($responseKey);
                    }
                }
            }

            if (empty($pending)) {
                break;
            }

            $pollInterval = min($pollInterval * 2, $maxPollInterval);
            usleep((int)($pollInterval * 1000000));
        }

        // Same fail-fast contract as pollForResponse: if no responses
        // came back at all, set the sticky daemonUnreachable flag and
        // emit a single diagnostic. If only some were missing, treat
        // that as a normal partial result (the daemon IS responding).
        if (empty($responses) && !empty($requestIds) && !$this->daemonUnreachable) {
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            $sampleRequestId = reset($requestIds);
            $sampleResponseKey = $sampleRequestId !== false
                ? $this->getResponseKey((string)$sampleRequestId)
                : '(no requests)';
            error_log(
                "[gNode-Client] batch timeout — no responses out of "
                . count($requestIds) . " requests in {$elapsed}ms. "
                . $this->getDiagnosticContext('(batch)', $sampleResponseKey)
            );
            $this->daemonUnreachable = true;
        } elseif (!empty($responses)) {
            // Partial / full success — daemon is alive; clear the flag.
            $this->daemonUnreachable = false;
        }

        return $responses;
    }

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
    public function templateFragment(string $templateId, string $content, array $dependencies = [], array $variables = [], ?int $ttl = null): array
    {
        $parameters = [
            'template_id' => $templateId,
            'content' => $content
        ];

        if (!empty($variables)) {
            $parameters['variables'] = (object)$variables;
        }

        if ($ttl !== null) {
            $parameters['ttl'] = $ttl;
        }

        $response = $this->sendCommand('template_fragment', $parameters);

        if (isset($response['status']) && $response['status'] === 'ok' && isset($response['messages'])) {
            if (!empty($response['messages']) && is_array($response['messages'][0])) {
                $message = $response['messages'][0];
                if (isset($message[2])) {
                    $result = $this->safeJsonDecode($message[2], true);
                    if (is_array($result)) {
                        return [
                            'success' => $result['stored'] ?? true,
                            'template_id' => $result['template_id'] ?? $templateId,
                            'dependencies' => $result['dependencies'] ?? $dependencies,
                            'registered_in_topology' => $result['registered_in_topology'] ?? false,
                            'ttl' => $result['ttl'] ?? $ttl
                        ];
                    }
                }
            }
        }

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error storing template'
        ];
    }

    /**
     * Render a template with variables
     *
     * @param string $templateId Template identifier
     * @param array $variables Template variables
     * @param array $config Render configuration
     * @return string Rendered HTML
     */
    public function renderTemplate(string $templateId, array $variables = [], array $config = []): string
    {
        return $this->getTemplateManager()->renderTemplate($templateId, $variables, $config);
    }

    /**
     * Get the template manager
     *
     * @return \gCore\gNode\Template\TemplateManager Template manager instance
     */
    public function getTemplateManager(): \gCore\gNode\Template\TemplateManager
    {
        if ($this->templateManager === null) {
            $this->templateManager = new \gCore\gNode\Template\TemplateManager(
                $this,
                $this->storage,
                $this->siteId,
                $this->nodeId,
                [
                    'cache_ttl' => $this->config['cache_expiration'] ?? 300,
                    'use_valkey_functions' => true,
                    'debug' => $this->config['debug'] ?? false
                ]
            );
        }

        return $this->templateManager;
    }

    //=========================================================================
    // UTILITY METHODS
    //=========================================================================

    /**
     * Ping ValKey to check connectivity
     *
     * Uses direct ValKey PING command via storage adapter for fast, reliable check.
     * Does NOT use stream-based daemon communication (which can hang).
     *
     * @return bool True if ping succeeded
     */
    public function ping(): bool
    {
        try {
            return $this->storage->ping();
        } catch (\Exception $e) {
            $this->debug("Ping failed: " . $e->getMessage());
            return false;
        }
    }

    //=========================================================================
    // DIRECT KEY OPERATIONS (via Lua functions)
    //=========================================================================

    /**
     * Set a key with TTL (SETEX equivalent)
     *
     * Uses GNODE_CORE_SET_WITH_TTL Lua function for atomic operation.
     * Keys are automatically namespaced with site_id.
     *
     * @param string $key Key name
     * @param int $ttl Time to live in seconds
     * @param string $value Value to store
     * @return bool Success
     */
    public function setex(string $key, int $ttl, string $value): bool
    {
        try {
            $result = $this->fcall(
                'GNODE_CORE_SET_WITH_TTL',
                [],
                [$key, $value, $ttl, $this->siteId]
            );

            return $result === 'OK' || $result === true ||
                   (is_array($result) && ($result['success'] ?? false));
        } catch (\Exception $e) {
            $this->debug("setex failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set a key value (SET equivalent)
     *
     * Uses GNODE_CORE_SET_WITH_TTL Lua function with TTL=0 for no expiry.
     * Keys are automatically namespaced with site_id.
     *
     * @param string $key Key name
     * @param string $value Value to store
     * @return bool Success
     */
    public function set(string $key, string $value): bool
    {
        try {
            $result = $this->fcall(
                'GNODE_CORE_SET_WITH_TTL',
                [],
                [$key, $value, 0, $this->siteId]
            );

            return $result === 'OK' || $result === true ||
                   (is_array($result) && ($result['success'] ?? false));
        } catch (\Exception $e) {
            $this->debug("set failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a key value (GET equivalent)
     *
     * Uses GNODE_CORE_GET Lua function.
     * Keys are automatically namespaced with site_id.
     *
     * @param string $key Key name
     * @return string|null Value or null if not found
     */
    public function get(string $key): ?string
    {
        try {
            $result = $this->fcall(
                'GNODE_CORE_GET',
                [],
                [$key, $this->siteId]
            );

            if ($result === false || $result === null) {
                return null;
            }

            return is_string($result) ? $result : json_encode($result);
        } catch (\Exception $e) {
            $this->debug("get failed for key '{$key}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get client status
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        return [
            'connected' => $this->connected,
            'using_fallback' => $this->usingFallback,
            'site_id' => $this->siteId,
            'node_id' => $this->nodeId,
            'environment' => $this->environment,
            'unified_stream' => $this->unifiedStream,
            'lua_enabled' => $this->luaEnabled,
            'metrics_level' => $this->metricsLevel,
        ];
    }

    /**
     * Generate cache key for command
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return string|null Cache key
     */
    protected function getCacheKey(string $command, array $parameters): ?string
    {
        if (!$this->isCommandCacheable($command)) {
            return null;
        }

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
        // Use hash tag braces for cluster slot consistency
        return "{{$this->siteId}}:req:{$requestId}";
    }

    /**
     * Get response key for request ID
     *
     * @param string $requestId Request identifier
     * @return string Response key
     */
    protected function getResponseKey(string $requestId): string
    {
        // Use hash tag braces for cluster slot consistency
        return "{{$this->siteId}}:res:{$requestId}";
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
            'renderTemplate', 'renderTemplateString' => 300,
            'findServices', 'getServiceDetails' => 300,
            'content_retrieve' => 900,
            'asset_bundle', 'template_fragment' => 600,
            'get_site_info', 'get_node_info' => 3600,
            'geometric_discover', 'geometric_dimensions' => 300,
            'getLoadSequence', 'getCapabilityDimensions' => 300,
            default => 300
        };
    }

    /**
     * Get command priority for gNode daemon
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
     * Track custom metric using Lua function
     *
     * @param string $metricType Metric type
     * @param int $value Metric value
     * @param array $extra Additional context
     * @return bool True if tracked
     */
    protected function trackMetric(string $metricType, int $value = 1, array $extra = []): bool
    {
        if (!$this->luaEnabled || $this->metricsLevel < 1) {
            return false;
        }

        try {
            $extraJson = !empty($extra) ? json_encode($extra) : '';

            $this->storage->fcall(
                'GNODE_MONITORING_TRACK_METRIC',
                [],
                [$this->siteId, $metricType, $value, $extraJson]
            );

            return true;
        } catch (\Exception $e) {
            $this->debug("Metric tracking failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Log debug messages.
     *
     * Commit 1.12.b (NC-D2.06): log-injection defence. Callers pass raw
     * exception messages + user-controllable strings (e.g., FCALL
     * function names from attacker-controlled config). CR/LF in the
     * message could forge log lines like
     * `\n[gNodeClient AUTH-SUCCESS] admin`. Normalize CR/LF/tab into
     * literal backslash-escapes before writing.
     *
     * @param string $message Debug message
     */
    protected function debug(string $message): void
    {
        if ($this->config['debug'] ?? false) {
            $safe = str_replace(
                ["\r\n", "\r", "\n", "\t"],
                ['\\r\\n', '\\r', '\\n', '\\t'],
                $message
            );
            error_log("[gNodeClient] {$safe}");
        }
    }

    /**
     * Clear response cache
     */
    public function clearCache(): void
    {
        $this->responseCache = [];
    }

    //=========================================================================
    // CONSUMER GROUP MANAGEMENT
    //=========================================================================

    /** @var ConsumerGroupHandler|null Consumer group handler */
    protected $consumerHandler = null;

    /** @var bool Using consumer groups */
    protected $usingConsumerGroups = true;

    /**
     * Get the consumer group handler instance
     *
     * @return ConsumerGroupHandler|null
     */
    public function getConsumerHandler(): ?ConsumerGroupHandler
    {
        return $this->consumerHandler;
    }

    /**
     * Enable consumer group approach
     *
     * @return bool Success
     */
    public function enableConsumerGroups(): bool
    {
        if (!$this->consumerHandler) {
            $this->consumerHandler = new ConsumerGroupHandler(
                $this->storage,
                $this->siteId,
                $this->nodeId,
                [
                    'stream_prefix' => $this->config['stream_prefix'],
                    'debug' => $this->config['debug'],
                    'batch_size' => $this->config['batch_size'] ?? 100,
                    'max_idle_time' => $this->config['max_idle_time'] ?? 30000,
                    'trim_threshold' => $this->config['trim_threshold'] ?? 10000,
                    'client_id' => $this->clientId,
                ]
            );

            if (!$this->consumerHandler->initialize()) {
                $this->debug("Failed to initialize consumer groups");
                return false;
            }
        }

        $this->usingConsumerGroups = true;
        $this->debug("Consumer group approach enabled");
        return true;
    }

    /**
     * Disable consumer group approach
     *
     * @return void
     */
    public function disableConsumerGroups(): void
    {
        $this->usingConsumerGroups = false;
        $this->debug("Consumer group approach disabled");
    }

    /**
     * Enable native RESP3 mode
     *
     * @return bool Success
     */
    public function enableNativeMode(): bool
    {
        if (!$this->consumerHandler) {
            $this->debug("Consumer handler not initialized, cannot enable native mode");
            return false;
        }

        $this->consumerHandler->enableNativeMode();
        $this->debug("Native RESP3 mode enabled");
        return true;
    }

    /**
     * Disable native RESP3 mode
     *
     * @return void
     */
    public function disableNativeMode(): void
    {
        if ($this->consumerHandler) {
            $this->consumerHandler->disableNativeMode();
        }
        $this->debug("Native RESP3 mode disabled");
    }

    /**
     * Check if native mode is enabled
     *
     * @return bool
     */
    public function isNativeMode(): bool
    {
        return $this->consumerHandler ? $this->consumerHandler->isNativeMode() : false;
    }

    //=========================================================================
    // STREAM METHODS
    //=========================================================================

    /**
     * Get information about a stream
     *
     * @param string $stream Stream name
     * @return array Stream information
     */
    public function streamInfo(string $stream): array
    {
        $response = $this->sendCommand('stream_info', ['stream' => $stream]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [];
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new gNodeException($response['error'] ?? 'Unknown error getting stream info');
        }

        return [];
    }

    /**
     * Get information about consumer groups for a stream
     *
     * @param string $stream Stream name
     * @param string|null $group Optional group name filter
     * @return array Consumer group information
     */
    public function streamGroupInfo(string $stream, ?string $group = null): array
    {
        $parameters = ['stream' => $stream];
        if ($group !== null) {
            $parameters['group'] = $group;
        }

        $response = $this->sendCommand('stream_group_info', $parameters);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [];
        }

        return [];
    }

    /**
     * Get consumer information for a stream and group
     *
     * @param string $stream Stream key
     * @param string $group Consumer group name
     * @return array Array of consumer information
     */
    public function streamConsumerInfo(string $stream, string $group): array
    {
        return $this->executeCommand('stream_consumer_info', [
            'stream' => $stream,
            'group' => $group
        ]) ?? [];
    }

    /**
     * Get pending messages for a stream and consumer group
     *
     * @param string $stream Stream key
     * @param string $group Consumer group name
     * @param int $count Number of detailed entries
     * @return array Pending messages summary
     */
    public function streamPendingInfo(string $stream, string $group, int $count = 0): array
    {
        return $this->executeCommand('stream_pending', [
            'stream' => $stream,
            'group' => $group,
            'count' => $count
        ]) ?? [];
    }

    /**
     * Send raw RESP3 message directly to the stream
     *
     * @param array $fields Raw fields to send
     * @param string|null $requestId Optional request ID
     * @return string Message ID
     */
    public function sendRawMessage(array $fields, ?string $requestId = null): string
    {
        if (!$this->consumerHandler) {
            throw new gNodeException("Consumer handler not initialized");
        }

        return $this->consumerHandler->sendRawMessage($fields, $requestId);
    }

    /**
     * Send a command with raw RESP3 fields
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @param array $additionalFields Additional raw fields
     * @return array Response from daemon
     */
    public function sendRawCommand(string $command, array $parameters = [], array $additionalFields = []): array
    {
        $requestId = uniqid('raw-', true);

        $fields = array_merge([
            't' => 'c',
            'c' => $command,
            'p' => json_encode($parameters, JSON_UNESCAPED_SLASHES),
            'id' => $requestId,
            'ss' => $this->siteId,
            'sn' => $this->nodeId,
            'ts' => (string)(microtime(true) * 1000)   // ms, per the wire contract (matches the other command paths)
        ], $additionalFields);

        try {
            $messageId = $this->sendRawMessage($fields, $requestId);
            $this->debug("Raw command sent: {$command} with message ID: {$messageId}");

            if ($this->usingConsumerGroups && $this->consumerHandler) {
                $response = $this->consumerHandler->waitForResponse($requestId, $this->timeout);
            } else {
                $response = $this->pollForResponse($requestId, $this->timeout, $command);
            }

            return $response ?: ['success' => false, 'error' => 'No response received'];
        } catch (\Exception $e) {
            $this->debug("Raw command error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    //=========================================================================
    // SERVICE REGISTRATION & DISCOVERY
    //=========================================================================

    /**
     * Register a SERVICE-tier service with the topology using human-readable capabilities
     *
     * NOTE: TOOL-tier services (gCore managers) should NOT use this method.
     * Tool-tier registration is a deploy-time operation handled by:
     *   gnode-daemon register-tools --site <site_id>
     * See: scripts/register-tools.sh
     *
     * This method is for SERVICE-tier and above (WordPress sites, business logic, etc.)
     * that register at process startup, not per page load.
     *
     * Capabilities can be specified using natural language names:
     *   $client->registerService('my-api', [
     *       'protocol' => 'http_rest',
     *       'native_format' => 'json',
     *       'domain_primary' => 'compute',
     *       'latency_class' => 'interactive',
     *   ]);
     *
     * NOTE: For local services, registration is handled by the gNode daemon's
     * periodic service discovery (reads geometric_topology.yaml).
     * This method is for remote services or CLI one-time registration only.
     *
     * @param string $id Service ID
     * @param array $capabilities Array of capabilities (human-readable names or coordinates)
     * @param array $metadata Optional metadata
     * @return bool Success
     * @throws gNodeException On registration failure
     */
    public function registerService(string $id, array $capabilities, array $metadata = []): bool
    {
        // Auto-inject classification dimensions (17-18) from metadata tier/environment
        // This ensures all services registered via gNode-Client use the 23-dimension schema
        $capabilities = $this->injectClassificationDimensions($capabilities, $metadata);

        // Translate human-readable capability names (e.g., 'http_rest') to numeric coordinates (e.g., 0.10)
        // The daemon expects HashMap<String, f64> — dimension names with float values
        $numericCapabilities = [];
        $schema = self::getCapabilitySchema();
        foreach ($capabilities as $name => $value) {
            if (is_numeric($value)) {
                $numericCapabilities[$name] = (float)$value;
                continue;
            }
            // Look up string value in schema to get numeric coordinate
            if (isset($schema['dimensions'][$name])) {
                $dim = $schema['dimensions'][$name];
                $values = $dim['values'] ?? [];
                if ($values === 'same as domain_primary') {
                    $values = $schema['dimensions']['domain_primary']['values'] ?? [];
                }
                if (is_array($values) && isset($values[$value])) {
                    $numericCapabilities[$name] = (float)$values[$value];
                } else {
                    $this->debug("Unknown capability value '{$value}' for dimension '{$name}', defaulting to 0.0");
                    $numericCapabilities[$name] = 0.0;
                }
            }
        }

        // Send registerService command via unified stream.
        // The daemon's async handler (handle_register_service_async) processes
        // this via FCALL GNODE_REGISTER_CAPABILITY_VECTOR which is O(1) and idempotent.
        //
        // Truthiness contract:
        //   true  — daemon ACK'd with status=ok (registration confirmed in topology)
        //   false — null/timeout response, non-ok status, or thrown exception
        //
        // Previously returned true on null/timeout ("idempotent, treat as success"),
        // which silently persisted the gcore_topology_hash_<site> wp_option as
        // proof of a registration the daemon never actually processed. The hash
        // then looked authoritative on subsequent runs ("already registered, skip")
        // while the topology entity was missing. Absence of ACK now fails closed.
        try {
            $response = $this->sendCommand('registerService', [
                'id' => $id,
                'capabilities' => $numericCapabilities,
                'metadata' => $metadata
            ]);

            if ($response && isset($response['status']) && $response['status'] === 'ok') {
                $this->debug("Service '{$id}' registered successfully");
                return true;
            }

            $this->debug("Service '{$id}' registration NOT ACK'd by daemon: " . json_encode($response));
            return false;
        } catch (\Exception $e) {
            $this->debug("Service '{$id}' registration failed: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Deregister a service from the gNode topology
     *
     * @param string $serviceId The service ID to deregister
     * @return bool True if service was found and removed
     * @throws gNodeException On deregistration failure
     */
    public function deregisterService(string $serviceId): bool
    {
        // Use unified stream command for service deregistration (stateless architecture)
        // Note: GNODE_TOPOLOGY_DEREGISTER_SERVICE Lua function was deprecated 2026-01-15
        $response = $this->sendCommand('deregisterService', [
            'service_id' => $serviceId
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'deregistered') {
            return true;
        }

        if ($response && isset($response['status']) && $response['status'] === 'not_found') {
            return false;
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new gNodeException($response['error'] ?? 'Unknown error deregistering service');
        }

        return false;
    }

    /**
     * Find services matching requirements
     *
     * @param array $requirements Array of requirements
     * @return array Array of service IDs
     */
    public function findServices(array $requirements): array
    {
        // Note: 'findServices' itself has no
        // handler descriptor in the daemon catalog. The canonical capability-
        // vector discovery command is 'geometric_discover' (geometric.rs,
        // Lane::Fast — takes `capabilities: {dim: value}` and returns matching
        // services). The wrapper translates its legacy `requirements` shape
        // to the new `capabilities` field so callers don't need updating.
        $response = $this->sendCommand('geometric_discover', [
            'capabilities' => $requirements,
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            // geometric_discover returns {total_matches, results: [...]}.
            // Legacy callers expected a flat array of service descriptors;
            // unwrap to preserve that shape.
            $result = $response['result'] ?? [];
            if (isset($result['results']) && is_array($result['results'])) {
                return $result['results'];
            }
            return is_array($result) ? $result : [];
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new gNodeException($response['error'] ?? 'Unknown error finding services');
        }

        return [];
    }

    /**
     * Get service details by ID
     *
     * @param string $serviceId Service ID
     * @return array Service details
     */
    public function getServiceDetails(string $serviceId): array
    {
        // Note: 'getServiceDetails' itself has
        // no handler descriptor in the daemon catalog. The canonical command
        // is 'service_describe' (introspection.rs, Lane::Fast — returns the
        // exact shape this wrapper expects: service_id + capabilities +
        // metadata + health + tier). The wrapper's external API is
        // unchanged; only the wire command name shifts.
        $response = $this->sendCommand('service_describe', ['service_id' => $serviceId]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [
                'id' => $serviceId,
                'capabilities' => [],
                'metadata' => []
            ];
        }

        return [
            'id' => $serviceId,
            'capabilities' => [],
            'metadata' => []
        ];
    }

    /**
     * Get the load sequence
     *
     * @param string $group Optional group name
     * @return array Array of service IDs in load order
     */
    public function getLoadSequence(string $group = 'default'): array
    {
        $response = $this->sendCommand('geometric_load_sequence', ['group' => $group]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [];
        }

        return [];
    }

    /**
     * Calculate geometric distance between two points
     *
     * @param array $point1 First point
     * @param array $point2 Second point
     * @return array Distance information
     */
    public function geometricDistance(array $point1, array $point2): array
    {
        $response = $this->sendCommand('geometric_distance', [
            'point1' => $point1,
            'point2' => $point2
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [
                'distance' => 0,
                'dimensions' => count($point1)
            ];
        }

        return [
            'distance' => 0,
            'dimensions' => count($point1)
        ];
    }

    //=========================================================================
    // HEALTH STREAM INTEGRATION
    //=========================================================================

    /**
     * Get or create the health stream writer
     *
     * @return \gCore\gNode\Health\HealthStreamWriter
     */
    public function getHealthWriter(): \gCore\gNode\Health\HealthStreamWriter
    {
        if ($this->healthWriter === null) {
            $this->healthWriter = new \gCore\gNode\Health\HealthStreamWriter(
                $this->storage,
                $this->siteId,
                $this->nodeId,
                [
                    'stream_prefix' => $this->config['stream_prefix'],
                    'environment' => $this->environment,
                    'debug' => $this->config['debug']
                ]
            );
        }

        return $this->healthWriter;
    }

    /**
     * Send health update for a service
     *
     * @param array $metricsData Health metrics data
     * @return string Message ID from XADD
     */
    public function sendHealthUpdate(array $metricsData): string
    {
        $metrics = \gCore\gNode\Health\HealthMetrics::fromArray($metricsData);
        return $this->getHealthWriter()->publishMetrics($metrics);
    }

    /**
     * Send health updates for multiple services in a batch
     *
     * @param array $metricsArray Array of metrics data arrays
     * @return array Array of message IDs
     */
    public function sendHealthUpdateBatch(array $metricsArray): array
    {
        $metricsObjects = [];
        foreach ($metricsArray as $metricsData) {
            $metricsObjects[] = \gCore\gNode\Health\HealthMetrics::fromArray($metricsData);
        }
        return $this->getHealthWriter()->publishBatch($metricsObjects);
    }

    /**
     * Start periodic health heartbeat for a service
     *
     * @param string $serviceId Service identifier
     * @param int $intervalMs Interval in milliseconds
     * @param callable $metricsProvider Callback that returns HealthMetrics
     * @param int|null $maxIterations Maximum iterations
     * @return void
     */
    public function startHealthHeartbeat(
        string $serviceId,
        callable $metricsProvider,
        int $intervalMs = 1000,
        ?int $maxIterations = null
    ): void {
        $this->getHealthWriter()->startHeartbeat($serviceId, $intervalMs, $metricsProvider, $maxIterations);
    }

    /**
     * Process all active health heartbeats
     *
     * @return array Array of service IDs that sent heartbeats
     */
    public function tickHealthHeartbeats(): array
    {
        return $this->getHealthWriter()->tickAllHeartbeats();
    }

    /**
     * Stop health heartbeat for a service
     *
     * @param string $serviceId Service identifier
     * @return bool True if heartbeat was stopped
     */
    public function stopHealthHeartbeat(string $serviceId): bool
    {
        return $this->getHealthWriter()->stopHeartbeat($serviceId);
    }

    /**
     * Stop all active health heartbeats
     *
     * @return int Number of heartbeats stopped
     */
    public function stopAllHealthHeartbeats(): int
    {
        return $this->getHealthWriter()->stopAllHeartbeats();
    }

    /**
     * Get heartbeat status for a service
     *
     * @param string $serviceId Service identifier
     * @return array|null Heartbeat status or null
     */
    public function getHeartbeatStatus(string $serviceId): ?array
    {
        return $this->getHealthWriter()->getHeartbeatStatus($serviceId);
    }

    /**
     * Get health stream statistics
     *
     * @return array Statistics about published metrics
     */
    public function getHealthStatistics(): array
    {
        return $this->getHealthWriter()->getStatistics();
    }

    /**
     * Initialize health stream consumer group
     *
     * @return bool True if group was created or exists
     */
    public function initializeHealthStream(): bool
    {
        return $this->getHealthWriter()->initializeConsumerGroup();
    }

    //=========================================================================
    // COMMS STREAM INTEGRATION (gNode-COMMS)
    //=========================================================================

    /**
     * Get the comms stream key for this site
     *
     * Stream key format: {site_id}:gnode:comms:{environment}
     * Example: example_com:gnode:comms:production
     *
     * @return string Comms stream key
     * @api
     */
    public function getCommsStream(): string
    {
        return "{{$this->siteId}}:gnode:comms:{$this->environment}";
    }

    /**
     * Queue a message to the comms stream for gNode-COMMS to process
     *
     * This method adds a notification message to the comms stream.
     * The gNode-COMMS daemon will pick it up and dispatch via configured channels
     * (email, Telegram, SMS, etc.).
     *
     * Message types (free-form; the daemon does not validate). Conventional:
     * contact, alert, error. Recognized specially: test (dropped by a daemon
     * whose --environment is production), system (never dispatched).
     * Priority levels: 1=critical, 2=high, 3=normal (default), 4=low, 5=bulk
     *
     * @param string $type Message type (contact | alert | error | test | system)
     * @param array $sender Sender info ['name' => '', 'email' => '', 'ip' => '', 'user_agent' => '']
     * @param array $content Message content ['subject' => '', 'body' => '']
     * @param array $metadata Additional metadata ['form_type' => '', 'source_url' => '', 'face_id' => 0]
     * @param int $priority Priority level 1-5 (default: 3 = normal)
     * @param array $channels Dispatch channels (default: ['email'])
     * @return string|false Message ID from XADD, or false on failure
     * @api
     */
    public function queueCommsMessage(
        string $type,
        array $sender,
        array $content,
        array $metadata = [],
        int $priority = 3,
        array $channels = ['email']
    ): string|false {
        if (!$this->connected && !$this->usingFallback) {
            $this->debug("Cannot queue comms message: not connected");
            return false;
        }

        $messageId = $this->generateUUID();
        $timestamp = date('c'); // ISO-8601

        $message = [
            'id' => $messageId,
            'type' => $type,
            'timestamp' => $timestamp,
            'site_id' => $this->siteId,
            // Top-level DTAP environment so the COMMS daemon's non-prod gate
            // can read it directly (parse_message reads a flat field, not the
            // nested metadata.environment). Authoritative for side-effect
            // gating: a non-prod message mis-routed onto the production stream
            // still carries its true environment and is caught.
            'environment' => $this->environment,
            'priority' => $priority,
            'sender' => json_encode([
                'name' => $sender['name'] ?? '',
                'email' => $sender['email'] ?? '',
                'phone' => $sender['phone'] ?? null,
                'user_agent' => $sender['user_agent'] ?? '',
                'ip' => $sender['ip'] ?? '',
            ]),
            'content' => json_encode(array_filter([
                'subject' => $content['subject'] ?? '',
                'body' => $content['body'] ?? '',
                'attachments' => !empty($content['attachments']) ? (object) $content['attachments'] : null,
            ], fn($v) => $v !== null)),
            'metadata' => json_encode([
                'form_type' => $metadata['form_type'] ?? $type,
                'source_url' => $metadata['source_url'] ?? '',
                'face_id' => $metadata['face_id'] ?? 0,
                'environment' => $this->environment,
            ]),
            'dispatch' => json_encode([
                'channels' => $channels,
                'status' => 'pending',
                'attempts' => 0,
                'last_attempt' => null,
                'next_retry' => null,
            ]),
        ];

        try {
            $streamKey = $this->getCommsStream();
            $this->debug("Queueing comms message to stream: {$streamKey}");

            // Use storage xAdd directly
            $streamId = $this->storage->xAdd($streamKey, '*', $message);

            $this->debug("Comms message queued with ID: {$streamId}");
            return $streamId;

        } catch (\Exception $e) {
            $this->debug("Failed to queue comms message: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Queue a contact form submission to the comms stream
     *
     * Convenience method for contact form submissions.
     *
     * @param string $name Sender name
     * @param string $email Sender email
     * @param string $subject Message subject
     * @param string $message Message body
     * @param array $metadata Additional metadata (source_url, face_id, etc.)
     * @return string|false Message ID or false on failure
     * @api
     */
    public function queueContactForm(
        string $name,
        string $email,
        string $subject,
        string $message,
        array $metadata = []
    ): string|false {
        return $this->queueCommsMessage(
            'contact',
            [
                'name' => $name,
                'email' => $email,
                'ip' => $metadata['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent' => $metadata['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ],
            [
                'subject' => $subject,
                'body' => $message,
            ],
            [
                'form_type' => 'contact',
                'source_url' => $metadata['source_url'] ?? '',
                'face_id' => $metadata['face_id'] ?? 0,
            ],
            3, // Normal priority
            ['email'] // Default to email channel
        );
    }

    /**
     * Read this site's Comms channel configuration, or null if unconfigured.
     *
     * The Geodineum-COMMS daemon reads {site}:comms:config as plain JSON. This
     * method owns that canonical key + encoding so callers never touch ValKey
     * or choose a serialization — the point of the contract layer.
     *
     * @return array|null Decoded settings, or null if absent/unreadable
     */
    public function getCommsSettings(): ?array
    {
        $raw = $this->get($this->commsSettingsKey());
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Persist this site's Comms channel configuration and signal the daemon.
     *
     * Writes the canonical {site}:comms:config as plain JSON matching the Rust
     * SiteSettings shape (site_id + channels always present), then publishes a
     * settings.reload control message so a running daemon drops its in-memory
     * settings cache for this site — without it a saved change stays invisible
     * until the daemon restarts.
     *
     * @param array $settings Settings to persist
     * @return bool True on write success
     */
    public function saveCommsSettings(array $settings): bool
    {
        $settings['site_id'] = $this->siteId;
        if (!isset($settings['channels']) || !is_array($settings['channels'])) {
            $settings['channels'] = [];
        }
        $json = json_encode($settings);
        if ($json === false) {
            $this->debug('saveCommsSettings: json_encode failed for ' . $this->siteId);
            return false;
        }
        if (!$this->set($this->commsSettingsKey(), $json)) {
            return false;
        }
        $this->publishCommsSettingsReload();
        return true;
    }

    /**
     * Delete this site's Comms configuration and signal the daemon to reload.
     *
     * @return bool True if the key was removed
     */
    public function deleteCommsSettings(): bool
    {
        $deleted = $this->fcall('GNODE_CORE_DELETE', [], [$this->commsSettingsKey(), $this->siteId]);
        $this->publishCommsSettingsReload();
        return $deleted !== false && $deleted !== null;
    }

    /**
     * Canonical ValKey key for this site's Comms configuration. Fully qualified
     * with the site hash-tag so the gNode core FCALLs (build_key) store it
     * verbatim — the exact key the COMMS daemon reads, not a cache-namespaced
     * derivative. This is why contract data goes through set()/get() with a
     * qualified key rather than the cache tier, which prefixes and type-tags.
     */
    private function commsSettingsKey(): string
    {
        return sprintf('{%s}:comms:config', $this->siteId);
    }

    /**
     * Publish a settings.reload control message on the comms stream. The daemon
     * recognises the type, invalidates its cached settings for this site, and
     * acks without dispatching. Rides the durable consumer-group stream so the
     * signal is applied even if issued while the daemon is momentarily down.
     */
    private function publishCommsSettingsReload(): void
    {
        $this->queueCommsMessage('settings.reload', [], [], [], 5, []);
    }

    /**
     * Generate a UUID v4
     *
     * @return string UUID
     */
    private function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    //=========================================================================
    // FORMAT SYSTEM INTEGRATION
    //=========================================================================

    /**
     * Get or create the format manager
     *
     * @return \gCore\gNode\Format\FormatManager
     */
    public function getFormatManager(): \gCore\gNode\Format\FormatManager
    {
        if ($this->formatManager === null) {
            $this->formatManager = new \gCore\gNode\Format\FormatManager(
                $this,
                $this->storage,
                $this->siteId,
                $this->nodeId,
                [
                    'cache_ttl' => $this->config['cache_expiration'] ?? 300,
                    'use_valkey_functions' => true,
                    'use_lua_fallback' => true,
                    'validate_schemas' => true,
                    'debug' => $this->config['debug'] ?? false
                ]
            );
        }

        return $this->formatManager;
    }

    /**
     * Register a custom message format
     *
     * @param array $definition Format definition
     * @return bool True on success
     */
    public function registerFormat(array $definition): bool
    {
        return $this->getFormatManager()->registerFormat($definition);
    }

    /**
     * Detect the format of a message
     *
     * @param string $message Message to analyze
     * @return string|null Format name or null
     */
    public function detectMessageFormat(string $message): ?string
    {
        try {
            return $this->getFormatManager()->detectFormat($message);
        } catch (\Exception $e) {
            $this->debug("Format detection error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert message from one format to another
     *
     * @param string $message Message to convert
     * @param string $fromFormat Source format
     * @param string $toFormat Target format
     * @return string Converted message
     */
    public function convertMessageFormat(string $message, string $fromFormat, string $toFormat): string
    {
        return $this->getFormatManager()->convertFormat($message, $fromFormat, $toFormat);
    }

    /**
     * List all registered formats
     *
     * @return array Array of format definitions
     */
    public function listRegisteredFormats(): array
    {
        return $this->getFormatManager()->listFormats();
    }

    /**
     * Get JSONSchema for a specific format
     *
     * @param string $formatName Format name
     * @return array|null Schema definition
     */
    public function getFormatSchema(string $formatName): ?array
    {
        return $this->getFormatManager()->getSchema($formatName);
    }

    /**
     * Clear format cache
     *
     * @return void
     */
    public function clearFormatCache(): void
    {
        if ($this->formatManager !== null) {
            $this->formatManager->clearCache();
        }
    }

    /**
     * Get format system statistics
     *
     * @return array Statistics data
     */
    public function getFormatStatistics(): array
    {
        if ($this->formatManager === null) {
            return [
                'formats_registered' => 0,
                'detections_performed' => 0,
                'conversions_performed' => 0,
                'cache_hits' => 0,
                'cache_misses' => 0,
                'errors' => 0
            ];
        }

        return $this->formatManager->getStatistics();
    }

    //=========================================================================
    // BROADCAST STREAM INTEGRATION
    //=========================================================================

    /**
     * Get or create the broadcast reader
     *
     * @return \gCore\gNode\Broadcast\BroadcastReader
     */
    public function getBroadcastReader(): \gCore\gNode\Broadcast\BroadcastReader
    {
        if ($this->broadcastReader === null) {
            $this->broadcastReader = new \gCore\gNode\Broadcast\BroadcastReader(
                $this->storage,
                $this->siteId,
                $this->nodeId,
                [
                    'stream_prefix' => $this->config['stream_prefix'],
                    'debug' => $this->config['debug'],
                    'use_valkey_functions' => true,
                    'last_seen_id' => '$'
                ]
            );
        }

        return $this->broadcastReader;
    }

    /**
     * Read broadcast messages from global stream
     *
     * @param int $count Maximum messages to read
     * @param int $blockMs Block timeout in milliseconds
     * @param string|null $typeFilter Filter by message type
     * @return \gCore\gNode\Broadcast\BroadcastMessage[] Array of messages
     */
    public function readBroadcastMessages(int $count = 100, int $blockMs = 0, ?string $typeFilter = null): array
    {
        return $this->getBroadcastReader()->read($count, $blockMs, $typeFilter);
    }

    /**
     * Write broadcast message to global stream
     *
     * @param string $messageType Message type
     * @param array $fields Additional message fields
     * @return string Message ID
     */
    public function writeBroadcastMessage(string $messageType, array $fields = []): string
    {
        return $this->getBroadcastReader()->write($messageType, $fields);
    }

    /**
     * Trim broadcast stream by retention time
     *
     * @param int $retentionSeconds Keep messages newer than this
     * @return int Number of messages trimmed
     */
    public function trimBroadcastStream(int $retentionSeconds = 300): int
    {
        return $this->getBroadcastReader()->trim($retentionSeconds);
    }

    /**
     * Get broadcast stream metadata
     *
     * @return array Stream metadata
     */
    public function getBroadcastStreamInfo(): array
    {
        return $this->getBroadcastReader()->getStreamInfo();
    }

    /**
     * Reset broadcast reader position to beginning
     *
     * @return void
     */
    public function resetBroadcastPosition(): void
    {
        $this->getBroadcastReader()->resetPosition();
    }

    /**
     * Reset broadcast reader position to new messages only
     *
     * @return void
     */
    public function resetBroadcastToNewMessages(): void
    {
        $this->getBroadcastReader()->resetToNewMessages();
    }

    /**
     * Get broadcast reader position
     *
     * @return string Current position
     */
    public function getBroadcastPosition(): string
    {
        return $this->getBroadcastReader()->getPosition();
    }

    /**
     * Get broadcast reader statistics
     *
     * @return array Statistics
     */
    public function getBroadcastStatistics(): array
    {
        if ($this->broadcastReader === null) {
            return [
                'broadcast_stream' => sprintf('{%s}:%s:broadcast:global', $this->siteId, $this->config['stream_prefix']),
                'messages_read' => 0,
                'last_seen_id' => 'not_initialized',
                'using_valkey_functions' => true
            ];
        }

        return $this->broadcastReader->getStatistics();
    }

    //=========================================================================
    // EXTENDED TEMPLATE METHODS
    //=========================================================================

    /**
     * Register a template with the daemon
     *
     * @param string $templateId Template identifier
     * @param string $content Template content
     * @param array $config Optional configuration
     * @return array Registration result
     */
    public function registerTemplate(string $templateId, string $content, array $config = []): array
    {
        return $this->getTemplateManager()->registerTemplate($templateId, $content, $config);
    }

    /**
     * Delete a template
     *
     * @param string $templateId Template identifier
     * @param array $config Delete configuration
     * @return bool Success
     */
    public function deleteTemplate(string $templateId, array $config = []): bool
    {
        return $this->getTemplateManager()->deleteTemplate($templateId, $config);
    }

    /**
     * List all registered templates
     *
     * @param array $config List configuration
     * @return array Template identifiers
     */
    public function listTemplates(array $config = []): array
    {
        return $this->getTemplateManager()->listTemplates($config);
    }

    /**
     * Get template metadata
     *
     * @param string $templateId Template identifier
     * @param array $config Metadata configuration
     * @return array|null Metadata or null
     */
    public function getTemplateMetadata(string $templateId, array $config = []): ?array
    {
        return $this->getTemplateManager()->getTemplateMetadata($templateId, $config);
    }

    /**
     * Get template dependencies
     *
     * @param string $templateId Template identifier
     * @return array Dependency template IDs
     */
    public function getTemplateDependencies(string $templateId): array
    {
        return $this->getTemplateManager()->getTemplateDependencies($templateId);
    }

    /**
     * Invalidate template cache (transitive)
     *
     * @param string $templateId Template identifier
     * @param array $config Invalidation configuration
     * @return array Invalidated template IDs
     */
    public function invalidateTemplate(string $templateId, array $config = []): array
    {
        return $this->getTemplateManager()->invalidateTemplate($templateId, $config);
    }

    /**
     * Discover similar templates via geometric search
     *
     * @param string $templateId Reference template ID
     * @param int $limit Maximum results
     * @return array Matching templates
     */
    public function discoverSimilarTemplates(string $templateId, int $limit = 10): array
    {
        return $this->getTemplateManager()->discoverSimilarTemplates($templateId, $limit);
    }

    /**
     * Discover templates by capability constraints
     *
     * @param array $capabilities 8D capability constraints
     * @param int $limit Maximum results
     * @return array Matching templates
     */
    public function discoverTemplatesByCapability(array $capabilities, int $limit = 100): array
    {
        return $this->getTemplateManager()->discoverTemplatesByCapability($capabilities, $limit);
    }

    /**
     * Render template string without registration
     *
     * @param string $template Template content
     * @param array $variables Variables
     * @param array $config Configuration
     * @return string Rendered output
     */
    public function renderTemplateString(string $template, array $variables = [], array $config = []): string
    {
        return $this->getTemplateManager()->renderString($template, $variables, $config);
    }

    /**
     * Get all template metadata
     *
     * @param array $config Configuration
     * @return array All template metadata
     */
    public function getAllTemplateMetadata(array $config = []): array
    {
        return $this->getTemplateManager()->getAllTemplateMetadata($config);
    }

    /**
     * Get template system statistics
     *
     * @return array Statistics
     */
    public function getTemplateStatistics(): array
    {
        if ($this->templateManager === null) {
            return [
                'templates_registered' => 0,
                'renders_performed' => 0,
                'cache_hits' => 0,
                'cache_misses' => 0,
                'invalidations' => 0,
                'errors' => 0
            ];
        }

        return $this->templateManager->getStatistics();
    }

    //=========================================================================
    // CONTENT OPERATIONS
    //=========================================================================

    /**
     * Store content with optional minification and TTL
     *
     * @param string $key Content key
     * @param string $content Content to store
     * @param string $contentType Content type
     * @param bool $minify Whether to minify
     * @param int|null $ttl Time to live in seconds
     * @return array Response from daemon
     */
    public function contentStore(string $key, string $content, string $contentType = 'text/html', bool $minify = false, ?int $ttl = null): array
    {
        $parameters = [
            'key' => $key,
            'content' => $content,
            'content_type' => $contentType,
            'minify' => $minify
        ];

        if ($ttl !== null) {
            $parameters['ttl'] = $ttl;
        }

        $response = $this->sendCommand('content_store', $parameters);

        if (isset($response['status']) && $response['status'] === 'ok' && isset($response['result'])) {
            $result = $response['result'];
            return [
                'success' => $result['stored'] ?? true,
                'key' => $result['key'] ?? $key,
                'content_type' => $result['content_type'] ?? $contentType,
                'stored_size' => $result['stored_size'] ?? strlen($content),
                'original_size' => $result['original_size'] ?? strlen($content),
                'minified' => $result['minified'] ?? false,
                'compressed' => $result['compressed'] ?? false,
                'ttl' => $result['ttl'] ?? $ttl
            ];
        }

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error storing content'
        ];
    }

    /**
     * Retrieve stored content by key
     *
     * @param string $key Content key
     * @return array Response with content or error
     */
    public function contentRetrieve(string $key): array
    {
        $response = $this->sendCommand('content_retrieve', ['key' => $key]);

        if (isset($response['status']) && $response['status'] === 'ok' && isset($response['result'])) {
            $result = $response['result'];
            return [
                'success' => true,
                'content' => $result['content'] ?? '',
                'key' => $result['key'] ?? $key,
                'retrieved_at' => $result['retrieved_at'] ?? time(),
                'metadata' => $result['metadata'] ?? [],
                'headers' => $result['headers'] ?? []
            ];
        }

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Content not found'
        ];
    }

    /**
     * Create an asset bundle from multiple assets
     *
     * @param string $bundleId Bundle identifier
     * @param array $assets Array of asset identifiers
     * @param string $bundleType Bundle type
     * @param bool $minify Whether to minify
     * @param int|null $ttl Time to live in seconds
     * @return array Response from daemon
     */
    public function assetBundle(string $bundleId, array $assets, string $bundleType = 'mixed', bool $minify = false, ?int $ttl = null): array
    {
        $parameters = [
            'bundle_id' => $bundleId,
            'assets' => $assets,
            'bundle_type' => $bundleType,
            'minify' => $minify
        ];

        if ($ttl !== null) {
            $parameters['ttl'] = $ttl;
        }

        $response = $this->sendCommand('asset_bundle', $parameters);

        if (isset($response['status']) && $response['status'] === 'ok' && isset($response['result'])) {
            $result = $response['result'];
            return [
                'success' => $result['bundled'] ?? true,
                'bundle_id' => $result['bundle_id'] ?? $bundleId,
                'assets_included' => $result['assets_included'] ?? $assets,
                'original_size' => $result['original_size'] ?? 0,
                'bundled_size' => $result['bundled_size'] ?? 0,
                'compression_ratio' => $result['compression_ratio'] ?? 0.0,
                'ttl' => $result['ttl'] ?? $ttl
            ];
        }

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error creating bundle'
        ];
    }

    //=========================================================================
    // DAEMON MANAGEMENT
    //=========================================================================

    /**
     * Start the daemon process
     *
     * @return bool Success status
     */
    public function startDaemon(): bool
    {
        $daemonPath = $this->config['daemon_path'] ?? null;
        if (!$daemonPath || !file_exists($daemonPath)) {
            $this->debug("Invalid daemon path: {$daemonPath}");
            return false;
        }

        try {
            // Commit 1.12.b (NC-D2.02): escapeshellarg every env value
            // before building the shell string. Pre-fix single-quote
            // wrapping was a no-op for any value containing `'`.
            //
            // Commit NC-D2.05.b: prefer passing GNODE_REDIS_AUTH_FILE (a
            // file path) over REDIS_AUTH (the password) so the password
            // never lands in /proc/<pid>/environ or /proc/<pid>/cmdline.
            // The daemon reads the file itself (requires matching daemon
            // support via --redis-auth-file, landed in gNode SHA 6fe264f).
            // Explicit override via config['redis_auth_file'] wins;
            // otherwise auto-resolve via CredentialResolver for the
            // gnode_daemon ACL. Fall back to password-passing only if
            // neither path is resolvable (dev boxes without credential
            // files).
            $authFile = $this->config['redis_auth_file']
                ?? CredentialResolver::tryResolveFilePath('gnode_daemon');

            $env = [
                'REDIS_HOST' => $this->config['redis_host'] ?? '127.0.0.1',
                'REDIS_PORT' => $this->config['redis_port'] ?? '47445',
                'SITE_ID' => $this->siteId,
                'NODE_ID' => $this->nodeId,
                'STREAM_PREFIX' => $this->config['stream_prefix'],
                'USE_UNIFIED_STREAM' => '1',
                'RUST_LOG' => 'info',
                'DEBUG' => $this->config['debug'] ? '1' : '0'
            ];
            if ($authFile !== null && $authFile !== '') {
                $env['GNODE_REDIS_AUTH_FILE'] = $authFile;
            } else {
                // Dev fallback: pass password inline. Logs an explicit
                // warning so the operator knows this path was taken.
                $env['REDIS_AUTH'] = $this->config['redis_auth'] ?? '';
                $this->debug(
                    "NC-D2.05: no readable daemon credential file found; "
                    . "falling back to REDIS_AUTH env (password will appear "
                    . "in /proc/<pid>/environ). Set redis_auth_file in "
                    . "config or provision "
                    . "/etc/geodineum/credentials/valkey_daemon.password "
                    . "to enable the file-path path."
                );
            }

            $envStr = '';
            foreach ($env as $key => $value) {
                if (!preg_match('/\A[A-Z_][A-Z0-9_]*\z/', (string) $key)) {
                    throw new \RuntimeException("invalid env var name: {$key}");
                }
                $envStr .= $key . '=' . escapeshellarg((string) $value) . ' ';
            }

            $logPath = $this->config['log_path'] ?? '/tmp/gnode-daemon.log';
            $command = $envStr
                . escapeshellarg((string) $daemonPath)
                . ' --site-id ' . escapeshellarg($this->siteId)
                . ' --node-id ' . escapeshellarg($this->nodeId)
                . ' --debug > ' . escapeshellarg((string) $logPath)
                . ' 2>&1 & echo $!';
            $pid = self::safePid(exec($command));

            if ($pid === '') {
                $this->debug("Daemon spawn returned no/invalid PID");
                return false;
            }

            $this->debug("Started gNode daemon with PID: {$pid}");

            $pidKey = sprintf('{%s}:%s:daemon:pid:%s', $this->siteId, $this->config['stream_prefix'], $this->nodeId);
            $this->luaSet($pidKey, $pid);

            return true;
        } catch (\Exception $e) {
            $this->debug("Failed to start daemon: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Stop the daemon process
     *
     * @return bool Success status
     */
    public function stopDaemon(): bool
    {
        $pidKey = sprintf('%s:%s:daemon:pid:%s', $this->siteId, $this->config['stream_prefix'], $this->nodeId);
        $pid = self::safePid($this->luaGet($pidKey));

        if ($pid === '') {
            $this->debug("No valid PID found for daemon");
            return false;
        }

        try {
            // Commit 1.12.b (NC-D2.02): escapeshellarg the PID before
            // every shell interpolation. safePid already gates to
            // ctype_digit; this is belt-and-suspenders.
            $pidArg = escapeshellarg($pid);

            $command = "kill {$pidArg} 2>/dev/null || true";
            exec($command);

            $attempts = 0;
            $maxAttempts = $this->config['retry_count'] ?? 3;
            $delay = $this->config['retry_delay'] ?? 0.1;

            while ($attempts < $maxAttempts) {
                sleep($delay);
                $attempts++;

                $command = "ps -p {$pidArg} > /dev/null 2>&1 || echo 'not-running'";
                $result = exec($command);

                if ($result === 'not-running') {
                    $this->debug("gNode daemon (PID: {$pid}) stopped successfully");
                    $this->luaDel($pidKey);
                    $this->connected = false;
                    return true;
                }
            }

            $command = "kill -9 {$pidArg} 2>/dev/null || true";
            exec($command);

            $this->debug("gNode daemon (PID: {$pid}) forcefully terminated");
            $this->luaDel($pidKey);
            $this->connected = false;
            return true;
        } catch (\Exception $e) {
            $this->debug("Failed to stop daemon: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Get daemon status
     *
     * @return array Status information
     */
    public function getDaemonStatus(): array
    {
        $pidKey = sprintf('%s:%s:daemon:pid:%s', $this->siteId, $this->config['stream_prefix'], $this->nodeId);
        $pid = self::safePid($this->luaGet($pidKey));

        if ($pid === '') {
            return [
                'running' => false,
                'pid' => null,
                'connected' => $this->connected,
                'uptime' => 0
            ];
        }

        // Commit 1.12.b (NC-D2.02): escapeshellarg the validated PID.
        $command = "ps -p " . escapeshellarg($pid) . " -o etime= 2>/dev/null || echo ''";
        $uptime = trim(exec($command));

        $running = !empty($uptime);

        return [
            'running' => $running,
            'pid' => $pid,
            'connected' => $this->connected,
            'uptime' => $uptime
        ];
    }

    //=========================================================================
    // CONFIGURATION COMMANDS
    //=========================================================================

    /**
     * Get daemon configuration value
     *
     * @param string $key Configuration key
     * @return mixed Configuration value
     */
    public function configGet(string $key)
    {
        $response = $this->executeCommand('config_get', ['key' => $key]);
        return $response['value'] ?? null;
    }

    /**
     * Set daemon configuration value
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return bool Success
     */
    public function configSet(string $key, $value): bool
    {
        $response = $this->executeCommand('config_set', [
            'key' => $key,
            'value' => $value
        ]);
        return isset($response['updated']) && $response['updated'] === true;
    }

    /**
     * List all daemon configuration
     *
     * @return array Configuration map
     */
    public function configList(): array
    {
        return $this->executeCommand('config_list', []) ?? [];
    }

    //=========================================================================
    // DIAGNOSTIC COMMANDS
    //=========================================================================

    /**
     * Get detailed debug information
     *
     * @return array Debug information
     */
    public function getDebugInfo(): array
    {
        return $this->executeCommand('debug_info', []) ?? [];
    }

    /**
     * Get daemon memory statistics
     *
     * @return array Memory stats
     */
    public function getMemoryStats(): array
    {
        return $this->executeCommand('memory_stats', []) ?? [];
    }

    /**
     * Get daemon thread status
     *
     * @return array Thread status
     */
    public function getThreadStatus(): array
    {
        return $this->executeCommand('thread_status', []) ?? [];
    }

    /**
     * Get ValKey connection status
     *
     * @return array Connection status
     */
    public function getConnectionStatus(): array
    {
        return $this->executeCommand('connection_status', []) ?? [];
    }

    /**
     * Get daemon performance metrics
     *
     * @return array Performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        return $this->executeCommand('performance_metrics', []) ?? [];
    }

    /**
     * Get security status
     *
     * @return array Security status
     */
    public function getSecurityStatus(): array
    {
        return $this->executeCommand('security_status', []) ?? [];
    }

    /**
     * Get topology status
     *
     * @return array Topology status
     */
    public function getTopologyStatus(): array
    {
        return $this->executeCommand('topology_status', []) ?? [];
    }

    //=========================================================================
    // ADDITIONAL SYSTEM COMMANDS
    //=========================================================================

    /**
     * Health check endpoint
     *
     * @return array Health status
     */
    public function health(): array
    {
        return $this->executeCommand('health', []) ?? [];
    }

    /**
     * Get daemon version information
     *
     * @return array Version information
     */
    public function version(): array
    {
        return $this->executeCommand('version', []) ?? [];
    }

    /**
     * Echo command for testing
     *
     * @param mixed $message Message to echo
     * @return mixed Echoed message
     */
    public function echo($message = null)
    {
        $params = $message !== null ? ['message' => $message] : [];
        $result = $this->executeCommand('echo', $params);
        // Return the echoed message string
        return $result['result'] ?? $result['message'] ?? $message;
    }

    /**
     * Get daemon status and system information
     *
     * @param string $detail Level of detail
     * @return array Daemon status information
     */
    public function status(string $detail = 'basic')
    {
        return $this->executeCommand('status', ['detail' => $detail]) ?? [];
    }

    /**
     * Get information about a node
     *
     * @param string $node Node identifier
     * @return array Node information
     */
    public function getNodeInfo(string $node = 'default'): array
    {
        $response = $this->sendCommand('get_node_info', ['node' => $node]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [];
        }

        return [];
    }

    /**
     * Get information about a site
     *
     * @param string $site Site identifier
     * @return array Site information
     */
    public function getSiteInfo(string $site = 'default'): array
    {
        $response = $this->sendCommand('get_site_info', ['site' => $site]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [];
        }

        return [];
    }

    /**
     * Get the command queue instance
     *
     * @return \gCore\gNode\Queue\CommandQueue|null
     */
    public function getQueue(): ?\gCore\gNode\Queue\CommandQueue
    {
        return $this->config['queue'] ?? null;
    }

    //=========================================================================
    // SMART BATCH ROUTING
    //=========================================================================

    /**
     * Smart batch execution that routes to optimal path
     *
     * Routes commands to either Lua batch (fast path) or stream (for daemon-required ops).
     *
     * @param array $commands Array of ['command', 'params'] or ['cmd' => ..., 'params' => ...]
     * @return array Results indexed by command position
     */
    public function smartBatch(array $commands): array
    {
        if (empty($commands)) {
            return [];
        }

        $startTime = microtime(true);
        $results = [];
        $luaOps = [];
        $luaIndexMap = [];
        $streamCommands = [];

        // 1. Separate commands by path
        foreach ($commands as $index => $cmdData) {
            if (isset($cmdData['cmd'])) {
                $command = $cmdData['cmd'];
                $params = $cmdData['params'] ?? [];
            } else {
                $command = $cmdData[0] ?? '';
                $params = $cmdData[1] ?? [];
            }

            if ($this->requiresDaemon($command)) {
                $streamCommands[$index] = [$command, $params];
            } else {
                $luaOp = $this->commandToLuaOp($command, $params);
                if ($luaOp !== null) {
                    $luaIndexMap[count($luaOps)] = $index;
                    $luaOps[] = $luaOp;
                } else {
                    $streamCommands[$index] = [$command, $params];
                }
            }
        }

        // 2. Execute Lua batch operations (fast path)
        if (!empty($luaOps)) {
            $luaResults = $this->batchExec($luaOps);
            foreach ($luaResults as $luaIndex => $result) {
                $originalIndex = $luaIndexMap[$luaIndex];
                $results[$originalIndex] = [
                    'status' => 'ok',
                    'result' => $result,
                    'path' => 'lua_batch'
                ];
            }
        }

        // 3. Execute stream commands (slow path, only if needed)
        if (!empty($streamCommands)) {
            $streamResults = $this->executeBatchViaStream($streamCommands);
            foreach ($streamResults as $index => $result) {
                $result['path'] = 'stream';
                $results[$index] = $result;
            }
        }

        // 4. Sort results by original index
        ksort($results);

        $latency = round((microtime(true) - $startTime) * 1000, 3);
        $this->trackMetric('smart_batch', count($commands), [
            'latency_ms' => $latency,
            'lua_ops' => count($luaOps),
            'stream_ops' => count($streamCommands)
        ]);

        $this->debug("smartBatch: " . count($luaOps) . " lua + " . count($streamCommands) . " stream in {$latency}ms");

        return $results;
    }

    /**
     * Check if a command requires daemon processing
     *
     * @param string $command Command name
     * @return bool True if command must go through stream
     */
    protected function requiresDaemon(string $command): bool
    {
        $normalized = strtolower($command);
        return in_array($normalized, array_map('strtolower', self::DAEMON_REQUIRED_COMMANDS), true);
    }

    /**
     * Convert a high-level command to a Lua batch operation
     *
     * @param string $command Command name
     * @param array $params Command parameters
     * @return array|null Lua operation array or null if not convertible
     */
    protected function commandToLuaOp(string $command, array $params): ?array
    {
        $cmd = strtolower($command);

        switch ($cmd) {
            case 'cache_get':
            case 'get':
                return ['GET', $params['key'] ?? $params[0] ?? ''];

            case 'cache_set':
            case 'set':
                return [
                    'SET',
                    $params['key'] ?? $params[0] ?? '',
                    $params['value'] ?? $params[1] ?? '',
                    $params['ttl'] ?? $params[2] ?? 0
                ];

            case 'cache_del':
            case 'del':
            case 'delete':
                return ['DEL', $params['key'] ?? $params[0] ?? ''];

            case 'exists':
                return ['EXISTS', $params['key'] ?? $params[0] ?? ''];

            case 'incr':
            case 'increment':
                return ['INCR', $params['key'] ?? $params[0] ?? ''];

            case 'decr':
            case 'decrement':
                return ['DECR', $params['key'] ?? $params[0] ?? ''];

            case 'ttl':
                return ['TTL', $params['key'] ?? $params[0] ?? ''];

            case 'expire':
                return [
                    'EXPIRE',
                    $params['key'] ?? $params[0] ?? '',
                    $params['ttl'] ?? $params[1] ?? 0
                ];

            case 'hget':
                return [
                    'HGET',
                    $params['key'] ?? $params[0] ?? '',
                    $params['field'] ?? $params[1] ?? ''
                ];

            case 'hset':
                return [
                    'HSET',
                    $params['key'] ?? $params[0] ?? '',
                    $params['field'] ?? $params[1] ?? '',
                    $params['value'] ?? $params[2] ?? ''
                ];

            case 'hdel':
                return [
                    'HDEL',
                    $params['key'] ?? $params[0] ?? '',
                    $params['field'] ?? $params[1] ?? ''
                ];

            case 'hincrby':
                return [
                    'HINCRBY',
                    $params['key'] ?? $params[0] ?? '',
                    $params['field'] ?? $params[1] ?? '',
                    $params['value'] ?? $params[2] ?? 1
                ];

            case 'content_retrieve':
                return ['GET', 'content:' . ($params['key'] ?? $params[0] ?? '')];

            case 'content_store':
                return [
                    'SET',
                    'content:' . ($params['key'] ?? $params[0] ?? ''),
                    $params['content'] ?? $params[1] ?? '',
                    $params['ttl'] ?? $params[2] ?? 0
                ];

            case 'ping':
                return ['GET', '__ping__'];

            case 'echo':
                return ['GET', '__echo__'];

            default:
                return null;
        }
    }

    /**
     * Execute commands via stream-based path
     *
     * @param array $commands Commands to execute
     * @return array Results indexed by original position
     */
    protected function executeBatchViaStream(array $commands): array
    {
        $indexedCommands = [];
        $indexMap = [];
        $i = 0;

        foreach ($commands as $originalIndex => $cmd) {
            $indexedCommands[$i] = $cmd;
            $indexMap[$i] = $originalIndex;
            $i++;
        }

        $results = $this->executeBatch($indexedCommands);

        $mappedResults = [];
        foreach ($results as $idx => $result) {
            $mappedResults[$indexMap[$idx]] = $result;
        }

        return $mappedResults;
    }

    //=========================================================================
    // WORDPRESS-SPECIFIC CONTENT METHODS
    //=========================================================================

    /**
     * Get bundled content by key
     *
     * @param string $key Bundle key
     * @return array|null Bundled content or null
     */
    public function getBundled(string $key, bool $decompress = true)
    {
        $startTime = microtime(true);
        $bundleKey = "{{$this->siteId}}:gnode:bundle:{$key}";
        $data = $this->luaGet($bundleKey);

        if ($data === false || $data === null) {
            $this->trackMetric('bundle_miss', 1, ['key' => $key]);
            return null;
        }

        $latency = round((microtime(true) - $startTime) * 1000, 3);
        $this->trackMetric('bundle_hit', 1, ['key' => $key, 'latency_ms' => $latency]);

        if (!$decompress) {
            return $data;
        }

        // Daemon-built bundles are gzip-compressed; try decompression first
        if (is_string($data) && !str_starts_with($data, '{') && !str_starts_with($data, '[')) {
            $decompressed = @gzdecode($data);
            if ($decompressed !== false) {
                $data = $decompressed;
            }
        }

        if (is_string($data)) {
            $decoded = $this->safeJsonDecode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Get rendered face/hero HTML fragment
     *
     * @return string|null Face HTML or null
     */
    public function getFaceHtml(): ?string
    {
        $key = "{{$this->siteId}}:gnode:face_html";
        $html = $this->luaGet($key);
        return $html !== false ? $html : null;
    }

    /**
     * Get navigation menu structure
     *
     * @return array|null Menu structure or null
     */
    public function getNavigationMenu(): ?array
    {
        $key = "{{$this->siteId}}:gnode:nav_menu";
        $data = $this->luaGet($key);

        if ($data === false) {
            return null;
        }

        $decoded = $this->safeJsonDecode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get paginated posts list
     *
     * @param int $limit Maximum posts to return
     * @param int $offset Pagination offset
     * @return array Posts list
     */
    public function getPostsList(int $limit = 10, int $offset = 0): array
    {
        $key = "{{$this->siteId}}:gnode:posts_list";
        $data = $this->luaGet($key);

        if ($data === false) {
            return [];
        }

        $allPosts = $this->safeJsonDecode($data, true) ?? [];
        return array_slice($allPosts, $offset, $limit);
    }

    /**
     * Get site metadata bundle
     *
     * @return array Site metadata
     */
    public function getSiteMetadata(): array
    {
        $key = "{{$this->siteId}}:gnode:site_metadata";
        $data = $this->luaGet($key);

        if ($data === false) {
            return [];
        }

        return $this->safeJsonDecode($data, true) ?? [];
    }

    //=========================================================================
    // TOPOLOGY REGISTRATION (Human-Readable Capabilities)
    //=========================================================================

    /**
     * Get the full capability schema with human-readable value mappings
     *
     * This is the canonical 23-dimension semantic topology schema.
     * Use this to understand what values are valid for each dimension.
     *
     * Dimensions 0-18: Discovery dimensions (used for bucket key hashing)
     * Dimensions 19-21: Visual topology (user_x, user_y, user_z) - storage-only
     * Dimension 22: Temporal (registration_order) - storage-only, auto-injected
     *
     * @return array Full schema with dimensions and value mappings
     */
    public static function getCapabilitySchema(): array
    {
        return [
            'schema_version' => '2.0',
            'total_dimensions' => 23,
            'dimensions' => [
                // Layer 1: Interface Identity (0-3)
                'protocol' => [
                    'index' => 0,
                    'layer' => 'interface_identity',
                    'query_type' => 'equality',
                    'values' => [
                        'undefined' => 0.00,
                        'http_rest' => 0.10,
                        'graphql' => 0.20,
                        'grpc' => 0.30,
                        'websocket' => 0.40,
                        'gnode_stream' => 0.50,
                        'resp3_direct' => 0.60,
                        'amqp' => 0.70,
                        'kafka' => 0.80,
                        'custom_tcp' => 0.90,
                    ],
                ],
                'native_format' => [
                    'index' => 1,
                    'layer' => 'interface_identity',
                    'query_type' => 'informational',
                    'values' => [
                        'undefined' => 0.00,
                        'plaintext' => 0.10,
                        'json' => 0.20,
                        'xml' => 0.30,
                        'yaml' => 0.40,
                        'msgpack' => 0.50,
                        'protobuf' => 0.60,
                        'cbor' => 0.70,
                        'resp3' => 0.80,
                        'custom_binary' => 0.90,
                    ],
                ],
                'api_version' => [
                    'index' => 2,
                    'layer' => 'interface_identity',
                    'query_type' => 'equality',
                    'values' => [
                        'v1' => 0.10,
                        'v2' => 0.20,
                        'v3' => 0.30,
                        'v4' => 0.40,
                        'v5' => 0.50,
                    ],
                ],
                'contract_stability' => [
                    'index' => 3,
                    'layer' => 'interface_identity',
                    'query_type' => 'minimum',
                    'values' => [
                        'experimental' => 0.00,
                        'alpha' => 0.25,
                        'beta' => 0.50,
                        'stable' => 0.75,
                        'frozen' => 1.00,
                    ],
                ],
                // Layer 2: Access Control (4-6)
                'clearance_required' => [
                    'index' => 4,
                    'layer' => 'access_control',
                    'query_type' => 'maximum',
                    'values' => [
                        'public' => 0.00,
                        'authenticated' => 0.20,
                        'authorized' => 0.40,
                        'privileged' => 0.60,
                        'confidential' => 0.80,
                        'classified' => 1.00,
                    ],
                ],
                'auth_method' => [
                    'index' => 5,
                    'layer' => 'access_control',
                    'query_type' => 'equality',
                    'values' => [
                        'none' => 0.00,
                        'api_key' => 0.20,
                        'bearer_token' => 0.40,
                        'session_cookie' => 0.60,
                        'mtls' => 0.80,
                        'hardware_token' => 1.00,
                    ],
                ],
                'data_sensitivity' => [
                    'index' => 6,
                    'layer' => 'access_control',
                    'query_type' => 'informational',
                    'values' => [
                        'public_data' => 0.00,
                        'internal' => 0.25,
                        'confidential' => 0.50,
                        'pii' => 0.75,
                        'regulated' => 1.00,
                    ],
                ],
                // Layer 3: Service Scope (7)
                'service_scope' => [
                    'index' => 7,
                    'layer' => 'service_scope',
                    'query_type' => 'range',
                    'values' => [
                        'infrastructure' => 0.00,
                        'daemon' => 0.15,
                        'worker' => 0.30,
                        'cron_scheduled' => 0.45,
                        'internal_api' => 0.60,
                        'bff' => 0.75,
                        'client_facing' => 0.90,
                        'edge' => 1.00,
                    ],
                ],
                // Layer 4: Functional Domain (8-10)
                'domain_primary' => [
                    'index' => 8,
                    'layer' => 'functional_domain',
                    'query_type' => 'equality',
                    'values' => [
                        'platform' => 0.05,
                        'identity' => 0.10,
                        'configuration' => 0.15,
                        'storage' => 0.20,
                        'cache' => 0.25,
                        'compute' => 0.30,
                        'transform' => 0.35,
                        'messaging' => 0.40,
                        'workflow' => 0.45,
                        'template' => 0.50,
                        'content' => 0.55,
                        'gateway' => 0.60,
                        'integration' => 0.65,
                        'analytics' => 0.70,
                        'logging' => 0.75,
                        'ml_inference' => 0.80,
                        'search' => 0.85,
                        'notification' => 0.90,
                        'presentation' => 0.95,
                    ],
                ],
                'domain_secondary' => [
                    'index' => 9,
                    'layer' => 'functional_domain',
                    'query_type' => 'equality',
                    'values' => 'same as domain_primary',
                ],
                'specialization' => [
                    'index' => 10,
                    'layer' => 'functional_domain',
                    'query_type' => 'range',
                    'values' => [
                        'platform' => 0.00,
                        'generalist' => 0.25,
                        'focused' => 0.50,
                        'specialist' => 0.75,
                        'single_purpose' => 1.00,
                    ],
                ],
                // Layer 5: Performance Profile (11-13)
                'throughput_tier' => [
                    'index' => 11,
                    'layer' => 'performance',
                    'query_type' => 'minimum',
                    'values' => [
                        'minimal' => 0.00,
                        'standard' => 0.25,
                        'professional' => 0.50,
                        'enterprise' => 0.75,
                        'hyperscale' => 1.00,
                    ],
                ],
                'latency_class' => [
                    'index' => 12,
                    'layer' => 'performance',
                    'query_type' => 'maximum',
                    'values' => [
                        'realtime' => 0.00,
                        'interactive' => 0.25,
                        'responsive' => 0.50,
                        'patient' => 0.75,
                        'batch' => 1.00,
                    ],
                ],
                'reliability_tier' => [
                    'index' => 13,
                    'layer' => 'performance',
                    'query_type' => 'minimum',
                    'values' => [
                        'best_effort' => 0.00,
                        'standard' => 0.25,
                        'high' => 0.50,
                        'critical' => 0.75,
                        'ultra' => 1.00,
                    ],
                ],
                // Layer 6: Workflow Context (14-15)
                'pipeline_stage' => [
                    'index' => 14,
                    'layer' => 'workflow',
                    'query_type' => 'range',
                    'values' => [
                        'source' => 0.00,
                        'ingest' => 0.20,
                        'process' => 0.40,
                        'enrich' => 0.60,
                        'deliver' => 0.80,
                        'sink' => 1.00,
                    ],
                ],
                'execution_priority' => [
                    'index' => 15,
                    'layer' => 'workflow',
                    'query_type' => 'minimum',
                    'values' => [
                        'background' => 0.00,
                        'low' => 0.25,
                        'normal' => 0.50,
                        'high' => 0.75,
                        'critical' => 1.00,
                    ],
                ],
                // Layer 7: Runtime State (16)
                'current_load' => [
                    'index' => 16,
                    'layer' => 'runtime',
                    'query_type' => 'maximum',
                    'values' => [
                        'idle' => 0.00,
                        'light' => 0.25,
                        'moderate' => 0.50,
                        'heavy' => 0.75,
                        'saturated' => 1.00,
                    ],
                ],
                // Layer 8: Classification (17-18)
                'service_tier' => [
                    'index' => 17,
                    'layer' => 'classification',
                    'query_type' => 'range',
                    'values' => [
                        'TOOL' => 0.10,             // Global utilities, managers
                        'SERVICE' => 0.30,           // Business logic, WordPress sites
                        'PIPELINE' => 0.50,          // Discovery, registry services
                        'INFRASTRUCTURE' => 0.70,    // Data pipelines, ETL
                        'ORCHESTRATOR' => 0.90,      // gNode daemons, orchestrators
                        // Backward-compatible aliases
                        'FORUM' => 0.50,
                        'AQUEDUCT' => 0.70,
                        'ROME' => 0.90,
                    ],
                ],
                'environment' => [
                    'index' => 18,
                    'layer' => 'classification',
                    'query_type' => 'equality',
                    'values' => [
                        'global' => 0.00,      // Tools, infrastructure (no environment)
                        'testing' => 0.25,     // Development, feature branches
                        'staging' => 0.50,     // Pre-production validation
                        'acceptance' => 0.75,  // UAT, client approval
                        'production' => 1.00,  // Live traffic
                    ],
                ],

                // Layer 9: Visual Topology (19-21) - User-set visual positioning
                'user_x' => [
                    'index' => 19,
                    'layer' => 'visual_topology',
                    'query_type' => 'range',
                    'values' => ['left' => 0.00, 'center' => 0.50, 'right' => 1.00],
                ],
                'user_y' => [
                    'index' => 20,
                    'layer' => 'visual_topology',
                    'query_type' => 'range',
                    'values' => ['bottom' => 0.00, 'middle' => 0.50, 'top' => 1.00],
                ],
                'user_z' => [
                    'index' => 21,
                    'layer' => 'visual_topology',
                    'query_type' => 'range',
                    'values' => ['back' => 0.00, 'center' => 0.50, 'front' => 1.00],
                ],

                // Layer 10: Temporal (22) - Auto-computed registration order
                'registration_order' => [
                    'index' => 22,
                    'layer' => 'temporal',
                    'query_type' => 'range',
                    'values' => ['first' => 0.00, 'early' => 0.25, 'middle' => 0.50, 'late' => 0.75, 'recent' => 1.00],
                ],
            ],
        ];
    }

    /**
     * Auto-inject classification dimensions (17-18) from metadata
     *
     * Converts metadata tier/environment strings to geometric coordinates:
     * - Dimension 17 (service_tier): TOOL=0.10, SERVICE=0.30, FORUM=0.50, AQUEDUCT=0.70, ROME=0.90
     * - Dimension 18 (environment): global=0.00, testing=0.25, staging=0.50, acceptance=0.75, production=1.00
     *
     * This ensures all services use the 23-dimension schema for proper geometric discovery.
     *
     * @param array $capabilities Existing capability vector
     * @param array $metadata Service metadata (may contain 'tier' and 'environment')
     * @return array Updated capability vector with dims 17-18 injected + visual defaults
     */
    private function injectClassificationDimensions(array $capabilities, array $metadata): array
    {
        // Tier coordinates (dimension 17)
        static $tierCoordinates = [
            'TOOL' => 0.10,
            'SERVICE' => 0.30,
            'PIPELINE' => 0.50,
            'INFRASTRUCTURE' => 0.70,
            'ORCHESTRATOR' => 0.90,
            // Backward-compatible aliases
            'FORUM' => 0.50,
            'AQUEDUCT' => 0.70,
            'ROME' => 0.90,
        ];

        // Environment coordinates (dimension 18)
        static $envCoordinates = [
            'global' => 0.00,
            'testing' => 0.25,
            'staging' => 0.50,
            'acceptance' => 0.75,
            'production' => 1.00
        ];

        // Inject tier coordinate if metadata has tier
        if (isset($metadata['tier'])) {
            $tier = strtoupper($metadata['tier']);
            $capabilities['service_tier'] = $tierCoordinates[$tier] ?? $tierCoordinates['SERVICE'];
        } elseif (!isset($capabilities['service_tier'])) {
            // Default to SERVICE tier if not specified
            $capabilities['service_tier'] = $tierCoordinates['SERVICE'];
        }

        // Inject environment coordinate if metadata has environment
        if (isset($metadata['environment'])) {
            $env = strtolower($metadata['environment']);
            $capabilities['environment'] = $envCoordinates[$env] ?? $envCoordinates['production'];
        } elseif (!isset($capabilities['environment'])) {
            // Default to production if not specified
            $capabilities['environment'] = $envCoordinates['production'];
        }

        // Layer 9: Visual Topology defaults (dims 19-21)
        // Center position (0.5) as default - user can override
        if (!isset($capabilities['user_x'])) {
            $capabilities['user_x'] = 0.50;
        }
        if (!isset($capabilities['user_y'])) {
            $capabilities['user_y'] = 0.50;
        }
        if (!isset($capabilities['user_z'])) {
            $capabilities['user_z'] = 0.50;
        }

        // Layer 10: registration_order (dim 22) is auto-injected by Lua
        // Do NOT set it here - Lua's GNODE_REGISTER_CAPABILITY_VECTOR handles it atomically

        return $capabilities;
    }

    /**
     * Translate human-readable capability names to geometric coordinates
     *
     * @param array $capabilities Associative array of dimension_name => value_name
     * @return array Array of 23 float coordinates (0.0-1.0) - 19 discovery + 3 visual + 1 temporal
     */
    public function translateCapabilitiesToCoordinates(array $capabilities): array
    {
        $schema = self::getCapabilitySchema();
        $coordinates = array_fill(0, 23, 0.0); // 23 dimensions (0-22)

        foreach ($capabilities as $dimensionName => $valueName) {
            if (!isset($schema['dimensions'][$dimensionName])) {
                throw new \InvalidArgumentException("Unknown dimension: {$dimensionName}");
            }

            $dimension = $schema['dimensions'][$dimensionName];
            $index = $dimension['index'];

            // Handle numeric values directly
            if (is_numeric($valueName)) {
                $coordinates[$index] = (float)$valueName;
                continue;
            }

            // domain_secondary shares values with domain_primary
            $values = $dimension['values'];
            if ($values === 'same as domain_primary') {
                $values = $schema['dimensions']['domain_primary']['values'];
            }

            if (!isset($values[$valueName])) {
                $validValues = implode(', ', array_keys($values));
                throw new \InvalidArgumentException(
                    "Invalid value '{$valueName}' for dimension '{$dimensionName}'. Valid values: {$validValues}"
                );
            }

            $coordinates[$index] = $values[$valueName];
        }

        return $coordinates;
    }

    /**
     * Translate geometric coordinates back to human-readable capability names
     *
     * @param array $coordinates Array of 23 float coordinates
     * @return array Associative array of dimension_name => value_name (closest match)
     */
    public function translateCoordinatesToHuman(array $coordinates): array
    {
        $schema = self::getCapabilitySchema();
        $result = [];

        foreach ($schema['dimensions'] as $dimensionName => $dimension) {
            $index = $dimension['index'];
            $coordinate = $coordinates[$index] ?? 0.0;

            // Handle domain_secondary's shared values
            $values = $dimension['values'];
            if ($values === 'same as domain_primary') {
                $values = $schema['dimensions']['domain_primary']['values'];
            }

            // Find the closest matching value
            $closestName = 'undefined';
            $closestDistance = PHP_FLOAT_MAX;

            foreach ($values as $name => $value) {
                $distance = abs($value - $coordinate);
                if ($distance < $closestDistance) {
                    $closestDistance = $distance;
                    $closestName = $name;
                }
            }

            $result[$dimensionName] = [
                'value' => $closestName,
                'coordinate' => $coordinate,
                'exact_match' => $closestDistance < 0.001,
            ];
        }

        return $result;
    }

    /**
     * Get human-readable description of a service's capabilities
     *
     * Fetches a service from the topology and returns its capabilities
     * in human-readable format.
     *
     * @param string $serviceId Service identifier
     * @return array|null Human-readable capabilities or null if not found
     */
    public function getServiceCapabilities(string $serviceId): ?array
    {
        $topology = $this->getTopology();

        if (!isset($topology['services'][$serviceId])) {
            return null;
        }

        $service = $topology['services'][$serviceId];
        $coordinates = $service['point'] ?? [];

        if (empty($coordinates)) {
            return null;
        }

        return [
            'service_id' => $serviceId,
            'capabilities' => $this->translateCoordinatesToHuman($coordinates),
            'metadata' => $service['metadata'] ?? [],
            'registered_at' => $service['registered_at'] ?? null,
        ];
    }

    /**
     * Discover services using human-readable capability requirements
     *
     * Example:
     *   $services = $client->discoverByCapabilities([
     *       'protocol' => 'http_rest',
     *       'domain_primary' => 'compute',
     *       'latency_class' => ['max' => 'responsive'],  // Use constraint
     *   ]);
     *
     * @param array $requirements Human-readable requirements
     * @param int $limit Maximum services to return
     * @return array Array of matching services with human-readable capabilities
     */
    public function discoverByCapabilities(array $requirements, int $limit = 10): array
    {
        // Translate requirements to coordinates for geometric discovery
        $coordinates = [];
        foreach ($requirements as $dimension => $value) {
            // Handle constraint syntax (e.g., ['max' => 'responsive'])
            if (is_array($value)) {
                // Complex constraint - will be handled by discoverRange
                continue;
            }
            $coords = $this->translateCapabilitiesToCoordinates([$dimension => $value]);
            $coordinates[$this->getDimensionIndex($dimension)] = $coords[$this->getDimensionIndex($dimension)];
        }

        // Use geometric discovery
        $services = $this->geometricDiscover($coordinates, $limit, 17, 0);

        // Enrich results with human-readable capabilities
        $enriched = [];
        foreach ($services as $service) {
            $serviceId = $service['id'] ?? $service['service_id'] ?? null;
            if ($serviceId) {
                $capabilities = $this->getServiceCapabilities($serviceId);
                if ($capabilities) {
                    $enriched[] = $capabilities;
                } else {
                    $enriched[] = $service;
                }
            }
        }

        return $enriched;
    }

    //=========================================================================
    // SERVICE + FORMAT REGISTRATION (Combined Registration)
    //=========================================================================

    /**
     * Register a service with its API format definition
     *
     * This method registers both the service's topology capabilities AND its
     * message format in a single operation. The format defines how to parse
     * messages from/to this service, enabling gNode to translate between formats.
     *
     * Example:
     *   $client->registerServiceWithFormat('my-rest-api', [
     *       'protocol' => 'http_rest',
     *       'native_format' => 'json',
     *       'domain_primary' => 'compute',
     *   ], [
     *       'endpoint' => 'https://api.example.com/v1',
     *       'version' => '1.0.0',
     *   ], [
     *       'schema' => [
     *           'type' => 'object',
     *           'required' => ['action', 'data'],
     *           'properties' => [
     *               'action' => ['type' => 'string'],
     *               'data' => ['type' => 'object'],
     *               'timestamp' => ['type' => 'number'],
     *           ],
     *       ],
     *       'patterns' => ['/^\\{\\s*"action":/'],
     *       'field_mapping' => [
     *           'action' => 'command',      // Maps to gNode's command field
     *           'data' => 'parameters',     // Maps to gNode's parameters field
     *           'timestamp' => 'timestamp',
     *       ],
     *   ]);
     *
     * @param string $serviceId Unique service identifier
     * @param array $capabilities Human-readable capability names
     * @param array $metadata Service metadata (endpoint, version, etc.)
     * @param array $format API format definition with schema, patterns, and field mappings
     * @return array Result with 'service_registered' and 'format_registered' booleans
     * @throws gNodeException On registration failure
     */
    public function registerServiceWithFormat(
        string $serviceId,
        array $capabilities,
        array $metadata = [],
        array $format = []
    ): array {
        $result = [
            'service_registered' => false,
            'format_registered' => false,
            'format_name' => null,
        ];

        // Step 1: Register the service in topology
        $result['service_registered'] = $this->registerService($serviceId, $capabilities, $metadata);

        // Step 2: Register the format if provided
        if (!empty($format)) {
            // Use service ID as format name if not specified
            $formatName = $format['name'] ?? "service:{$serviceId}";

            $formatDefinition = [
                'name' => $formatName,
                'schema' => $format['schema'] ?? $this->generateDefaultSchema($capabilities),
                'patterns' => $format['patterns'] ?? $this->generateDefaultPatterns($capabilities),
                'description' => $format['description'] ?? "API format for service: {$serviceId}",
                'version' => $format['version'] ?? $metadata['version'] ?? '1.0.0',
                'metadata' => array_merge([
                    'service_id' => $serviceId,
                    'endpoint' => $metadata['endpoint'] ?? null,
                    'content_type' => $this->getContentTypeForFormat($capabilities),
                ], $format['metadata'] ?? []),
            ];

            // Include field mapping if provided (for translating to/from RESP3)
            if (isset($format['field_mapping'])) {
                $formatDefinition['field_mapping'] = $format['field_mapping'];
            }

            try {
                $result['format_registered'] = $this->registerFormat($formatDefinition);
                $result['format_name'] = $formatName;
            } catch (\Exception $e) {
                $this->debug("Format registration failed: " . $e->getMessage());
                // Service is registered but format failed - partial success
            }
        }

        return $result;
    }

    /**
     * Generate default JSON schema based on capabilities
     *
     * @param array $capabilities Service capabilities
     * @return array Default JSON schema
     */
    protected function generateDefaultSchema(array $capabilities): array
    {
        $format = $capabilities['native_format'] ?? 'json';

        // Default schema for JSON-based services
        if (in_array($format, ['json', 'undefined'])) {
            return [
                '$schema' => 'http://json-schema.org/draft-07/schema#',
                'type' => 'object',
                'required' => ['command'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'Request identifier',
                    ],
                    'command' => [
                        'type' => 'string',
                        'description' => 'Command/action to execute',
                    ],
                    'parameters' => [
                        'type' => 'object',
                        'description' => 'Command parameters',
                        'additionalProperties' => true,
                    ],
                    'timestamp' => [
                        'type' => 'number',
                        'description' => 'Unix timestamp',
                    ],
                ],
                'additionalProperties' => true,
            ];
        }

        // For other formats, return a permissive schema
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'additionalProperties' => true,
        ];
    }

    /**
     * Generate default detection patterns based on capabilities
     *
     * @param array $capabilities Service capabilities
     * @return array Detection patterns
     */
    protected function generateDefaultPatterns(array $capabilities): array
    {
        $format = $capabilities['native_format'] ?? 'json';

        switch ($format) {
            case 'json':
                return ['/^\\s*\\{/'];  // Starts with {
            case 'xml':
                return ['/^\\s*<\\?xml|^\\s*</'];  // XML declaration or tag
            case 'yaml':
                return ['/^---\\s*$|^[a-zA-Z_]+:/m'];  // YAML document start or key:
            case 'msgpack':
            case 'protobuf':
            case 'cbor':
                return ['/[\\x00-\\x1f]/'];  // Contains binary characters
            default:
                return ['/./'];  // Match anything
        }
    }

    /**
     * Get content type for service format
     *
     * @param array $capabilities Service capabilities
     * @return string Content type
     */
    protected function getContentTypeForFormat(array $capabilities): string
    {
        $format = $capabilities['native_format'] ?? 'json';

        $contentTypes = [
            'json' => 'application/json',
            'xml' => 'application/xml',
            'yaml' => 'application/x-yaml',
            'msgpack' => 'application/msgpack',
            'protobuf' => 'application/x-protobuf',
            'cbor' => 'application/cbor',
            'resp3' => 'application/x-resp3',
            'plaintext' => 'text/plain',
        ];

        return $contentTypes[$format] ?? 'application/octet-stream';
    }

    /**
     * Get a service's registered format definition
     *
     * @param string $serviceId Service identifier
     * @return array|null Format definition or null if not found
     */
    public function getServiceFormat(string $serviceId): ?array
    {
        $formatName = "service:{$serviceId}";

        try {
            $formats = $this->listRegisteredFormats();
            foreach ($formats as $format) {
                if ($format['name'] === $formatName) {
                    return $format;
                }
                // Also check metadata for service_id
                if (isset($format['metadata']['service_id']) && $format['metadata']['service_id'] === $serviceId) {
                    return $format;
                }
            }
        } catch (\Exception $e) {
            $this->debug("Error fetching service format: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Convert a message to a service's native format
     *
     * Translates a gNode internal message (RESP3/JSON) to the target service's
     * native format using the registered format definition.
     *
     * @param array|string $message Message to convert
     * @param string $targetServiceId Target service identifier
     * @return string Converted message in target format
     * @throws gNodeException If service format not found
     */
    public function convertToServiceFormat($message, string $targetServiceId): string
    {
        $format = $this->getServiceFormat($targetServiceId);

        if ($format === null) {
            throw new gNodeException("No format registered for service: {$targetServiceId}");
        }

        // If message is an array, encode as JSON first
        if (is_array($message)) {
            $message = json_encode($message, JSON_THROW_ON_ERROR);
        }

        return $this->convertMessageFormat($message, 'json_internal', $format['name']);
    }

    /**
     * Convert a message from a service's native format to gNode internal format
     *
     * @param string $message Message from service
     * @param string $sourceServiceId Source service identifier
     * @return array Parsed message in gNode internal format
     * @throws gNodeException If service format not found or conversion fails
     */
    public function convertFromServiceFormat(string $message, string $sourceServiceId): array
    {
        $format = $this->getServiceFormat($sourceServiceId);

        if ($format === null) {
            // Try to detect format automatically
            $detectedFormat = $this->detectMessageFormat($message);
            if ($detectedFormat !== null) {
                $converted = $this->convertMessageFormat($message, $detectedFormat, 'json_internal');
                return $this->safeJsonDecode($converted, true) ?? ['raw' => $message];
            }

            // Fallback: assume JSON
            $decoded = $this->safeJsonDecode($message, true);
            if ($decoded !== null) {
                return $decoded;
            }

            throw new gNodeException("Cannot parse message from service: {$sourceServiceId}");
        }

        $converted = $this->convertMessageFormat($message, $format['name'], 'json_internal');
        return $this->safeJsonDecode($converted, true) ?? ['raw' => $message];
    }

    /**
     * Register a format translation rule
     *
     * Allows defining how to translate between two formats, enabling gNode
     * to act as a format bridge between services with different native formats.
     *
     * @param string $sourceFormat Source format name
     * @param string $targetFormat Target format name
     * @param array $mappings Field mappings from source to target
     * @return bool True on success
     */
    public function registerFormatTranslation(string $sourceFormat, string $targetFormat, array $mappings): bool
    {
        $translationKey = "format_translation:{$sourceFormat}:{$targetFormat}";

        try {
            $this->storage->set($translationKey, json_encode([
                'source' => $sourceFormat,
                'target' => $targetFormat,
                'mappings' => $mappings,
                'created_at' => time(),
            ]));
            return true;
        } catch (\Exception $e) {
            $this->debug("Failed to register format translation: " . $e->getMessage());
            return false;
        }
    }

    //=========================================================================
    // ENDPOINT REGISTRATION (Auto-Translation Between Services)
    //=========================================================================

    /**
     * Default endpoint registry key
     */
    protected const ENDPOINT_REGISTRY_KEY = 'gnode:endpoints';

    /**
     * FCALL prefixes owned by Pro extensions → [extension, feature].
     * fcallDecode() returns the premium-required shape for these instead
     * of putting a Pro function name on the wire from a base install.
     */
    protected const PREMIUM_FCALL_PREFIXES = [
        'GNODE_DEP_'        => ['gNode-TOPO', 'multi_topology'],
        'GNODE_REGISTRY_'   => ['gNode-TOPO', 'multi_topology'],
        'GNODE_CROSS_'      => ['gNode-TOPO', 'multi_topology'],
        'GNODE_FEATURE_'    => ['gNode-OBSERVE', 'observability'],
        'GNODE_EXPERIMENT_' => ['gNode-OBSERVE', 'observability'],
        'GNODE_SESSION_'    => ['gNode-OBSERVE', 'observability'],
        'GNODE_TRACE_'      => ['gNode-OBSERVE', 'observability'],
        'GNODE_ENDPOINT_'   => ['gNode-BROKER', 'endpoint_translation'],
    ];

    /**
     * Standard premium-required response (same shape as the custom-topology
     * precedent): callers can branch on ['premium'] and name the extension.
     */
    protected function premiumUnavailable(string $extension, string $feature): array
    {
        return [
            'error'   => "This capability requires the {$extension} extension.",
            'premium' => true,
            'feature' => $feature,
        ];
    }

    /**
     * Register a service endpoint with its request/response format
     *
     * This enables gNode to automatically translate messages between services
     * with different API formats.
     *
     * Example:
     *   $client->registerEndpoint('user-service', [
     *       'path' => '/api/v1/users/{id}',
     *       'method' => 'GET',
     *       'description' => 'Get user by ID',
     *       'request' => [
     *           'content_type' => 'application/json',
     *           'field_mapping' => [
     *               'id' => 'user_id',           // external 'id' maps to internal 'user_id'
     *               'include_details' => 'verbose',
     *           ],
     *       ],
     *       'response' => [
     *           'content_type' => 'application/json',
     *           'field_mapping' => [
     *               'user_id' => 'id',           // internal 'user_id' maps to external 'id'
     *               'full_name' => 'name',
     *           ],
     *       ],
     *       'transforms' => [
     *           'request' => ['id' => 'parseInt'],
     *           'response' => ['created_at' => 'unixToIso'],
     *       ],
     *   ]);
     *
     * @param string $serviceId Service identifier
     * @param array $endpoint Endpoint definition
     * @return array Result with endpoint_id and status
     * @throws gNodeException On registration failure
     */
    public function registerEndpoint(string $serviceId, array $endpoint): array
    {
        return $this->endpointRegister($serviceId, $endpoint);
    }

    /**
     * Register multiple endpoints for a service at once
     *
     * @param string $serviceId Service identifier
     * @param array $endpoints Array of endpoint definitions
     * @return array Results for each endpoint
     */
    public function registerEndpoints(string $serviceId, array $endpoints): array
    {
        $results = [];
        foreach ($endpoints as $endpoint) {
            try {
                $results[] = $this->registerEndpoint($serviceId, $endpoint);
            } catch (\Exception $e) {
                $results[] = [
                    'endpoint_id' => $endpoint['endpoint_id'] ?? $endpoint['path'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    /**
     * Register a service with capabilities, format, AND endpoints
     *
     * This is the registration method that sets up:
     * 1. Service topology (capabilities for discovery)
     * 2. Message format (for format detection/conversion)
     * 3. API endpoints (for auto-translation)
     *
     * Example:
     *   $client->registerServiceComplete('order-service', [
     *       'capabilities' => [
     *           'protocol' => 'http_rest',
     *           'native_format' => 'json',
     *           'domain_primary' => 'compute',
     *       ],
     *       'metadata' => [
     *           'endpoint' => 'https://orders.example.com/api',
     *           'version' => '2.0.0',
     *       ],
     *       'format' => [
     *           'schema' => [...],
     *           'patterns' => [...],
     *       ],
     *       'endpoints' => [
     *           [
     *               'path' => '/orders',
     *               'method' => 'POST',
     *               'request' => ['field_mapping' => ['items' => 'line_items']],
     *           ],
     *           [
     *               'path' => '/orders/{id}',
     *               'method' => 'GET',
     *               'response' => ['field_mapping' => ['order_id' => 'id']],
     *           ],
     *       ],
     *   ]);
     *
     * @param string $serviceId Service identifier
     * @param array $config Complete service configuration
     * @return array Registration results
     */
    public function registerServiceComplete(string $serviceId, array $config): array
    {
        $result = [
            'service_id' => $serviceId,
            'topology_registered' => false,
            'format_registered' => false,
            'endpoints_registered' => [],
        ];

        // Step 1: Register topology (capabilities)
        if (isset($config['capabilities'])) {
            try {
                $result['topology_registered'] = $this->registerService(
                    $serviceId,
                    $config['capabilities'],
                    $config['metadata'] ?? []
                );
            } catch (\Exception $e) {
                $this->debug("Topology registration failed: " . $e->getMessage());
            }
        }

        // Step 2: Register format
        if (isset($config['format'])) {
            try {
                $formatResult = $this->registerServiceWithFormat(
                    $serviceId,
                    $config['capabilities'] ?? [],
                    $config['metadata'] ?? [],
                    $config['format']
                );
                $result['format_registered'] = $formatResult['format_registered'] ?? false;
                $result['format_name'] = $formatResult['format_name'] ?? null;
            } catch (\Exception $e) {
                $this->debug("Format registration failed: " . $e->getMessage());
            }
        }

        // Step 3: Register endpoints
        if (isset($config['endpoints']) && is_array($config['endpoints'])) {
            $result['endpoints_registered'] = $this->registerEndpoints($serviceId, $config['endpoints']);
        }

        return $result;
    }

    /**
     * Get an endpoint definition
     *
     * @param string $endpointId Endpoint identifier
     * @return array|null Endpoint definition or null
     */
    public function getEndpoint(string $endpointId): ?array
    {
        return $this->endpointGet($endpointId);
    }

    /**
     * List endpoints for a service or all services
     *
     * @param string|null $serviceId Optional service ID filter
     * @return array List of endpoints
     */
    public function listEndpoints(?string $serviceId = null): array
    {
        return $this->endpointList($serviceId ?? '');
    }

    /**
     * Find endpoints by path pattern
     *
     * @param string $path Path to match
     * @param string|null $method Optional HTTP method filter
     * @return array Matching endpoints
     */
    public function findEndpoints(string $path, ?string $method = null): array
    {
        return $this->endpointFind($path, $method ?? '');
    }

    /**
     * Translate a message between two service endpoints
     *
     * This is the core translation function that converts a message from
     * one service's format to another service's format.
     *
     * @param string $sourceEndpointId Source endpoint
     * @param string $targetEndpointId Target endpoint
     * @param array|string $message Message to translate
     * @param string $direction 'request' or 'response'
     * @return array Translated message
     * @throws gNodeException On translation failure
     */
    public function translateBetweenEndpoints(
        string $sourceEndpointId,
        string $targetEndpointId,
        $message,
        string $direction = 'request'
    ): array {
        $msg = is_array($message) ? $message : (array)$this->safeJsonDecode((string)$message, true);
        return $this->endpointTranslate($sourceEndpointId, $targetEndpointId, $msg, $direction);
    }

    /**
     * Translate a message to gNode internal format
     *
     * @param string $endpointId Source endpoint
     * @param array|string $message Message to translate
     * @param string $direction 'request' or 'response'
     * @return array Message in internal format
     */
    public function translateToInternal(string $endpointId, $message, string $direction = 'request'): array
    {
        $msg = is_array($message) ? $message : (array)$this->safeJsonDecode((string)$message, true);
        return $this->endpointTranslateToInternal($endpointId, $msg);
    }

    /**
     * Translate a message from gNode internal format to endpoint format
     *
     * @param string $endpointId Target endpoint
     * @param array|string $message Message to translate
     * @param string $direction 'request' or 'response'
     * @return array Message in endpoint format
     */
    public function translateFromInternal(string $endpointId, $message, string $direction = 'response'): array
    {
        $msg = is_array($message) ? $message : (array)$this->safeJsonDecode((string)$message, true);
        return $this->endpointTranslateFromInternal($endpointId, $msg);
    }

    /**
     * Deregister an endpoint
     *
     * @param string $endpointId Endpoint to remove
     * @return bool True if successful
     */
    public function deregisterEndpoint(string $endpointId): bool
    {
        return $this->endpointDeregister($endpointId);
    }

    /**
     * Get the endpoint schema definition for documentation
     *
     * @return array Schema definition
     */
    public function getEndpointSchema(): array
    {
        return $this->endpointGetSchema();
    }

    /**
     * Register a custom translation rule between two endpoints
     *
     * Use this for complex translations that can't be expressed with simple field mappings.
     *
     * @param string $sourceEndpoint Source endpoint ID
     * @param string $targetEndpoint Target endpoint ID
     * @param array $rule Translation rule definition
     * @return bool True if successful
     */
    public function registerTranslationRule(string $sourceEndpoint, string $targetEndpoint, array $rule): bool
    {
        $result = $this->endpointRegisterTranslationRule($sourceEndpoint, $targetEndpoint, $rule);
        return !empty($result['success']);
    }

    //=========================================================================
    // CUSTOM TOPOLOGY METHODS (premium — gNode-TOPO)
    // User-defined multi-dimension topologies are a premium capability. The
    // base build ships the geometric Service Topology engine only; these
    // methods are gated and return a premium-required error. The gNode-TOPO
    // extension provides the multi-topology API (registry / cross / dependency).
    //=========================================================================

    /**
     * Standard "custom topology is premium" response — base does not ship the
     * user-defined multi-dimension builder.
     */
    private function customTopologyUnavailable(): array
    {
        return [
            'error'   => 'Custom (user-defined) topologies require the gNode-TOPO extension.',
            'premium' => true,
            'feature' => 'multi_topology',
        ];
    }

    /**
     * Create a custom topology with user-defined dimensions
     *
     * @param string $topologyKey The key for this topology (e.g., "analytics:pages")
     * @param array $config Topology configuration:
     *   - dimension_count: int - Total number of dimensions
     *   - dimensions: array - Dimension definitions with index, query_type
     *   - values: array - Optional human-readable value mappings
     * @param array $metadata Optional metadata about the topology
     * @return array Result with success status
     *
     * @example
     * $gNode->createCustomTopology('analytics:pages', [
     *     'dimension_count' => 5,
     *     'dimensions' => [
     *         'user_engagement' => ['index' => 0, 'query_type' => 'minimum'],
     *         'conversion_rate' => ['index' => 1, 'query_type' => 'minimum'],
     *         'bounce_rate' => ['index' => 2, 'query_type' => 'maximum'],
     *         'session_duration' => ['index' => 3, 'query_type' => 'minimum'],
     *         'page_load_time' => ['index' => 4, 'query_type' => 'maximum'],
     *     ],
     *     'values' => [
     *         'user_engagement' => ['low' => 0.0, 'medium' => 0.5, 'high' => 0.8],
     *         'conversion_rate' => ['poor' => 0.0, 'average' => 0.05, 'good' => 0.10],
     *     ]
     * ], ['purpose' => 'Page analytics tracking']);
     */
    public function createCustomTopology(string $topologyKey, array $config, array $metadata = []): array
    {
        return $this->customTopologyUnavailable();
    }

    /**
     * Add a dimension to an existing custom topology
     *
     * @param string $topologyKey The topology key
     * @param string $dimensionName Name of the new dimension
     * @param array $config Dimension config: index, query_type, values
     * @return array Result with success status
     */
    public function addTopologyDimension(string $topologyKey, string $dimensionName, array $config): array
    {
        return $this->customTopologyUnavailable();
    }

    /**
     * Add human-readable value mappings for a dimension
     *
     * @param string $topologyKey The topology key
     * @param string $dimensionName Dimension to add values for
     * @param array $values Map of name => numeric_value
     * @return array Result with success status
     *
     * @example
     * $gNode->addTopologyValues('analytics:pages', 'user_engagement', [
     *     'low' => 0.0,
     *     'medium' => 0.5,
     *     'high' => 0.8,
     *     'very_high' => 1.0
     * ]);
     */
    public function addTopologyValues(string $topologyKey, string $dimensionName, array $values): array
    {
        return $this->customTopologyUnavailable();
    }

    /**
     * Register an entity in a custom topology using natural language
     *
     * @param string $topologyKey The topology key
     * @param string $entityId Unique ID for this entity
     * @param array $capabilities Dimension values (can use human-readable names)
     * @param array $metadata Optional metadata
     * @return array Result with point coordinates and conversion log
     *
     * @example
     * $gNode->registerCustomEntity('analytics:pages', 'page:checkout', [
     *     'user_engagement' => 'high',
     *     'conversion_rate' => 0.12,
     *     'bounce_rate' => 0.25,
     * ], ['url' => '/checkout', 'type' => 'conversion_page']);
     */
    public function registerCustomEntity(string $topologyKey, string $entityId, array $capabilities, array $metadata = []): array
    {
        return $this->customTopologyUnavailable();
    }

    /**
     * Discover entities in a custom topology
     *
     * @param string $topologyKey The topology key
     * @param array $requirements Requirements with optional constraints
     * @param int $limit Maximum results
     * @param bool $includeMetadata Include entity metadata in results
     * @return array Matching entities with scores
     *
     * @example
     * // Find high-engagement pages with low bounce rate
     * $gNode->discoverCustom('analytics:pages', [
     *     'user_engagement' => 'high',
     *     'bounce_rate' => ['max' => 0.3],
     * ], 10, true);
     */
    public function discoverCustom(string $topologyKey, array $requirements, int $limit = 10, bool $includeMetadata = true): array
    {
        return $this->customTopologyUnavailable();
    }

    /**
     * Get the schema for a custom topology
     *
     * @param string $topologyKey The topology key
     * @return array Schema with dimensions, values, and entity count
     */
    public function getCustomTopologySchema(string $topologyKey): array
    {
        return $this->customTopologyUnavailable();
    }

    /**
     * List all entities in a custom topology
     *
     * @param string $topologyKey The topology key
     * @param bool $includeMetadata Include entity metadata
     * @param int $limit Maximum results
     * @return array List of entities with human-readable values
     */
    public function listCustomEntities(string $topologyKey, bool $includeMetadata = false, int $limit = 100): array
    {
        return $this->customTopologyUnavailable();
    }

    // =========================================================================
    // RUST Q64.64 PRECISION METHODS
    // These methods use the Rust daemon for cluster-safe, deterministic calculations
    // =========================================================================

    /**
     * Discover entities in custom topology using Rust Q64.64 precision
     *
     * Unlike discoverCustom() which uses Lua floating-point, this method
     * routes through the Rust daemon for cluster-safe deterministic calculations.
     *
     * @param string $topologyKey The topology key (e.g., 'analytics:pages')
     * @param array $requirements Requirements with optional constraints
     * @param int $maxResults Maximum results to return
     * @param bool $includeMetadata Include entity metadata in results
     * @return array Matching entities with Q64.64 precision scores
     *
     * @example
     * // Cluster-safe discovery with deterministic ranking
     * $gNode->discoverCustomPrecise('analytics:pages', [
     *     'user_engagement' => 'high',
     *     'bounce_rate' => ['max' => 0.3],
     * ], 10, true);
     */
    public function discoverCustomPrecise(string $topologyKey, array $requirements, int $maxResults = 10, bool $includeMetadata = true): ?array
    {
        return $this->sendCommand('custom_topology_discover', [
            'topology_key' => $topologyKey,
            'requirements' => $requirements,
            'max_results' => $maxResults,
            'include_metadata' => $includeMetadata,
        ]);
    }

    /**
     * Calculate precise distance between two points using Rust Q64.64 fixed-point
     *
     * This is cluster-safe - produces identical results on any node in the cluster.
     *
     * @param string $topologyKey The topology key
     * @param array $point1 First coordinate array
     * @param array $point2 Second coordinate array
     * @return array Distance calculation result with Q64.64 precision
     *
     * @example
     * $distance = $gNode->customTopologyDistance('analytics:pages', [0.8, 0.2, 0.9], [0.5, 0.4, 0.6]);
     */
    public function customTopologyDistance(string $topologyKey, array $point1, array $point2): ?array
    {
        return $this->sendCommand('custom_topology_distance', [
            'topology_key' => $topologyKey,
            'point1' => $point1,
            'point2' => $point2,
        ]);
    }

    /**
     * Find k-nearest neighbors using Rust Q64.64 precision
     *
     * Uses Q64.64 fixed-point arithmetic for cluster-safe deterministic results.
     *
     * @param string $topologyKey The topology key
     * @param array $queryPoint The query point coordinates
     * @param int $k Number of nearest neighbors to find
     * @return array K nearest entities with Q64.64 precision distances
     *
     * @example
     * // Find 5 most similar pages to a query point
     * $neighbors = $gNode->customTopologyKnn('analytics:pages', [0.8, 0.3, 0.7, 0.5, 0.9], 5);
     */
    public function customTopologyKnn(string $topologyKey, array $queryPoint, int $k = 5): ?array
    {
        return $this->sendCommand('custom_topology_knn', [
            'topology_key' => $topologyKey,
            'query_point' => $queryPoint,
            'k' => $k,
        ]);
    }

    /**
     * Calculate similarity between two entities using Rust Q64.64 precision
     *
     * Returns both distance and similarity score (1 / (1 + distance)).
     * Cluster-safe - identical results on any node.
     *
     * @param string $topologyKey The topology key
     * @param string $entityId1 First entity ID
     * @param string $entityId2 Second entity ID
     * @return array Similarity calculation with distance and score
     *
     * @example
     * $similarity = $gNode->customTopologySimilarity('analytics:pages', 'page_home', 'page_about');
     * // Returns: ['distance' => 0.234, 'similarity' => 0.810, 'entity1' => [...], 'entity2' => [...]]
     */
    public function customTopologySimilarity(string $topologyKey, string $entityId1, string $entityId2): ?array
    {
        return $this->sendCommand('custom_topology_similarity', [
            'topology_key' => $topologyKey,
            'entity_id_1' => $entityId1,
            'entity_id_2' => $entityId2,
        ]);
    }

    // =========================================================================
    // ENDPOINT AUTO-DETECTION AND REGISTRATION
    // Auto-discover service APIs via reflection, OpenAPI/Swagger, or manual definition
    // =========================================================================

    /**
     * Register a service and auto-detect all its endpoints using PHP Reflection
     *
     * This method introspects a PHP class to discover all public methods,
     * their parameters, types, and return values. It then registers the
     * service in the topology AND registers all discovered endpoints.
     *
     * @param string|object $classOrObject Class name or instance to introspect
     * @param array $capabilities Service capabilities for topology registration
     * @param array $options Introspection options
     * @return array Registration result with service and endpoint details
     *
     * @example
     * // Register a service with auto-detected endpoints
     * $gNode->registerServiceWithEndpoints(
     *     UserService::class,
     *     ['protocol' => 'gnode_stream'],
     *     ['base_path' => '/api/users', 'own_methods_only' => true]
     * );
     */
    public function registerServiceWithEndpoints($classOrObject, array $capabilities = [], array $options = []): array
    {
        $introspector = new \gCore\gNode\Discovery\EndpointIntrospector();

        // Configure introspector
        if (isset($options['include_accessors'])) {
            $introspector->setIncludeAccessors($options['include_accessors']);
        }
        if (isset($options['exclude_methods'])) {
            $introspector->excludeMethods($options['exclude_methods']);
        }

        // Introspect the class
        $introspection = $introspector->introspect($classOrObject, $options);
        $serviceId = $introspection['service_id'];

        // Register service in topology with capabilities
        $topologyResult = null;
        if (!empty($capabilities)) {
            $topologyResult = $this->registerService($serviceId, $capabilities, [
                'class' => $introspection['class'],
                'endpoint_count' => $introspection['endpoint_count'],
                'version' => $introspection['version']
            ]);
        }

        // Register each endpoint using existing registerEndpoint method
        $registeredEndpoints = [];

        foreach ($introspection['endpoints'] as $endpoint) {
            try {
                $result = $this->registerEndpoint($serviceId, $endpoint);
                $registeredEndpoints[] = [
                    'endpoint_id' => $endpoint['endpoint_id'],
                    'path' => $endpoint['path'],
                    'method' => $endpoint['http_method'],
                    'status' => 'registered'
                ];
            } catch (\Exception $e) {
                $registeredEndpoints[] = [
                    'endpoint_id' => $endpoint['endpoint_id'],
                    'path' => $endpoint['path'],
                    'method' => $endpoint['http_method'],
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'status' => 'ok',
            'service_id' => $serviceId,
            'class' => $introspection['class'],
            'topology_registration' => $topologyResult,
            'endpoints' => $registeredEndpoints,
            'endpoint_count' => count($registeredEndpoints),
            'introspection' => $introspection
        ];
    }

    /**
     * Import and register endpoints from an OpenAPI/Swagger specification
     *
     * Supports OpenAPI 3.x and Swagger 2.x specifications in JSON or YAML format.
     * Can fetch from URL, read from file, or accept direct content.
     *
     * @param string $source URL, file path, or spec content
     * @param string $sourceType One of: 'url', 'file', 'json', 'yaml'
     * @param array $options Import options
     * @return array Import result with registered endpoints
     *
     * @example
     * // Import from URL
     * $gNode->importOpenAPISpec('https://api.example.com/openapi.json', 'url');
     *
     * // Import from file
     * $gNode->importOpenAPISpec('/path/to/swagger.yaml', 'file');
     *
     * // Import from content
     * $gNode->importOpenAPISpec($jsonContent, 'json', ['service_id' => 'my-api']);
     */
    public function importOpenAPISpec(string $source, string $sourceType = 'auto', array $options = []): array
    {
        $importer = new \gCore\gNode\Discovery\OpenAPIImporter();

        // Auto-detect source type
        if ($sourceType === 'auto') {
            if (filter_var($source, FILTER_VALIDATE_URL)) {
                $sourceType = 'url';
            } elseif (file_exists($source)) {
                $sourceType = 'file';
            } elseif (str_starts_with(trim($source), '{')) {
                $sourceType = 'json';
            } else {
                $sourceType = 'yaml';
            }
        }

        // Load spec
        switch ($sourceType) {
            case 'url':
                $importer->fromUrl($source, $options);
                break;
            case 'file':
                $importer->fromFile($source, $options);
                break;
            case 'json':
            case 'yaml':
            default:
                $importer->fromString($source);
                break;
        }

        // Convert to gNode endpoints
        $serviceId = $options['service_id'] ?? null;
        $gNodeEndpoints = $importer->toGNodeEndpoints($serviceId);
        $serviceId = $gNodeEndpoints['service_id'];

        // Register service in topology if capabilities provided
        $topologyResult = null;
        if (!empty($options['capabilities'])) {
            $topologyResult = $this->registerService($serviceId, $options['capabilities'], [
                'source' => 'openapi',
                'openapi_version' => $gNodeEndpoints['openapi_version'],
                'endpoint_count' => $gNodeEndpoints['endpoint_count'],
                'servers' => $gNodeEndpoints['servers']
            ]);
        }

        // Register each endpoint using existing registerEndpoint method
        $registeredEndpoints = [];

        foreach ($gNodeEndpoints['endpoints'] as $endpoint) {
            try {
                $result = $this->registerEndpoint($serviceId, $endpoint);
                $registeredEndpoints[] = [
                    'endpoint_id' => $endpoint['endpoint_id'],
                    'path' => $endpoint['path'],
                    'method' => $endpoint['method'],
                    'status' => 'registered'
                ];
            } catch (\Exception $e) {
                $registeredEndpoints[] = [
                    'endpoint_id' => $endpoint['endpoint_id'],
                    'path' => $endpoint['path'],
                    'method' => $endpoint['method'],
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'status' => 'ok',
            'service_id' => $serviceId,
            'source' => $sourceType,
            'openapi_version' => $gNodeEndpoints['openapi_version'],
            'topology_registration' => $topologyResult,
            'endpoints' => $registeredEndpoints,
            'endpoint_count' => count($registeredEndpoints),
            'servers' => $gNodeEndpoints['servers'],
            'security_schemes' => $gNodeEndpoints['security_schemes']
        ];
    }

    /**
     * Introspect a class without registering (for preview/debugging)
     *
     * @param string|object $classOrObject Class name or instance
     * @param array $options Introspection options
     * @return array Discovered endpoints
     */
    public function introspectService($classOrObject, array $options = []): array
    {
        $introspector = new \gCore\gNode\Discovery\EndpointIntrospector();

        if (isset($options['include_accessors'])) {
            $introspector->setIncludeAccessors($options['include_accessors']);
        }
        if (isset($options['exclude_methods'])) {
            $introspector->excludeMethods($options['exclude_methods']);
        }

        return $introspector->introspect($classOrObject, $options);
    }

    /**
     * Generate OpenAPI spec from introspected service
     *
     * @param string|object $classOrObject Class name or instance
     * @param array $options Options
     * @return array OpenAPI specification
     */
    public function generateOpenAPISpec($classOrObject, array $options = []): array
    {
        $introspector = new \gCore\gNode\Discovery\EndpointIntrospector();
        $introspection = $introspector->introspect($classOrObject, $options);
        return $introspector->toOpenAPI($introspection);
    }

    /**
     * Translate a message between two endpoint formats
     *
     * Uses field mappings and transforms defined in endpoint registrations
     * to convert messages between different API formats.
     *
     * @param string $sourceEndpoint Source endpoint ID
     * @param string $targetEndpoint Target endpoint ID
     * @param array $message Message to translate
     * @param string $direction 'request' or 'response'
     * @return array Translated message
     *
     * @example
     * // Translate user-service format to payment-service format
     * $translated = $gNode->translateEndpointMessage(
     *     'user-service:get-user',
     *     'payment-service:charge-user',
     *     ['id' => 123, 'include_details' => true],
     *     'request'
     * );
     */
    public function translateEndpointMessage(string $sourceEndpoint, string $targetEndpoint, array $message, string $direction = 'request'): array
    {
        return $this->endpointTranslate($sourceEndpoint, $targetEndpoint, $message, $direction);
    }

    /**
     * Scan and register WordPress REST API endpoints from a theme or plugin
     *
     * Scans PHP files for register_rest_route() calls, extracts endpoint
     * definitions, and registers them with gNode for inter-service discovery.
     *
     * @param string $directory Directory to scan (theme or plugin path)
     * @param array $capabilities Service capabilities for topology registration
     * @param array $options Scan options
     * @return array Registration result with discovered endpoints
     *
     * @example
     * // Scan a WordPress theme for REST endpoints
     * $result = $gNode->scanWordPressRESTEndpoints(
     *     '/var/www/html/wp-content/themes/gcube',
     *     ['protocol' => 'wordpress_rest', 'tier' => 'service'],
     *     ['service_id' => 'gcube', 'exclude_dirs' => ['vendor', 'node_modules']]
     * );
     */
    public function scanWordPressRESTEndpoints(string $directory, array $capabilities = [], array $options = []): array
    {
        $scanner = new \gCore\gNode\Discovery\WordPressRESTScanner();

        // Scan the directory
        $scanner->scanDirectory($directory, [
            'pattern' => $options['pattern'] ?? '*.php',
            'recursive' => $options['recursive'] ?? true,
            'exclude_dirs' => $options['exclude_dirs'] ?? ['vendor', 'node_modules', '.git'],
        ]);

        // Convert to gNode format
        $serviceId = $options['service_id'] ?? null;
        $gNodeEndpoints = $scanner->toGNodeEndpoints($serviceId);
        $serviceId = $gNodeEndpoints['service_id'];

        // Register service in topology if capabilities provided
        $topologyResult = null;
        if (!empty($capabilities)) {
            $topologyResult = $this->registerService($serviceId, $capabilities, [
                'source' => 'wordpress-rest-scan',
                'endpoint_count' => $gNodeEndpoints['endpoint_count'],
                'scanned_files' => $gNodeEndpoints['scanned_files'],
            ]);
        }

        // Register each endpoint
        $registeredEndpoints = [];

        foreach ($gNodeEndpoints['endpoints'] as $endpoint) {
            try {
                $result = $this->registerEndpoint($serviceId, $endpoint);
                $registeredEndpoints[] = [
                    'endpoint_id' => $endpoint['endpoint_id'],
                    'path' => $endpoint['path'],
                    'methods' => $endpoint['methods'],
                    'status' => 'registered',
                ];
            } catch (\Exception $e) {
                $registeredEndpoints[] = [
                    'endpoint_id' => $endpoint['endpoint_id'],
                    'path' => $endpoint['path'],
                    'methods' => $endpoint['methods'],
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'status' => 'ok',
            'service_id' => $serviceId,
            'source' => 'wordpress-rest-scan',
            'directory' => $directory,
            'topology_registration' => $topologyResult,
            'endpoints' => $registeredEndpoints,
            'endpoint_count' => count($registeredEndpoints),
            'scanned_files' => $gNodeEndpoints['scanned_files'],
            'scan_errors' => $gNodeEndpoints['errors'],
        ];
    }

    /**
     * Preview WordPress REST endpoint scan without registering
     *
     * @param string $directory Directory to scan
     * @param array $options Scan options
     * @return array Discovered endpoints
     */
    public function previewWordPressRESTEndpoints(string $directory, array $options = []): array
    {
        $scanner = new \gCore\gNode\Discovery\WordPressRESTScanner();

        $scanner->scanDirectory($directory, [
            'pattern' => $options['pattern'] ?? '*.php',
            'recursive' => $options['recursive'] ?? true,
            'exclude_dirs' => $options['exclude_dirs'] ?? ['vendor', 'node_modules', '.git'],
        ]);

        return $scanner->toGNodeEndpoints($options['service_id'] ?? null);
    }
}
