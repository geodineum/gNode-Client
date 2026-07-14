<?php
declare(strict_types=1);

namespace gCore\gNode\Health;

/**
 * HealthMetrics - Runtime operational metrics for service instances
 *
 * This structure holds real-time performance and load data for a service,
 * representing the ephemeral health state separate from persistent geometric capabilities.
 *
 * Message Format (compressed field names for bandwidth optimization):
 * {
 *   "t": "lu",            // Type: load update (REQUIRED)
 *   "si": "service-id",   // Service ID (REQUIRED)
 *   "l": 0.35,            // Load factor 0.0-1.0 (REQUIRED)
 *   "cpu": 0.45,          // CPU usage 0.0-1.0 (optional)
 *   "mem": 0.60,          // Memory usage 0.0-1.0 (optional)
 *   "rq": 12,             // Active requests count (optional)
 *   "lat": 150,           // Avg latency milliseconds (optional)
 *   "err": 0.02,          // Error rate 0.0-1.0 (optional)
 *   "ts": 1696800000000   // Timestamp milliseconds (REQUIRED)
 * }
 *
 * @package gCore\gNode\Health
 */
class HealthMetrics
{
    /** @var string Service identifier */
    public $serviceId;

    /** @var float Load factor (0.0-1.0, lower is better) */
    public $loadFactor;

    /** @var float|null CPU usage (0.0-1.0) */
    public $cpuUsage;

    /** @var float|null Memory usage (0.0-1.0) */
    public $memoryUsage;

    /** @var int|null Active request count */
    public $activeRequests;

    /** @var int|null Average latency in milliseconds */
    public $avgLatencyMs;

    /** @var float|null Error rate (0.0-1.0) */
    public $errorRate;

    /** @var int Timestamp in milliseconds */
    public $timestamp;

    /** @var int Time-to-live in seconds (default: 30) */
    public $ttlSeconds;

    /**
     * Constructor
     *
     * @param string $serviceId Service identifier
     * @param float $loadFactor Load factor (0.0-1.0)
     * @param array $optional Optional metrics
     */
    public function __construct(
        string $serviceId,
        float $loadFactor,
        array $optional = []
    ) {
        $this->serviceId = $serviceId;
        $this->loadFactor = max(0.0, min(1.0, $loadFactor)); // Clamp to 0.0-1.0

        $this->cpuUsage = isset($optional['cpu_usage'])
            ? max(0.0, min(1.0, (float)$optional['cpu_usage']))
            : null;

        $this->memoryUsage = isset($optional['memory_usage'])
            ? max(0.0, min(1.0, (float)$optional['memory_usage']))
            : null;

        $this->activeRequests = isset($optional['active_requests'])
            ? (int)$optional['active_requests']
            : null;

        $this->avgLatencyMs = isset($optional['avg_latency_ms'])
            ? (int)$optional['avg_latency_ms']
            : null;

        $this->errorRate = isset($optional['error_rate'])
            ? max(0.0, min(1.0, (float)$optional['error_rate']))
            : null;

        $this->timestamp = isset($optional['timestamp'])
            ? (int)$optional['timestamp']
            : (int)(microtime(true) * 1000);

        $this->ttlSeconds = isset($optional['ttl_seconds'])
            ? (int)$optional['ttl_seconds']
            : 30;
    }

    /**
     * Create HealthMetrics from an associative array
     *
     * @param array $data Metrics data
     * @return self
     * @throws \InvalidArgumentException If required fields are missing
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['service_id'])) {
            throw new \InvalidArgumentException('Missing required field: service_id');
        }

        if (!isset($data['load_factor'])) {
            throw new \InvalidArgumentException('Missing required field: load_factor');
        }

        return new self(
            $data['service_id'],
            (float)$data['load_factor'],
            $data
        );
    }

    /**
     * Check if metrics are stale based on TTL
     *
     * @param int $now Current timestamp in milliseconds
     * @return bool True if metrics have exceeded TTL
     * @api
     */
    public function isStale(int $now): bool
    {
        return $now - $this->timestamp > ($this->ttlSeconds * 1000);
    }

    /**
     * Check if service is healthy based on load and error thresholds
     *
     * A service is considered unhealthy if:
     * - Load factor >= 0.95 (95% capacity)
     * - Error rate >= 0.05 (5% errors)
     *
     * @return bool True if service is healthy
     * @api
     */
    public function isHealthy(): bool
    {
        return $this->loadFactor < 0.95 &&
               ($this->errorRate === null || $this->errorRate < 0.05);
    }

    /**
     * Calculate composite score for ranking (lower is better)
     *
     * Scoring formula (matches gNode daemon):
     * - Load factor: 60% weight
     * - CPU usage: 20% weight
     * - Memory usage: 10% weight
     * - Latency: 10% weight (normalized to 0-1 scale)
     *
     * @return float Composite score (0.0-1.0+, lower is better)
     * @api
     */
    public function calculateScore(): float
    {
        return $this->loadFactor * 0.6 +
               ($this->cpuUsage ?? 0.5) * 0.2 +
               ($this->memoryUsage ?? 0.5) * 0.1 +
               (($this->avgLatencyMs ?? 100) / 1000.0) * 0.1;
    }

    /**
     * Convert to compressed message format for health stream
     *
     * Uses abbreviated field names to minimize bandwidth:
     * - t: type ("lu" for load update)
     * - si: service_id
     * - l: load_factor
     * - cpu: cpu_usage
     * - mem: memory_usage
     * - rq: active_requests
     * - lat: avg_latency_ms
     * - err: error_rate
     * - ts: timestamp
     *
     * @return array Compressed message fields
     * @api
     */
    public function toCompressedFormat(): array
    {
        $fields = [
            't' => 'lu',                    // Type: load update
            'si' => $this->serviceId,       // Service ID
            'l' => (string)$this->loadFactor, // Load factor
            'ts' => (string)$this->timestamp  // Timestamp
        ];

        // Add optional fields only if present
        if ($this->cpuUsage !== null) {
            $fields['cpu'] = (string)$this->cpuUsage;
        }

        if ($this->memoryUsage !== null) {
            $fields['mem'] = (string)$this->memoryUsage;
        }

        if ($this->activeRequests !== null) {
            $fields['rq'] = (string)$this->activeRequests;
        }

        if ($this->avgLatencyMs !== null) {
            $fields['lat'] = (string)$this->avgLatencyMs;
        }

        if ($this->errorRate !== null) {
            $fields['err'] = (string)$this->errorRate;
        }

        return $fields;
    }

    /**
     * Convert to associative array (verbose format)
     *
     * @return array Full metrics data
     * @api
     */
    public function toArray(): array
    {
        return [
            'service_id' => $this->serviceId,
            'load_factor' => $this->loadFactor,
            'cpu_usage' => $this->cpuUsage,
            'memory_usage' => $this->memoryUsage,
            'active_requests' => $this->activeRequests,
            'avg_latency_ms' => $this->avgLatencyMs,
            'error_rate' => $this->errorRate,
            'timestamp' => $this->timestamp,
            'ttl_seconds' => $this->ttlSeconds,
            'is_healthy' => $this->isHealthy(),
            'score' => $this->calculateScore()
        ];
    }

    /**
     * Create metrics from current system state
     *
     * @param string $serviceId Service identifier
     * @param float|null $customLoadFactor Optional custom load factor (otherwise calculated)
     * @return self
     */
    public static function captureCurrentMetrics(string $serviceId, ?float $customLoadFactor = null): self
    {
        $loadAvg = sys_getloadavg();
        $cpuCount = self::getCpuCount();

        // Calculate load factor from system load average if not provided
        $loadFactor = $customLoadFactor ?? ($loadAvg[0] / max(1, $cpuCount));

        // Get memory usage
        $memUsage = memory_get_usage(true);
        $memLimit = self::getMemoryLimit();
        $memoryUsage = $memLimit > 0 ? ($memUsage / $memLimit) : 0.5;

        return new self($serviceId, $loadFactor, [
            'cpu_usage' => min(1.0, $loadAvg[0] / max(1, $cpuCount)),
            'memory_usage' => min(1.0, $memoryUsage),
            'active_requests' => self::getActiveRequestCount(),
            'avg_latency_ms' => null, // Must be tracked externally
            'error_rate' => null       // Must be tracked externally
        ]);
    }

    /**
     * Get CPU count
     *
     * @return int Number of CPU cores
     */
    private static function getCpuCount(): int
    {
        static $cpuCount = null;

        if ($cpuCount === null) {
            if (function_exists('shell_exec')) {
                $count = @shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 1');
                $cpuCount = max(1, (int)trim($count));
            } else {
                $cpuCount = 1;
            }
        }

        return $cpuCount;
    }

    /**
     * Get memory limit in bytes
     *
     * @return int Memory limit in bytes (0 if unlimited)
     */
    private static function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return 0; // Unlimited
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int)substr($limit, 0, -1);

        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int)$limit;
        }
    }

    /**
     * Get active request count (from global state if available)
     *
     * @return int|null Active request count
     */
    private static function getActiveRequestCount(): ?int
    {
        // This should be tracked by the application via a global counter
        // or APM tool. Return null if not available.
        if (isset($GLOBALS['active_requests'])) {
            return (int)$GLOBALS['active_requests'];
        }

        return null;
    }

    /**
     * Validate metrics values
     *
     * @return array Array of validation errors (empty if valid)
     * @api
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->serviceId)) {
            $errors[] = 'Service ID cannot be empty';
        }

        if ($this->loadFactor < 0.0 || $this->loadFactor > 1.0) {
            $errors[] = 'Load factor must be between 0.0 and 1.0';
        }

        if ($this->cpuUsage !== null && ($this->cpuUsage < 0.0 || $this->cpuUsage > 1.0)) {
            $errors[] = 'CPU usage must be between 0.0 and 1.0';
        }

        if ($this->memoryUsage !== null && ($this->memoryUsage < 0.0 || $this->memoryUsage > 1.0)) {
            $errors[] = 'Memory usage must be between 0.0 and 1.0';
        }

        if ($this->errorRate !== null && ($this->errorRate < 0.0 || $this->errorRate > 1.0)) {
            $errors[] = 'Error rate must be between 0.0 and 1.0';
        }

        if ($this->activeRequests !== null && $this->activeRequests < 0) {
            $errors[] = 'Active requests cannot be negative';
        }

        if ($this->avgLatencyMs !== null && $this->avgLatencyMs < 0) {
            $errors[] = 'Average latency cannot be negative';
        }

        if ($this->timestamp <= 0) {
            $errors[] = 'Timestamp must be positive';
        }

        if ($this->ttlSeconds <= 0) {
            $errors[] = 'TTL must be positive';
        }

        return $errors;
    }
}
