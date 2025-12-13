<?php
/**
 * Load Demo Template Fragments into GSD
 *
 * Templates are stored as services with "template:" prefix in the geometric topology.
 * Each template has an 8D capability vector matching the service discovery space.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;

// Initialize GSD Client
$storage = new ValKeyStorage([
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => trim(file_get_contents(__DIR__ . '/../.gsd/valkey.password'))
]);

$client = new Client($storage, 'default', 'default', [
    'debug' => false,
    'timeout' => 10.0,
    'use_consumer_groups' => true
]);

echo "Loading demo service configuration templates into GSD...\n\n";
echo "ℹ️  Note: These are service descriptors (nginx, postgres, etc.) registered as\n";
echo "   services in the geometric topology. For actual Tera HTML templates, see\n";
echo "   register_example_data.php instead.\n\n";

// 8D Capability Dimensions:
// [storage, compute, network, security, latency, throughput, reliability, scalability]

$templates = [
    [
        'template_id' => 'nginx-reverse-proxy',
        'capabilities' => [
            0.1,  // storage: very low (config file only)
            0.3,  // compute: low (simple routing)
            0.98, // network: very high (network-focused)
            0.85, // security: high (TLS termination)
            0.95, // latency: very low latency
            0.95, // throughput: very high throughput
            0.97, // reliability: very reliable
            0.80  // scalability: horizontally scalable
        ],
        'metadata' => [
            'name' => 'NGINX Reverse Proxy Configuration',
            'description' => 'High-performance reverse proxy with TLS termination and load balancing',
            'content_type' => 'nginx/config',
            'size_bytes' => 2048
        ]
    ],
    [
        'template_id' => 'postgres-connection-pool',
        'capabilities' => [
            0.95, // storage: very high (database-focused)
            0.50, // compute: moderate
            0.60, // network: moderate (TCP connections)
            0.85, // security: high (auth + encryption)
            0.70, // latency: moderate
            0.80, // throughput: high
            0.95, // reliability: very reliable
            0.90  // scalability: scales well
        ],
        'metadata' => [
            'name' => 'PostgreSQL Connection Pool Configuration',
            'description' => 'Optimized connection pooling for high-throughput database access',
            'content_type' => 'application/json',
            'size_bytes' => 512
        ]
    ],
    [
        'template_id' => 'rest-api-crud-endpoint',
        'capabilities' => [
            0.40, // storage: moderate (CRUD operations)
            0.50, // compute: moderate (business logic)
            0.80, // network: high (HTTP API)
            0.90, // security: very high (auth + validation)
            0.85, // latency: low latency requirement
            0.85, // throughput: high request rate
            0.90, // reliability: resilient error handling
            0.95  // scalability: horizontally scalable
        ],
        'metadata' => [
            'name' => 'RESTful CRUD API Endpoint Template',
            'description' => 'Secure, scalable REST API with authentication and validation',
            'content_type' => 'application/python',
            'size_bytes' => 4096
        ]
    ],
    [
        'template_id' => 'redis-cache-config',
        'capabilities' => [
            0.40, // storage: moderate (caching layer)
            0.30, // compute: low (key-value ops)
            0.70, // network: high (distributed cache)
            0.60, // security: moderate
            1.00, // latency: ultra-low latency
            0.95, // throughput: very high throughput
            0.88, // reliability: good with replication
            0.85  // scalability: scales horizontally
        ],
        'metadata' => [
            'name' => 'Redis Cache Configuration',
            'description' => 'Ultra-low-latency distributed caching with persistence',
            'content_type' => 'redis/config',
            'size_bytes' => 1024
        ]
    ],
    [
        'template_id' => 'ml-inference-service',
        'capabilities' => [
            0.30, // storage: low-moderate (model files)
            1.00, // compute: maximum (ML inference)
            0.75, // network: high (API endpoint)
            0.65, // security: moderate
            0.30, // latency: higher latency (compute-bound)
            0.85, // throughput: high request rate
            0.80, // reliability: good
            0.98  // scalability: highly scalable
        ],
        'metadata' => [
            'name' => 'ML Model Inference Service',
            'description' => 'Compute-intensive machine learning inference API endpoint',
            'content_type' => 'application/python',
            'size_bytes' => 8192
        ]
    ]
];

// Build topology structure with templates
// Templates are stored as services with "template:" prefix
$services = [];
foreach ($templates as $template) {
    $service_id = 'template:' . $template['template_id'];
    $services[$service_id] = [
        'point' => $template['capabilities'],
        'dependencies' => [],
        'metadata' => $template['metadata']
    ];
}

// Store topology (this merges with existing services)
echo "Registering " . count($templates) . " service configuration templates...\n\n";

$successCount = 0;
$errorCount = 0;

try {
    // Use geometric_store_topology to store service descriptors
    // Note: This is a low-level operation, no facade exists for topology management
    $result = $client->executeCommand('geometric_store_topology', [
        'data' => [  // IMPORTANT: Wrap in 'data' key to match expected format
            'services' => $services,
            'dimensions' => 8,
            'capability_dimensions' => [
                'storage',
                'compute',
                'network',
                'security',
                'latency',
                'throughput',
                'reliability',
                'scalability'
            ]
        ]
    ]);

    if (isset($result['success']) && $result['success']) {
        $successCount = count($templates);
        echo "✅ Service templates registered successfully!\n\n";

        // Verify by listing templates (using modern TemplateManager facade)
        echo "🔍 Verifying registration...\n";
        try {
            $tm = $client->getTemplateManager();
            $list_result = $tm->discoverTemplatesByCapability([]);

            if (isset($list_result['matches'])) {
                echo "📊 Found " . count($list_result['matches']) . " service templates:\n";
                foreach ($list_result['matches'] as $match) {
                    echo "   ✓ " . ($match['template_id'] ?? $match['service_id'] ?? 'Unknown') . "\n";
                }
            } else {
                echo "⚠️  No templates found in verification (this may be normal if discovery filters differ)\n";
            }
        } catch (Exception $e) {
            echo "⚠️  Verification failed: " . $e->getMessage() . "\n";
            echo "   (Templates may still be registered correctly)\n";
        }

    } else {
        $errorCount = count($templates);
        $errorMsg = $result['error'] ?? $result['message'] ?? 'Unknown error';
        echo "❌ Failed to register templates: $errorMsg\n";

        if (isset($result['details'])) {
            echo "   Details: " . json_encode($result['details']) . "\n";
        }
    }

} catch (\gCore\GSD\Exception\ConnectionException $e) {
    $errorCount = count($templates);
    echo "❌ Connection Error: " . $e->getMessage() . "\n";
    echo "   ℹ️  Make sure the GSD daemon is running:\n";
    echo "      cd ~/gh/GSD && ./scripts/start-daemon.sh\n";
} catch (\gCore\GSD\Exception\GSDException $e) {
    $errorCount = count($templates);
    echo "❌ GSD Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    $errorCount = count($templates);
    echo "❌ Unexpected Error: " . $e->getMessage() . "\n";
    echo "   Stack trace:\n";
    echo "   " . $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "📊 Summary:\n";
echo "   ✅ Successful: $successCount\n";
echo "   ❌ Errors: $errorCount\n";
echo "   📝 Total: " . count($templates) . "\n";
echo str_repeat("=", 60) . "\n\n";

if ($successCount > 0) {
    echo "✅ Demo service template loading complete!\n";
    echo "Open the demo in your browser (demo/index.php) and navigate to:\n";
    echo "  • Overview tab: See template count\n";
    echo "  • Templates tab: Browse and discover templates\n";
    echo "  • Topology tab: Visualize service templates in 3D space\n";
} else {
    echo "❌ No templates were registered successfully.\n";
    echo "Please check the error messages above and ensure the daemon is running.\n";
}
