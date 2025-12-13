<?php
/**
 * gCore GSD Integration - Basic Usage Example
 *
 * Demonstrates the key features of the gCore GSD integration:
 * - Auto-batching (10K+ cmd/s)
 * - Transparent service proxying
 * - Service discovery caching
 * - Load-aware routing
 *
 * Prerequisites:
 * - gCore installed with GSD-Client library
 * - ValKey daemon running
 * - GSD daemon running
 * - Config file: gCore/config/gsd.php configured
 *
 * @package gCore\GSD\Examples
 */

require_once __DIR__ . '/../../../gCore/bootstrap.php'; // Adjust path as needed

// Initialize gCore (assumes standard gCore setup)
$gCore = \gCore\Core::getInstance();
$gCore->initialize();

// Get GSD service via gCore's service locator
$gsd = $gCore->getService('GSD');

echo "=== gCore GSD Integration Examples ===\n\n";

// ==================================================================
// Example 1: Basic Transparent Calls (Auto-batched)
// ==================================================================

echo "1. Basic transparent calls (auto-batched):\n";
echo "   Calling ping(), status(), echo() - all batched automatically\n\n";

// These calls return DeferredResult objects (promise-like)
$result1 = $gsd->ping();
$result2 = $gsd->status();
$result3 = $gsd->echo('Hello from gCore!');

// Access results - triggers flush if not already flushed
echo "   Ping result: " . ($result1->get()['result'] ? 'OK' : 'FAIL') . "\n";
echo "   Status: " . json_encode($result2->get(), JSON_PRETTY_PRINT) . "\n";
echo "   Echo: " . json_encode($result3->get(), JSON_PRETTY_PRINT) . "\n\n";

// ==================================================================
// Example 2: Service Discovery via Magic Methods
// ==================================================================

echo "2. Transparent service discovery:\n";
echo "   Calling renderTemplate() - auto-discovers template service\n\n";

try {
    // This automatically:
    // 1. Looks up method→capability mapping
    // 2. Discovers template service via geometric topology
    // 3. Caches the discovery
    // 4. Invokes the service
    // 5. Queues for batching

    $templateResult = $gsd->renderTemplate('mytemplate', [
        'title' => 'gCore GSD Integration',
        'content' => 'Auto-batching rocks!',
    ]);

    echo "   Template rendered: " . $templateResult->get() . "\n\n";

} catch (\Exception $e) {
    echo "   Note: Template service not available - " . $e->getMessage() . "\n\n";
}

// ==================================================================
// Example 3: Batch Multiple Service Calls
// ==================================================================

echo "3. Batching multiple service calls:\n";
echo "   Queueing 10 commands...\n\n";

$results = [];
for ($i = 0; $i < 10; $i++) {
    $results[] = $gsd->echo("Message $i");
}

echo "   All 10 commands queued\n";
echo "   Manually flushing batch...\n\n";

// Manual flush
$flushedResults = $gsd->flush();
echo "   Flushed " . count($flushedResults) . " results\n";
echo "   First result: " . json_encode($results[0]->get(), JSON_PRETTY_PRINT) . "\n\n";

// ==================================================================
// Example 4: Direct Client Access (Advanced)
// ==================================================================

echo "4. Direct client access for advanced operations:\n\n";

$client = $gsd->getClient();

// Geometric discovery example
$capabilities = [
    'template_rendering' => 1.0,
    'html' => 1.0,
];

$discoveredServices = $client->geometricDiscover($capabilities);
echo "   Services matching template capabilities: " . count($discoveredServices) . " found\n";
echo "   Service IDs: " . json_encode($discoveredServices) . "\n\n";

// ==================================================================
// Example 5: Performance Statistics
// ==================================================================

echo "5. Performance statistics:\n\n";

$stats = $gsd->getProxyStats();
echo "   Proxy Stats:\n";
echo "   - Total calls: " . ($stats['calls'] ?? 0) . "\n";
echo "   - Discoveries: " . ($stats['discoveries'] ?? 0) . "\n";
echo "   - Cache hits: " . ($stats['cache_hits'] ?? 0) . "\n";
echo "   - Cache misses: " . ($stats['cache_misses'] ?? 0) . "\n";
echo "   - Cache hit rate: " . ($stats['cache_hit_rate'] ?? 0) . "%\n";
echo "   - Cached services: " . ($stats['cached_services'] ?? 0) . "\n\n";

// Get queue stats if available
$queue = $client->getQueue();
if ($queue) {
    $queueStats = $queue->getStats();
    echo "   Queue Stats:\n";
    echo "   - Enqueued: " . $queueStats['enqueued'] . "\n";
    echo "   - Flushed: " . $queueStats['flushed'] . "\n";
    echo "   - Auto-flush (size): " . $queueStats['auto_flush_size'] . "\n";
    echo "   - Auto-flush (time): " . $queueStats['auto_flush_time'] . "\n";
    echo "   - Manual flush: " . $queueStats['manual_flush'] . "\n";
    echo "   - Shutdown flush: " . $queueStats['shutdown_flush'] . "\n";
    echo "   - Current queue size: " . $queueStats['queue_size'] . "\n\n";
}

// ==================================================================
// Example 6: Cache Management
// ==================================================================

echo "6. Cache management:\n\n";

// Warmup cache for common methods
echo "   Warming up cache for common methods...\n";
$warmedUp = $gsd->warmupCache(['renderTemplate', 'optimizeImage', 'parseMarkdown']);
echo "   Pre-cached $warmedUp services\n\n";

// Clear cache
echo "   Clearing discovery cache...\n";
$gsd->clearCache();
echo "   Cache cleared\n\n";

// ==================================================================
// Example 7: Service Registry Management
// ==================================================================

echo "7. Service registry:\n\n";

$registry = $gsd->getServiceRegistry();

// Register custom method
$registry->register('customService', [
    'custom' => 1.0,
    'processing' => 0.8,
]);

echo "   Registered custom service method\n";
echo "   Available methods: " . count($registry->getMethods()) . "\n";
echo "   Methods: " . implode(', ', array_slice($registry->getMethods(), 0, 10)) . "...\n\n";

// ==================================================================
// Example 8: Error Handling
// ==================================================================

echo "8. Error handling:\n\n";

try {
    // Call unknown method
    $result = $gsd->unknownMethod(['test' => 'data']);
    $result->get(); // This will throw

} catch (\gCore\GSD\Exception\GSDException $e) {
    echo "   Caught expected error: " . $e->getMessage() . "\n\n";
}

// ==================================================================
// Cleanup and Shutdown
// ==================================================================

echo "=== All examples completed ===\n\n";
echo "Note: Any pending batched commands will be automatically flushed\n";
echo "      when the script exits (via shutdown handler).\n\n";

// The shutdown handler registered in ExternalGSDAdapter will
// automatically flush any remaining batched commands.
// No manual cleanup needed!
