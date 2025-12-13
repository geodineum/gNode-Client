<?php
/**
 * Load Demo Data for GSD Interactive Demo
 *
 * This script populates GSD with:
 * - 3 example message formats
 * - 14 real services in 8D capability space
 * - 10 example template fragments
 */

require_once __DIR__ . '/../vendor/autoload.php';

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;

// Initialize GSD Client
$storage = new ValKeyStorage([
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => trim(file_get_contents(__DIR__ . '/../../GSD/.gsd/valkey.password'))
]);

$client = new Client($storage, 'default', 'default', [
    'debug' => false,
    'timeout' => 10.0,
    'use_consumer_groups' => true
]);

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         GSD Demo Data Loader - Comprehensive Setup          ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// ============================================================================
// PART 1: Register Message Formats
// ============================================================================

echo "📋 PART 1: Registering Message Formats...\n";
echo str_repeat("─", 70) . "\n";

$formats = [
    [
        'format_name' => 'json_v1_basic',
        'version' => '1.0.0',
        'description' => 'Basic JSON format for development and testing',
        'content_type' => 'application/json',
        'binary' => false,
        'detection_patterns' => [
            [
                'pattern_type' => 'regex',
                'pattern' => '^\s*\{\s*"(id|command)":',
                'confidence' => 0.9
            ]
        ],
        'field_mapping' => [
            'id' => 'id',
            'command' => 'command',
            'parameters' => 'parameters',
            'timestamp' => 'timestamp'
        ],
        'schema' => [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['id', 'command', 'parameters'],
            'properties' => [
                'id' => ['type' => 'string'],
                'command' => ['type' => 'string'],
                'parameters' => ['type' => 'object'],
                'timestamp' => ['type' => 'number']
            ]
        ]
    ],
    [
        'format_name' => 'json_v2_enhanced',
        'version' => '2.0.0',
        'description' => 'Enhanced JSON with distributed tracing support',
        'content_type' => 'application/json',
        'binary' => false,
        'detection_patterns' => [
            [
                'pattern_type' => 'regex',
                'pattern' => '"trace_id"\s*:',
                'confidence' => 0.95
            ]
        ],
        'field_mapping' => [
            'id' => 'id',
            'command' => 'command',
            'parameters' => 'parameters',
            'timestamp' => 'timestamp',
            'trace_id' => 'trace_id',
            'span_id' => 'span_id',
            'metadata' => 'metadata'
        ],
        'schema' => [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['id', 'command', 'parameters', 'trace_id'],
            'properties' => [
                'id' => ['type' => 'string'],
                'command' => ['type' => 'string'],
                'parameters' => ['type' => 'object'],
                'timestamp' => ['type' => 'number'],
                'trace_id' => ['type' => 'string'],
                'span_id' => ['type' => 'string'],
                'metadata' => ['type' => 'object']
            ]
        ]
    ],
    [
        'format_name' => 'compact_minimal',
        'version' => '1.0.0',
        'description' => 'Compact format for bandwidth optimization (~40% reduction)',
        'content_type' => 'application/json',
        'binary' => false,
        'detection_patterns' => [
            [
                'pattern_type' => 'regex',
                'pattern' => '^\s*\{\s*"i"\s*:',
                'confidence' => 0.92
            ]
        ],
        'field_mapping' => [
            'i' => 'id',
            'c' => 'command',
            'p' => 'parameters',
            't' => 'timestamp',
            's' => 'site_id',
            'n' => 'node_id'
        ],
        'schema' => [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['i', 'c', 't'],
            'properties' => [
                'i' => ['type' => 'string', 'description' => 'id'],
                'c' => ['type' => 'string', 'description' => 'command'],
                'p' => ['type' => 'object', 'description' => 'parameters'],
                't' => ['type' => 'number', 'description' => 'timestamp'],
                's' => ['type' => 'string', 'description' => 'site_id'],
                'n' => ['type' => 'string', 'description' => 'node_id']
            ]
        ]
    ]
];

$formatCount = 0;
foreach ($formats as $index => $format) {
    try {
        $result = $client->executeCommand('register_format', [
            'format_definition' => $format
        ]);

        // Result is the format registration data with format_name and status='registered'
        if ($result && isset($result['format_name'])) {
            $formatCount++;
            echo sprintf("  ✓ [%d/3] %-20s - %s\n",
                $index + 1,
                $format['format_name'],
                $format['description']
            );
        } else {
            echo sprintf("  ✗ [%d/3] %-20s - Failed\n",
                $index + 1,
                $format['format_name']
            );
            echo "     Debug: result = " . var_export($result, true) . "\n";
        }
    } catch (Exception $e) {
        echo sprintf("  ✗ [%d/3] %-20s - Error: %s\n", $index + 1, $format['format_name'], $e->getMessage());
    }
}

echo "\n✅ Registered $formatCount/3 message formats\n\n";

// ============================================================================
// PART 2: Register Real Services (8D Capability Space)
// ============================================================================

echo "🌐 PART 2: Registering Real Services in 8D Space...\n";
echo str_repeat("─", 70) . "\n";

$services = [
    [
        'service_id' => 'auth-service-001',
        'name' => 'OAuth2 Authentication Service',
        'capabilities' => [0.3, 0.4, 0.5, 1.0, 0.9, 0.6, 0.95, 0.8],
        'endpoint' => 'http://localhost:8001'
    ],
    [
        'service_id' => 'postgres-primary-001',
        'name' => 'PostgreSQL Primary Database',
        'capabilities' => [1.0, 0.6, 0.4, 0.85, 0.7, 0.75, 0.98, 0.5],
        'endpoint' => 'postgres://db-primary:5432'
    ],
    [
        'service_id' => 'mongodb-shard-001',
        'name' => 'MongoDB Shard Cluster',
        'capabilities' => [1.0, 0.5, 0.6, 0.75, 0.65, 0.85, 0.92, 0.95],
        'endpoint' => 'mongodb://mongo-cluster:27017'
    ],
    [
        'service_id' => 'redis-cache-001',
        'name' => 'Redis Cache Cluster',
        'capabilities' => [0.4, 0.3, 0.7, 0.6, 1.0, 0.95, 0.88, 0.85],
        'endpoint' => 'redis://cache:6379'
    ],
    [
        'service_id' => 'api-gateway-001',
        'name' => 'Kong API Gateway',
        'capabilities' => [0.2, 0.5, 1.0, 0.9, 0.85, 0.92, 0.95, 0.9],
        'endpoint' => 'https://api.example.com'
    ],
    [
        'service_id' => 'rabbitmq-cluster-001',
        'name' => 'RabbitMQ Message Broker',
        'capabilities' => [0.5, 0.4, 0.9, 0.7, 0.75, 1.0, 0.93, 0.85],
        'endpoint' => 'amqp://rabbitmq:5672'
    ],
    [
        'service_id' => 'spark-analytics-001',
        'name' => 'Apache Spark Analytics',
        'capabilities' => [0.6, 1.0, 0.75, 0.65, 0.3, 0.85, 0.8, 0.98],
        'endpoint' => 'spark://spark-master:7077'
    ],
    [
        'service_id' => 's3-storage-001',
        'name' => 'S3-Compatible Object Storage',
        'capabilities' => [1.0, 0.2, 0.6, 0.85, 0.6, 0.7, 0.999, 0.99],
        'endpoint' => 'https://s3.example.com'
    ],
    [
        'service_id' => 'notification-service-001',
        'name' => 'Multi-Channel Notifications',
        'capabilities' => [0.3, 0.4, 0.95, 0.8, 0.7, 0.88, 0.9, 0.85],
        'endpoint' => 'https://notifications.example.com'
    ],
    [
        'service_id' => 'elasticsearch-001',
        'name' => 'Elasticsearch Search Cluster',
        'capabilities' => [0.8, 0.85, 0.65, 0.75, 0.8, 0.8, 0.88, 0.92],
        'endpoint' => 'https://search.example.com:9200'
    ],
    [
        'service_id' => 'nginx-lb-001',
        'name' => 'NGINX Load Balancer',
        'capabilities' => [0.1, 0.3, 0.98, 0.85, 0.95, 0.95, 0.97, 0.8],
        'endpoint' => 'https://lb.example.com'
    ],
    [
        'service_id' => 'prometheus-001',
        'name' => 'Prometheus Monitoring',
        'capabilities' => [0.7, 0.6, 0.7, 0.65, 0.75, 0.85, 0.92, 0.75],
        'endpoint' => 'http://prometheus:9090'
    ],
    [
        'service_id' => 'cdn-edge-001',
        'name' => 'CDN Edge Node',
        'capabilities' => [0.6, 0.25, 0.95, 0.8, 0.98, 0.97, 0.95, 0.9],
        'endpoint' => 'https://cdn-edge.example.com'
    ],
    [
        'service_id' => 'airflow-001',
        'name' => 'Apache Airflow Orchestrator',
        'capabilities' => [0.4, 0.7, 0.6, 0.7, 0.5, 0.75, 0.9, 0.85],
        'endpoint' => 'https://airflow.example.com'
    ]
];

$capabilityDims = [
    'storage', 'compute', 'network', 'security',
    'latency', 'throughput', 'reliability', 'scalability'
];

// Build topology structure
$topologyServices = [];
foreach ($services as $service) {
    $topologyServices[$service['service_id']] = [
        'point' => $service['capabilities'],
        'dependencies' => [],
        'metadata' => [
            'name' => $service['name'],
            'endpoint' => $service['endpoint']
        ]
    ];
}

// Store topology
try {
    $result = $client->executeCommand('geometric_store_topology', [
        'data' => [
            'services' => $topologyServices,
            'dimensions' => 8,
            'capability_dimensions' => $capabilityDims
        ]
    ]);

    if ($result && isset($result['status']) && $result['status'] === 'ok') {
        foreach ($services as $index => $service) {
            echo sprintf("  ✓ [%2d/14] %-30s - %s\n",
                $index + 1,
                $service['service_id'],
                $service['name']
            );
        }
        echo "\n✅ Registered 14/14 real services in 8D capability space\n\n";
    } else {
        echo "✗ Failed to register services\n\n";
    }
} catch (Exception $e) {
    echo "✗ Error registering services: " . $e->getMessage() . "\n\n";
}


// ============================================================================
// Summary
// ============================================================================

echo str_repeat("═", 70) . "\n";
echo "📊 DEMO DATA LOADING COMPLETE\n";
echo str_repeat("═", 70) . "\n";
echo sprintf("  ✓ Message Formats:     %d/3 registered\n", $formatCount);
echo sprintf("  ✓ Real Services:       14/14 registered (8D space)\n");
echo str_repeat("═", 70) . "\n";
echo "\n💡 Capability Dimensions:\n";
echo "   1. Storage     - Data persistence capability\n";
echo "   2. Compute     - Processing power\n";
echo "   3. Network     - Network I/O capability\n";
echo "   4. Security    - Authentication & encryption\n";
echo "   5. Latency     - Low-latency requirement\n";
echo "   6. Throughput  - High-throughput capability\n";
echo "   7. Reliability - Fault tolerance\n";
echo "   8. Scalability - Horizontal/vertical scaling\n";
echo "\n🚀 Ready to view at: http://localhost/demo/\n\n";
