<?php

namespace gCore\gNode\Tests\Unit;

use PHPUnit\Framework\TestCase;
use gCore\gNode\gNodeClient;
use gCore\gNode\Storage\StorageInterface;

/**
 * The per-shape schema comes from the daemon, never from a copy in this client. These
 * pin that the client asks by shape key, decodes what the daemon published, and answers
 * the one topology's width when nothing is published yet.
 */
class SchemaResolutionTest extends TestCase
{
    private array $asked = [];

    private function storageWithSchema(array $index, array $slots)
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);
        $storage->method('fcallRo')->willReturnCallback(function (string $fn, array $keys, array $args) use ($index, $slots) {
            $this->asked[] = [$fn, $args];
            return [
                'schema_version', '5.0',
                'shape', $args[1],
                'name', 'daemon',
                'width', '24',
                'layer_code', '3',
                'layer_hi', '4',
                'slots', json_encode($slots),
                'dimension_index', json_encode($index),
                'dimension_values', json_encode(['protocol' => ['http_rest' => 0.10]]),
                'fundamentality_default', '0.3',
            ];
        });

        return $storage;
    }

    private function client($storage): gNodeClient
    {
        return new gNodeClient($storage, 'test_site', 'test-node', ['use_fallback' => false]);
    }

    private function slots(int $provider, int $other): array
    {
        $s = [];
        for ($i = 0; $i < $provider; $i++) {
            $s[$i] = ['name' => "a{$i}", 'kind' => 'colour', 'many' => false, 'writer' => 'provider', 'static' => true, 'freeform' => false];
        }
        for ($j = 0; $j < $other; $j++) {
            $s[16 + $j] = ['name' => "d{$j}", 'kind' => 'ordered', 'many' => false, 'writer' => 'sampler', 'static' => false, 'freeform' => false];
        }
        return $s;
    }

    /** @test */
    public function the_client_asks_by_shape_key_and_decodes_what_was_published(): void
    {
        $c = $this->client($this->storageWithSchema(['protocol' => 2, 'domain' => 3], $this->slots(14, 3)));

        $schema = $c->getPublishedCapabilitySchema('daemon');
        self::assertSame(['GNODE_SCHEMA_GET', ['geodineum', '3.3']], $this->asked[0], 'a shape word becomes its key on the wire');
        self::assertSame('5.0', $schema['schema_version']);
        self::assertSame(24, $schema['width']);
        self::assertSame(['protocol' => 2, 'domain' => 3], $schema['dimension_index']);
        self::assertSame(0.10, $schema['dimension_values']['protocol']['http_rest']);
        self::assertSame(0.3, $schema['fundamentality_default']);
        self::assertSame('provider', $schema['slots'][0]['writer']);

        self::assertSame(24, $c->capabilityDimensionCount('daemon'));
        self::assertSame(14, $c->capabilityDiscoveryWidth('daemon'), 'declared slots are the provider-written ones');
        self::assertSame(['protocol' => 2, 'domain' => 3], $c->getCapabilityDimensions('daemon'));
        self::assertCount(1, $this->asked, 'cached per shape');
    }

    /** @test */
    public function nothing_published_answers_the_one_topology_width(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);
        $storage->method('fcallRo')->willThrowException(new \RuntimeException('No schema published'));
        $c = $this->client($storage);

        self::assertNull($c->getPublishedCapabilitySchema('site'));
        self::assertSame(24, $c->capabilityDimensionCount('site'));
        self::assertSame(16, $c->capabilityDiscoveryWidth('site'));
    }

    /** @test */
    public function an_unknown_shape_is_refused_before_the_wire(): void
    {
        $c = $this->client($this->storageWithSchema([], []));
        $this->expectException(\InvalidArgumentException::class);
        $c->getPublishedCapabilitySchema('galaxy');
    }
}
