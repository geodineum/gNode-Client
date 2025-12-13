<?php
/**
 * Populate GSD with actual services in the topology
 *
 * This creates a diverse set of services positioned in 64D space
 * to demonstrate geometric discovery and 3D visualization.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;

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

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Populating GSD Topology with Services                      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// Define services with 8D capability vectors
// Dimensions: [complexity, cacheability, personalization, security, freshness, dependencies, interactivity, render_cost]
$services = [
    // Web Frontend Services
    [
        'service_id' => 'web-static-cdn',
        'capabilities' => [0.1, 0.95, 0.0, 0.1, 0.1, 0.0, 0.0, 0.1],
        'description' => 'Static CDN - high cache, no personalization'
    ],
    [
        'service_id' => 'web-homepage',
        'capabilities' => [0.3, 0.8, 0.2, 0.2, 0.3, 0.1, 0.3, 0.2],
        'description' => 'Homepage - moderate complexity, cached'
    ],
    [
        'service_id' => 'web-user-dashboard',
        'capabilities' => [0.7, 0.1, 0.9, 0.7, 0.8, 0.4, 0.8, 0.6],
        'description' => 'User Dashboard - personalized, low cache'
    ],

    // API Services
    [
        'service_id' => 'api-auth-service',
        'capabilities' => [0.5, 0.2, 0.8, 0.95, 0.9, 0.3, 0.4, 0.4],
        'description' => 'Auth API - high security, personalized'
    ],
    [
        'service_id' => 'api-public-data',
        'capabilities' => [0.2, 0.9, 0.0, 0.1, 0.2, 0.1, 0.1, 0.1],
        'description' => 'Public Data API - high cache, no auth'
    ],
    [
        'service_id' => 'api-user-profile',
        'capabilities' => [0.4, 0.3, 0.95, 0.6, 0.7, 0.2, 0.5, 0.3],
        'description' => 'User Profile API - highly personalized'
    ],

    // Backend Services
    [
        'service_id' => 'backend-payment-processor',
        'capabilities' => [0.8, 0.0, 0.7, 0.99, 0.95, 0.8, 0.6, 0.7],
        'description' => 'Payment - high security, external deps'
    ],
    [
        'service_id' => 'backend-email-sender',
        'capabilities' => [0.3, 0.6, 0.8, 0.4, 0.5, 0.7, 0.2, 0.3],
        'description' => 'Email Service - external SMTP dependency'
    ],
    [
        'service_id' => 'backend-analytics',
        'capabilities' => [0.6, 0.4, 0.6, 0.3, 0.9, 0.5, 0.4, 0.5],
        'description' => 'Analytics - real-time data processing'
    ],

    // Database Services
    [
        'service_id' => 'db-user-primary',
        'capabilities' => [0.4, 0.1, 0.9, 0.8, 0.95, 0.2, 0.3, 0.4],
        'description' => 'Primary DB - fresh data, personalized'
    ],
    [
        'service_id' => 'db-cache-redis',
        'capabilities' => [0.2, 0.95, 0.3, 0.3, 0.6, 0.1, 0.2, 0.1],
        'description' => 'Cache Layer - high performance'
    ],
    [
        'service_id' => 'db-analytics-warehouse',
        'capabilities' => [0.5, 0.7, 0.1, 0.2, 0.3, 0.3, 0.2, 0.6],
        'description' => 'Analytics Warehouse - aggregated data'
    ],

    // Microservices
    [
        'service_id' => 'micro-search-engine',
        'capabilities' => [0.7, 0.5, 0.4, 0.2, 0.7, 0.4, 0.7, 0.8],
        'description' => 'Search - complex algorithms, real-time'
    ],
    [
        'service_id' => 'micro-recommendation',
        'capabilities' => [0.9, 0.3, 0.95, 0.3, 0.8, 0.6, 0.5, 0.9],
        'description' => 'Recommendations - ML-based, personalized'
    ],
    [
        'service_id' => 'micro-notification',
        'capabilities' => [0.4, 0.2, 0.9, 0.5, 0.95, 0.7, 0.6, 0.3],
        'description' => 'Notifications - real-time, personalized'
    ],

    // Admin/Monitoring
    [
        'service_id' => 'admin-panel',
        'capabilities' => [0.6, 0.1, 0.7, 0.9, 0.9, 0.5, 0.8, 0.5],
        'description' => 'Admin Panel - high security, real-time'
    ],
    [
        'service_id' => 'monitoring-metrics',
        'capabilities' => [0.5, 0.3, 0.1, 0.4, 0.99, 0.6, 0.7, 0.4],
        'description' => 'Metrics - real-time monitoring'
    ],
    [
        'service_id' => 'monitoring-logs',
        'capabilities' => [0.4, 0.5, 0.0, 0.3, 0.8, 0.4, 0.3, 0.3],
        'description' => 'Log Aggregation - searchable logs'
    ],

    // Content Delivery
    [
        'service_id' => 'cdn-images',
        'capabilities' => [0.1, 0.99, 0.0, 0.1, 0.0, 0.0, 0.0, 0.1],
        'description' => 'Image CDN - static, highly cached'
    ],
    [
        'service_id' => 'cdn-videos',
        'capabilities' => [0.2, 0.95, 0.1, 0.1, 0.1, 0.2, 0.1, 0.3],
        'description' => 'Video CDN - streaming, cached'
    ],
    [
        'service_id' => 'content-api-blog',
        'capabilities' => [0.3, 0.8, 0.1, 0.2, 0.4, 0.2, 0.2, 0.2],
        'description' => 'Blog Content API - moderately cached'
    ],
];

echo "📍 Registering " . count($services) . " services in geometric topology...\n\n";

$registered = 0;
$failed = 0;

foreach ($services as $i => $service) {
    try {
        // Use geometric_store_topology to register service with capabilities
        $result = $client->executeCommand('geometric_store_topology', [
            'services' => [
                [
                    'service_id' => $service['service_id'],
                    'capabilities' => $service['capabilities']
                ]
            ]
        ]);

        echo sprintf("✓ [%2d/%2d] %-25s %s\n",
            $i + 1,
            count($services),
            $service['service_id'],
            $service['description']
        );
        $registered++;

    } catch (Exception $e) {
        echo sprintf("✗ [%2d/%2d] %-25s FAILED: %s\n",
            $i + 1,
            count($services),
            $service['service_id'],
            $e->getMessage()
        );
        $failed++;
    }
}

echo "\n" . str_repeat("=", 64) . "\n";
echo "✅ Registration Complete!\n\n";
echo "Summary:\n";
echo "  • Services registered: $registered\n";
echo "  • Failed: $failed\n";
echo "  • Total in topology: $registered\n\n";

echo "Topology Distribution:\n";
echo "  • Web Frontend: 3 services\n";
echo "  • API Layer: 3 services\n";
echo "  • Backend: 3 services\n";
echo "  • Databases: 3 services\n";
echo "  • Microservices: 3 services\n";
echo "  • Admin/Monitoring: 3 services\n";
echo "  • Content Delivery: 3 services\n\n";

echo "You should now see services in the 3D topology visualization!\n";
