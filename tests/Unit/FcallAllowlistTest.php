<?php

namespace gCore\gNode\Tests\Unit;

use PHPUnit\Framework\TestCase;
use gCore\gNode\gNodeClient;
use gCore\gNode\Storage\StorageInterface;

class FcallAllowlistTest extends TestCase
{
    private function client(array &$called): gNodeClient
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('fcall')->willReturnCallback(function (string $fn) use (&$called) {
            $called[] = $fn;
            return null;
        });
        return new gNodeClient($storage, 'nierto_com', 'test-node', ['use_fallback' => false, 'metrics_level' => 0]);
    }

    public static function allowed(): array
    {
        return [['GNODE_PING'], ['GC_X'], ['GCORE_MGR_CONFIG_HGETALL'], ['GCORE_MGR_CONFIG_GET'], ['GCORE_MGR_SECRETS_GET']];
    }

    public static function refused(): array
    {
        return [['GCORE_FLUSH'], ['GCORE_MGR_OTHER_GET'], ['GCORE_MGR_CONFIG'], ['gcore_mgr_config_get'], ['FUNCTION_X']];
    }

    /** @test @dataProvider allowed */
    public function manager_config_and_canonical_families_reach_the_wire(string $fn): void
    {
        $called = [];
        $this->client($called)->fcall($fn, [], ['nierto_com', 'mollie']);
        self::assertContains($fn, $called);
    }

    /** @test @dataProvider refused */
    public function anything_else_under_gcore_is_refused_before_the_wire(string $fn): void
    {
        $called = [];
        try {
            $this->client($called)->fcall($fn, [], []);
            self::fail("$fn passed the allowlist");
        } catch (\InvalidArgumentException $e) {
            self::assertNotContains($fn, $called);
        }
    }
}
