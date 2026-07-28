<?php

namespace gCore\gNode\Tests\Unit;

use PHPUnit\Framework\TestCase;
use gCore\gNode\Fallback\FallbackHandler;
use gCore\gNode\Exception\gNodeException;

class FallbackHandlerTest extends TestCase
{
    /**
     * @test
     */
    public function it_can_be_instantiated()
    {
        $handler = new FallbackHandler();
        $this->assertInstanceOf(FallbackHandler::class, $handler);
    }

    /**
     * @test
     */
    public function it_allows_ping_command_without_local_execution()
    {
        $handler = new FallbackHandler(false);

        $result = $handler->executeCommand('ping');
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function it_rejects_other_commands_when_local_execution_disabled()
    {
        $handler = new FallbackHandler(false);

        $this->expectException(gNodeException::class);
        $handler->executeCommand('registerCapabilityDimension', [
            'name' => 'performance',
            'dimension' => 0
        ]);
    }

    /**
     * @test
     */
    public function it_refuses_commands_it_cannot_serve_offline()
    {
        // registerCapabilityDimension was removed from the client; the fallback
        // must reject it rather than pretend it succeeded. A fallback that
        // returns true for a write it did not perform is worse than an outage:
        // the caller proceeds believing the dimension is registered.
        $handler = new FallbackHandler(true);

        $this->expectException(\gCore\gNode\Exception\gNodeException::class);
        $handler->executeCommand('registerCapabilityDimension', [
            'name' => 'performance',
            'dimension' => 0
        ]);
    }

    /**
     * @test
     */
    public function it_handles_register_service()
    {
        $handler = new FallbackHandler(true);

        $result = $handler->executeCommand('registerService', [
            'id' => 'test-service',
            'capabilities' => [
                'performance' => 0.9,
                'reliability' => 0.8
            ],
            'metadata' => [
                'version' => '1.0'
            ]
        ]);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function it_handles_find_services()
    {
        $handler = new FallbackHandler(true);

        // Register a service first
        $handler->executeCommand('registerService', [
            'id' => 'test-service',
            'capabilities' => [
                'performance' => 0.9,
                'reliability' => 0.8
            ]
        ]);

        // Find services
        $services = $handler->executeCommand('findServices', [
            'requirements' => [
                'performance' => 0.5
            ]
        ]);

        $this->assertIsArray($services);
        $this->assertContains('test-service', $services);
    }

    /**
     * @test
     */
    public function it_returns_empty_array_when_no_matching_services()
    {
        $handler = new FallbackHandler(true);

        // Register a service with low performance
        $handler->executeCommand('registerService', [
            'id' => 'test-service',
            'capabilities' => [
                'performance' => 0.3
            ]
        ]);

        // Find services with high requirements
        $services = $handler->executeCommand('findServices', [
            'requirements' => [
                'performance' => 0.8
            ]
        ]);

        $this->assertIsArray($services);
        $this->assertEmpty($services);
    }

    /**
     * @test
     */
    public function it_handles_get_load_sequence()
    {
        $handler = new FallbackHandler(true);

        // Register multiple services
        $handler->executeCommand('registerService', [
            'id' => 'service1',
            'capabilities' => [
                'performance' => 0.9
            ]
        ]);

        $handler->executeCommand('registerService', [
            'id' => 'service2',
            'capabilities' => [
                'performance' => 0.7
            ]
        ]);

        $handler->executeCommand('registerService', [
            'id' => 'service3',
            'capabilities' => [
                'performance' => 0.8
            ]
        ]);

        // Get load sequence
        $sequence = $handler->executeCommand('getLoadSequence');

        $this->assertIsArray($sequence);
        $this->assertCount(3, $sequence);

        // Should be ordered by performance (highest first)
        $this->assertEquals('service1', $sequence[0]);
        $this->assertEquals('service3', $sequence[1]);
        $this->assertEquals('service2', $sequence[2]);
    }

    /**
     * @test
     */
    public function it_throws_exception_for_unsupported_command()
    {
        $handler = new FallbackHandler(true);

        $this->expectException(gNodeException::class);
        $handler->executeCommand('unsupportedCommand');
    }
}
