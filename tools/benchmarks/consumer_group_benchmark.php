<?php
/**
 * Consumer Group Benchmark
 * 
 * Benchmark comparing the performance of consumer group approach vs. script-based polling
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;

// Configuration
$iterations = 1000;
$warmup = 50;
$timeout = 5.0; // seconds
$batchSize = 100;

echo "GSD Consumer Group vs. Polling Benchmark\n";
echo "====================================\n";
echo "Iterations: {$iterations}\n";
echo "Warmup: {$warmup}\n\n";

// Connect to ValKey/Redis
$storage = new ValKeyStorage([
    'host' => '127.0.0.1',
    'port' => 6379
]);

// Test with consumer groups enabled
echo "Running benchmark with consumer groups...\n";
$clientWithGroups = new Client(
    $storage,
    'benchmark',
    'bench-cg-' . uniqid(),
    [
        'debug' => false,
        'use_fallback' => true,
        'timeout' => $timeout,
        'use_consumer_groups' => true,
        'batch_size' => $batchSize,
    ]
);

// Ensure daemon is available
if (!$clientWithGroups->ping()) {
    die("Cannot connect to GSD daemon. Make sure it's running.\n");
}

// Also create a client with consumer groups disabled
echo "Running benchmark with script-based polling...\n";
$clientWithPolling = new Client(
    $storage,
    'benchmark',
    'bench-poll-' . uniqid(),
    [
        'debug' => false,
        'use_fallback' => true,
        'timeout' => $timeout,
        'use_consumer_groups' => false,
    ]
);

// Warm up both clients
echo "Warming up...\n";
for ($i = 0; $i < $warmup; $i++) {
    $clientWithGroups->ping();
    $clientWithPolling->ping();
}

// Benchmark functions
function benchmark($client, $iterations, $operation = 'ping')
{
    $times = [];
    
    for ($i = 0; $i < $iterations; $i++) {
        $start = microtime(true);
        
        switch ($operation) {
            case 'ping':
                $client->ping();
                break;
            case 'find':
                $client->findServices(['performance' => 0.5]);
                break;
            // Add more operations as needed
        }
        
        $end = microtime(true);
        $times[] = ($end - $start) * 1000; // convert to ms
    }
    
    return $times;
}

// Run benchmarks for ping
echo "\nRunning ping benchmark...\n";
$groupPingTimes = benchmark($clientWithGroups, $iterations);
$pollingPingTimes = benchmark($clientWithPolling, $iterations);

// Run benchmarks for findServices
echo "Running findServices benchmark...\n";
$groupFindTimes = benchmark($clientWithGroups, $iterations / 10, 'find');
$pollingFindTimes = benchmark($clientWithPolling, $iterations / 10, 'find');

// Calculate and print results
function calculateStats($times)
{
    sort($times);
    $count = count($times);
    
    return [
        'min' => $times[0],
        'max' => $times[$count - 1],
        'avg' => array_sum($times) / $count,
        'median' => $times[(int)($count / 2)],
        'p95' => $times[(int)($count * 0.95)],
        'p99' => $times[(int)($count * 0.99)],
        'throughput' => 1000 / (array_sum($times) / $count), // ops/sec
    ];
}

$groupPingStats = calculateStats($groupPingTimes);
$pollingPingStats = calculateStats($pollingPingTimes);
$groupFindStats = calculateStats($groupFindTimes);
$pollingFindStats = calculateStats($pollingFindTimes);

// Print formatted results
echo "\n=== Benchmark Results ===\n";
echo str_repeat('-', 80) . "\n";
echo sprintf("%-20s %-14s %-14s %-14s %-14s\n", 
    'Operation', 'Approach', 'Avg (ms)', 'P95 (ms)', 'Throughput (ops/s)');
echo str_repeat('-', 80) . "\n";

echo sprintf("%-20s %-14s %-14.2f %-14.2f %-14.2f\n",
    'ping', 'Consumer Groups', $groupPingStats['avg'], $groupPingStats['p95'], $groupPingStats['throughput']);
echo sprintf("%-20s %-14s %-14.2f %-14.2f %-14.2f\n",
    'ping', 'Script Polling', $pollingPingStats['avg'], $pollingPingStats['p95'], $pollingPingStats['throughput']);

echo sprintf("%-20s %-14s %-14.2f %-14.2f %-14.2f\n",
    'findServices', 'Consumer Groups', $groupFindStats['avg'], $groupFindStats['p95'], $groupFindStats['throughput']);
echo sprintf("%-20s %-14s %-14.2f %-14.2f %-14.2f\n",
    'findServices', 'Script Polling', $pollingFindStats['avg'], $pollingFindStats['p95'], $pollingFindStats['throughput']);

echo str_repeat('-', 80) . "\n\n";

// Calculate and print improvement percentage
$pingImprovement = ($groupPingStats['throughput'] / $pollingPingStats['throughput'] - 1) * 100;
$findImprovement = ($groupFindStats['throughput'] / $pollingFindStats['throughput'] - 1) * 100;

echo "Performance Improvement with Consumer Groups:\n";
echo "  - ping: " . sprintf("%.1f%%", $pingImprovement) . "\n";
echo "  - findServices: " . sprintf("%.1f%%", $findImprovement) . "\n";

$avgImprovement = ($pingImprovement + $findImprovement) / 2;
echo "  - Average improvement: " . sprintf("%.1f%%", $avgImprovement) . "\n";

echo "\nBenchmark complete!\n";