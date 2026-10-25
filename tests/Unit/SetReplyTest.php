<?php
declare(strict_types=1);
namespace gCore\gNode\Tests\Unit;

use PHPUnit\Framework\TestCase;
use gCore\gNode\gNodeClient;
use gCore\gNode\Storage\StorageInterface;

/** set() reports what GNODE_CORE_SET_WITH_TTL did: its Lua `true` arrives as the integer 1. */
final class SetReplyTest extends TestCase
{
    private function client($reply, array &$calls = []): gNodeClient
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('fcall')->willReturnCallback(function (string $fn, array $keys, array $args) use ($reply, &$calls) {
            $calls[] = [$fn, $args];
            return $reply;
        });
        return new gNodeClient($storage, 'geodineum_com', 'test-node', ['use_fallback' => false, 'metrics_level' => 0]);
    }

    public function testTheIntegerOneIsSuccess(): void
    {
        $calls = [];
        $this->assertTrue($this->client(1, $calls)->set('{geodineum_com}:comms:config', '{}'));
        $this->assertContains('GNODE_CORE_SET_WITH_TTL', array_column($calls, 0));
    }

    public function testOtherRepliesKeepTheirMeaning(): void
    {
        $this->assertTrue($this->client('OK')->set('k', 'v'));
        $this->assertTrue($this->client(true)->set('k', 'v'));
        $this->assertFalse($this->client(0)->set('k', 'v'));
        $this->assertFalse($this->client(false)->set('k', 'v'));
        $this->assertFalse($this->client(null)->set('k', 'v'));
    }

    public function testSavingCommsSettingsReportsTheWrite(): void
    {
        $this->assertTrue($this->client(1)->saveCommsSettings(['channels' => [], 'addressed' => ['types' => ['login', 'alpha'], 'daily_cap' => 200]]));
    }
}
