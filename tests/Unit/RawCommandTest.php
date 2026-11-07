<?php
declare(strict_types=1);
namespace gCore\gNode\Tests\Unit;

use gCore\gNode\gNodeClient as Client;
use gCore\gNode\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;

/**
 * sendRawCommand and sendCommandToCapable on a default client (no consumer groups):
 * the command goes onto the unified stream and the reply is read from the origin's
 * response key, `{site}:res:{id}`, where the daemon's relay delivers it.
 */
final class RawCommandTest extends TestCase
{
    private array $added = [];
    private array $deleted = [];

    private function client(?string $reply): Client
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('xAdd')->willReturnCallback(function (string $stream, string $id, array $fields) {
            $this->added[] = [$stream, $fields];
            return '1-0';
        });
        $storage->method('fcall')->willReturnCallback(function (string $fn, array $keys, array $args) use ($reply) {
            if ($fn === 'GNODE_CACHE_GET') {
                $own = $this->added[0][1]['id'] ?? '';
                return $args[0] === "{web}:res:{$own}" ? $reply : null;
            }
            if ($fn === 'GNODE_CACHE_DEL') {
                $this->deleted[] = $args[0];
                return 1;
            }
            return null;
        });
        return new Client($storage, 'web', 'test-node', ['use_fallback' => false, 'metrics_level' => 0, 'timeout' => 0.05]);
    }

    public function testRawCommandReadsTheReplyKeyedOnItsOwnId(): void
    {
        $r = $this->client('{"status":"ok","result":{"n":1}}')->sendRawCommand('ping', ['a' => 1]);
        self::assertSame('ok', $r['status'] ?? null, json_encode($r));
        self::assertCount(1, $this->added);
        self::assertSame('ping', $this->added[0][1]['c']);
        self::assertSame(["{web}:res:{$this->added[0][1]['id']}"], $this->deleted);
    }

    public function testCapabilityRelayCarriesTheQueryAndReturnsTheReply(): void
    {
        $r = $this->client('{"status":"ok"}')->sendCommandToCapable('infer', [], ['domain' => 'ml_inference']);
        self::assertSame('ok', $r['status'] ?? null, json_encode($r));
        self::assertArrayHasKey('_rt', $this->added[0][1]);
    }

    public function testNoReplyIsAFailureNotAnException(): void
    {
        $r = $this->client(null)->sendRawCommand('ping');
        self::assertFalse($r['success']);
    }
}
