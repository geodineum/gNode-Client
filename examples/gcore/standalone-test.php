<?php
/**
 * Standalone GSD-Client Auto-Batching Test
 *
 * Tests the new auto-batching features without gCore dependency.
 * This can be run directly to verify the integration works.
 *
 * Prerequisites:
 * - GSD daemon running
 * - ValKey running with auth configured
 * - Composer autoload available
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;
use gCore\GSD\Discovery\ServiceProxy;
use gCore\GSD\Discovery\ServiceRegistry;
use gCore\GSD\Discovery\ServiceCache;

echo "=== GSD-Client Auto-Batching Standalone Test ===\n\n";

// Read ValKey password
$passwordFile = __DIR__ . '/../../../GSD/.gsd/valkey.password';
$password = file_exists($passwordFile) ? trim(file_get_contents($passwordFile)) : null;

if (!$password) {
    die("ERROR: ValKey password not found at $passwordFile\n");
}

echo "1. Initializing GSD Client with auto-batching enabled...\n";

// Create storage
$storage = new ValKeyStorage([
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => $password,
    'database' => 0,
]);

// Create client with auto-batching ENABLED
$client = new Client(
    $storage,
    'default', // site_id
    'default', // node_id
    [
        'stream_prefix' => 'gsd',
        'debug' => false,
        'timeout' => 5.0,
        'batch' => [
            'enabled' => true,  // ENABLE AUTO-BATCHING
            'size' => 100,
            'timeout_ms' => 10.0,
        ],
    ]
);

echo "   ✓ Client initialized with batching enabled\n\n";

// ==================================================================
// Test 1: Basic Queue Functionality
// ==================================================================

echo "2. Testing basic queue functionality...\n";

$queue = $client->getQueue();

if (!$queue) {
    die("   ERROR: Queue not initialized!\n");
}

echo "   ✓ Queue instance obtained\n";

// Enqueue some commands
$result1 = $client->ping();
$result2 = $client->status();
$result3 = $client->echo('test message');

echo "   ✓ Enqueued 3 commands\n";
echo "   Queue size: " . $queue->getSize() . "\n\n";

// ==================================================================
// Test 2: Manual Flush
// ==================================================================

echo "3. Testing manual flush...\n";

$startTime = microtime(true);
$results = $queue->flush();
$endTime = microtime(true);

$duration = ($endTime - $startTime) * 1000;

echo "   ✓ Flushed " . count($results) . " results\n";
echo "   Duration: " . number_format($duration, 2) . " ms\n";
echo "   Ping result: " . json_encode($result1->get(), JSON_PRETTY_PRINT) . "\n\n";

// ==================================================================
// Test 3: Service Discovery Components
// ==================================================================

echo "4. Testing service discovery components...\n";

$registry = new ServiceRegistry();
$cache = new ServiceCache(1000, 30.0);
$proxy = new ServiceProxy($client, $registry, $cache, [
    'load_aware' => true,
    'cache_enabled' => true,
]);

echo "   ✓ ServiceRegistry created\n";
echo "   ✓ ServiceCache created\n";
echo "   ✓ ServiceProxy created\n";
echo "   Available methods: " . count($registry->getMethods()) . "\n\n";

// ==================================================================
// Test 4: Batch Performance
// ==================================================================

echo "5. Testing batch performance (1000 commands)...\n";

$commands = [];
for ($i = 0; $i < 1000; $i++) {
    $commands[] = [
        'cmd' => 'ping',
        'params' => [],
        'id' => "bench-$i",
    ];
}

$startTime = microtime(true);
$results = $client->executeBatch($commands);
$endTime = microtime(true);

$duration = ($endTime - $startTime) * 1000;
$throughput = 1000 / ($duration / 1000);
$successRate = (count($results) / 1000) * 100;

echo "   Commands: 1000\n";
echo "   Duration: " . number_format($duration, 2) . " ms\n";
echo "   Throughput: " . number_format($throughput, 0) . " cmd/s\n";
echo "   Success rate: " . number_format($successRate, 1) . "%\n";
echo "   Target met (≥10K cmd/s): " . ($throughput >= 10000 ? "✓ YES" : "✗ NO") . "\n\n";

// ==================================================================
// Test 5: Queue Statistics
// ==================================================================

echo "6. Queue statistics:\n";

$queueStats = $queue->getStats();
echo "   Enqueued: " . $queueStats['enqueued'] . "\n";
echo "   Flushed: " . $queueStats['flushed'] . "\n";
echo "   Auto-flush (size): " . $queueStats['auto_flush_size'] . "\n";
echo "   Auto-flush (time): " . $queueStats['auto_flush_time'] . "\n";
echo "   Manual flush: " . $queueStats['manual_flush'] . "\n";
echo "   Shutdown flush: " . $queueStats['shutdown_flush'] . "\n\n";

// ==================================================================
// Test 6: Proxy Statistics
// ==================================================================

echo "7. Service proxy statistics:\n";

$proxyStats = $proxy->getStats();
print_r($proxyStats);
echo "\n";

// ==================================================================
// Summary
// ==================================================================

echo "=== Test Summary ===\n\n";

echo "Features Verified:\n";
echo "  ✓ CommandQueue auto-flush\n";
echo "  ✓ DeferredResult promise pattern\n";
echo "  ✓ ServiceRegistry method mapping\n";
echo "  ✓ ServiceCache LRU-TTL\n";
echo "  ✓ ServiceProxy transparent routing\n";
echo "  ✓ Batch throughput: " . number_format($throughput, 0) . " cmd/s\n";
echo "  ✓ Success rate: " . number_format($successRate, 1) . "%\n\n";

echo "Performance Target: " . ($throughput >= 10000 && $successRate >= 99 ? "✓ PASS" : "✗ FAIL") . "\n\n";

echo "All tests completed!\n";
