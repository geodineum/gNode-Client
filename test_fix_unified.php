<?php
// Script to fix GSD unified stream and consumer groups
require_once 'vendor/autoload.php';

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;

// Function to output header
function outputHeader($title = '') {
    echo "\n";
    echo str_repeat('=', 80) . "\n";
    echo " $title\n";
    echo str_repeat('=', 80) . "\n";
}

// Function to execute ValKey CLI commands
function valkeyCli($command) {
    $fullCommand = "docker exec valkey valkey-cli $command";
    echo "Executing: $fullCommand\n";
    $output = [];
    $returnVar = 0;
    exec($fullCommand, $output, $returnVar);
    
    $result = implode("\n", $output);
    echo "Result: " . ($returnVar === 0 ? "✅ Success" : "❌ Failed") . "\n";
    if (!empty($output)) {
        echo "Output:\n" . $result . "\n";
    }
    
    return ['code' => $returnVar, 'output' => $result];
}

// Stream and group names
$siteId = 'default';
$nodeId = 'default';
$streamPrefix = 'gsd';
$unifiedStream = "{$siteId}:{$streamPrefix}:unified:{$nodeId}";

outputHeader('CHECKING VALKEY CONNECTION');
valkeyCli('PING');

// Attempt to fix unified stream and groups
outputHeader('FIXING UNIFIED STREAM ISSUES');

// Delete existing unified stream if it exists
echo "Deleting existing unified stream if it exists...\n";
valkeyCli("DEL $unifiedStream");

// Create unified stream with a dummy message so groups can be created
echo "Creating unified stream with initial message...\n";
valkeyCli("XADD $unifiedStream \"*\" setup initial");

// Create consumer groups
echo "Creating client consumer group...\n";
valkeyCli("XGROUP CREATE $unifiedStream gsd-client 0 MKSTREAM");

echo "Creating daemon consumer group...\n";
valkeyCli("XGROUP CREATE $unifiedStream gsd-daemon 0 MKSTREAM");

// Verify consumer groups
echo "Verifying consumer groups...\n";
valkeyCli("XINFO GROUPS $unifiedStream");

// Create site and node records for testing site_info and node_info commands
outputHeader('CREATING SITE AND NODE RECORDS');

echo "Creating site record for site_id: $siteId\n";
valkeyCli("HMSET gsd:site:$siteId name \"Default Testing Site\" created " . time() . " status active");

echo "Creating node record for node_id: $nodeId in site: $siteId\n";
valkeyCli("HMSET gsd:site:$siteId:node:$nodeId name \"Default Testing Node\" created " . time() . " status active ip 127.0.0.1");

// Try to create a client and test connection
outputHeader('TESTING CLIENT CONNECTION');

try {
    $storage = new ValKeyStorage();
    $client = new Client(
        $storage,
        $siteId,
        $nodeId,
        [
            'stream_prefix' => $streamPrefix,
            'debug' => true
        ]
    );
    
    echo "Client created successfully\n";
    echo "Client status:\n";
    print_r($client->getStatus());
    
    // Test ping
    echo "\nSending ping command...\n";
    $ping = $client->ping();
    echo "Ping result: " . ($ping ? "✅ SUCCESS" : "❌ FAILED") . "\n";
    
    // Test batch operations
    echo "\nSending batch commands including site_info and node_info...\n";
    $batchCommands = [
        ['ping', []],
        ['get_site_info', []],
        ['get_node_info', ['node_id' => $nodeId]]
    ];
    
    $batchResults = $client->executeBatch($batchCommands);
    echo "Batch result:\n";
    print_r($batchResults);
    
} catch (Exception $e) {
    echo "❌ Error testing client: " . $e->getMessage() . "\n";
}

outputHeader('COMPLETED');
echo "If the client test was successful, you should now be able to use the GSD client with ValKey.\n";
echo "If issues persist, please check the GSD daemon logs for more information.\n";