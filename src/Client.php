<?php

namespace gCore\GSD;

use gCore\GSD\Storage\StorageInterface;
use gCore\GSD\Exception\StorageException;
use gCore\GSD\Exception\GSDException;
use gCore\GSD\Exception\ScriptException;
use gCore\GSD\Exception\ConnectionException;
use gCore\GSD\Fallback\FallbackHandler;
use gCore\GSD\ConsumerGroupHandler;
use gCore\GSD\Queue\CommandQueue;
use gCore\GSD\Queue\DeferredResult;

/**
 * Client - Base client implementation for the GSD service
 *
 * WARNING: This is the BASE class. For production use, prefer KeyBasedClientLuaEnabled
 * which provides better performance, Lua function optimization, and automatic metrics.
 *
 * RECOMMENDED: Use KeyBasedClientLuaEnabled instead of this class directly.
 * @see KeyBasedClientLuaEnabled The canonical, production-ready client
 * @see KeyBasedClient Key-based architecture without Lua metrics
 *
 * Inheritance hierarchy:
 *   Client (base, legacy stream methods)
 *     └── KeyBasedClient (key-based SET/GET + XADD, preferred architecture)
 *           └── KeyBasedClientLuaEnabled (+ Lua functions, metrics, CANONICAL)
 *
 * @package gCore\GSD
 */
class Client
{
    /** @var StorageInterface Storage for communication */
    protected $storage;

    /** @var \Redis|null Redis client - accessible to class */
    protected $redis;

    /** @var string Site identifier */
    protected $siteId;

    /** @var string Node identifier */
    protected $nodeId;

    /** @var string Environment (production/staging/testing/acceptance) */
    protected $environment = 'production';

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

    /** @var int Cache expiration in seconds */
    protected $cacheExpiration = 300;

    /** @var ConsumerGroupHandler Consumer group handler */
    protected $consumerHandler;

    /** @var bool Using consumer group approach */
    protected $usingConsumerGroups = true;

    /** @var array Cached capability dimensions */
    protected $capabilityDimensions = [];

    /** @var int Sequence counter for command ordering */
    protected $sequenceCounter = 0;

    /** @var \gCore\GSD\Health\HealthStreamWriter|null Health stream writer */
    protected $healthWriter = null;

    /** @var \gCore\GSD\Format\FormatManager|null Format manager */
    protected $formatManager = null;

    /** @var \gCore\GSD\Broadcast\BroadcastReader|null Broadcast reader */
    protected $broadcastReader = null;

    /** @var \gCore\GSD\Queue\CommandQueue|null Command queue for auto-batching */
    protected $queue = null;

    /** @var bool Consumer groups initialized flag (lazy initialization) */
    protected $consumerGroupsInitialized = false;

    /** @var array Worker-wide initialization state (survives across requests in same PHP-FPM worker) */
    protected static $workerInitializedStreams = [];

    /**
     * Constructor
     *
     * @param StorageInterface $storage Storage for communication
     * @param string $siteId Site identifier
     * @param string $nodeId Node identifier
     * @param array $config Configuration options
     * @throws ConnectionException If connection fails and no fallback is available
     */
    public function __construct(
        StorageInterface $storage,
        string $siteId = 'default',
        string $nodeId = 'default',
        array $config = []
    ) {
        $this->storage = $storage;

        // Get redis instance if available
        if (property_exists($storage, 'redis')) {
            $this->redis = $storage->redis;
        } elseif (method_exists($storage, 'getRedis')) {
            $this->redis = $storage->getRedis();
        }

        $this->siteId = $siteId;
        $this->nodeId = $nodeId;
        $this->config = array_merge([
            'stream_prefix' => 'gsd',
            'environment' => 'production', // DTAP environment (production/staging/testing/acceptance)
            'debug' => false,
            'use_fallback' => true,
            'timeout' => 5.0,
            'retry_count' => 3,
            'retry_delay' => 0.1,
            'cache_expiration' => 300,
            'allow_local_execution' => false,
            'daemon_path' => null,
            'auto_start_daemon' => false,
            'use_consumer_groups' => true, // Enable consumer group approach by default
            'batch_size' => 100,
            'max_idle_time' => 30000,
            'trim_threshold' => 10000,
            'client_id' => uniqid('', true),
            'native_mode' => false, // Native RESP3 mode (bypass encoding/decoding)
            'skip_connection_check' => false, // Skip ping check in constructor (useful for tests)
            // Auto-batching queue configuration
            'batch' => [
                'enabled' => false, // Disabled by default for backward compatibility
                'size' => 100, // Max queue size before auto-flush
                'timeout_ms' => 10.0, // Max time in milliseconds before auto-flush
            ],
        ], $config);

        $this->clientId = $this->config['client_id'];

        // Set up unified stream name according to new naming convention
        // Using braces for proper hash distribution in ValKey/Redis
        $this->unifiedStream = sprintf(
            '{%s}:%s:unified:%s',
            $this->siteId,
            $this->config['stream_prefix'],
            $this->nodeId
        );

        $this->timeout = intval($this->config['timeout'] * 1000); // Convert to milliseconds
        $this->cacheExpiration = intval($this->config['cache_expiration']);
        $this->usingConsumerGroups = $this->config['use_consumer_groups'];
        $this->environment = $this->config['environment']; // DTAP environment for stream routing

        // Initialize fallback if enabled
        if ($this->config['use_fallback']) {
            $this->fallback = new FallbackHandler($this->config['allow_local_execution']);
        }

        // Initialize consumer group handler
        if ($this->usingConsumerGroups) {
            // error_log('[GSD-Client] About to construct ConsumerGroupHandler');
            $this->consumerHandler = new ConsumerGroupHandler(
                $this->storage,
                $this->siteId,
                $this->nodeId,
                [
                    'stream_prefix' => $this->config['stream_prefix'],
                    'debug' => $this->config['debug'],
                    'batch_size' => $this->config['batch_size'],
                    'max_idle_time' => $this->config['max_idle_time'],
                    'trim_threshold' => $this->config['trim_threshold'],
                    'client_id' => $this->clientId,
                ]
            );
            // error_log('[GSD-Client] ConsumerGroupHandler constructed successfully');
        }

        // Initialize command queue if enabled
        if ($this->config['batch']['enabled'] ?? false) {
            $this->queue = new CommandQueue(
                $this,
                $this->config['batch']['size'] ?? 100,
                $this->config['batch']['timeout_ms'] ?? 10.0
            );
        }

        try {
            // Initialize streams
            // error_log('[GSD-Client] About to call setupStreams()');
            $this->setupStreams();
            // error_log('[GSD-Client] setupStreams() completed successfully');

            // Attempt connection (skip if requested, e.g., in tests)
            if (!$this->config['skip_connection_check']) {
                $this->connect();

                if (!$this->isConnected() && !$this->config['use_fallback']) {
                    throw new ConnectionException(
                        "Failed to connect to GSD daemon and fallback mode is disabled"
                    );
                }
            } else {
                // Assume connected if skipping check
                $this->connected = true;
                $this->debug("Skipped connection check (configured)");
            }
        } catch (\Exception $e) {
            $this->debug("Initialization error: {$e->getMessage()}");

            if ($this->config['use_fallback']) {
                $this->usingFallback = true;
                $this->debug("Using fallback mode due to initialization error");
            } else {
                throw new ConnectionException(
                    "Failed to initialize GSD client: {$e->getMessage()}"
                );
            }
        }
    }

    /**
     * Get a persistent client ID for the given site and node
     *
     * This prevents consumer leak by ensuring the same client_id is reused
     * across multiple Client instantiations within the same PHP process or server.
     *
     * @param string $siteId Site identifier
     * @param string $nodeId Node identifier
     * @return string Persistent client ID
     */
    protected static function getPersistentClientId(string $siteId, string $nodeId): string
    {
        // Use a temp file to store the client ID persistently
        $idFile = sys_get_temp_dir() . '/gsd_client_id_' . $siteId . '_' . $nodeId;

        // Try to read existing ID from file
        if (file_exists($idFile)) {
            $storedId = trim(file_get_contents($idFile));
            if (!empty($storedId)) {
                return $storedId;
            }
        }

        // Generate new ID if file doesn't exist or is empty
        $newId = uniqid('', true);

        // Store it for future use
        file_put_contents($idFile, $newId);

        return $newId;
    }

    /**
     * Set up streams and consumer groups
     *
     * @throws StorageException If stream setup fails
     */
    protected function setupStreams(): void
    {
        if (!$this->storage->isConnected()) {
            throw new StorageException('Not connected to Redis server');
        }

        $this->debug("Setting up unified stream: {$this->unifiedStream}");

        try {
            if ($this->usingConsumerGroups && $this->consumerHandler) {
                // LAZY INITIALIZATION: Don't initialize consumer groups yet!
                // This eliminates 500-1000ms blocking on EVERY WordPress initialization.
                // Consumer groups will be initialized on first actual GSD command.
                // error_log('[GSD-Client] Deferring consumer group initialization (lazy mode)');
                $this->debug("Consumer group initialization deferred until first use (lazy loading)");
            } else {
                // Fall back to direct setup
                // First check if consumer groups need recreation
                $needsRecreation = $this->checkConsumerGroupSettings();

                if ($needsRecreation) {
                    $this->debug("Consumer groups need recreation with proper settings");

                    // Destroy existing groups if they exist
                    try {
                        $this->storage->xGroupDestroy($this->unifiedStream, 'gsd-client');
                        $this->storage->xGroupDestroy($this->unifiedStream, 'gsd-command-processor');
                        $this->debug("Destroyed existing consumer groups");
                    } catch (\Exception $e) {
                        $this->debug("Error destroying groups (may not exist): " . $e->getMessage());
                    }
                }

                // Create client consumer group
                try {
                    $this->storage->xGroupCreate(
                        $this->unifiedStream,
                        'gsd-client',
                        '0', // Start from beginning (critical for protocol v2)
                        true // Create stream if not exists
                    );

                    // Create daemon consumer group
                    $this->storage->xGroupCreate(
                        $this->unifiedStream,
                        'gsd-daemon',
                        '0', // Start from beginning (critical for protocol v2)
                        true // Create stream if not exists
                    );

                    $this->debug("Created unified stream and consumer groups with direct Redis calls");
                } catch (\Exception $e) {
                    // Ignore BUSYGROUP error (group already exists)
                    if (!strpos($e->getMessage(), 'BUSYGROUP')) {
                        throw $e;
                    }
                    $this->debug("Consumer groups already exist for unified stream");
                }
            }
        } catch (\Exception $e) {
            // Stream groups may already exist, not necessarily an error
            $this->debug("Stream setup warning (may be benign): {$e->getMessage()}");
        }
    }

    /**
     * Ensure consumer groups are initialized (lazy initialization with triple-layer caching)
     *
     * This method implements a three-tier caching strategy to minimize overhead:
     * 1. Worker-wide static state (fastest - 0ms, survives entire PHP-FPM worker lifecycle)
     * 2. ValKey cache (fast - ~2ms, survives 5 minutes, protects across workers)
     * 3. Actual initialization (slow - ~1000ms, only when truly needed)
     *
     * This eliminates the 500-1000ms consumer group verification that was blocking
     * EVERY WordPress initialization, allowing the site to handle 1000+ concurrent users.
     *
     * @throws StorageException If initialization fails
     */
    protected function ensureConsumerGroupsInitialized(): void
    {
        // Layer 1: Check if already initialized in THIS instance
        if ($this->consumerGroupsInitialized) {
            return; // Already initialized, 0ms overhead
        }

        // Layer 2: Check worker-wide static state (survives across requests in same PHP-FPM worker)
        $streamKey = "{$this->siteId}:{$this->nodeId}";
        if (isset(self::$workerInitializedStreams[$streamKey])) {
            $this->consumerGroupsInitialized = true;
            // error_log('[GSD-Client] Consumer groups already initialized in this worker (static cache hit)');
            return; // Worker already initialized this stream, ~0ms overhead
        }

        // Layer 3: Check ValKey cache (protects against concurrent initializations across workers)
        $cacheKey = "gsd:consumer_groups_verified:{$this->siteId}:{$this->nodeId}";
        try {
            $cachedVerification = $this->storage->get($cacheKey);
            if ($cachedVerification === '1') {
                // Another worker (or previous request) verified recently
                $this->consumerGroupsInitialized = true;
                self::$workerInitializedStreams[$streamKey] = true;
                // error_log('[GSD-Client] Consumer groups already initialized (ValKey cache hit)');
                return; // Cache hit, ~2ms overhead
            }
        } catch (\Exception $e) {
            // Cache check failed, proceed to initialization
            error_log('[GSD-Client] Cache check failed: ' . $e->getMessage());
        }

        // Layer 4: Actually initialize consumer groups (expensive, but only when truly needed)
        if ($this->usingConsumerGroups && $this->consumerHandler) {
            // error_log('[GSD-Client] Initializing consumer groups (cache miss, first time in this worker)');
            $this->consumerHandler->initialize();

            // Cache the result in ValKey (5 min TTL)
            try {
                $this->storage->set($cacheKey, '1', 300);
            } catch (\Exception $e) {
                error_log('[GSD-Client] Failed to cache initialization result: ' . $e->getMessage());
            }

            // Mark as initialized in worker-wide static state AND instance state
            self::$workerInitializedStreams[$streamKey] = true;
            $this->consumerGroupsInitialized = true;

            error_log('[GSD-Client] Consumer groups initialized and cached');
        } else {
            // Consumer groups disabled, mark as initialized to skip future checks
            $this->consumerGroupsInitialized = true;
        }
    }

    /**
     * Check if consumer groups have the proper settings
     *
     * @return bool True if groups need recreation, false if properly configured
     */
    protected function checkConsumerGroupSettings(): bool
    {
        try {
            // Check if stream exists first
            if (!$this->storage->exists($this->unifiedStream)) {
                return false; // No need to check further if stream doesn't exist
            }

            // Get information about existing groups
            $groups = $this->storage->xInfo('GROUPS', $this->unifiedStream);

            if (empty($groups)) {
                return false; // No groups exist yet
            }

            $needsRecreation = false;

            foreach ($groups as $group) {
                // Check if client group is properly configured with ID '0'
                if ($group['name'] === 'gsd-client' && $group['last-delivered-id'] !== '0-0') {
                    $this->debug("Client group exists but not configured with ID '0'");
                    $needsRecreation = true;
                }

                // Check if daemon group is properly configured with ID '0'
                if ($group['name'] === 'gsd-command-processor' && $group['last-delivered-id'] !== '0-0') {
                    $this->debug("Daemon group exists but not configured with ID '0'");
                    $needsRecreation = true;
                }
            }

            return $needsRecreation;
        } catch (\Exception $e) {
            $this->debug("Error checking consumer group settings: " . $e->getMessage());
            return false; // Assume no recreation needed on error
        }
    }

    /**
     * Connect to the daemon via Redis
     *
     * @return bool Success status
     * @throws ConnectionException If connection fails and auto-start is enabled
     */
    protected function connect(): bool
    {
        if (!$this->storage->isConnected()) {
            throw new StorageException('Not connected to Redis server');
        }

        $this->debug("Connecting to GSD daemon via unified stream: {$this->unifiedStream}");

        // Check if daemon is running by pinging it
        $response = $this->sendCommand('ping');

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            // Only set connected=true and usingFallback=false if we're not already using fallback
            // (sendCommand may have activated fallback mode if daemon didn't respond)
            if (!$this->usingFallback) {
                $this->connected = true;
                $this->debug("Successfully connected to GSD daemon");
            } else {
                $this->debug("Ping succeeded via fallback (daemon not available)");
            }
            return true;
        }

        // If auto-start is enabled, try to start the daemon
        if ($this->config['auto_start_daemon'] && $this->config['daemon_path']) {
            $this->debug("Auto-starting GSD daemon");

            if (!$this->startDaemon()) {
                throw new ConnectionException(
                    "Failed to auto-start GSD daemon"
                );
            }

            // Wait for daemon to start
            $attempts = 0;
            $maxAttempts = $this->config['retry_count'];
            $delay = $this->config['retry_delay'];

            while ($attempts < $maxAttempts) {
                sleep($delay);
                $attempts++;

                $this->debug("Pinging daemon (attempt {$attempts}/{$maxAttempts})");
                $response = $this->sendCommand('ping');

                if ($response && isset($response['status']) && $response['status'] === 'ok') {
                    // Only set connected=true and usingFallback=false if we're not already using fallback
                    if (!$this->usingFallback) {
                        $this->connected = true;
                        $this->debug("Successfully connected to auto-started GSD daemon");
                    } else {
                        $this->debug("Ping succeeded via fallback (daemon still not responding)");
                    }
                    return true;
                }
            }

            $this->debug("Failed to connect to auto-started GSD daemon after {$maxAttempts} attempts");
        }

        // Fall back to local implementation if available
        if ($this->fallback && $this->config['use_fallback']) {
            $this->debug("Using fallback implementation");
            $this->usingFallback = true;
            return true;
        }

        $this->debug("Failed to connect to GSD daemon and no fallback available");
        return false;
    }

    /**
     * Start the daemon process
     *
     * @return bool Success status
     */
    public function startDaemon(): bool
    {
        $daemonPath = $this->config['daemon_path'];
        if (!$daemonPath || !file_exists($daemonPath)) {
            $this->debug("Invalid daemon path: {$daemonPath}");
            return false;
        }

        try {
            // Build environment variables
            $env = [
                'REDIS_HOST' => $this->config['redis_host'] ?? '127.0.0.1',
                'REDIS_PORT' => $this->config['redis_port'] ?? '6379',
                'REDIS_AUTH' => $this->config['redis_auth'] ?? '',
                'SITE_ID' => $this->siteId,
                'NODE_ID' => $this->nodeId,
                'STREAM_PREFIX' => $this->config['stream_prefix'],
                'USE_UNIFIED_STREAM' => '1', // Enable unified stream mode
                'RUST_LOG' => 'info',
                'DEBUG' => $this->config['debug'] ? '1' : '0'
            ];

            // Build environment string
            $envStr = '';
            foreach ($env as $key => $value) {
                $envStr .= "{$key}='{$value}' ";
            }

            // Start daemon in background
            $logPath = $this->config['log_path'] ?? '/tmp/gsd-daemon.log';
            $command = "{$envStr} {$daemonPath} --site-id {$this->siteId} --node-id {$this->nodeId} --debug > {$logPath} 2>&1 & echo $!";
            $pid = exec($command);

            $this->debug("Started GSD daemon with PID: {$pid}");

            // Store PID for future reference
            $pidKey = sprintf(
                '{%s}:%s:daemon:pid:%s',
                $this->siteId,
                $this->config['stream_prefix'],
                $this->nodeId
            );
            $this->storage->set($pidKey, $pid);

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
        // Get daemon PID
        $pidKey = sprintf(
            '%s:%s:daemon:pid:%s',
            $this->siteId,
            $this->config['stream_prefix'],
            $this->nodeId
        );
        $pid = $this->storage->get($pidKey);

        if (!$pid) {
            $this->debug("No PID found for daemon");
            return false;
        }

        try {
            // Send SIGTERM to daemon
            $command = "kill {$pid} 2>/dev/null || true";
            exec($command);

            // Wait for daemon to stop
            $attempts = 0;
            $maxAttempts = $this->config['retry_count'];
            $delay = $this->config['retry_delay'];

            while ($attempts < $maxAttempts) {
                sleep($delay);
                $attempts++;

                // Check if process is still running
                $command = "ps -p {$pid} > /dev/null 2>&1 || echo 'not-running'";
                $result = exec($command);

                if ($result === 'not-running') {
                    $this->debug("GSD daemon (PID: {$pid}) stopped successfully");
                    $this->storage->delete($pidKey);
                    $this->connected = false;
                    return true;
                }
            }

            // Force kill if still running
            $command = "kill -9 {$pid} 2>/dev/null || true";
            exec($command);

            $this->debug("GSD daemon (PID: {$pid}) forcefully terminated");
            $this->storage->delete($pidKey);
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
        // Get daemon PID
        $pidKey = sprintf(
            '%s:%s:daemon:pid:%s',
            $this->siteId,
            $this->config['stream_prefix'],
            $this->nodeId
        );
        $pid = $this->storage->get($pidKey);

        if (!$pid) {
            return [
                'running' => false,
                'pid' => null,
                'connected' => $this->connected,
                'uptime' => 0
            ];
        }

        // Check if process is running
        $command = "ps -p {$pid} -o etime= 2>/dev/null || echo ''";
        $uptime = trim(exec($command));

        $running = !empty($uptime);

        return [
            'running' => $running,
            'pid' => $pid,
            'connected' => $this->connected,
            'uptime' => $uptime
        ];
    }

    /**
     * Adapt command parameters to the format expected by the GSD daemon
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return array Adapted parameters
     */
    protected function adaptCommandParameters(string $command, array $parameters = []): array
    {
        switch ($command) {
            case 'geometric_store_topology':
                // Per API docs, topology data needs to be wrapped in 'data' field
                if (!isset($parameters['data']) && !empty($parameters)) {
                    return ['data' => $parameters];
                }
                break;

            case 'geometric_discover':
                // Per API docs, capabilities should be an array of strings, not a map
                if (
                    isset($parameters['capabilities']) && is_array($parameters['capabilities']) &&
                    !isset($parameters['capabilities'][0]) && count($parameters['capabilities']) > 0
                ) {
                    // Convert associative array to indexed array of keys
                    $parameters['capabilities'] = array_keys($parameters['capabilities']);
                }
                break;
        }

        return $parameters;
    }

    /**
     * Drain pending responses from the unified stream
     *
     * This clears any unconsumed responses to prevent receiving stale data
     * when making new requests.
     *
     * @return int Number of messages drained
     */
    protected function drainPendingResponses(): int
    {
        $drained = 0;
        $consumerName = sprintf(
            'client-%s-%s-%s',
            $this->siteId,
            $this->nodeId,
            substr($this->clientId, 0, 8)
        );

        try {
            // Read and acknowledge all pending messages without processing
            for ($i = 0; $i < 100; $i++) { // Max 100 iterations to prevent infinite loops
                $messages = $this->storage->xReadGroup(
                    'gsd-client',
                    $consumerName,
                    [$this->unifiedStream => '>'],
                    50,  // Read up to 50 messages at once
                    100  // 100ms timeout
                );

                if (empty($messages[$this->unifiedStream])) {
                    break; // No more messages
                }

                // Acknowledge all messages to remove them
                $ids = array_keys($messages[$this->unifiedStream]);
                foreach ($ids as $id) {
                    $this->storage->xAck($this->unifiedStream, 'gsd-client', [$id]);
                    $drained++;
                }

                if (count($ids) < 50) {
                    break; // We got less than requested, so no more pending
                }
            }

            if ($drained > 0) {
                $this->debug("Drained {$drained} pending responses from stream");
            }
        } catch (\Exception $e) {
            $this->debug("Error draining responses: " . $e->getMessage());
        }

        return $drained;
    }

    /**
     * Send a command to the daemon via Redis stream
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return array|null Response or null on failure
     * @throws GSDException On unexpected errors
     */
    protected function sendCommand(string $command, array $parameters = []): ?array
    {
        if (!$this->storage->isConnected()) {
            throw new StorageException('Not connected to Redis server');
        }

        // LAZY INITIALIZATION: Ensure consumer groups are initialized before first command
        // This eliminates 500-1000ms blocking on WordPress initialization.
        // Initialization only happens on first actual GSD command.
        $this->ensureConsumerGroupsInitialized();

        // Drain any pending responses before sending new command
        // This prevents receiving stale responses from previous requests
        // TEMPORARILY DISABLED FOR TESTING
        /*
        if (in_array($command, ['render_template', 'list_templates'])) {
            if ($this->usingConsumerGroups && $this->consumerHandler) {
                // Use consumer group flush for better isolation
                $this->consumerHandler->flushPending();
            } else {
                // Fall back to direct stream drain
                $this->drainPendingResponses();
            }
        }
        */

        // Adapt parameters to the expected format
        $parameters = $this->adaptCommandParameters($command, $parameters);

        // Check if using fallback
        if ($this->usingFallback && $this->fallback) {
            return $this->executeFallbackCommand($command, $parameters);
        }

        // Check cache for read-only commands
        $cacheKey = null;
        $useCache = in_array($command, ['findServices', 'getLoadSequence', 'getCapabilityDimensions']);

        if ($useCache) {
            $cacheKey = md5($command . json_encode($parameters));
            if (
                isset($this->responseCache[$cacheKey]) &&
                (time() - $this->responseCache[$cacheKey]['time'] < $this->cacheExpiration)
            ) {
                return $this->responseCache[$cacheKey]['data'];
            }
        }

        try {
            // Generate a unique request ID
            $requestId = uniqid($this->siteId . ':', true);

            // Use consumer group handler if enabled (unified stream is required)
            if ($this->usingConsumerGroups && $this->consumerHandler) {
                // Send command using consumer group handler
                $messageId = $this->consumerHandler->sendCommand($command, $parameters, $requestId);
                $this->debug("Sent command {$command} with consumer group handler, message ID: {$messageId}");

                // Wait for response using consumer group handler
                $response = $this->consumerHandler->readResponse($requestId, $this->timeout);

                if ($response) {
                    // Cache the response if needed
                    if ($useCache && $cacheKey && isset($response['status']) && $response['status'] === 'ok') {
                        $this->responseCache[$cacheKey] = [
                            'time' => time(),
                            'data' => $response
                        ];
                    }

                    return $response;
                }

                // Timeout occurred, use fallback if available
                if ($this->fallback && $this->config['use_fallback']) {
                    $this->debug("Falling back to local implementation after timeout");
                    $this->usingFallback = true;
                    return $this->executeFallbackCommand($command, $parameters);
                }

                throw new GSDException("Timed out waiting for response to command: {$command}");
            }

            // Direct stream interaction (used as a backup approach)
            $this->debug("Sending command: {$command} with ID: {$requestId}");

            // Prepare message fields using RESP3 protocol format
            // CRITICAL: Use full command names (not abbreviated) as per GSD daemon spec
            $fields = [
                't' => 'c',                          // Type: command
                'c' => $command,                     // Full command name (REQUIRED by daemon)
                'p' => json_encode($parameters, JSON_UNESCAPED_SLASHES), // Parameters
                's' => ++$this->sequenceCounter       // Sequence number (REQUIRED by daemon)
            ];

            // Add message to unified stream
            $msgId = $this->storage->xAdd($this->unifiedStream, '*', $fields);
            $this->debug("Added command message to unified stream with ID: " . $msgId);

            // Wait for response with polling
            $start = microtime(true);
            $timeout = $this->timeout / 1000; // Convert to seconds
            $interval = 0.1; // 100ms polling interval

            $this->debug("Waiting for response to command: {$command}, ID: {$requestId}, timeout: {$timeout}s");

            // Create consumer name
            $consumerName = sprintf(
                'client-%s-%s-%s',
                $this->siteId,
                $this->nodeId,
                substr($this->clientId, 0, 8)
            );

            // Ensure consumer group exists (we're using braced format for hash distribution)
            try {
                $this->storage->xGroupCreate($this->unifiedStream, 'gsd-client', '0', true);
            } catch (\Exception $e) {
                // Ignore BUSYGROUP error
                if (!strpos($e->getMessage(), 'BUSYGROUP')) {
                    $this->debug("Error creating consumer group: " . $e->getMessage());
                }
            }

            do {
                // Read from unified stream using XREADGROUP
                $messages = $this->storage->xReadGroup(
                    'gsd-client',
                    $consumerName,
                    [$this->unifiedStream => '>'],
                    10,
                    floor($interval * 1000)
                );

                if (!empty($messages[$this->unifiedStream])) {
                    foreach ($messages[$this->unifiedStream] as $id => $data) {
                        // Process response messages (type 'r' or 'br' for batch responses)
                        // Check for both direct ID match and 'ri' (reply ID) field that some GSD implementations use
                        // Also check if 'ri' matches the message ID of our request (daemon may use stream IDs)
                        $messageType = $data['t'] ?? '';
                        $isResponseType = $messageType === 'r' || $messageType === 'br';
                        $matchesMsgId = isset($data['ri']) && $data['ri'] === $msgId;

                        if (
                            $isResponseType &&
                            ((isset($data['id']) && $data['id'] === $requestId) ||
                             (isset($data['ri']) && $data['ri'] === $requestId) ||
                             $matchesMsgId)
                        ) {
                            $this->debug("Found matching response for request ID: {$requestId}");

                            // Acknowledge the message
                            $this->storage->xAck($this->unifiedStream, 'gsd-client', [$id]);

                            $response = null;

                            // Parse the response from the 'r' (result) field
                            if (isset($data['r'])) {
                                try {
                                    $responseData = $data['r'];

                                    // Handle boolean responses (e.g., from ping)
                                    if ($responseData === "true" || $responseData === true) {
                                        $response = ['result' => true];
                                    } elseif ($responseData === "false" || $responseData === false) {
                                        $response = ['result' => false];
                                    } else {
                                        // Parse JSON response
                                        $response = json_decode($responseData, true);

                                        if (json_last_error() !== JSON_ERROR_NONE) {
                                            // Try unescaping if there are issues
                                            $unescaped = stripslashes($responseData);
                                            $response = json_decode($unescaped, true);

                                            if (json_last_error() !== JSON_ERROR_NONE) {
                                                $this->debug("Failed to parse response JSON: " . json_last_error_msg());
                                                throw new \Exception("Invalid JSON response");
                                            }
                                        }
                                    }

                                    // Add status field if not present
                                    if (!isset($response['status'])) {
                                        if (isset($data['s'])) {
                                            $response['status'] = $data['s'];
                                        } elseif (isset($data['st'])) {
                                            $response['status'] = $data['st'];
                                        }
                                    }

                                    // Add message field if not present
                                    if (!isset($response['message'])) {
                                        if (isset($data['m'])) {
                                            $response['message'] = $data['m'];
                                        } elseif (isset($data['msg'])) {
                                            $response['message'] = $data['msg'];
                                        }
                                    }
                                } catch (\Exception $e) {
                                    $this->debug("Error parsing response: " . $e->getMessage());
                                    throw new GSDException("Error parsing response: " . $e->getMessage());
                                }
                            } else {
                                // Construct response from field data directly
                                $response = [
                                    'status' => $data['s'] ?? $data['st'] ?? 'unknown',
                                    'result' => isset($data['r']) ?
                                        (is_string($data['r']) ? json_decode($data['r'], true) : $data['r']) : null,
                                    'message' => $data['m'] ?? $data['msg'] ?? null
                                ];
                            }

                            // Cache the response if needed
                            if ($useCache && $cacheKey && isset($response['status']) && $response['status'] === 'ok') {
                                $this->responseCache[$cacheKey] = [
                                    'time' => time(),
                                    'data' => $response
                                ];
                            }

                            return $response;
                        } else {
                            // Acknowledge other messages so they don't pile up
                            $this->storage->xAck($this->unifiedStream, 'gsd-client', [$id]);
                        }
                    }
                }

                // Sleep for interval
                usleep($interval * 1000000);
            } while (microtime(true) - $start < $timeout);

            $this->debug("Timed out waiting for response to command: {$command}");

            // Timeout occurred, use fallback if available
            if ($this->fallback && $this->config['use_fallback']) {
                $this->debug("Falling back to local implementation after timeout");
                $this->usingFallback = true;
                return $this->executeFallbackCommand($command, $parameters);
            }

            throw new GSDException("Timed out waiting for response to command: {$command}");
        } catch (GSDException $e) {
            $this->debug("GSD error: " . $e->getMessage());

            if ($this->fallback && $this->config['use_fallback']) {
                $this->debug("Falling back to local implementation after GSD error");
                $this->usingFallback = true;
                return $this->executeFallbackCommand($command, $parameters);
            }

            throw $e;
        } catch (\Exception $e) {
            $this->debug("Unexpected error: " . $e->getMessage());

            if ($this->fallback && $this->config['use_fallback']) {
                $this->debug("Falling back to local implementation after unexpected error");
                $this->usingFallback = true;
                return $this->executeFallbackCommand($command, $parameters);
            }

            throw new GSDException("Error sending command: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute a command using the fallback implementation
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return array|null Result or null on failure
     * @throws GSDException On unsupported commands
     */
    protected function executeFallbackCommand(string $command, array $parameters = []): ?array
    {
        if (!$this->fallback) {
            throw new GSDException("Fallback mode requested but not available");
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
     * Log debug messages
     *
     * @param string $message Debug message
     */
    protected function debug(string $message): void
    {
        if ($this->config['debug']) {
            error_log("[GSD Client] {$message}");
        }
    }

    /**
     * Register a capability dimension
     *
     * @param string $name Name of the capability
     * @param int $dimension Dimension index
     * @return bool Success
     * @throws GSDException On server errors
     */
    public function registerCapabilityDimension(string $name, int $dimension): bool
    {
        $response = $this->sendCommand('registerCapabilityDimension', [
            'name' => $name,
            'dimension' => $dimension
        ]);

        // Update local cache of capability dimensions
        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            $this->capabilityDimensions[$name] = $dimension;
            return true;
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error registering capability dimension');
        }

        return false;
    }

    /**
     * Register a service with the topology
     *
     * @param string $id Service ID
     * @param array $capabilities Array of capabilities [name => value]
     * @param array $metadata Optional metadata
     * @return bool Success
     * @throws GSDException On server errors
     */
    public function registerService(string $id, array $capabilities, array $metadata = []): bool
    {
        $response = $this->sendCommand('registerService', [
            'id' => $id,
            'capabilities' => $capabilities,
            'metadata' => $metadata
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return true;
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error registering service');
        }

        return false;
    }

    /**
     * Deregister a service from the GSD topology
     *
     * This removes the service from:
     * - The topology's services list
     * - The spatial hash index
     * - The dependencies map
     *
     * The removal is persisted to ValKey immediately.
     *
     * @param string $serviceId The service ID to deregister
     * @return bool True if service was found and removed, false if not found
     * @throws GSDException On server errors
     */
    public function deregisterService(string $serviceId): bool
    {
        $response = $this->sendCommand('deregisterService', [
            'service_id' => $serviceId
        ]);

        // Successfully deregistered
        if ($response && isset($response['status']) && $response['status'] === 'deregistered') {
            return true;
        }

        // Service was not found (not an error - may already be deregistered)
        if ($response && isset($response['status']) && $response['status'] === 'not_found') {
            return false;
        }

        // Server error
        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error deregistering service');
        }

        return false;
    }

    /**
     * Find services matching requirements
     *
     * @param array $requirements Array of requirements [name => min_value]
     * @return array Array of service IDs
     * @throws GSDException On server errors
     */
    public function findServices(array $requirements): array
    {
        $response = $this->sendCommand('findServices', [
            'requirements' => $requirements
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [];
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error finding services');
        }

        return [];
    }

    /**
     * Get service details by ID
     *
     * @param string $serviceId Service ID
     * @return array Service details with capabilities and metadata
     * @throws GSDException On server errors
     */
    public function getServiceDetails(string $serviceId): array
    {
        $response = $this->sendCommand('getServiceDetails', [
            'id' => $serviceId
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [
                'id' => $serviceId,
                'capabilities' => [],
                'metadata' => []
            ];
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error getting service details');
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
     * @throws GSDException On server errors
     */
    public function getLoadSequence(string $group = 'default'): array
    {
        // Use the new geometric_load_sequence command with backward compatibility
        $response = $this->sendCommand('geometric_load_sequence', [
            'group' => $group
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [];
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error getting load sequence');
        }

        return [];
    }

    /**
     * Get registered capability dimensions
     *
     * @return array Map of capability names to dimensions
     * @throws GSDException On server errors
     */
    public function getCapabilityDimensions(): array
    {
        // Use the new geometric_dimensions command with backward compatibility
        $response = $this->sendCommand('geometric_dimensions', []);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            $this->capabilityDimensions = $response['result'] ?? [];
            return $this->capabilityDimensions;
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error getting capability dimensions');
        }

        return $this->capabilityDimensions;
    }

    /**
     * Check if client is connected to daemon
     *
     * @return bool Connection status
     */
    public function isConnected(): bool
    {
        return $this->connected || $this->usingFallback;
    }

    /**
     * Check if client is using fallback mode
     *
     * @return bool Fallback status
     */
    public function isUsingFallback(): bool
    {
        return $this->usingFallback;
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
            'using_consumer_groups' => $this->usingConsumerGroups,
            'site_id' => $this->siteId,
            'node_id' => $this->nodeId,
            'unified_stream' => $this->unifiedStream,
            'consumer_name' => $this->consumerHandler ? $this->consumerHandler->getConsumerName() :
                sprintf('client-%s-%s-%s', $this->siteId, $this->nodeId, substr($this->clientId, 0, 8)),
            'daemon' => $this->getDaemonStatus()
        ];
    }

    /**
     * Ping the daemon to check connectivity
     *
     * @return bool|DeferredResult True if ping succeeded, or DeferredResult if queued
     */
    public function ping()
    {
        // Use queue if enabled
        if ($this->queue !== null) {
            return $this->queue->enqueue('ping', []);
        }

        // Direct execution (original behavior)
        try {
            $response = $this->sendCommand('ping', []);
            return $response && isset($response['status']) && $response['status'] === 'ok';
        } catch (\Exception $e) {
            $this->debug("Ping failed: " . $e->getMessage());
            return false;
        }
    }

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
     * Get the command queue instance
     *
     * @return CommandQueue|null
     */
    public function getQueue(): ?CommandQueue
    {
        return $this->queue;
    }

    /**
     * Get the ValKey storage instance
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
     * @return string Environment name (production/staging/testing/acceptance)
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * Get the compute stream name for the current environment
     *
     * Used by KeyBasedClient to route requests to the per-environment stream.
     *
     * @return string Stream name (e.g., "gsd:compute:production")
     */
    public function getComputeStream(): string
    {
        return sprintf('gsd:compute:%s', $this->environment);
    }

    /**
     * Clear response cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->responseCache = [];
    }

    /**
     * Execute a generic command
     *
     * This allows executing any command supported by the GSD daemon
     *
     * @deprecated Use KeyBasedClientLuaEnabled::executeBatch() instead, even for single commands.
     *             The key-based architecture is faster and provides better metrics.
     *             Example: $client->executeBatch([['cmd' => 'ping', 'params' => []]])
     *             Will be removed in v3.0.
     * @see KeyBasedClientLuaEnabled::executeBatch() Preferred method
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return array|null Response data or null on failure
     * @throws GSDException On server errors
     */
    public function executeCommand(string $command, array $parameters = []): ?array
    {
        $response = $this->sendCommand($command, $parameters);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            // Parse batched response format first: {status: "ok", messages: [["r", "0", "{json}", "0"]]}
            if (isset($response['messages']) && !empty($response['messages'])) {
                $message = $response['messages'][0];
                if (isset($message[2])) {
                    $result = json_decode($message[2], true);
                    return is_array($result) ? $result : [];
                }
            }

            // Fallback to old format for backward compatibility
            if (isset($response['result'])) {
                // Handle boolean results separately
                if (is_bool($response['result'])) {
                    return [
                        'status' => 'ok',
                        'success' => $response['result'],
                        'result' => $response['result']
                    ];
                }
                return $response['result'];
            }

            return [];
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error executing command: ' . $command);
        }

        return null;
    }

    /**
     * Execute a batch of commands (legacy stream-based)
     *
     * This method allows executing multiple commands in a single batch
     * for improved performance. The batch is sent as a single message
     * with type 'bc' (batch command) according to the GSD protocol v2.
     *
     * @deprecated Use KeyBasedClientLuaEnabled::executeBatch() instead.
     *             This base class uses stream-based communication which is slower
     *             than the key-based architecture in KeyBasedClientLuaEnabled.
     *             Will be removed in v3.0.
     * @see KeyBasedClientLuaEnabled::executeBatch() Preferred implementation
     *
     * Supports two command formats:
     * 1. Legacy: [command_name, parameters]
     * 2. New: ['cmd' => command_name, 'params' => parameters, 'id' => id]
     *
     * @param array $commands Array of commands
     * @return array Results for each command in the batch
     * @throws GSDException On server errors
     */
    public function executeBatch(array $commands): array
    {
        if (empty($commands)) {
            return [];
        }

        $this->debug("Executing batch of " . count($commands) . " commands");

        // Check if using fallback
        if ($this->usingFallback && $this->fallback) {
            $results = [];
            foreach ($commands as $index => $cmdData) {
                // Handle both formats
                if (isset($cmdData['cmd'])) {
                    // New format: ['cmd' => ..., 'params' => ...]
                    $command = $cmdData['cmd'];
                    $parameters = $cmdData['params'] ?? [];
                } else {
                    // Legacy format: [command, parameters]
                    list($command, $parameters) = $cmdData;
                }
                $results[$index] = $this->executeFallbackCommand($command, $parameters);
            }
            return $results;
        }

        try {
            // Generate a unique batch ID
            $batchId = uniqid($this->siteId . ':batch:', true);

            // Format commands for batch sending
            $batchCommands = [];
            $requestIds = [];

            foreach ($commands as $index => $cmdData) {
                // Handle both formats
                if (isset($cmdData['cmd'])) {
                    // New format: ['cmd' => ..., 'params' => ..., 'id' => ...]
                    $command = $cmdData['cmd'];
                    $parameters = $cmdData['params'] ?? [];
                    $requestId = $cmdData['id'] ?? uniqid($this->siteId . ':' . $index . ':seq:' . $index . ':', true);
                } else {
                    // Legacy format: [command, parameters]
                    list($command, $parameters) = $cmdData;
                    $requestId = uniqid($this->siteId . ':' . $index . ':seq:' . $index . ':', true);
                }
                $requestIds[$index] = $requestId;
                // Adapt parameters to the expected format
                $parameters = $this->adaptCommandParameters($command, $parameters);

                // Add to batch commands with the correct format for protocol v2
                // Format: [type, command, params_json, sequence]
                // CRITICAL: Use full command name (not abbreviated)
                $batchCommands[] = [
                    'c',  // Type is 'c' for commands within a batch
                    $command,  // Full command name (REQUIRED by daemon)
                    json_encode($parameters, JSON_UNESCAPED_SLASHES),
                    $index // Sequence number
                ];
            }

            // Use consumer group handler if enabled
            if ($this->usingConsumerGroups && $this->consumerHandler) {
                // Send batch command using consumer group handler
                $messageId = $this->consumerHandler->sendBatchCommand($batchCommands, $batchId);
                $this->debug("Sent batch command with ID: {$batchId}, message ID: {$messageId}");

                // Wait for batch response (protocol v2 uses 'br' type)
                $batchResponse = $this->consumerHandler->readResponse($batchId, $this->timeout);

                if ($batchResponse && isset($batchResponse['batch_id']) && $batchResponse['batch_id'] === $batchId) {
                    $this->debug("Received batch response for batch ID: {$batchId}");

                    // Process batch messages if available
                    $responses = [];

                    if (isset($batchResponse['messages']) && is_array($batchResponse['messages'])) {
                        $batchMessages = $batchResponse['messages'];

                        foreach ($batchMessages as $batchMsg) {
                            // Batch message format: ["r", command_name, response_json, sequence_number]
                            if (is_array($batchMsg) && count($batchMsg) >= 4) {
                                $msgType = $batchMsg[0];
                                $command = $batchMsg[1]; // Usually contains the command name
                                $responseData = $batchMsg[2];
                                $sequenceNumber = $batchMsg[3];

                                // Skip non-response messages
                                if ($msgType !== 'r') {
                                    $this->debug("Skipping non-response message of type '{$msgType}' in batch");
                                    continue;
                                }

                                // Parse response data
                                $parsedResponse = null;
                                if (is_string($responseData)) {
                                    $decoded = json_decode($responseData, true);
                                    if (json_last_error() !== JSON_ERROR_NONE) {
                                        // JSON parse error - wrap as result
                                        $parsedResponse = ['result' => $responseData];
                                    } elseif (is_array($decoded)) {
                                        // Successfully decoded as array
                                        $parsedResponse = $decoded;
                                    } else {
                                        // JSON scalar (true, false, null, number, string) - wrap it
                                        $parsedResponse = ['result' => $decoded];
                                    }
                                } else {
                                    // Non-string data - wrap as result
                                    $parsedResponse = ['result' => $responseData];
                                }

                                if ($parsedResponse && is_array($parsedResponse)) {
                                    if (!isset($parsedResponse['status'])) {
                                        $parsedResponse['status'] = 'ok';
                                    }
                                    $responses[$sequenceNumber] = $parsedResponse;
                                }
                            }
                        }
                    }

                    // If we got responses from the batch, return them
                    if (!empty($responses)) {
                        // Fill in any missing responses with error messages
                        foreach (array_keys($commands) as $index) {
                            if (!isset($responses[$index])) {
                                $responses[$index] = [
                                    'status' => 'error',
                                    'error' => "No response received for command at index {$index} in batch"
                                ];
                            }
                        }

                        return $responses;
                    }
                }

                // If we didn't get a batch response or responses array was empty,
                // fall back to sending individual commands
                $this->debug("Batch response not received or empty, falling back to individual commands");

                $responses = [];
                foreach ($commands as $index => $cmdData) {
                    list($command, $parameters) = $cmdData;
                    $this->debug("Sending individual command from batch: {$command} (index {$index})");

                    try {
                        $individualResponse = $this->sendCommand($command, $parameters);
                        if ($individualResponse) {
                            $responses[$index] = $individualResponse;
                        } else {
                            $responses[$index] = [
                                'status' => 'error',
                                'error' => "Failed to get response for command in batch"
                            ];
                        }
                    } catch (\Exception $e) {
                        $responses[$index] = [
                            'status' => 'error',
                            'error' => "Error executing command: " . $e->getMessage()
                        ];
                    }
                }

                return $responses;
            } else {
                // Not using consumer groups - execute commands individually
                $this->debug("Consumer groups not enabled, executing commands individually");

                $responses = [];
                foreach ($commands as $index => $cmdData) {
                    list($command, $parameters) = $cmdData;
                    $responses[$index] = $this->sendCommand($command, $parameters);
                }
                return $responses;
            }
        } catch (GSDException $e) {
            $this->debug("GSD error in batch execution: " . $e->getMessage());

            if ($this->fallback && $this->config['use_fallback']) {
                $this->debug("Falling back to local implementation after GSD error in batch");
                $this->usingFallback = true;

                $results = [];
                foreach ($commands as $index => $cmdData) {
                    list($command, $parameters) = $cmdData;
                    $results[$index] = $this->executeFallbackCommand($command, $parameters);
                }
                return $results;
            }

            throw $e;
        } catch (\Exception $e) {
            $this->debug("Unexpected error in batch execution: " . $e->getMessage());

            if ($this->fallback && $this->config['use_fallback']) {
                $this->debug("Falling back to local implementation after unexpected error in batch");
                $this->usingFallback = true;

                $results = [];
                foreach ($commands as $index => $cmdData) {
                    list($command, $parameters) = $cmdData;
                    $results[$index] = $this->executeFallbackCommand($command, $parameters);
                }
                return $results;
            }

            throw new GSDException("Error executing batch: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Discover services based on geometric requirements
     *
     * @param array $capabilities Required capabilities with minimum values or capability names
     * @param int $limit Maximum number of services to return
     * @param int $dimensions Number of dimensions to consider
     * @param int $distance Maximum distance threshold
     * @return array Array of matching services
     * @throws GSDException On server errors
     */
    public function geometricDiscover(array $capabilities, int $limit = 10, int $dimensions = 0, int $distance = 0): array
    {
        // Per API docs, capabilities should be an array of strings
        if (!isset($capabilities[0]) && count($capabilities) > 0) {
            // Convert associative array to indexed array of capability names
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
            throw new GSDException($response['error'] ?? 'Unknown error in geometric discovery');
        }

        return [];
    }

    /**
     * Store topology information
     *
     * @param array $topology Topology structure to store
     * @param int $dimensions Number of dimensions
     * @return bool Success status
     * @throws GSDException On server errors
     */
    public function geometricStoreTopology(array $topology, int $dimensions = 8): bool
    {
        // Per API docs, topology data should be provided in the 'data' field
        $response = $this->sendCommand('geometric_store_topology', [
            'data' => $topology
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return true;
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error storing topology');
        }

        return false;
    }

    /**
     * Calculate the geometric distance between two points
     *
     * @param array $point1 First point coordinates
     * @param array $point2 Second point coordinates
     * @return array Distance information
     * @throws GSDException On server errors
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

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error calculating geometric distance');
        }

        return [
            'distance' => 0,
            'dimensions' => count($point1)
        ];
    }

    /**
     * Get information about a stream
     *
     * @param string $stream Stream name
     * @return array Stream information
     * @throws GSDException On server errors
     */
    public function streamInfo(string $stream): array
    {
        $response = $this->sendCommand('stream_info', [
            'stream' => $stream
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [];
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error getting stream info');
        }

        return [];
    }

    /**
     * Get information about consumer groups for a stream
     *
     * @param string $stream Stream name
     * @param string|null $group Optional group name filter
     * @return array Consumer group information
     * @throws GSDException On server errors
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

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error getting stream group info');
        }

        return [];
    }

    /**
     * Get information about a node
     *
     * @param string $node Node identifier
     * @return array Node information
     * @throws GSDException On server errors
     */
    public function getNodeInfo(string $node = 'default'): array
    {
        $response = $this->sendCommand('get_node_info', [
            'node' => $node
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [];
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error getting node info');
        }

        return [];
    }

    /**
     * Get information about a site
     *
     * @param string $site Site identifier
     * @return array Site information
     * @throws GSDException On server errors
     */
    public function getSiteInfo(string $site = 'default'): array
    {
        $response = $this->sendCommand('get_site_info', [
            'site' => $site
        ]);

        if ($response && isset($response['status']) && $response['status'] === 'ok') {
            return $response['result'] ?? [];
        }

        if ($response && isset($response['status']) && $response['status'] === 'error') {
            throw new GSDException($response['error'] ?? 'Unknown error getting site info');
        }

        return [];
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
                    'batch_size' => $this->config['batch_size'],
                    'max_idle_time' => $this->config['max_idle_time'],
                    'trim_threshold' => $this->config['trim_threshold'],
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
     * Disable consumer group approach and fall back to script-based polling
     *
     * @return void
     */
    public function disableConsumerGroups(): void
    {
        $this->usingConsumerGroups = false;
        $this->debug("Consumer group approach disabled, using script-based polling");
    }

    /**
     * Enable native RESP3 mode (bypass encoding/decoding)
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

    /**
     * Send raw RESP3 message directly to the stream
     *
     * @deprecated Use KeyBasedClientLuaEnabled::executeBatch() instead.
     *             This method uses legacy stream-based communication which has higher
     *             overhead than the key-based architecture. Will be removed in v3.0.
     * @see KeyBasedClientLuaEnabled::executeBatch() Preferred method for sending commands
     *
     * @param array $fields Raw fields to send
     * @param string|null $requestId Optional request ID for tracking
     * @return string Message ID
     * @throws GSDException If consumer handler not initialized
     */
    public function sendRawMessage(array $fields, ?string $requestId = null): string
    {
        if (!$this->consumerHandler) {
            throw new GSDException("Consumer handler not initialized");
        }

        return $this->consumerHandler->sendRawMessage($fields, $requestId);
    }

    /**
     * Send a command with raw RESP3 fields
     *
     * @deprecated Use KeyBasedClientLuaEnabled::executeBatch() instead.
     *             This method uses legacy stream-based communication. The key-based
     *             architecture with XADD to per-environment streams is faster.
     *             Will be removed in v3.0.
     * @see KeyBasedClientLuaEnabled::executeBatch() Preferred method for sending commands
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @param array $additionalFields Additional raw fields to include
     * @return array Response from daemon
     */
    public function sendRawCommand(string $command, array $parameters = [], array $additionalFields = []): array
    {
        $requestId = uniqid('raw-', true);

        // Base fields for a command
        $fields = array_merge([
            't' => 'c',                     // Type: command
            'c' => $command,                // Command name
            'p' => json_encode($parameters, JSON_UNESCAPED_SLASHES), // Parameters
            'id' => $requestId,             // Request ID
            'ss' => $this->siteId,          // Source site
            'sn' => $this->nodeId,          // Source node
            'ts' => (string)microtime(true) // Timestamp
        ], $additionalFields);

        try {
            // Send the raw message
            $messageId = $this->sendRawMessage($fields, $requestId);
            $this->debug("Raw command sent: {$command} with message ID: {$messageId}");

            // Wait for response
            if ($this->usingConsumerGroups && $this->consumerHandler) {
                $response = $this->consumerHandler->waitForResponse($requestId, $this->timeout);
            } else {
                $response = $this->waitForScriptResponse($requestId);
            }

            return $response ?: ['success' => false, 'error' => 'No response received'];
        } catch (\Exception $e) {
            $this->debug("Raw command error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Store content with optional minification and TTL
     *
     * @param string $key Content key
     * @param string $content Content to store
     * @param string $contentType Content type (text/html, text/css, application/javascript)
     * @param bool $minify Whether to minify the content
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

        // Check daemon response format: {status: "ok", result: {stored: true, ...}}
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

        // Handle error response
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
        $response = $this->sendCommand('content_retrieve', [
            'key' => $key
        ]);

        // Check daemon response format: {status: "ok", result: {content: "...", key: "...", ...}}
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

        // Handle error response
        return [
            'success' => false,
            'error' => $response['error'] ?? 'Content not found or retrieval error'
        ];
    }

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

        // Only add variables if not empty (daemon expects HashMap, not array)
        if (!empty($variables)) {
            // Convert to object to ensure JSON object not array
            $parameters['variables'] = (object)$variables;
        }

        if ($ttl !== null) {
            $parameters['ttl'] = $ttl;
        }

        $response = $this->sendCommand('template_fragment', $parameters);

        // Parse batched response format: {status: "ok", messages: [["r", "0", "{json}", "0"]]}
        if (isset($response['status']) && $response['status'] === 'ok' && isset($response['messages'])) {
            // Get first message tuple
            if (!empty($response['messages']) && is_array($response['messages'][0])) {
                $message = $response['messages'][0];

                // Parse JSON result from index 2
                if (isset($message[2])) {
                    $result = json_decode($message[2], true);

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

        // Handle error response
        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error storing template'
        ];
    }

    /**
     * Create an asset bundle from multiple assets
     *
     * @param string $bundleId Bundle identifier
     * @param array $assets Array of asset identifiers
     * @param string $bundleType Bundle type (css, js, mixed)
     * @param bool $minify Whether to minify the bundle
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

        // Check daemon response format: {status: "ok", result: {bundled: true, bundle_id: "...", ...}}
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

        // Handle error response
        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error creating bundle'
        ];
    }

    //=====================================================================
    // HEALTH STREAM INTEGRATION
    //=====================================================================

    /**
     * Get or create the health stream writer
     *
     * The health stream writer publishes load metrics directly to the health stream,
     * which the GSD daemon reads to provide load-aware service discovery.
     *
     * @return \gCore\GSD\Health\HealthStreamWriter Health stream writer instance
     */
    public function getHealthWriter(): \gCore\GSD\Health\HealthStreamWriter
    {
        if ($this->healthWriter === null) {
            $this->healthWriter = new \gCore\GSD\Health\HealthStreamWriter(
                $this->storage,
                $this->siteId,
                $this->nodeId,
                [
                    'stream_prefix' => $this->config['stream_prefix'],
                    'debug' => $this->config['debug']
                ]
            );
        }

        return $this->healthWriter;
    }

    /**
     * Send health update for a service
     *
     * This publishes health metrics to the health stream for load-aware discovery.
     * The GSD daemon reads these metrics and uses them to select optimal services.
     *
     * Usage:
     * ```php
     * $client->sendHealthUpdate([
     *     'service_id' => 'api-service',
     *     'load_factor' => 0.45,
     *     'cpu_usage' => 0.32,
     *     'memory_usage' => 0.18,
     *     'active_requests' => 12,
     *     'avg_latency_ms' => 23,
     *     'error_rate' => 0.002
     * ]);
     * ```
     *
     * @param array $metricsData Health metrics data
     * @return string Message ID from XADD
     * @throws \InvalidArgumentException If metrics are invalid
     * @throws \gCore\GSD\Exception\StorageException If storage operation fails
     */
    public function sendHealthUpdate(array $metricsData): string
    {
        $metrics = \gCore\GSD\Health\HealthMetrics::fromArray($metricsData);
        return $this->getHealthWriter()->publishMetrics($metrics);
    }

    /**
     * Send health updates for multiple services in a batch
     *
     * @param array $metricsArray Array of metrics data arrays
     * @return array Array of message IDs indexed by service ID
     * @throws \gCore\GSD\Exception\StorageException If storage operation fails
     */
    public function sendHealthUpdateBatch(array $metricsArray): array
    {
        $metricsObjects = [];

        foreach ($metricsArray as $metricsData) {
            $metricsObjects[] = \gCore\GSD\Health\HealthMetrics::fromArray($metricsData);
        }

        return $this->getHealthWriter()->publishBatch($metricsObjects);
    }

    /**
     * Start periodic health heartbeat for a service
     *
     * This starts a background heartbeat that periodically publishes health metrics.
     * You must call tickHealthHeartbeats() in your application's main loop to
     * actually send the heartbeats.
     *
     * Usage:
     * ```php
     * $client->startHealthHeartbeat(
     *     'api-service',
     *     1000,  // 1 second interval
     *     function() {
     *         return \gCore\GSD\Health\HealthMetrics::captureCurrentMetrics('api-service', 0.45);
     *     }
     * );
     *
     * // In your main loop:
     * while (true) {
     *     $client->tickHealthHeartbeats();
     *     usleep(100000); // 100ms sleep
     * }
     * ```
     *
     * @param string $serviceId Service identifier
     * @param int $intervalMs Interval in milliseconds (default: 1000ms = 1Hz)
     * @param callable $metricsProvider Callback that returns HealthMetrics
     * @param int|null $maxIterations Maximum iterations (null = infinite)
     * @return void
     * @throws \InvalidArgumentException If parameters are invalid
     */
    public function startHealthHeartbeat(
        string $serviceId,
        int $intervalMs = 1000,
        callable $metricsProvider,
        ?int $maxIterations = null
    ): void {
        $this->getHealthWriter()->startHeartbeat($serviceId, $intervalMs, $metricsProvider, $maxIterations);
    }

    /**
     * Process all active health heartbeats
     *
     * Call this method in your application's main loop or tick handler
     * to actually send heartbeat messages.
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
     * @return bool True if heartbeat was stopped, false if it wasn't running
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
     * @return array|null Heartbeat status or null if not running
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
     * This is typically handled automatically by the daemon, but can be
     * called manually if needed.
     *
     * @return bool True if group was created or already exists
     * @throws \gCore\GSD\Exception\StorageException If operation fails
     */
    public function initializeHealthStream(): bool
    {
        return $this->getHealthWriter()->initializeConsumerGroup();
    }

    //=====================================================================
    // FORMAT SYSTEM INTEGRATION
    //=====================================================================

    /**
     * Get or create the format manager
     *
     * The format manager handles custom message format registration, detection,
     * and conversion using the GSD daemon's format system (Phase 1-8 complete).
     *
     * @return \gCore\GSD\Format\FormatManager Format manager instance
     */
    public function getFormatManager(): \gCore\GSD\Format\FormatManager
    {
        if ($this->formatManager === null) {
            $this->formatManager = new \gCore\GSD\Format\FormatManager(
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
     * Registers a new format definition with JSONSchema validation (draft-7).
     * Formats are persisted in ValKey and cached locally for performance.
     *
     * Usage:
     * ```php
     * $client->registerFormat([
     *     'name' => 'custom_api_v1',
     *     'schema' => [
     *         'type' => 'object',
     *         'properties' => [
     *             'id' => ['type' => 'string'],
     *             'action' => ['type' => 'string'],
     *             'data' => ['type' => 'object']
     *         ],
     *         'required' => ['id', 'action']
     *     ],
     *     'patterns' => [
     *         '/^{.*"action":/',
     *         '/^{.*"custom_api"/'
     *     ],
     *     'description' => 'Custom API message format v1',
     *     'version' => '1.0.0'
     * ]);
     * ```
     *
     * @param array $definition Format definition with name, schema, and patterns
     * @return bool True on success
     * @throws \gCore\GSD\Exception\GSDException On validation failure or storage error
     */
    public function registerFormat(array $definition): bool
    {
        return $this->getFormatManager()->registerFormat($definition);
    }

    /**
     * Detect the format of a message
     *
     * Uses pattern matching with confidence scoring to identify the format
     * of a given message. Returns the format name with highest confidence.
     *
     * Usage:
     * ```php
     * $message = '{"id":"123","action":"create","data":{}}';
     * $format = $client->detectMessageFormat($message);
     * // Returns: "custom_api_v1" or null if unknown
     * ```
     *
     * @param string $message Message to analyze
     * @return string|null Format name, or null if no match found
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
     * Performs bidirectional transformation with field mapping and validation.
     * Both input and output are validated against their respective schemas.
     *
     * Usage:
     * ```php
     * $message = '{"id":"123","action":"create"}';
     * $converted = $client->convertMessageFormat($message, 'custom_api_v1', 'json_v2_enhanced');
     * // Returns transformed message in json_v2_enhanced format
     * ```
     *
     * @param string $message Message to convert
     * @param string $fromFormat Source format name
     * @param string $toFormat Target format name
     * @return string Converted message
     * @throws \gCore\GSD\Exception\GSDException On conversion failure or validation error
     */
    public function convertMessageFormat(string $message, string $fromFormat, string $toFormat): string
    {
        return $this->getFormatManager()->convertFormat($message, $fromFormat, $toFormat);
    }

    /**
     * List all registered formats
     *
     * Returns metadata for all registered formats including schema, patterns,
     * and creation timestamp.
     *
     * Usage:
     * ```php
     * $formats = $client->listRegisteredFormats();
     * foreach ($formats as $format) {
     *     echo "Format: {$format['name']} (v{$format['version']})\n";
     * }
     * ```
     *
     * @return array Array of format definitions
     * @throws \gCore\GSD\Exception\GSDException On retrieval failure
     */
    public function listRegisteredFormats(): array
    {
        return $this->getFormatManager()->listFormats();
    }

    /**
     * Get JSONSchema for a specific format
     *
     * Retrieves the schema definition for validation purposes.
     * Results are cached indefinitely (schemas rarely change).
     *
     * @param string $formatName Format name
     * @return array|null Schema definition, or null if not found
     */
    public function getFormatSchema(string $formatName): ?array
    {
        return $this->getFormatManager()->getSchema($formatName);
    }

    /**
     * Clear format cache
     *
     * Forces reload of formats from ValKey on next access.
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
     * Returns performance and usage statistics for monitoring.
     *
     * @return array Statistics data including hits, misses, conversions
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

    //=====================================================================
    // BROADCAST STREAM INTEGRATION
    //=====================================================================

    /**
     * Get or create the broadcast reader
     *
     * The broadcast reader provides access to the global broadcast stream for
     * 1:many pub-sub messaging. Unlike unified/health streams, broadcast uses
     * XREAD (no consumer groups) where each reader tracks its own position.
     *
     * Common use cases:
     * - Topology update notifications
     * - Service registration/deregistration events
     * - Configuration change broadcasts
     * - Health threshold alerts
     *
     * @return \gCore\GSD\Broadcast\BroadcastReader Broadcast reader instance
     */
    public function getBroadcastReader(): \gCore\GSD\Broadcast\BroadcastReader
    {
        if ($this->broadcastReader === null) {
            $this->broadcastReader = new \gCore\GSD\Broadcast\BroadcastReader(
                $this->storage,
                $this->siteId,
                $this->nodeId,
                [
                    'stream_prefix' => $this->config['stream_prefix'],
                    'debug' => $this->config['debug'],
                    'use_valkey_functions' => true,
                    'last_seen_id' => '$' // Start from new messages
                ]
            );
        }

        return $this->broadcastReader;
    }

    /**
     * Read broadcast messages from global stream
     *
     * Reads messages starting from the last-seen position. The reader automatically
     * tracks position across calls. Each reader instance maintains independent position.
     *
     * Usage:
     * ```php
     * // Read new messages (up to 100)
     * $messages = $client->readBroadcastMessages();
     * foreach ($messages as $msg) {
     *     echo $msg->type . ": " . $msg->getMessage() . "\n";
     * }
     *
     * // Read with type filter
     * $topologyUpdates = $client->readBroadcastMessages(50, 0, 'topology_update');
     * ```
     *
     * @param int $count Maximum messages to read (default: 100)
     * @param int $blockMs Block timeout in milliseconds (0 = non-blocking, default: 0)
     * @param string|null $typeFilter Filter by message type (null = all types)
     * @return \gCore\GSD\Broadcast\BroadcastMessage[] Array of broadcast messages
     * @throws \gCore\GSD\Exception\StorageException If read operation fails
     */
    public function readBroadcastMessages(int $count = 100, int $blockMs = 0, ?string $typeFilter = null): array
    {
        return $this->getBroadcastReader()->read($count, $blockMs, $typeFilter);
    }

    /**
     * Write broadcast message to global stream
     *
     * Publishes a message that all readers will receive. Use for announcements,
     * topology changes, or any event that should be broadcast to all nodes.
     *
     * Usage:
     * ```php
     * // Broadcast topology update
     * $client->writeBroadcastMessage('topology_update', [
     *     'msg' => 'Service api-v2 registered',
     *     'service_id' => 'api-v2',
     *     'action' => 'registered'
     * ]);
     *
     * // Broadcast configuration change
     * $client->writeBroadcastMessage('config_changed', [
     *     'config_key' => 'timeout',
     *     'old_value' => 10,
     *     'new_value' => 30
     * ]);
     * ```
     *
     * @param string $messageType Message type identifier
     * @param array $fields Additional message fields (auto-adds site_id and timestamp)
     * @return string Message ID from XADD
     * @throws \gCore\GSD\Exception\StorageException If write operation fails
     */
    public function writeBroadcastMessage(string $messageType, array $fields = []): string
    {
        return $this->getBroadcastReader()->write($messageType, $fields);
    }

    /**
     * Trim broadcast stream by retention time
     *
     * Removes old messages to keep stream size manageable. Uses MAXLEN with
     * approximate trim for efficiency.
     *
     * This is typically called periodically by a background task or after
     * significant broadcast activity.
     *
     * @param int $retentionSeconds Keep messages newer than this (default: 300 = 5 minutes)
     * @return int Number of messages trimmed
     * @throws \gCore\GSD\Exception\StorageException If trim operation fails
     */
    public function trimBroadcastStream(int $retentionSeconds = 300): int
    {
        return $this->getBroadcastReader()->trim($retentionSeconds);
    }

    /**
     * Get broadcast stream metadata
     *
     * Returns information about the broadcast stream including length and
     * first/last message IDs.
     *
     * @return array Stream metadata
     * @throws \gCore\GSD\Exception\StorageException If operation fails
     */
    public function getBroadcastStreamInfo(): array
    {
        return $this->getBroadcastReader()->getStreamInfo();
    }

    /**
     * Reset broadcast reader position to beginning
     *
     * Next read will return all messages from the start of the stream.
     * Useful for replaying message history.
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
     * Next read will only return messages added after this call.
     * This is the default behavior for new readers.
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
     * Returns the message ID that the reader will start from on next read.
     *
     * @return string Current position (message ID)
     */
    public function getBroadcastPosition(): string
    {
        return $this->getBroadcastReader()->getPosition();
    }

    /**
     * Get broadcast reader statistics
     *
     * Returns performance and usage statistics for the broadcast reader.
     *
     * @return array Statistics including messages read, position, stream name
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

    //=====================================================================
    // TEMPLATE SYSTEM INTEGRATION
    //=====================================================================

    /**
     * @var \gCore\GSD\Template\TemplateManager|null Template manager
     */
    protected $templateManager = null;

    /**
     * Get or create the template manager
     *
     * The template manager handles Tera-powered template rendering with
     * 8D geometric capability discovery and dependency DAG management.
     *
     * @return \gCore\GSD\Template\TemplateManager Template manager instance
     */
    public function getTemplateManager(): \gCore\GSD\Template\TemplateManager
    {
        if ($this->templateManager === null) {
            $this->templateManager = new \gCore\GSD\Template\TemplateManager(
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

    /**
     * Register a template with the daemon
     *
     * Usage:
     * ```php
     * $client->registerTemplate('user-card',
     *     '<div class="card"><h2>{{ name }}</h2><p>{{ bio }}</p></div>'
     * );
     * ```
     *
     * @param string $templateId Unique template identifier
     * @param string $content Template content (Tera syntax)
     * @param array $config Optional configuration
     * @return array Registration result
     * @throws \gCore\GSD\Exception\GSDException
     */
    public function registerTemplate(string $templateId, string $content, array $config = []): array
    {
        return $this->getTemplateManager()->registerTemplate($templateId, $content, $config);
    }

    /**
     * Render a template with variables
     *
     * Usage:
     * ```php
     * $html = $client->renderTemplate('user-card', [
     *     'name' => 'Alice',
     *     'bio' => 'Software Engineer'
     * ]);
     * ```
     *
     * @param string $templateId Template identifier
     * @param array $variables Template variables
     * @param array $config Render configuration
     * @return string Rendered HTML
     * @throws \gCore\GSD\Exception\GSDException
     */
    public function renderTemplate(string $templateId, array $variables = [], array $config = []): string
    {
        return $this->getTemplateManager()->renderTemplate($templateId, $variables, $config);
    }

    /**
     * Delete a template
     *
     * @param string $templateId Template identifier
     * @param array $config Delete configuration
     * @return bool Success
     * @throws \gCore\GSD\Exception\GSDException
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
     * @throws \gCore\GSD\Exception\GSDException
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
     * @throws \gCore\GSD\Exception\GSDException
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
     * @throws \gCore\GSD\Exception\GSDException
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
     * @throws \gCore\GSD\Exception\GSDException
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
     * @throws \gCore\GSD\Exception\GSDException
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
     * @throws \gCore\GSD\Exception\GSDException
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
     * @throws \gCore\GSD\Exception\GSDException
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
     * @throws \gCore\GSD\Exception\GSDException
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

    //=====================================================================
    // CONFIGURATION COMMANDS
    //=====================================================================

    /**
     * Get daemon configuration value
     *
     * Retrieves a configuration value from the running daemon.
     *
     * Valid keys: threads, dimensions, site_id, node_id, debug, log_level
     *
     * Usage:
     * ```php
     * $dimensions = $client->configGet('dimensions');
     * $logLevel = $client->configGet('log_level');
     * ```
     *
     * @param string $key Configuration key
     * @return mixed Configuration value
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function configGet(string $key)
    {
        $response = $this->executeCommand('config_get', ['key' => $key]);
        return $response['value'] ?? null;
    }

    /**
     * Set daemon configuration value
     *
     * Updates a configuration value in the running daemon.
     * Note: Some config values may require daemon restart to take effect.
     *
     * Usage:
     * ```php
     * $client->configSet('log_level', 'debug');
     * $client->configSet('debug', true);
     * ```
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return bool Success
     * @throws \gCore\GSD\Exception\GSDException On error
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
     * List all daemon configuration keys and values
     *
     * Retrieves the full configuration map from the daemon including
     * runtime information.
     *
     * Usage:
     * ```php
     * $config = $client->configList();
     * echo "Dimensions: " . $config['configuration']['dimensions'] . "\n";
     * echo "Uptime: " . $config['runtime_info']['uptime'] . "s\n";
     * ```
     *
     * @return array Configuration map with 'configuration' and 'runtime_info' keys
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function configList(): array
    {
        return $this->executeCommand('config_list', []) ?? [];
    }

    //=====================================================================
    // DIAGNOSTIC COMMANDS
    //=====================================================================

    /**
     * Get comprehensive debug information
     *
     * Returns detailed system debug information for troubleshooting.
     *
     * @return array Debug information including topology, system, uptime
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function getDebugInfo(): array
    {
        return $this->executeCommand('debug_info', []) ?? [];
    }

    /**
     * Get daemon memory statistics
     *
     * Returns memory usage information for the daemon process.
     *
     * @return array Memory stats (process_memory_kb, heap_estimate, etc.)
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function getMemoryStats(): array
    {
        return $this->executeCommand('memory_stats', []) ?? [];
    }

    /**
     * Get daemon thread status
     *
     * Returns thread pool status and utilization information.
     *
     * @return array Thread status (available_cores, active_threads, max_threads, status)
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function getThreadStatus(): array
    {
        return $this->executeCommand('thread_status', []) ?? [];
    }

    /**
     * Get ValKey connection status
     *
     * Returns ValKey connection pool status and health metrics.
     *
     * @return array Connection status (valkey_connected, pool_size, active_connections, etc.)
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function getConnectionStatus(): array
    {
        return $this->executeCommand('connection_status', []) ?? [];
    }

    /**
     * Get daemon performance metrics
     *
     * Returns throughput statistics and performance metrics.
     *
     * @return array Performance metrics (commands_per_second, avg_response_time_ms, etc.)
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function getPerformanceMetrics(): array
    {
        return $this->executeCommand('performance_metrics', []) ?? [];
    }

    /**
     * Get security status
     *
     * Returns security configuration status and recommendations.
     *
     * @return array Security status (valkey_auth, tls, access_control, etc.)
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function getSecurityStatus(): array
    {
        return $this->executeCommand('security_status', []) ?? [];
    }

    /**
     * Get topology status
     *
     * Returns geometric topology information and service count.
     *
     * @return array Topology status
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function getTopologyStatus(): array
    {
        return $this->executeCommand('topology_status', []) ?? [];
    }

    /**
     * Get full topology including capability dimensions
     *
     * Reads the topology directly from ValKey ({default}:gsd:topology).
     * Results are cached in memory for the duration of the request.
     *
     * @return array Full topology with capability_dimensions, services, etc.
     */
    public function getTopology(): array
    {
        // Cache topology for this request
        static $cachedTopology = null;

        if ($cachedTopology !== null) {
            return $cachedTopology;
        }

        try {
            // Read directly from ValKey - topology is at {default}:gsd:topology
            $topologyJson = $this->storage->get('{default}:gsd:topology');

            if ($topologyJson) {
                $cachedTopology = json_decode($topologyJson, true) ?? [];
                return $cachedTopology;
            }
        } catch (\Exception $e) {
            // Log but don't fail - return empty array
            error_log('[GSD-Client] Failed to read topology: ' . $e->getMessage());
        }

        $cachedTopology = [];
        return $cachedTopology;
    }

    //=====================================================================
    // ADDITIONAL SYSTEM COMMANDS
    //=====================================================================

    /**
     * Health check endpoint for monitoring
     *
     * Verifies Redis/ValKey connectivity and daemon health.
     *
     * @return array Health status with checks and timestamp
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function health(): array
    {
        return $this->executeCommand('health', []) ?? [];
    }

    /**
     * Get daemon version information
     *
     * Returns version, build date, and Rust compiler version.
     *
     * @return array Version information
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function version(): array
    {
        return $this->executeCommand('version', []) ?? [];
    }

    /**
     * Echo command for testing
     *
     * Returns the provided message or all parameters.
     *
     * @param mixed $message Message to echo back
     * @return mixed|DeferredResult Echoed message, or DeferredResult if queued
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function echo($message = null)
    {
        $params = $message !== null ? ['message' => $message] : [];

        // Use queue if enabled
        if ($this->queue !== null) {
            return $this->queue->enqueue('echo', $params);
        }

        // Direct execution (original behavior)
        return $this->executeCommand('echo', $params);
    }

    /**
     * Get daemon status and system information
     *
     * Retrieves daemon status with optional detailed output including:
     * - version: Daemon version string
     * - uptime: Daemon uptime in seconds
     * - timestamp: Current timestamp in milliseconds
     * - connection_pool: Connection pool statistics (full detail only)
     * - supported_commands: List of available commands (full detail only)
     * - valkey_functions: ValKey function information (full detail only)
     * - redis_info: ValKey/Redis server info (full detail only)
     *
     * @param string $detail Level of detail - "basic" or "full" (default: "basic")
     * @return array|DeferredResult Daemon status information, or DeferredResult if queued
     * @throws \gCore\GSD\Exception\GSDException On error
     */
    public function status(string $detail = 'basic')
    {
        // Use queue if enabled
        if ($this->queue !== null) {
            return $this->queue->enqueue('status', ['detail' => $detail]);
        }

        // Direct execution (original behavior)
        return $this->executeCommand('status', ['detail' => $detail]) ?? [];
    }

    //=====================================================================
    // STREAM MANAGEMENT COMMANDS
    //=====================================================================

    /**
     * Get consumer information for a stream and group
     *
     * Uses XINFO CONSUMERS to get consumer details.
     *
     * @param string $stream Stream key
     * @param string $group Consumer group name
     * @return array Array of consumer information
     * @throws \gCore\GSD\Exception\GSDException On error
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
     * Uses XPENDING to get pending message information.
     *
     * @param string $stream Stream key
     * @param string $group Consumer group name
     * @param int $count Number of detailed entries (0 = summary only)
     * @return array Pending messages summary and optional details
     * @throws \gCore\GSD\Exception\GSDException On error
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
     * Discover services using range query operators (Phase 1 - Dynamic Dimensions)
     *
     * Enables flexible service discovery with comparison operators:
     * - 'eq': Equal to (within 0.005 tolerance)
     * - 'neq': Not equal to
     * - 'gt': Greater than
     * - 'gte': Greater than or equal
     * - 'lt': Less than
     * - 'lte': Less than or equal
     *
     * @param array $criteria Associative array of dimension => constraint
     *                         Constraint can be:
     *                         - Scalar value (treated as 'eq')
     *                         - Array with operator: ['eq' => 0.10], ['gte' => 0.20, 'lte' => 0.99]
     *                         - For static dimensions: ['topology_tier' => ['eq' => 0.10]]
     *                         - For dynamic dimensions: ['dimensions' => ['profit_per_hour' => ['gt' => 100]]]
     * @return array Array of service IDs matching all criteria
     * @throws GSDException On communication failure
     *
     * @example
     * // Find infrastructure services (tier 0.10)
     * $services = $client->discoverRange(['topology_tier' => ['eq' => 0.10]]);
     *
     * @example
     * // Find application services (tier 0.70-0.79)
     * $services = $client->discoverRange([
     *     'topology_tier' => ['gte' => 0.70, 'lte' => 0.79]
     * ]);
     *
     * @example
     * // Find profitable services (dynamic dimension)
     * $services = $client->discoverRange([
     *     'dimensions' => ['profit_per_hour' => ['gt' => 100]]
     * ]);
     */
    public function discoverRange(array $criteria): array
    {
        $requirements = [];

        foreach ($criteria as $dimension => $constraint) {
            if ($dimension === 'dimensions') {
                // Dynamic dimensions
                foreach ($constraint as $dynDim => $dynConstraint) {
                    $dimIndex = $this->getDimensionIndex($dynDim);
                    // Convert to string key for JSON serialization
                    $requirements[(string)$dimIndex] = $this->normalizeConstraint($dynConstraint);
                }
            } else {
                // Static dimensions
                $dimIndex = $this->getDimensionIndex($dimension);
                // Convert to string key for JSON serialization
                $requirements[(string)$dimIndex] = $this->normalizeConstraint($constraint);
            }
        }

        // Send command to GSD daemon
        $result = $this->executeCommand('geometric_discover_range', [
            'requirements' => $requirements
        ]);

        return $result ?? [];
    }

    /**
     * Normalize constraint to operator format
     *
     * Converts various constraint formats to standard operator array:
     * - Scalar value => ['eq' => value]
     * - Array with operators => passthrough
     *
     * @param mixed $constraint Constraint value or operator array
     * @return array Normalized operator array
     */
    protected function normalizeConstraint($constraint): array
    {
        if (!is_array($constraint)) {
            // Scalar value - treat as equality
            return ['eq' => $constraint];
        }

        // Already in operator format
        return $constraint;
    }

    /**
     * Get dimension index for a dimension name
     *
     * Maps dimension names to their index in the 9D topology space.
     * Indices 0-8 are static dimensions (service characteristics).
     * Indices 9+ are dynamic dimensions (runtime metrics, registered on-demand).
     *
     * @param string $name Dimension name
     * @return int Dimension index
     * @throws \InvalidArgumentException If dimension name is unknown
     */
    protected function getDimensionIndex(string $name): int
    {
        // Static dimensions (0-8) - Must match GSD daemon's registered dimensions
        // See: /opt/GSD/daemon/src/daemon.rs lines 117-125
        $static = [
            'security' => 0,        // Security features (CSP, input sanitization, nonces)
            'auth' => 1,            // Authentication integration
            'crypto' => 2,          // Cryptographic operations (hashing, tokens)
            'rules' => 3,           // Input validation, business rules
            'cache' => 4,           // Caching capabilities (ValKey)
            'storage' => 5,         // Persistence (DB + ValKey)
            'errors' => 6,          // Error handling
            'logging' => 7,         // Debug logging, audit trails
            'topology_tier' => 8    // Service tier (1.0 = service, 0.5 = tool, 0.1 = infra)
        ];

        if (isset($static[$name])) {
            return $static[$name];
        }

        // Dynamic dimensions (9+) - Query topology registry from GSD daemon
        // This enables runtime dimension registration without client updates
        $topology = $this->getTopology();
        if (isset($topology['capability_dimensions'][$name])) {
            return (int)$topology['capability_dimensions'][$name];
        }

        throw new \InvalidArgumentException("Unknown dimension: {$name}. Not in static dimensions (0-8) or topology registry.");
    }
}
