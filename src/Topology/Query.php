<?php
declare(strict_types=1);

namespace gCore\gNode\Topology;

/**
 * A discovery on the one topology: a layer (shape word or key; default every service),
 * facets (colour words, any-of), axis ranges ([lo, hi] as words or fractions), a limit.
 * One `geometric_discover` call answers it with records that carry their colour; the
 * same JSON in `_rt` lets the daemon's ring choose the placement (SPEC §6).
 */
final class Query
{
    /** @param array<string, mixed> $facets  @param array<string, array{0: mixed, 1: mixed}> $axes */
    public static function build(string $layer = '3', array $facets = [], array $axes = [], int $limit = 10): array
    {
        $q = ['layer' => self::layerKey($layer), 'limit' => max(0, $limit)];
        if ($facets !== []) {
            $q['facets'] = $facets;
        }
        if ($axes !== []) {
            $q['axes'] = $axes;
        }
        return $q;
    }

    public static function layerKey(string $layer): string
    {
        $l = strtolower(trim($layer));
        if (in_array($l, ['1', '2', '3', '4', '3.1', '3.2', '3.3', '4.1', '4.2'], true)) {
            return $l;
        }
        return Declaration::shapeKey($l);
    }

    /**
     * From the requirement shapes callers pass today: a word is a facet value; a list of
     * words is any-of; a constraint array (`min`/`max`, `gte`/`lte`, `gt`/`lt`, `eq`) is an
     * axis range of words or fractions. A bare number is refused: axes are named, not coded.
     */
    public static function fromLegacy(array $requirements, int $limit = 10, string $layer = '3'): array
    {
        $facets = [];
        $axes = [];
        foreach ($requirements as $name => $value) {
            if ($name === 'dimensions' && is_array($value)) {
                $inner = self::fromLegacy($value, $limit, $layer);
                $facets += $inner['facets'] ?? [];
                $axes += $inner['axes'] ?? [];
                continue;
            }
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                throw new \InvalidArgumentException(
                    "requirement '{$name}' is a number; name a word (facet) or a range ([lo, hi]) on the one topology"
                );
            }
            if (is_string($value)) {
                $facets[(string) $name] = $value;
                continue;
            }
            if (!is_array($value)) {
                throw new \InvalidArgumentException("requirement '{$name}' must be a word, a list of words or a constraint");
            }
            if (array_is_list($value)) {
                if (count($value) === 2 && (is_numeric($value[0]) || is_numeric($value[1]))) {
                    $axes[(string) $name] = [$value[0], $value[1]];
                } else {
                    $facets[(string) $name] = array_values(array_map('strval', $value));
                }
                continue;
            }
            $lo = $value['min'] ?? $value['gte'] ?? $value['gt'] ?? $value['eq'] ?? 0.0;
            $hi = $value['max'] ?? $value['lte'] ?? $value['lt'] ?? $value['eq'] ?? 1.0;
            $axes[(string) $name] = [$lo, $hi];
        }
        return self::build($layer, $facets, $axes, $limit);
    }

    /** The `_rt` value that makes the daemon pick the service by colour and the placement by its ring. */
    public static function relayTarget(array $query): string
    {
        unset($query['limit']);
        return json_encode($query, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
