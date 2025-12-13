<?php
/**
 * gCore GSD Integration - Performance Benchmark
 *
 * Tests:
 * - Auto-batching throughput (target: 10,000+ cmd/s)
 * - Service discovery cache hit rate (target: >90%)
 * - Queue overhead (target: <1ms)
 * - Simdjson acceleration (if available)
 *
 * @package gCore\GSD\Examples
 */

require_once __DIR__ . '/../../../gCore/bootstrap.php'; // Adjust path as needed

// Initialize gCore
$gCore = \gCore\Core::getInstance();
$gCore->initialize();

// Get GSD service
$gsd = $gCore->getService('GSD');
$client = $gsd->getClient();

echo "=== gCore GSD Integration Performance Benchmark ===\n\n";

// Check requirements
echo "Environment:\n";
echo "- PHP Version: " . PHP_VERSION . "\n";
echo "- SimdJSON available: " . (extension_loaded('simdjson') ? 'YES' : 'NO') . "\n";
echo "- Redis extension: " . (extension_loaded('redis') ? phpversion('redis') : 'NOT AVAILABLE') . "\n\n";

// ==================================================================
// Benchmark 1: Auto-batching Throughput
// ==================================================================

echo "=== Benchmark 1: Auto-Batching Throughput ===\n\n";

$batchSizes = [10, 50, 100, 500, 1000];

foreach ($batchSizes as $batchSize) {
    echo "Testing batch size: $batchSize\n";

    // Prepare commands
    $commands = [];
    for ($i = 0; $i < $batchSize; $i++) {
        $commands[] = [
            'cmd' => 'ping',
            'params' => [],
            'id' => "bench-{$i}",
        ];
    }

    // Benchmark
    $startTime = microtime(true);
    $results = $client->executeBatch($commands);
    $endTime = microtime(true);

    $duration = ($endTime - $startTime) * 1000; // milliseconds
    $throughput = $batchSize / ($duration / 1000); // commands per second
    $successRate = count($results) / $batchSize * 100;

    echo "  Duration: " . number_format($duration, 2) . " ms\n";
    echo "  Throughput: " . number_format($throughput, 0) . " cmd/s\n";
    echo "  Success rate: " . number_format($successRate, 1) . "%\n";
    echo "  Avg latency: " . number_format($duration / $batchSize, 3) . " ms/cmd\n\n";

    // Clear between runs
    usleep(100000); // 100ms cooldown
}

// ==================================================================
// Benchmark 2: Sustained Throughput (10 batches of 100)
// ==================================================================

echo "=== Benchmark 2: Sustained Throughput (10x100 = 1000 commands) ===\n\n";

$totalCommands = 1000;
$batchSize = 100;
$numBatches = $totalCommands / $batchSize;

$overallStart = microtime(true);
$batchTimes = [];
$successCount = 0;

for ($batch = 0; $batch < $numBatches; $batch++) {
    $commands = [];
    for ($i = 0; $i < $batchSize; $i++) {
        $commands[] = [
            'cmd' => 'echo',
            'params' => ['message' => "batch-{$batch}-cmd-{$i}"],
            'id' => "sustained-{$batch}-{$i}",
        ];
    }

    $batchStart = microtime(true);
    $results = $client->executeBatch($commands);
    $batchEnd = microtime(true);

    $batchTime = ($batchEnd - $batchStart) * 1000;
    $batchTimes[] = $batchTime;
    $successCount += count($results);

    echo "  Batch " . ($batch + 1) . ": " . number_format($batchTime, 2) . " ms (" .
         number_format($batchSize / ($batchTime / 1000), 0) . " cmd/s)\n";
}

$overallEnd = microtime(true);
$totalDuration = ($overallEnd - $overallStart) * 1000;
$overallThroughput = $totalCommands / ($totalDuration / 1000);
$avgBatchTime = array_sum($batchTimes) / count($batchTimes);
$successRate = ($successCount / $totalCommands) * 100;

echo "\nSustained Performance:\n";
echo "  Total duration: " . number_format($totalDuration, 2) . " ms\n";
echo "  Overall throughput: " . number_format($overallThroughput, 0) . " cmd/s\n";
echo "  Average batch time: " . number_format($avgBatchTime, 2) . " ms\n";
echo "  Success rate: " . number_format($successRate, 1) . "%\n";
echo "  Target: 10,000 cmd/s - " . ($overallThroughput >= 10000 ? "✓ PASS" : "✗ FAIL") . "\n\n";

// ==================================================================
// Benchmark 3: Service Discovery Cache Performance
// ==================================================================

echo "=== Benchmark 3: Service Discovery Cache Performance ===\n\n";

// Clear cache first
$gsd->clearCache();

$capabilities = [
    'template_rendering' => 1.0,
    'html' => 1.0,
];

$lookupTimes = [];
$cacheHits = 0;
$cacheMisses = 0;

echo "Performing 50 identical service discoveries...\n";

for ($i = 0; $i < 50; $i++) {
    $statsBefore = $gsd->getProxyStats();
    $hitsBefore = $statsBefore['cache_hits'] ?? 0;
    $missesBefore = $statsBefore['cache_misses'] ?? 0;

    $start = microtime(true);
    $services = $client->geometricDiscover($capabilities);
    $end = microtime(true);

    $lookupTime = ($end - $start) * 1000;
    $lookupTimes[] = $lookupTime;

    $statsAfter = $gsd->getProxyStats();
    $hitsAfter = $statsAfter['cache_hits'] ?? 0;
    $missesAfter = $statsAfter['cache_misses'] ?? 0;

    if ($hitsAfter > $hitsBefore) {
        $cacheHits++;
    } else if ($missesAfter > $missesBefore) {
        $cacheMisses++;
    }

    if ($i == 0) {
        echo "  First lookup (cold): " . number_format($lookupTime, 3) . " ms\n";
    } else if ($i == 1) {
        echo "  Second lookup (cached): " . number_format($lookupTime, 3) . " ms\n";
    }
}

$avgLookupTime = array_sum($lookupTimes) / count($lookupTimes);
$cacheHitRate = ($cacheHits / 50) * 100;

echo "\nCache Performance:\n";
echo "  Average lookup time: " . number_format($avgLookupTime, 3) . " ms\n";
echo "  Cache hits: $cacheHits\n";
echo "  Cache misses: $cacheMisses\n";
echo "  Cache hit rate: " . number_format($cacheHitRate, 1) . "%\n";
echo "  Target: >90% hit rate - " . ($cacheHitRate >= 90 ? "✓ PASS" : "✗ FAIL") . "\n\n";

// ==================================================================
// Benchmark 4: Queue Overhead
// ==================================================================

echo "=== Benchmark 4: Queue Overhead ===\n\n";

$queue = $client->getQueue();

if ($queue) {
    echo "Testing enqueue performance...\n";

    $enqueueTimes = [];
    $numOps = 1000;

    for ($i = 0; $i < $numOps; $i++) {
        $start = microtime(true);
        $deferred = $queue->enqueue('ping', []);
        $end = microtime(true);

        $enqueueTimes[] = ($end - $start) * 1000000; // microseconds
    }

    // Flush to clean up
    $queue->flush();

    $avgEnqueueTime = array_sum($enqueueTimes) / count($enqueueTimes);
    $maxEnqueueTime = max($enqueueTimes);
    $minEnqueueTime = min($enqueueTimes);

    echo "  Operations: $numOps\n";
    echo "  Average enqueue time: " . number_format($avgEnqueueTime, 3) . " μs\n";
    echo "  Min: " . number_format($minEnqueueTime, 3) . " μs\n";
    echo "  Max: " . number_format($maxEnqueueTime, 3) . " μs\n";
    echo "  Target: <1000 μs (1ms) - " . ($avgEnqueueTime < 1000 ? "✓ PASS" : "✗ FAIL") . "\n\n";

} else {
    echo "  Queue not enabled - skipping\n\n";
}

// ==================================================================
// Benchmark 5: Transparent Proxy Overhead
// ==================================================================

echo "=== Benchmark 5: Transparent Proxy vs Direct Client ===\n\n";

// Direct client call
$directTimes = [];
for ($i = 0; $i < 100; $i++) {
    $start = microtime(true);
    $result = $client->ping();
    $end = microtime(true);
    $directTimes[] = ($end - $start) * 1000;
}

$avgDirectTime = array_sum($directTimes) / count($directTimes);

echo "  Direct client call avg: " . number_format($avgDirectTime, 3) . " ms\n";
echo "  (Proxy overhead test skipped - requires queue disabled)\n\n";

// ==================================================================
// Summary
// ==================================================================

echo "=== Benchmark Summary ===\n\n";

echo "Performance Goals:\n";
echo "  1. Batching throughput: " . ($overallThroughput >= 10000 ? "✓" : "✗") . " " .
     number_format($overallThroughput, 0) . " cmd/s (target: ≥10,000)\n";
echo "  2. Cache hit rate: " . ($cacheHitRate >= 90 ? "✓" : "✗") . " " .
     number_format($cacheHitRate, 1) . "% (target: >90%)\n";
if ($queue) {
    echo "  3. Queue overhead: " . ($avgEnqueueTime < 1000 ? "✓" : "✗") . " " .
         number_format($avgEnqueueTime, 3) . " μs (target: <1ms)\n";
}
echo "  4. Success rate: " . ($successRate >= 99 ? "✓" : "✗") . " " .
     number_format($successRate, 1) . "% (target: ≥99%)\n";

echo "\nFinal Stats:\n";
$finalStats = $gsd->getProxyStats();
print_r($finalStats);

if ($queue) {
    echo "\nQueue Stats:\n";
    print_r($queue->getStats());
}

echo "\n=== Benchmark Complete ===\n";
