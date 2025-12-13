<?php

namespace gCore\GSD\Broadcast;

/**
 * BroadcastMessage - Immutable broadcast message from GSD broadcast stream
 *
 * Represents a single broadcast message (1:many pub-sub pattern).
 * Broadcast streams use XREAD (no consumer groups, no PEL, no XACK).
 * Each reader tracks its own position independently.
 *
 * Common Message Types:
 * - topology_update: Topology changed (services added/removed/modified)
 * - service_registered: New service registered with capabilities
 * - service_deregistered: Service removed from topology
 * - config_changed: Configuration update notification
 * - health_threshold_exceeded: Service health degraded
 * - custom: Application-specific broadcast
 *
 * Message Format (from gsd_broadcast.lua):
 * {
 *   "id": "1234567890-0",           // Stream message ID (XREAD)
 *   "type": "topology_update",      // Message type
 *   "site_id": "production",        // Originating site
 *   "timestamp": 1696800000000,     // Milliseconds since epoch
 *   "fields": {                     // All stream fields
 *     "t": "topology_update",
 *     "ss": "production",
 *     "ts": "1696800000000",
 *     "msg": "Service api-v2 registered",
 *     ...custom fields...
 *   }
 * }
 *
 * @package gCore\GSD\Broadcast
 */
class BroadcastMessage
{
    /** @var string Stream message ID (e.g., "1234567890-0") */
    public $id;

    /** @var string Message type (e.g., "topology_update") */
    public $type;

    /** @var string Originating site ID */
    public $siteId;

    /** @var int Timestamp in milliseconds */
    public $timestamp;

    /** @var array All message fields from stream */
    public $fields;

    /**
     * Constructor
     *
     * @param string $id Stream message ID
     * @param string $type Message type
     * @param string $siteId Originating site
     * @param int $timestamp Timestamp in milliseconds
     * @param array $fields All stream fields
     */
    public function __construct(
        string $id,
        string $type,
        string $siteId,
        int $timestamp,
        array $fields
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->siteId = $siteId;
        $this->timestamp = $timestamp;
        $this->fields = $fields;
    }

    /**
     * Create BroadcastMessage from ValKey function result
     *
     * The GSD_BROADCAST_READ function returns msgpack-encoded array of messages:
     * [{id, type, site_id, timestamp, fields}, ...]
     *
     * @param array $data Message data from ValKey function
     * @return self
     * @throws \InvalidArgumentException If required fields are missing
     */
    public static function fromValkeyResult(array $data): self
    {
        if (!isset($data['id'])) {
            throw new \InvalidArgumentException('Missing required field: id');
        }

        $type = $data['type'] ?? 'unknown';
        $siteId = $data['site_id'] ?? '';
        $timestamp = $data['timestamp'] ?? 0;
        $fields = $data['fields'] ?? [];

        return new self(
            $data['id'],
            $type,
            $siteId,
            (int)$timestamp,
            $fields
        );
    }

    /**
     * Create BroadcastMessage from stream entry (direct XREAD)
     *
     * Used when reading directly with XREAD instead of ValKey function.
     * Stream entry format: [id => [field => value, ...]]
     *
     * @param string $id Stream message ID
     * @param array $fields Stream fields
     * @return self
     */
    public static function fromStreamEntry(string $id, array $fields): self
    {
        // Extract type from 't' or 'type' field
        $type = $fields['t'] ?? $fields['type'] ?? 'unknown';

        // Extract site_id from 'ss' or 'site_id' field
        $siteId = $fields['ss'] ?? $fields['site_id'] ?? '';

        // Extract timestamp from 'ts' or 'timestamp' field
        $timestamp = (int)($fields['ts'] ?? $fields['timestamp'] ?? 0);

        return new self($id, $type, $siteId, $timestamp, $fields);
    }

    /**
     * Get message content (user-facing message)
     *
     * Extracts 'msg' or 'message' field from fields.
     *
     * @return string|null Message content or null if not present
     */
    public function getMessage(): ?string
    {
        return $this->fields['msg'] ?? $this->fields['message'] ?? null;
    }

    /**
     * Get custom field value
     *
     * @param string $fieldName Field name
     * @param mixed $default Default value if field not present
     * @return mixed Field value or default
     */
    public function getField(string $fieldName, $default = null)
    {
        return $this->fields[$fieldName] ?? $default;
    }

    /**
     * Check if message has a specific field
     *
     * @param string $fieldName Field name
     * @return bool True if field exists
     */
    public function hasField(string $fieldName): bool
    {
        return isset($this->fields[$fieldName]);
    }

    /**
     * Check if message age exceeds threshold
     *
     * @param int $maxAgeMs Maximum age in milliseconds
     * @param int|null $nowMs Current time (default: current time)
     * @return bool True if message is older than threshold
     */
    public function isStale(int $maxAgeMs, ?int $nowMs = null): bool
    {
        $nowMs = $nowMs ?? (int)(microtime(true) * 1000);
        return ($nowMs - $this->timestamp) > $maxAgeMs;
    }

    /**
     * Get message age in seconds
     *
     * @param int|null $nowMs Current time (default: current time)
     * @return float Message age in seconds
     */
    public function getAgeSeconds(?int $nowMs = null): float
    {
        $nowMs = $nowMs ?? (int)(microtime(true) * 1000);
        return ($nowMs - $this->timestamp) / 1000.0;
    }

    /**
     * Check if message matches type filter
     *
     * @param string|array $types Single type or array of types
     * @return bool True if message type matches filter
     */
    public function matchesType($types): bool
    {
        if (is_string($types)) {
            return $this->type === $types;
        }

        if (is_array($types)) {
            return in_array($this->type, $types, true);
        }

        return false;
    }

    /**
     * Convert to associative array
     *
     * @return array Message data
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'site_id' => $this->siteId,
            'timestamp' => $this->timestamp,
            'message' => $this->getMessage(),
            'age_seconds' => $this->getAgeSeconds(),
            'fields' => $this->fields
        ];
    }

    /**
     * Convert to JSON string
     *
     * @return string JSON representation
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Magic method for string conversion
     *
     * @return string Human-readable representation
     */
    public function __toString(): string
    {
        $msg = $this->getMessage() ?? '(no message)';
        return sprintf(
            '[%s] %s from %s: %s',
            $this->type,
            date('Y-m-d H:i:s', $this->timestamp / 1000),
            $this->siteId,
            $msg
        );
    }
}
