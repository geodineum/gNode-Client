<?php
declare(strict_types=1);

namespace gCore\gNode\Tests\Unit;

use gCore\gNode\gNodeClient;
use gCore\gNode\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;

/** getTopology() is one read of the constellation's entities, grouped by layer and decoded by shape. */
final class TopologyReadTest extends TestCase
{
    public function testEntitiesAreGroupedByLayerAndDecoded(): void
    {
        $pd = array_fill(0, 24, 0.0);
        $pd[16] = 0.4; $pd[17] = 1.0; $pd[18] = 0.25;
        $entities = [
            'nierto_com', json_encode(['id' => 'nierto_com', 'sh' => '3.1', 'env' => 'production', 'c' => ['protocol' => ['http_rest']], 'm' => ['type' => 'wordpress-site'], 'pd' => $pd, 'ra' => 1760000000, 'ro' => 3]),
            'gflow', json_encode(['id' => 'gflow', 'sh' => '3.3', 'env' => 'production', 'c' => ['domain' => ['workflow']], 'm' => [], 'pd' => $pd, 'ro' => 4]),
            'gCore', json_encode(['id' => 'gCore', 'sh' => '2', 'env' => 'global', 'c' => [], 'm' => [], 'pd' => $pd, 'ro' => 1]),
            'aesir-solutions', json_encode(['id' => 'aesir-solutions', 'sh' => '1', 'env' => 'global', 'c' => ['node_role' => ['master']], 'm' => [], 'pd' => $pd, 'ro' => 2]),
            'gflow@aesir-solutions', json_encode(['id' => 'gflow@aesir-solutions', 'sh' => '4.2', 'env' => 'production', 'of' => 'gflow', 'on' => 'aesir-solutions', 'w' => 2, 'hb' => 'gflow', 'c' => [], 'm' => [], 'pd' => $pd, 'ro' => 5]),
            'broken', '{not json',
        ];
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);
        $asked = [];
        $storage->method('fcall')->willReturnCallback(function (string $fn, array $keys, array $args) use (&$asked, $entities) {
            $asked[] = [$fn, $keys];
            return $entities;
        });
        $c = new gNodeClient($storage, 'nierto_com', 'test-node', ['use_fallback' => false]);

        $t = $c->getTopology();
        $reads = array_values(array_filter($asked, fn ($a) => $a[0] === 'GNODE_HASH_HGETALL'));
        self::assertSame([['GNODE_HASH_HGETALL', ['{geodineum}:gnode:constellation:entities']]], $reads, 'one key, one read');
        self::assertSame(['nierto_com', 'gflow'], array_keys($t['services']));
        self::assertSame(['gCore'], array_keys($t['components']));
        self::assertSame(['aesir-solutions'], array_keys($t['nodes']));
        self::assertSame(['gflow@aesir-solutions'], array_keys($t['placements']));
        $site = $t['services']['nierto_com'];
        self::assertSame('3.1', $site['shape']);
        self::assertSame(['http_rest'], $site['colour']['protocol']);
        self::assertSame(['type' => 'wordpress-site'], $site['metadata']);
        self::assertSame(0.4, $site['load']);
        self::assertSame(1.0, $site['health']);
        self::assertCount(24, $site['point']);
        self::assertSame(1760000000, $site['registered_at']);
        self::assertSame('gflow', $t['placements']['gflow@aesir-solutions']['of']);
        self::assertSame(2, $t['placements']['gflow@aesir-solutions']['weight']);
        self::assertSame('geodineum', $c->getTopologyNamespace());
    }
}
