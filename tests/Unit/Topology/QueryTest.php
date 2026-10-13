<?php
declare(strict_types=1);

namespace gCore\gNode\Tests\Unit\Topology;

use gCore\gNode\Topology\Query;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    public function testWordsAreFacetsAndConstraintsAreRanges(): void
    {
        $q = Query::fromLegacy([
            'protocol' => 'grpc',
            'domain' => ['workflow', 'compute'],
            'latency_class' => ['max' => 'responsive'],
            'contract_version' => ['gte' => 'v2', 'lte' => 'v5'],
            'fundamentality' => [0.0, 0.3],
        ], 5, 'daemon');
        self::assertSame('3.3', $q['layer']);
        self::assertSame(5, $q['limit']);
        self::assertSame('grpc', $q['facets']['protocol']);
        self::assertSame(['workflow', 'compute'], $q['facets']['domain']);
        self::assertSame([0.0, 'responsive'], $q['axes']['latency_class']);
        self::assertSame(['v2', 'v5'], $q['axes']['contract_version']);
        self::assertSame([0.0, 0.3], $q['axes']['fundamentality']);
    }

    public function testDefaultLayerIsEveryService(): void
    {
        $q = Query::fromLegacy(['protocol' => 'http_rest']);
        self::assertSame('3', $q['layer']);
        self::assertSame(10, $q['limit']);
        self::assertArrayNotHasKey('axes', $q);
    }

    public function testLegacyDimensionsWrapperUnwraps(): void
    {
        $q = Query::fromLegacy(['dimensions' => ['protocol' => 'grpc']]);
        self::assertSame('grpc', $q['facets']['protocol']);
    }

    public function testNumbersAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Query::fromLegacy(['protocol' => 0.5]);
    }

    public function testRelayTargetCarriesTheQueryWithoutALimit(): void
    {
        $rt = Query::relayTarget(Query::build('daemon', ['domain' => 'workflow'], [], 1));
        self::assertSame('{"layer":"3.3","facets":{"domain":"workflow"}}', $rt);
    }

    public function testLayerKeysPassThrough(): void
    {
        self::assertSame('4.2', Query::layerKey('4.2'));
        self::assertSame('2', Query::layerKey('component'));
    }
}
