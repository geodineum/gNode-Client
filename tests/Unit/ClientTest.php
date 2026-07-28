<?php

namespace gCore\gNode\Tests\Unit;

use PHPUnit\Framework\TestCase;
use gCore\gNode\Client;
use gCore\gNode\Storage\StorageInterface;
use gCore\gNode\Fallback\FallbackHandler;

class ClientTest extends TestCase
{
    /**
     * @test
     */
    public function it_can_be_instantiated_with_fallback()
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);

        $client = new Client($storage, 'test', 'test-node', [
            'use_fallback' => true,
            'allow_local_execution' => true
        ]);

        $this->assertInstanceOf(Client::class, $client);
        $this->assertTrue($client->isConnected());
    }

    /**
     * @test
     */
    public function it_can_read_capability_dimensions()
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);

        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs([$storage, 'test', 'test-node', [
                'use_fallback' => true,
                'allow_local_execution' => true
            ]])
            ->onlyMethods(['sendCommand'])
            ->getMock();

        $client->method('sendCommand')->willReturn([
            'status' => 'ok',
            'result' => true,
            'timestamp' => microtime(true)
        ]);

        $result = $client->getCapabilityDimensions();
        $this->assertIsArray($result);
    }

    /**
     * @test
     */
    public function it_can_register_service()
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);

        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs([$storage, 'test', 'test-node', [
                'use_fallback' => true,
                'allow_local_execution' => true
            ]])
            ->onlyMethods(['sendCommand'])
            ->getMock();

        $client->method('sendCommand')->willReturn([
            'status' => 'ok',
            'result' => true,
            'timestamp' => microtime(true)
        ]);

        $result = $client->registerService('test-service', [
            'performance' => 0.9,
            'reliability' => 0.8
        ], [
            'version' => '1.0'
        ]);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function it_can_find_services()
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);

        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs([$storage, 'test', 'test-node', [
                'use_fallback' => true,
                'allow_local_execution' => true
            ]])
            ->onlyMethods(['sendCommand'])
            ->getMock();

        $client->method('sendCommand')->willReturn([
            'status' => 'ok',
            'result' => ['service1', 'service2'],
            'timestamp' => microtime(true)
        ]);

        $result = $client->findServices(['performance' => 0.5]);
        $this->assertEquals(['service1', 'service2'], $result);
    }

    /**
     * @test
     */
    public function it_can_get_load_sequence()
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);

        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs([$storage, 'test', 'test-node', [
                'use_fallback' => true,
                'allow_local_execution' => true
            ]])
            ->onlyMethods(['sendCommand'])
            ->getMock();

        $client->method('sendCommand')->willReturn([
            'status' => 'ok',
            'result' => ['service1', 'service2', 'service3'],
            'timestamp' => microtime(true)
        ]);

        $result = $client->getLoadSequence();
        $this->assertEquals(['service1', 'service2', 'service3'], $result);
    }

    /**
     * @test
     */
    public function it_can_get_service_details()
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);

        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs([$storage, 'test', 'test-node', [
                'use_fallback' => true,
                'allow_local_execution' => true
            ]])
            ->onlyMethods(['sendCommand'])
            ->getMock();

        $serviceDetails = [
            'id' => 'service1',
            'capabilities' => [
                'performance' => 0.9,
                'reliability' => 0.8
            ],
            'metadata' => [
                'version' => '1.0'
            ]
        ];

        $client->method('sendCommand')->willReturn([
            'status' => 'ok',
            'result' => $serviceDetails,
            'timestamp' => microtime(true)
        ]);

        $result = $client->getServiceDetails('service1');
        $this->assertEquals($serviceDetails, $result);
    }

    /**
     * @test
     */
    public function it_can_ping()
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('phpredis extension not loaded');
        }

        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn(true);

        $storage = $this->createMock(\gCore\gNode\Storage\ValKeyStorage::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('ping')->willReturn(true);
        $storage->method('getRedis')->willReturn($redis);

        $client = new Client($storage, 'test', 'test-node', [
            'use_fallback' => true,
            'allow_local_execution' => true
        ]);

        $result = $client->ping();
        $this->assertTrue($result);
    }
}
