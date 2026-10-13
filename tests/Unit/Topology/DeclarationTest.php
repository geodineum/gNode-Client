<?php
declare(strict_types=1);

namespace gCore\gNode\Tests\Unit\Topology;

use gCore\gNode\Topology\Declaration;
use PHPUnit\Framework\TestCase;

/**
 * The client declares by name; the daemon codes. These pin what the legacy flat map
 * becomes on the one topology and what is refused before it reaches the wire.
 */
final class DeclarationTest extends TestCase
{
    public function testShapeWordsMapToShapeKeys(): void
    {
        self::assertSame('3.1', Declaration::shapeKey('site'));
        self::assertSame('3.2', Declaration::shapeKey('app'));
        self::assertSame('3.3', Declaration::shapeKey('daemon'));
        self::assertSame('2', Declaration::shapeKey('component'));
        self::assertSame('1', Declaration::shapeKey('node'));
        self::assertSame('3.3', Declaration::shapeKey('3.3'));
        $this->expectException(\InvalidArgumentException::class);
        Declaration::shapeKey('pipeline');
    }

    public function testShapeKeysHaveNames(): void
    {
        self::assertSame('site', Declaration::name('3.1'));
        self::assertSame('service@node', Declaration::name('4.2'));
        self::assertSame('9', Declaration::name('9'));
    }

    public function testLegacyAxesAreRenamedDroppedOrKept(): void
    {
        $b = Declaration::fromLegacy('gflow', [
            'protocol' => 'gnode_stream',
            'domain_primary' => 'workflow',
            'domain_secondary' => 'compute',
            'api_version' => 'v2',
            'implementation_language' => 'rust',
            'service_tier' => 'SERVICE',
            'clearance_required' => 'infra',
            'data_sensitivity' => 'public',
        ], ['tier' => 'SERVICE', 'environment' => 'production', 'manifest' => 'gFlow/geodeploy.yaml']);

        $p = $b['params'];
        self::assertSame('gflow', $p['id']);
        self::assertSame('3.3', $p['shape'], 'a legacy SERVICE tier is a daemon');
        self::assertSame('production', $p['environment']);
        self::assertSame(['gnode_stream'], $p['axes']['protocol']);
        self::assertSame(['workflow', 'compute'], $p['axes']['domain'], 'primary and secondary fold into one list');
        self::assertSame('v2', $p['axes']['contract_version']);
        self::assertSame('rust', $p['axes']['implementation_language']);
        self::assertArrayNotHasKey('service_tier', $p['axes']);
        self::assertSame(['service_tier'], $b['dropped']);
        self::assertSame(['infra'], $p['requires'], 'clearance became a REACH label; public is no label');
        self::assertSame(['tier' => 'SERVICE', 'manifest' => 'gFlow/geodeploy.yaml'], $p['metadata'], 'environment is a registration fact, lifted out of the metadata');
    }

    public function testRegistrationFactsRideInMetadata(): void
    {
        $p = Declaration::fromLegacy('nierto_com', ['protocol' => 'http_rest'], [
            'shape' => 'site', 'on' => 'aesir-solutions', 'capacity_weight' => 2, 'heartbeat' => 'gnode-daemon', 'holds' => ['infra'], 'site' => 'nierto_com',
        ])['params'];
        self::assertSame('3.1', $p['shape']);
        self::assertSame('aesir-solutions', $p['on']);
        self::assertSame(2, $p['capacity_weight']);
        self::assertSame('gnode-daemon', $p['heartbeat']);
        self::assertSame(['infra'], $p['holds']);
        self::assertSame(['site' => 'nierto_com'], $p['metadata'], 'facts leave the metadata; metadata stays');
    }

    public function testTheNewFormPassesThrough(): void
    {
        $p = Declaration::fromLegacy('x', ['shape' => 'daemon', 'axes' => ['protocol' => ['grpc'], 'fundamentality' => 0.4]], ['k' => 'v'])['params'];
        self::assertSame('3.3', $p['shape']);
        self::assertSame(['grpc'], $p['axes']['protocol']);
        self::assertSame(0.4, $p['axes']['fundamentality'], 'a fraction on a freeform axis is a number, not a code');
        self::assertSame(['k' => 'v'], $p['metadata']);
    }

    public function testNumbersAreRefusedBeforeTheWire(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Declaration::fromLegacy('x', ['protocol' => 0.10]);
    }

    public function testToolTierIsAComponent(): void
    {
        $p = Declaration::fromLegacy('gcore', ['protocol' => 'library'], ['tier' => 'TOOL'])['params'];
        self::assertSame('2', $p['shape']);
    }

    public function testAnEmptyIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Declaration::fromLegacy('', ['protocol' => 'grpc']);
    }
}
