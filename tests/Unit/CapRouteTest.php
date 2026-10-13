<?php
declare(strict_types=1);
namespace gCore\gNode\Tests\Unit;

use gCore\gNode\gNodeClient as Client;
use gCore\gNode\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;

/**
 * Capability routing on the one topology: the client puts the QUERY in `_rt` and the
 * daemon's ring picks the placement, once per relay. What is unit-testable is that the
 * query reaching the wire is the one the caller meant, and that nothing is sent when
 * the caller named nothing.
 */
final class CapRouteTest extends TestCase
{
    private array $sent = [];

    private function client(): Client
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);
        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs([$storage, 'web', 'test-node', ['use_fallback' => false]])
            ->onlyMethods(['sendRawCommand'])
            ->getMock();
        $client->method('sendRawCommand')->willReturnCallback(function (string $c, array $p, array $f) {
            $this->sent[] = [$c, $p, $f];
            return ['success' => true];
        });
        return $client;
    }

    public function testNoRequirementsSendsNothing(): void
    {
        $r = $this->client()->sendCommandToCapable('infer', ['x' => 1], []);
        self::assertFalse($r['success']);
        self::assertSame([], $this->sent, 'an unqualified relay is refused in the caller');
    }

    public function testLegacyWordsBecomeTheRelayQuery(): void
    {
        $r = $this->client()->sendCommandToCapable('infer', ['x' => 1], ['domain' => 'ml_inference', 'protocol' => 'gnode_stream'], ['layer' => 'daemon']);
        self::assertTrue($r['success']);
        [$c, $p, $f] = $this->sent[0];
        self::assertSame('infer', $c);
        self::assertSame(['x' => 1], $p);
        self::assertSame(['layer' => '3.3', 'facets' => ['domain' => 'ml_inference', 'protocol' => 'gnode_stream']], json_decode($f['_rt'], true));
    }

    public function testAnExplicitQueryPassesThrough(): void
    {
        $this->client()->sendCommandToCapable('infer', [], ['layer' => '3.3', 'facets' => ['domain' => 'ml_inference'], 'axes' => ['contract_version' => ['v2', 'v5']]]);
        self::assertSame('{"layer":"3.3","facets":{"domain":"ml_inference"},"axes":{"contract_version":["v2","v5"]}}', $this->sent[0][2]['_rt']);
    }

    public function testANumericRequirementIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->sendCommandToCapable('infer', [], ['performance' => 0.9]);
    }
}
