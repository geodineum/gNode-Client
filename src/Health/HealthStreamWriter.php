<?php
declare(strict_types=1);

namespace gCore\gNode\Health;

use gCore\gNode\Storage\StorageInterface;
use gCore\gNode\Exception\StorageException;

/**
 * HealthStreamWriter - High-frequency health metrics publisher
 *
 * This class writes health metrics directly to the dedicated health stream
 * using compressed field format for optimal bandwidth efficiency.
 *
 * Stream: {site_id}:gnode:health:{node_id}
 * Consumer Group: gnode-daemon (daemon reads from this stream)
 * Throughput: Supports >10,000 msg/sec
 *
 * Usage:
 * ```php
 * $writer = new HealthStreamWriter($storage, 'production', 'node1');
 * $metrics = HealthMetrics::captureCurrentMetrics('api-service', 0.45);
 * $writer->publishMetrics($metrics);
 * ```
 *
 * @package gCore\gNode\Health
 */
class HealthStreamWriter
{
    /** @var StorageInterface Storage interface for ValKey operations */
    private $storage;

    /** @var string Site identifier */
    private $siteId;

    /** @var string Node identifier */
    private $nodeId;

    /** @var string DTAP environment */
    private $environment;

    /** @var string Health stream key */
    private $healthStream;

    /** @var bool Debug mode */
    private $debug;

    /** @var array Heartbeat state */
    private $heartbeatState = [];

    /** @var int Message counter for diagnostics */
    private $messageCount = 0;

    /** @var int Total bytes written (approximation) */
    private $bytesWritten = 0;

    /** @var float Last publish timestamp */
    private $lastPublishTime = 0.0;

    /**
     * Constructor
     *
     * @param StorageInterface $storage Storage interface
     * @param string $siteId Site identifier (default: 'default')
     * @param string $nodeId Node identifier (default: 'default')
     * @param array $config Configuration options (should include 'environment' for DTAP isolation)
     */
    public function __construct(
        StorageInterface $storage,
        string $siteId = 'default',
        string $nodeId = 'default',
        array $config = []
    ) {
        $this->storage = $storage;
        $this->siteId = $siteId;
        $this->nodeId = $nodeId;
        $this->environment = $config['environment'] ?? 'production';  // NEW: DTAP environment
        $this->debug = $config['debug'] ?? false;

        // Health stream naming convention: {site_id}:gnode:health:{environment}
        // Using braces for proper hash distribution in ValKey
        // Pattern matches daemon expectation: {site_id}:gnode:health:{environment}
        $streamPrefix = $config['stream_prefix'] ?? 'gnode';
        $this->healthStream = sprintf(
            '{%s}:%s:health:%s',
            $this->siteId,
            $streamPrefix,
            $this->environment  // FIX: Was nodeId - now uses environment for DTAP isolation
        );

        $this->debug("HealthStreamWriter initialized for stream: {$this->healthStream}");
    }

    /**
     * Publish health metrics to the health stream
     *
     * This method writes metrics directly to the health stream using XADD.
     * It does NOT go through the daemon - the daemon READS from this stream.
     *
     * @param HealthMetrics $metrics Health metrics to publish
     * @return string Message ID from XADD
     * @throws StorageException If storage operation fails
     * @throws \InvalidArgumentException If metrics are invalid
     * @api
     */
    public function publishMetrics(HealthMetrics $metrics): string
    {
        // Validate metrics
        $errors = $metrics->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Invalid metrics: ' . implode(', ', $errors));
        }

        if (!$this->storage->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }

        // Convert to compressed format
        $fields = $metrics->toCompressedFormat();

        // Calculate message size for diagnostics
        $messageSize = strlen(json_encode($fields));

        $startTime = microtime(true);

        try {
            // Write directly to health stream using XADD
            // XADD {stream} * {field} {value} {field} {value} ...
            $messageId = $this->storage->xAdd($this->healthStream, '*', $fields);

            $duration = microtime(true) - $startTime;

            // Update statistics
            $this->messageCount++;
            $this->bytesWritten += $messageSize;
            $this->lastPublishTime = microtime(true);

            $this->debug(sprintf(
                "Published health metrics for service '%s' (msg_id: %s, load: %.2f, score: %.2f, size: %d bytes, duration: %.2fms)",
                $metrics->serviceId,
                $messageId,
                $metrics->loadFactor,
                $metrics->calculateScore(),
                $messageSize,
                $duration * 1000
            ));

            return $messageId;
        } catch (\Exception $e) {
            $this->debug("Failed to publish health metrics: " . $e->getMessage());
            throw new StorageException("Failed to publish health metrics: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Publish multiple metrics in a single operation (batch write)
     *
     * This is more efficient than calling publishMetrics() multiple times
     * when publishing metrics for multiple services.
     *
     * @param HealthMetrics[] $metricsArray Array of health metrics
     * @return array Array of message IDs indexed by service ID
     * @throws StorageException If storage operation fails
     */
    public function publishBatch(array $metricsArray): array
    {
        if (empty($metricsArray)) {
            return [];
        }

        $messageIds = [];
        $errors = [];

        foreach ($metricsArray as $metrics) {
            if (!$metrics instanceof HealthMetrics) {
                $errors[] = 'Invalid metrics object in batch';
                continue;
            }

            try {
                $messageId = $this->publishMetrics($metrics);
                $messageIds[$metrics->serviceId] = $messageId;
            } catch (\Exception $e) {
                $errors[] = sprintf(
                    "Failed to publish metrics for service '%s': %s",
                    $metrics->serviceId,
                    $e->getMessage()
                );
            }
        }

        if (!empty($errors) && empty($messageIds)) {
            throw new StorageException('Batch publish failed: ' . implode('; ', $errors));
        }

        if (!empty($errors)) {
            $this->debug('Batch publish completed with errors: ' . implode('; ', $errors));
        }

        return $messageIds;
    }

    /**
     * Start periodic heartbeat for a service
     *
     * This spawns a background task that periodically publishes health metrics.
     * The metrics provider callback is called at each interval to get current metrics.
     *
     * Note: This uses a simple timer-based approach. For production use,
     * consider using a proper job scheduler or event loop.
     *
     * @param string $serviceId Service identifier
     * @param int $intervalMs Interval in milliseconds (default: 1000ms = 1Hz)
     * @param callable $metricsProvider Callback that returns HealthMetrics
     * @param int|null $maxIterations Maximum iterations (null = infinite)
     * @return void
     * @throws \InvalidArgumentException If parameters are invalid
     */
    public function startHeartbeat(
        string $serviceId,
        int $intervalMs = 1000,
        callable $metricsProvider,
        ?int $maxIterations = null
    ): void {
        if (empty($serviceId)) {
            throw new \InvalidArgumentException('Service ID cannot be empty');
        }

        if ($intervalMs < 100) {
            throw new \InvalidArgumentException('Interval must be at least 100ms');
        }

        if (isset($this->heartbeatState[$serviceId])) {
            $this->debug("Heartbeat already running for service: {$serviceId}");
            return;
        }

        $this->heartbeatState[$serviceId] = [
            'interval_ms' => $intervalMs,
            'provider' => $metricsProvider,
            'started_at' => microtime(true),
            'iterations' => 0,
            'max_iterations' => $maxIterations,
            'errors' => 0
        ];

        $this->debug(sprintf(
            "Started heartbeat for service '%s' (interval: %dms, max_iterations: %s)",
            $serviceId,
            $intervalMs,
            $maxIterations === null ? 'infinite' : $maxIterations
        ));
    }

    /**
     * Process heartbeat iteration for a service
     *
     * This should be called periodically (e.g., from a tick handler or event loop).
     * It checks if it's time to send a heartbeat and publishes metrics if so.
     *
     * @param string $serviceId Service identifier
     * @return bool True if heartbeat was sent, false otherwise
     * @throws \Exception If metrics provider callback fails
     */
    public function tickHeartbeat(string $serviceId): bool
    {
        if (!isset($this->heartbeatState[$serviceId])) {
            return false;
        }

        $state = &$this->heartbeatState[$serviceId];
        $now = microtime(true);
        $intervalSeconds = $state['interval_ms'] / 1000.0;
        $lastTick = $state['last_tick'] ?? $state['started_at'];

        // Check if it's time for next heartbeat
        if ($now - $lastTick < $intervalSeconds) {
            return false;
        }

        // Check max iterations
        if ($state['max_iterations'] !== null && $state['iterations'] >= $state['max_iterations']) {
            $this->stopHeartbeat($serviceId);
            $this->debug("Heartbeat stopped for service '{$serviceId}' (max iterations reached)");
            return false;
        }

        try {
            // Call metrics provider
            $metrics = call_user_func($state['provider']);

            if (!$metrics instanceof HealthMetrics) {
                throw new \RuntimeException('Metrics provider must return HealthMetrics instance');
            }

            // Publish metrics
            $this->publishMetrics($metrics);

            // Update state
            $state['last_tick'] = $now;
            $state['iterations']++;

            return true;
        } catch (\Exception $e) {
            $state['errors']++;
            $this->debug("Heartbeat error for service '{$serviceId}': " . $e->getMessage());

            // Stop heartbeat if too many consecutive errors
            if ($state['errors'] >= 10) {
                $this->stopHeartbeat($serviceId);
                $this->debug("Heartbeat stopped for service '{$serviceId}' (too many errors)");
            }

            throw $e;
        }
    }

    /**
     * Process all active heartbeats
     *
     * This is a convenience method that calls tickHeartbeat() for all active services.
     * Use this in your application's main loop or tick handler.
     *
     * @return array Array of service IDs that sent heartbeats
     */
    public function tickAllHeartbeats(): array
    {
        $sent = [];

        foreach (array_keys($this->heartbeatState) as $serviceId) {
            try {
                if ($this->tickHeartbeat($serviceId)) {
                    $sent[] = $serviceId;
                }
            } catch (\Exception $e) {
                // Errors are logged in tickHeartbeat, continue with other services
                continue;
            }
        }

        return $sent;
    }

    /**
     * Stop heartbeat for a service
     *
     * @param string $serviceId Service identifier
     * @return bool True if heartbeat was stopped, false if it wasn't running
     */
    public function stopHeartbeat(string $serviceId): bool
    {
        if (!isset($this->heartbeatState[$serviceId])) {
            return false;
        }

        $state = $this->heartbeatState[$serviceId];
        unset($this->heartbeatState[$serviceId]);

        $this->debug(sprintf(
            "Stopped heartbeat for service '%s' (ran for %.2fs, %d iterations, %d errors)",
            $serviceId,
            microtime(true) - $state['started_at'],
            $state['iterations'],
            $state['errors']
        ));

        return true;
    }

    /**
     * Stop all active heartbeats
     *
     * @return int Number of heartbeats stopped
     */
    public function stopAllHeartbeats(): int
    {
        $count = count($this->heartbeatState);

        foreach (array_keys($this->heartbeatState) as $serviceId) {
            $this->stopHeartbeat($serviceId);
        }

        return $count;
    }

    /**
     * Get heartbeat status for a service
     *
     * @param string $serviceId Service identifier
     * @return array|null Heartbeat state or null if not running
     */
    public function getHeartbeatStatus(string $serviceId): ?array
    {
        if (!isset($this->heartbeatState[$serviceId])) {
            return null;
        }

        $state = $this->heartbeatState[$serviceId];
        $now = microtime(true);

        return [
            'service_id' => $serviceId,
            'running' => true,
            'interval_ms' => $state['interval_ms'],
            'started_at' => $state['started_at'],
            'uptime_seconds' => $now - $state['started_at'],
            'iterations' => $state['iterations'],
            'max_iterations' => $state['max_iterations'],
            'errors' => $state['errors'],
            'last_tick' => $state['last_tick'] ?? null,
            'next_tick_in' => isset($state['last_tick'])
                ? max(0, ($state['interval_ms'] / 1000.0) - ($now - $state['last_tick']))
                : 0
        ];
    }

    /**
     * Get status for all active heartbeats
     *
     * @return array Array of heartbeat statuses indexed by service ID
     */
    public function getAllHeartbeatStatuses(): array
    {
        $statuses = [];

        foreach (array_keys($this->heartbeatState) as $serviceId) {
            $statuses[$serviceId] = $this->getHeartbeatStatus($serviceId);
        }

        return $statuses;
    }

    /**
     * Get writer statistics
     *
     * @return array Statistics about published metrics
     */
    public function getStatistics(): array
    {
        $uptime = $this->lastPublishTime > 0
            ? microtime(true) - $this->lastPublishTime
            : 0;

        return [
            'health_stream' => $this->healthStream,
            'site_id' => $this->siteId,
            'node_id' => $this->nodeId,
            'messages_published' => $this->messageCount,
            'bytes_written' => $this->bytesWritten,
            'avg_message_size' => $this->messageCount > 0
                ? round($this->bytesWritten / $this->messageCount)
                : 0,
            'last_publish_time' => $this->lastPublishTime,
            'seconds_since_last_publish' => $uptime,
            'active_heartbeats' => count($this->heartbeatState),
            'heartbeat_services' => array_keys($this->heartbeatState)
        ];
    }

    /**
     * Initialize health stream consumer group for daemon
     *
     * This creates the consumer group that the daemon will use to read health updates.
     * The daemon automatically creates this on startup, but this method can be used
     * for manual initialization or testing.
     *
     * @return bool True if group was created or already exists
     * @throws StorageException If operation fails
     */
    public function initializeConsumerGroup(): bool
    {
        try {
            // Create consumer group for daemon
            // Start from '0' to read all messages (including historical)
            $this->storage->xGroupCreate($this->healthStream, 'gnode-daemon', '0', true);
            $this->debug("Created consumer group 'gnode-daemon' for health stream");
            return true;
        } catch (\Exception $e) {
            // Ignore BUSYGROUP error (group already exists)
            if (strpos($e->getMessage(), 'BUSYGROUP') !== false) {
                $this->debug("Consumer group 'gnode-daemon' already exists for health stream");
                return true;
            }

            throw new StorageException(
                "Failed to initialize consumer group: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get health stream information
     *
     * @return array Stream information from XINFO STREAM
     * @throws StorageException If operation fails
     */
    public function getStreamInfo(): array
    {
        try {
            return $this->storage->xInfo('STREAM', $this->healthStream);
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to get stream info: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get consumer group information for health stream
     *
     * @return array Consumer group information from XINFO GROUPS
     * @throws StorageException If operation fails
     */
    public function getConsumerGroupInfo(): array
    {
        try {
            return $this->storage->xInfo('GROUPS', $this->healthStream);
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to get consumer group info: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get health stream name
     *
     * @return string Health stream key
     */
    public function getHealthStream(): string
    {
        return $this->healthStream;
    }

    /**
     * Get site ID
     *
     * @return string Site identifier
     */
    public function getSiteId(): string
    {
        return $this->siteId;
    }

    /**
     * Get node ID
     *
     * @return string Node identifier
     */
    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    /**
     * Reset statistics
     *
     * @return void
     */
    public function resetStatistics(): void
    {
        $this->messageCount = 0;
        $this->bytesWritten = 0;
        $this->lastPublishTime = 0.0;
    }

    /**
     * Log debug message
     *
     * @param string $message Debug message
     * @return void
     */
    private function debug(string $message): void
    {
        if ($this->debug) {
            error_log("[HealthStreamWriter] {$message}");
        }
    }
}
