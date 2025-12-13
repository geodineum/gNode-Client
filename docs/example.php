<?php
/**
 * GSD Client Example
 * 
 * This example demonstrates how to use the GSD client package.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;
use gCore\GSD\Utils\IntegrationHelper;
use gCore\GSD\Exception\GSDException;

// Example 1: Basic initialization
function example1() {
    try {
        // Create ValKey storage
        $storage = new ValKeyStorage([
            'host' => '127.0.0.1',
            'port' => 6379
        ]);
        
        // Create GSD client
        $client = new Client(
            $storage,
            'default',       // site ID
            'default',       // node ID
            [
                'debug' => true,
                'use_fallback' => true,
                'timeout' => 5.0
            ]
        );
        
        // Check connection status
        echo "Connected to GSD: " . ($client->isConnected() ? "Yes" : "No") . "\n";
        echo "Using fallback: " . ($client->isUsingFallback() ? "Yes" : "No") . "\n";
        
        // Register capability dimensions
        $client->registerCapabilityDimension('performance', 0);
        $client->registerCapabilityDimension('reliability', 1);
        $client->registerCapabilityDimension('memory', 2);
        
        // Register a service
        $client->registerService(
            'example-service',
            [
                'performance' => 0.9,
                'reliability' => 0.8,
                'memory' => 0.7
            ],
            [
                'description' => 'Example service',
                'version' => '1.0',
                'endpoints' => ['http://example.com/api']
            ]
        );
        
        // Find services
        $services = $client->findServices(['performance' => 0.5]);
        echo "Found services: " . implode(', ', $services) . "\n";
        
        // Get service details
        foreach ($services as $serviceId) {
            $details = $client->getServiceDetails($serviceId);
            echo "Service: {$serviceId}\n";
            echo "  Capabilities: " . json_encode($details['capabilities']) . "\n";
            echo "  Metadata: " . json_encode($details['metadata']) . "\n";
        }
        
        // Get load sequence
        $sequence = $client->getLoadSequence();
        echo "Load sequence: " . implode(' -> ', $sequence) . "\n";
        
    } catch (GSDException $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Example 2: Using IntegrationHelper
function example2() {
    // Initialize GSD with auto-start
    $result = IntegrationHelper::initialize([
        'host' => '127.0.0.1',
        'port' => 6379,
        'site_id' => 'production',
        'node_id' => 'gsd-main',
        'client_id' => 'example-client',
        'debug' => true,
        'use_fallback' => true,
        'daemon_path' => '/path/to/gsd-daemon'  // Set actual path here
    ], true);
    
    echo "Initialization status: {$result['status']}\n";
    echo "Message: {$result['message']}\n";
    echo "Daemon running: " . ($result['daemon_running'] ? "Yes" : "No") . "\n";
    echo "Scripts loaded: " . ($result['scripts_loaded'] ? "Yes" : "No") . "\n";
    echo "Script count: {$result['script_count']}\n";
    
    if ($result['status'] === 'healthy' || $result['status'] === 'fallback') {
        $client = $result['client'];
        
        // Use client as in example1
        echo "Connected to GSD: " . ($client->isConnected() ? "Yes" : "No") . "\n";
        echo "Using fallback: " . ($client->isUsingFallback() ? "Yes" : "No") . "\n";
    }
}

// Example 3: Batch operations using ScriptManager
function example3() {
    try {
        // Create ValKey storage
        $storage = new ValKeyStorage([
            'host' => '127.0.0.1',
            'port' => 6379
        ]);
        
        // Create GSD client
        $client = new Client(
            $storage,
            'default',
            'default',
            ['debug' => true]
        );
        
        // Get script manager
        $scriptManager = $client->getScriptManager();
        
        // Execute batch operations using script
        $batchOps = json_encode([
            ["SET", "{default}:example:key1", "value1"],
            ["SET", "{default}:example:key2", "value2"],
            ["GET", "{default}:example:key1"],
            ["GET", "{default}:example:key2"],
            ["HSET", "{default}:example:hash", "field1", "value1"],
            ["HSET", "{default}:example:hash", "field2", "value2"],
            ["HGETALL", "{default}:example:hash"]
        ]);
        
        $result = $scriptManager->executeScript(
            'BATCH_OPERATIONS',
            [], // No keys
            ['default', 'JSON', $batchOps]
        );
        
        echo "Batch operations result: " . $result . "\n";
        
    } catch (GSDException $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Example 4: Stream operations using ScriptManager
function example4() {
    try {
        // Create ValKey storage
        $storage = new ValKeyStorage([
            'host' => '127.0.0.1',
            'port' => 6379
        ]);
        
        // Create GSD client
        $client = new Client(
            $storage,
            'default',
            'default',
            ['debug' => true]
        );
        
        // Get script manager
        $scriptManager = $client->getScriptManager();
        
        // Stream key
        $streamKey = '{default}:example:stream';
        
        // Create consumer group
        $scriptManager->executeScript(
            'STREAM_OPERATIONS',
            [$streamKey],
            ['CREATEGROUP', 'example-group', '0-0']
        );
        
        // Add messages to stream
        for ($i = 1; $i <= 3; $i++) {
            $messageId = $scriptManager->executeScript(
                'STREAM_OPERATIONS',
                [$streamKey],
                ['ADD', '*', 'id', $i, 'data', "Message {$i}", 'timestamp', microtime(true)]
            );
            
            echo "Added message with ID: {$messageId}\n";
        }
        
        // Read messages from stream
        $result = $scriptManager->executeScript(
            'STREAM_OPERATIONS',
            [$streamKey],
            ['READ', '0', '10']
        );
        
        echo "Stream read result: {$result}\n";
        
        // Read as consumer group
        $result = $scriptManager->executeScript(
            'STREAM_OPERATIONS',
            [$streamKey],
            ['GROUPREAD', 'example-group', 'consumer1', '>', '10']
        );
        
        echo "Consumer group read result: {$result}\n";
        
    } catch (GSDException $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Uncomment the example you want to run
// example1();
// example2();
// example3();
// example4();

echo "Choose an example to run (1-4): ";
$choice = trim(fgets(STDIN));

switch ($choice) {
    case '1':
        example1();
        break;
    case '2':
        example2();
        break;
    case '3':
        example3();
        break;
    case '4':
        example4();
        break;
    default:
        echo "Invalid choice. Please select 1-4.\n";
}