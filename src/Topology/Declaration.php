<?php
declare(strict_types=1);

namespace gCore\gNode\Topology;

/**
 * A service's declaration for the one topology: axes by NAME (words of the schema's
 * vocabulary, lists for many-valued colours, a fraction only where the axis is freeform).
 * The daemon validates against the compiled per-shape schema and refuses by name; nothing
 * is translated to a coordinate here (SPEC-ONE-TOPOLOGY §4, §10).
 */
final class Declaration
{
    /** Shape words a caller may use → the shape key the schema knows. */
    public const SHAPES = [
        'site' => '3.1', 'web' => '3.1',
        'app' => '3.2', 'headless' => '3.2',
        'daemon' => '3.3', 'service' => '3.3', 'system' => '3.3',
        'component' => '2', 'tool' => '2',
        'node' => '1',
        '1' => '1', '2' => '2', '3.1' => '3.1', '3.2' => '3.2', '3.3' => '3.3', '4.1' => '4.1', '4.2' => '4.2',
    ];

    /** Legacy tier-schema names → the component/service body (log §3). */
    private const RENAMES = [
        'domain_primary'   => 'domain',
        'domain_secondary' => 'domain',
        'api_version'      => 'contract_version',
    ];

    /** Legacy axes that became REACH labels: their value is a `requires` label. */
    private const LABELS = ['clearance_required', 'data_sensitivity'];

    /** Legacy axes that left the vector: the shape, derived writers, positions nobody read. */
    private const DROPPED = [
        'service_tier', 'specialization', 'pipeline_stage', 'data_persistence',
        'user_x', 'user_y', 'user_z', 'registration_order', 'environment',
        'current_load', 'health_status', 'lifecycle_state', 'aggregate_load', 'node_health', 'storage_available',
    ];

    /** Keys a caller may pass in `$metadata` that are registration facts, not metadata. */
    private const FACTS = ['shape', 'environment', 'on', 'capacity_weight', 'heartbeat', 'holds', 'requires', 'peers', 'constellation'];

    /** The labels that are not labels: an open value declares nothing. */
    private const OPEN = ['public', 'public_data', 'none', 'undefined'];

    public static function shapeKey(string $word): string
    {
        $w = strtolower(trim($word));
        if (!isset(self::SHAPES[$w])) {
            throw new \InvalidArgumentException("shape '{$word}' is not one of site, app, daemon, component, node");
        }
        return self::SHAPES[$w];
    }

    /**
     * Build the `register_service` parameters from what callers pass today.
     *
     * `$capabilities` is either the new form (`['shape' => ..., 'axes' => [...]]`) or the
     * legacy flat map of axis name → word. A number is refused: the one topology declares
     * words, the daemon codes them.
     *
     * @return array{params: array, dropped: string[]}
     */
    public static function fromLegacy(string $id, array $capabilities, array $metadata = []): array
    {
        if ($id === '') {
            throw new \InvalidArgumentException('a declaration needs an id');
        }
        $dropped = [];
        $facts = [];
        foreach (self::FACTS as $k) {
            if (array_key_exists($k, $metadata)) {
                $facts[$k] = $metadata[$k];
                unset($metadata[$k]);
            }
        }

        if (isset($capabilities['axes']) || isset($capabilities['shape'])) {
            $params = $capabilities;
            $params['id'] = $id;
            $params['shape'] = self::shapeKey((string) ($params['shape'] ?? $facts['shape'] ?? 'app'));
            $params['environment'] = (string) ($params['environment'] ?? $facts['environment'] ?? 'production');
            $params['axes'] = self::checkAxes($params['axes'] ?? []);
            $params['metadata'] = $metadata + ($params['metadata'] ?? []);
            foreach ($facts as $k => $v) {
                if ($k !== 'shape' && $k !== 'environment' && !array_key_exists($k, $params)) {
                    $params[$k] = $v;
                }
            }
            return ['params' => $params, 'dropped' => $dropped];
        }

        $shapeWord = (string) ($facts['shape'] ?? $metadata['profile'] ?? $metadata['type'] ?? 'app');
        if (isset($metadata['tier']) && !isset($facts['shape']) && !isset($metadata['profile']) && !isset($metadata['type'])) {
            $shapeWord = strtolower((string) $metadata['tier']) === 'tool' ? 'component' : 'daemon';
        }
        $environment = (string) ($facts['environment'] ?? $metadata['environment'] ?? $capabilities['environment'] ?? 'production');

        $axes = [];
        $requires = [];
        foreach ($capabilities as $name => $value) {
            if (in_array($name, self::DROPPED, true)) {
                $dropped[] = (string) $name;
                continue;
            }
            if (in_array($name, self::LABELS, true)) {
                $word = strtolower((string) $value);
                if (!in_array($word, self::OPEN, true)) {
                    $requires[] = $word;
                }
                continue;
            }
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                throw new \InvalidArgumentException(
                    "axis '{$name}' got a number; the one topology declares words of the schema's vocabulary"
                );
            }
            $target = self::RENAMES[$name] ?? (string) $name;
            if ($target === 'domain' || $target === 'protocol') {
                foreach ((array) $value as $w) {
                    $axes[$target][] = (string) $w;
                }
                $axes[$target] = array_values(array_unique($axes[$target]));
                continue;
            }
            $axes[$target] = is_array($value) ? array_values(array_map('strval', $value)) : (string) $value;
        }

        $params = [
            'id' => $id,
            'shape' => self::shapeKey($shapeWord),
            'environment' => $environment,
            'axes' => $axes,
            'metadata' => $metadata,
        ];
        if ($requires !== []) {
            $params['requires'] = array_values(array_unique($requires));
        }
        foreach ($facts as $k => $v) {
            if ($k === 'shape' || $k === 'environment') {
                continue;
            }
            if ($k === 'requires') {
                $params['requires'] = array_values(array_unique(array_merge($params['requires'] ?? [], (array) $v)));
                continue;
            }
            $params[$k] = $v;
        }
        return ['params' => $params, 'dropped' => $dropped];
    }

    /** @param array<string, mixed> $axes */
    private static function checkAxes(array $axes): array
    {
        foreach ($axes as $name => $value) {
            if (is_string($value) && is_numeric($value)) {
                throw new \InvalidArgumentException("axis '{$name}' got a numeric string; declare a word, or a fraction as a number");
            }
            if (!is_string($value) && !is_array($value) && !is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException("axis '{$name}' must be a word, a list of words or a fraction");
            }
        }
        return $axes;
    }
}
