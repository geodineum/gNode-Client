<?php

namespace gCore\gNode\Tests\Unit;

use PHPUnit\Framework\TestCase;
use gCore\gNode\gNodeClient;
use gCore\gNode\Storage\StorageInterface;

/**
 * The dimension count and index map must come from the daemon, not from a copy.
 *
 * Four private copies of this map existed at once — this client's static schema,
 * its getDimensionIndex table, gCore's geometric_topology.yaml and the Lua
 * libraries — and six indices disagreed. Nothing failed loudly. discoverRange()
 * sends an index on the wire, so a query for service_tier constrained
 * health_status instead and returned confident, wrong matches.
 *
 * These tests pin the precedence: published wins, built-in is the fallback, and
 * a fallback is announced rather than passed off as the answer.
 */
class SchemaResolutionTest extends TestCase
{
    /** Storage that answers GNODE_SCHEMA_GET with a flat HGETALL array. */
    private function storageWithSchema(array $index, int $total, int $discovery)
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);
        $storage->method('fcallRo')->willReturn([
            'schema_version', '3.0',
            'tier', 'service',
            'total_dimensions', (string) $total,
            'discovery_dimensions', (string) $discovery,
            'dimension_index', json_encode($index),
            'dimension_values', json_encode(['protocol' => ['http_rest' => 0.10]]),
        ]);

        return $storage;
    }

    private function client($storage): gNodeClient
    {
        return new gNodeClient($storage, 'test_site', 'test-node', ['use_fallback' => false]);
    }

    /**
     * @test
     */
    public function the_published_count_wins_over_the_builtin(): void
    {
        $c = $this->client($this->storageWithSchema(['protocol' => 0, 'environment' => 20], 30, 25));

        $this->assertSame(30, $c->capabilityDimensionCount());
        $this->assertSame(25, $c->capabilityDiscoveryWidth());
    }

    /**
     * @test
     */
    public function a_published_index_overrides_the_builtin_one(): void
    {
        // environment is 18 in the built-in table and 20 in the daemon. The
        // wrong one here is not a cosmetic difference: it is the number that
        // goes on the wire in a range query.
        $c = $this->client($this->storageWithSchema(['environment' => 20], 30, 25));

        $m = new \ReflectionMethod($c, 'getDimensionIndex');
        $m->setAccessible(true);
        $this->assertSame(20, $m->invoke($c, 'environment'));
    }

    /**
     * @test
     */
    public function nothing_published_falls_back_and_stays_usable(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);
        $storage->method('fcallRo')->willThrowException(new \RuntimeException('no such function'));
        $c = $this->client($storage);

        // The fallback must be the CURRENT service width, not the historical 23.
        $this->assertSame(30, $c->capabilityDimensionCount());
        $this->assertSame(25, $c->capabilityDiscoveryWidth());
    }

    /**
     * @test
     */
    public function the_builtin_copy_is_frozen_not_quietly_drifting(): void
    {
        // Kept deliberately as the no-daemon fallback, and pinned at the width
        // it actually describes. If someone bumps this number without adding the
        // seven missing dimensions, the copy starts lying again — this is the
        // assertion that stops that being invisible.
        $b = gNodeClient::getBuiltinCapabilitySchema();
        $this->assertSame(23, $b['total_dimensions']);
        $this->assertCount(23, $b['dimensions']);
        $this->assertArrayHasKey('http_rest', $b['dimensions']['protocol']['values']);
    }

    /**
     * @test
     */
    public function coordinate_width_follows_the_published_schema(): void
    {
        $c = $this->client($this->storageWithSchema(['protocol' => 0], 30, 25));

        // Was array_fill(0, 23, 0.0): a literal that could not widen with the
        // schema, so five dimensions had nowhere to land.
        $this->assertCount(30, $c->translateCapabilitiesToCoordinates(['protocol' => 'http_rest']));
    }
}
