<?php
/**
 * Load ALL Demo Data - Combined Services + Templates
 *
 * This script loads services AND templates in a single topology operation
 * to work around the current limitation where geometric_store_topology replaces instead of merging.
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

echo "Loading complete demo data (services + templates)...\n\n";

// 8D Capability Dimensions
$capabilityDims = [
    'storage', 'compute', 'network', 'security',
    'latency', 'throughput', 'reliability', 'scalability'
];

// ============= SERVICES (14) =============
$services = [
    ['service_id' => 'auth-service-001', 'name' => 'OAuth2 Authentication Service', 'capabilities' => [0.3, 0.4, 0.5, 1.0, 0.9, 0.6, 0.95, 0.8], 'endpoint' => 'http://localhost:8001'],
    ['service_id' => 'postgres-primary-001', 'name' => 'PostgreSQL Primary Database', 'capabilities' => [1.0, 0.6, 0.4, 0.85, 0.7, 0.75, 0.98, 0.5], 'endpoint' => 'postgres://db-primary:5432'],
    ['service_id' => 'mongodb-shard-001', 'name' => 'MongoDB Shard Cluster', 'capabilities' => [1.0, 0.5, 0.6, 0.75, 0.65, 0.85, 0.92, 0.95], 'endpoint' => 'mongodb://mongo-cluster:27017'],
    ['service_id' => 'redis-cache-001', 'name' => 'Redis Cache Cluster', 'capabilities' => [0.4, 0.3, 0.7, 0.6, 1.0, 0.95, 0.88, 0.85], 'endpoint' => 'redis://cache:6379'],
    ['service_id' => 'api-gateway-001', 'name' => 'Kong API Gateway', 'capabilities' => [0.2, 0.5, 1.0, 0.9, 0.85, 0.92, 0.95, 0.9], 'endpoint' => 'https://api.example.com'],
    ['service_id' => 'rabbitmq-cluster-001', 'name' => 'RabbitMQ Message Broker', 'capabilities' => [0.5, 0.4, 0.9, 0.7, 0.75, 1.0, 0.93, 0.85], 'endpoint' => 'amqp://rabbitmq:5672'],
    ['service_id' => 'spark-analytics-001', 'name' => 'Apache Spark Analytics', 'capabilities' => [0.6, 1.0, 0.75, 0.65, 0.3, 0.85, 0.8, 0.98], 'endpoint' => 'spark://spark-master:7077'],
    ['service_id' => 's3-storage-001', 'name' => 'S3-Compatible Object Storage', 'capabilities' => [1.0, 0.2, 0.6, 0.85, 0.6, 0.7, 0.999, 0.99], 'endpoint' => 'https://s3.example.com'],
    ['service_id' => 'notification-service-001', 'name' => 'Multi-Channel Notifications', 'capabilities' => [0.3, 0.4, 0.95, 0.8, 0.7, 0.88, 0.9, 0.85], 'endpoint' => 'https://notifications.example.com'],
    ['service_id' => 'elasticsearch-001', 'name' => 'Elasticsearch Search Cluster', 'capabilities' => [0.8, 0.85, 0.65, 0.75, 0.8, 0.8, 0.88, 0.92], 'endpoint' => 'https://search.example.com:9200'],
    ['service_id' => 'nginx-lb-001', 'name' => 'NGINX Load Balancer', 'capabilities' => [0.1, 0.3, 0.98, 0.85, 0.95, 0.95, 0.97, 0.8], 'endpoint' => 'https://lb.example.com'],
    ['service_id' => 'prometheus-001', 'name' => 'Prometheus Monitoring', 'capabilities' => [0.7, 0.6, 0.7, 0.65, 0.75, 0.85, 0.92, 0.75], 'endpoint' => 'http://prometheus:9090'],
    ['service_id' => 'cdn-edge-001', 'name' => 'CDN Edge Node', 'capabilities' => [0.6, 0.25, 0.95, 0.8, 0.98, 0.97, 0.95, 0.9], 'endpoint' => 'https://cdn-edge.example.com'],
    ['service_id' => 'airflow-001', 'name' => 'Apache Airflow Orchestrator', 'capabilities' => [0.4, 0.7, 0.6, 0.7, 0.5, 0.75, 0.9, 0.85], 'endpoint' => 'https://airflow.example.com'],
];

// ============= TEMPLATES (5) =============
$templates = [
    ['template_id' => 'nginx-reverse-proxy', 'name' => 'NGINX Reverse Proxy Configuration', 'capabilities' => [0.1, 0.3, 0.98, 0.85, 0.95, 0.95, 0.97, 0.80], 'type' => 'nginx/config'],
    ['template_id' => 'postgres-connection-pool', 'name' => 'PostgreSQL Connection Pool Configuration', 'capabilities' => [0.95, 0.50, 0.60, 0.85, 0.70, 0.80, 0.95, 0.90], 'type' => 'application/json'],
    ['template_id' => 'rest-api-crud-endpoint', 'name' => 'RESTful CRUD API Endpoint Template', 'capabilities' => [0.40, 0.50, 0.80, 0.90, 0.85, 0.85, 0.90, 0.95], 'type' => 'application/python'],
    ['template_id' => 'redis-cache-config', 'name' => 'Redis Cache Configuration', 'capabilities' => [0.40, 0.30, 0.70, 0.60, 1.00, 0.95, 0.88, 0.85], 'type' => 'redis/config'],
    ['template_id' => 'ml-inference-service', 'name' => 'ML Model Inference Service', 'capabilities' => [0.30, 1.00, 0.75, 0.65, 0.30, 0.85, 0.80, 0.98], 'type' => 'application/python'],
];

// Build combined topology structure
$topologyServices = [];

// Add services
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

// Add templates (with "template:" prefix)
foreach ($templates as $template) {
    $service_id = 'template:' . $template['template_id'];
    $topologyServices[$service_id] = [
        'point' => $template['capabilities'],
        'dependencies' => [],
        'metadata' => [
            'name' => $template['name'],
            'content_type' => $template['type']
        ]
    ];
}

// Store combined topology
try {
    $result = $client->executeCommand('geometric_store_topology', [
        'data' => [
            'services' => $topologyServices,
            'dimensions' => 8,
            'capability_dimensions' => $capabilityDims
        ]
    ]);

    if ($result && isset($result['status']) && $result['status'] === 'ok') {
        echo "✅ Registered " . count($services) . " services + " . count($templates) . " templates = " . count($topologyServices) . " total\n\n";
        echo "Demo is ready at: http://localhost:9000/\n";
    } else {
        echo "❌ Failed to register topology\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
