<?php
/**
 * Multi-Client Consumer Group Benchmark
 * 
 * Benchmark simulating multiple clients connecting to the GSD daemon
 * to demonstrate high throughput capability.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;

// Configuration
$numClients = 10;      // Number of simulated clients
$opsPerClient = 200;   // Operations per client
$batchSize = 10;       // Batch size for operations
$timeout = 5.0;        // Timeout in seconds

// Command line argument parsing
$options = getopt('', ['clients:', 'ops:', 'batch:', 'timeout:']);
if (isset($options['clients'])) $numClients = (int)$options['clients'];
if (isset($options['ops'])) $opsPerClient = (int)$options['ops'];
if (isset($options['batch'])) $batchSize = (int)$options['batch'];
if (isset($options['timeout'])) $timeout = (float)$options['timeout'];

echo "GSD Multi-Client Throughput Benchmark\n";
echo "===================================\n";
echo "Number of clients: {$numClients}\n";
echo "Operations per client: {$opsPerClient}\n";
echo "Batch size: {$batchSize}\n";
echo "Timeout: {$timeout} seconds\n\n";

// Create ValKey storage
$storage = new ValKeyStorage([
    'host' => '127.0.0.1',
    'port' => 6379
]);

// Create array of client instances
$clients = [];
for ($i = 0; $i < $numClients; $i++) {
    $nodeId = "bench-client-{$i}-" . uniqid('', true);
    
    $clients[$i] = new Client(
        $storage,
        'benchmark',
        $nodeId,
        [
            'debug' => false,
            'use_fallback' => true,
            'timeout' => $timeout * 1000, // Convert to milliseconds
            'use_consumer_groups' => true,
            'batch_size' => $batchSize,
        ]
    );
}

// Check if daemon is available
echo "Checking daemon connectivity...\n";
if (!$clients[0]->ping()) {
    die("Cannot connect to GSD daemon. Make sure it's running.\n");
}

// Track throughput statistics
$startTime = microtime(true);
$totalOperations = 0;
$successOperations = 0;
$failedOperations = 0;
$latencies = [];

// Generate random capability vector
function randomCapabilities() {
    return [
        'performance' => mt_rand(1, 100) / 100,
        'reliability' => mt_rand(1, 100) / 100,
        'availability' => mt_rand(1, 100) / 100,
    ];
}

// Register a random service
function registerRandomService(Client $client, int $index) {
    $serviceId = "test-service-" . uniqid('', true);
    $capabilities = randomCapabilities();
    return $client->registerService($serviceId, $capabilities);
}

// Find services by capabilities
function findServices(Client $client) {
    $criteria = [
        'performance' => mt_rand(1, 100) / 100,
    ];
    return $client->findServices($criteria);
}

// Run operations
echo "Starting benchmark with {$numClients} clients running {$opsPerClient} operations each...\n";

// Warmup
echo "Warming up clients...\n";
foreach ($clients as $client) {
    $client->ping();
}

// Run the benchmark - register services first
echo "Registering services...\n";
foreach ($clients as $i => $client) {
    for ($j = 0; $j < min(50, $opsPerClient / 4); $j++) {
        $operationStart = microtime(true);
        $result = registerRandomService($client, $j);
        $operationEnd = microtime(true);
        
        $totalOperations++;
        if ($result && isset($result['status']) && $result['status'] === 'ok') {
            $successOperations++;
            $latencies[] = ($operationEnd - $operationStart) * 1000; // ms
        } else {
            $failedOperations++;
        }
    }
}

// Now run mixed operations
echo "Running mixed operations...\n";
foreach ($clients as $i => $client) {
    for ($j = 0; $j < $opsPerClient; $j++) {
        $operationStart = microtime(true);
        
        // Mix of operations (80% find, 10% register, 10% ping)
        $opType = mt_rand(1, 10);
        if ($opType <= 8) {
            $result = findServices($client);
        } elseif ($opType == 9) {
            $result = registerRandomService($client, $j);
        } else {
            $result = $client->ping();
        }
        
        $operationEnd = microtime(true);
        
        $totalOperations++;
        if ($result && (
            (is_array($result) && isset($result['status']) && $result['status'] === 'ok') ||
            (is_bool($result) && $result === true)
        )) {
            $successOperations++;
            $latencies[] = ($operationEnd - $operationStart) * 1000; // ms
        } else {
            $failedOperations++;
        }
        
        // Progress report every 500 operations
        if ($totalOperations % 500 === 0) {
            $elapsed = microtime(true) - $startTime;
            $currentThroughput = $totalOperations / $elapsed;
            echo "Completed {$totalOperations} operations, current throughput: " . 
                 number_format($currentThroughput, 2) . " ops/sec\n";
        }
    }
}

$endTime = microtime(true);
$totalTime = $endTime - $startTime;
$throughput = $totalOperations / $totalTime;
$perClientThroughput = $throughput / $numClients;

// Calculate latency statistics
sort($latencies);
$count = count($latencies);
$avgLatency = array_sum($latencies) / $count;
$medianLatency = $latencies[(int)($count / 2)];
$p95Latency = $latencies[(int)($count * 0.95)];
$p99Latency = $latencies[(int)($count * 0.99)];

// Print results
echo "\n=== Multi-Client Benchmark Results ===\n";
echo str_repeat('-', 50) . "\n";
echo "Total time: " . number_format($totalTime, 2) . " seconds\n";
echo "Total operations: {$totalOperations}\n";
echo "Successful operations: {$successOperations}\n";
echo "Failed operations: {$failedOperations}\n";
echo "Total throughput: " . number_format($throughput, 2) . " ops/sec\n";
echo "Per-client throughput: " . number_format($perClientThroughput, 2) . " ops/sec\n";
echo "\nLatency statistics:\n";
echo "  Average: " . number_format($avgLatency, 2) . " ms\n";
echo "  Median: " . number_format($medianLatency, 2) . " ms\n";
echo "  P95: " . number_format($p95Latency, 2) . " ms\n";
echo "  P99: " . number_format($p99Latency, 2) . " ms\n";

// Project to maximum capacity
$projectedMax = $throughput * 20; // Assuming linear scaling up to 20 times the current load
echo "\nProjected maximum throughput (linear scaling): " . number_format($projectedMax, 2) . " ops/sec\n";

$scalingFactor = 0.8; // Assuming 80% efficiency when scaling
$projectedRealistic = $throughput * $numClients * $scalingFactor;
echo "Projected realistic throughput (80% scaling efficiency): " . 
     number_format($projectedRealistic, 2) . " ops/sec\n";

echo "\nBenchmark complete!\n";