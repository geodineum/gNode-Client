<?php
/**
 * Register Real Services for Geometric Service Mesh Demo
 *
 * This script populates GSD with realistic service registrations demonstrating
 * the geometric service discovery capabilities. Services are positioned in an
 * 8-dimensional capability space for O(1) discovery.
 *
 * Capability Dimensions (0.0 - 1.0):
 * 1. storage      - Data persistence and storage capability
 * 2. compute      - Processing power and computational capability
 * 3. network      - Network I/O and communication capability
 * 4. security     - Authentication, encryption, access control
 * 5. latency      - Low-latency requirement (inverse: 1.0 = real-time)
 * 6. throughput   - High-throughput capability
 * 7. reliability  - Fault tolerance and availability
 * 8. scalability  - Horizontal and vertical scaling capability
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

echo "✓ Initialized GSD client\n\n";

/**
 * Real service definitions with geometric capabilities
 */
$services = [
    // Authentication & Security Services
    [
        'service_id' => 'auth-service-001',
        'name' => 'OAuth2 Authentication Service',
        'description' => 'Handles user authentication, session management, and OAuth2 flows',
        'endpoint' => 'http://localhost:8001',
        'capabilities' => [
            'storage' => 0.3,      // Moderate storage for session data
            'compute' => 0.4,      // Token generation and validation
            'network' => 0.5,      // API calls to external providers
            'security' => 1.0,     // PRIMARY: Security is core capability
            'latency' => 0.9,      // Must respond quickly for auth
            'throughput' => 0.6,   // Moderate concurrent auth requests
            'reliability' => 0.95, // Critical service, high reliability
            'scalability' => 0.8   // Stateless, scales well
        ],
        'metadata' => [
            'version' => '2.4.0',
            'protocol' => 'HTTPS',
            'supported_oauth' => ['google', 'github', 'microsoft'],
            'max_sessions' => 100000
        ]
    ],

    // Database Services
    [
        'service_id' => 'postgres-primary-001',
        'name' => 'PostgreSQL Primary Database',
        'description' => 'Primary relational database for transactional workloads',
        'endpoint' => 'postgres://db-primary:5432',
        'capabilities' => [
            'storage' => 1.0,      // PRIMARY: Storage is core capability
            'compute' => 0.6,      // Query processing
            'network' => 0.4,      // Internal network only
            'security' => 0.85,    // Encryption at rest, SSL
            'latency' => 0.7,      // Good latency for OLTP
            'throughput' => 0.75,  // High transaction throughput
            'reliability' => 0.98, // Mission-critical reliability
            'scalability' => 0.5   // Vertical scaling primarily
        ],
        'metadata' => [
            'version' => 'PostgreSQL 15.3',
            'max_connections' => 200,
            'storage_capacity_gb' => 5000,
            'replication' => 'streaming'
        ]
    ],

    [
        'service_id' => 'mongodb-shard-001',
        'name' => 'MongoDB Shard Cluster',
        'description' => 'Horizontally sharded MongoDB cluster for document storage',
        'endpoint' => 'mongodb://mongo-cluster:27017',
        'capabilities' => [
            'storage' => 1.0,      // PRIMARY: Storage capability
            'compute' => 0.5,      // Aggregation pipeline processing
            'network' => 0.6,      // Distributed queries
            'security' => 0.75,    // Role-based access control
            'latency' => 0.65,     // Good read latency
            'throughput' => 0.85,  // Very high write throughput
            'reliability' => 0.92, // High availability with replica sets
            'scalability' => 0.95  // Excellent horizontal scaling
        ],
        'metadata' => [
            'version' => 'MongoDB 7.0',
            'shards' => 6,
            'replicas_per_shard' => 3,
            'storage_capacity_tb' => 50
        ]
    ],

    // Caching Services
    [
        'service_id' => 'redis-cache-001',
        'name' => 'Redis Cache Cluster',
        'description' => 'In-memory cache for ultra-low latency data access',
        'endpoint' => 'redis://cache:6379',
        'capabilities' => [
            'storage' => 0.4,      // Memory-based storage
            'compute' => 0.3,      // Simple operations
            'network' => 0.7,      // High network utilization
            'security' => 0.6,     // Basic auth and encryption
            'latency' => 1.0,      // PRIMARY: Sub-millisecond latency
            'throughput' => 0.95,  // Extremely high throughput
            'reliability' => 0.88, // Good reliability with persistence
            'scalability' => 0.85  // Horizontal scaling with cluster
        ],
        'metadata' => [
            'version' => 'Redis 7.2',
            'memory_gb' => 128,
            'eviction_policy' => 'allkeys-lru',
            'persistence' => 'AOF'
        ]
    ],

    // API Gateway & Routing
    [
        'service_id' => 'api-gateway-001',
        'name' => 'Kong API Gateway',
        'description' => 'API gateway for routing, rate limiting, and load balancing',
        'endpoint' => 'https://api.example.com',
        'capabilities' => [
            'storage' => 0.2,      // Minimal storage
            'compute' => 0.5,      // Request transformation
            'network' => 1.0,      // PRIMARY: Network routing capability
            'security' => 0.9,     // SSL termination, auth, rate limiting
            'latency' => 0.85,     // Low overhead routing
            'throughput' => 0.92,  // Very high request throughput
            'reliability' => 0.95, // Critical ingress point
            'scalability' => 0.9   // Stateless, scales horizontally
        ],
        'metadata' => [
            'version' => 'Kong 3.4',
            'plugins' => ['rate-limiting', 'jwt', 'cors', 'oauth2'],
            'max_rps' => 50000
        ]
    ],

    // Message Queue Services
    [
        'service_id' => 'rabbitmq-cluster-001',
        'name' => 'RabbitMQ Message Broker',
        'description' => 'Message queue for asynchronous communication between services',
        'endpoint' => 'amqp://rabbitmq:5672',
        'capabilities' => [
            'storage' => 0.5,      // Message persistence
            'compute' => 0.4,      // Message routing logic
            'network' => 0.9,      // High network I/O for messaging
            'security' => 0.7,     // TLS, SASL authentication
            'latency' => 0.75,     // Good message delivery latency
            'throughput' => 1.0,   // PRIMARY: Very high message throughput
            'reliability' => 0.93, // Message durability and acknowledgments
            'scalability' => 0.85  // Cluster-based scaling
        ],
        'metadata' => [
            'version' => 'RabbitMQ 3.12',
            'cluster_nodes' => 3,
            'max_messages_per_sec' => 100000,
            'protocols' => ['AMQP', 'MQTT', 'STOMP']
        ]
    ],

    // Analytics & Processing
    [
        'service_id' => 'spark-analytics-001',
        'name' => 'Apache Spark Analytics Engine',
        'description' => 'Distributed data processing and analytics platform',
        'endpoint' => 'spark://spark-master:7077',
        'capabilities' => [
            'storage' => 0.6,      // HDFS integration
            'compute' => 1.0,      // PRIMARY: Massive parallel processing
            'network' => 0.75,     // Distributed data shuffling
            'security' => 0.65,    // Kerberos authentication
            'latency' => 0.3,      // Batch processing, not real-time
            'throughput' => 0.85,  // High data throughput
            'reliability' => 0.8,  // Fault tolerance with retries
            'scalability' => 0.98  // Excellent horizontal scaling
        ],
        'metadata' => [
            'version' => 'Spark 3.5',
            'executors' => 50,
            'cores_per_executor' => 8,
            'memory_per_executor_gb' => 32
        ]
    ],

    // File Storage
    [
        'service_id' => 's3-storage-001',
        'name' => 'S3-Compatible Object Storage',
        'description' => 'Distributed object storage for files, images, and backups',
        'endpoint' => 'https://s3.example.com',
        'capabilities' => [
            'storage' => 1.0,      // PRIMARY: Massive storage capacity
            'compute' => 0.2,      // Minimal processing
            'network' => 0.6,      // Network for uploads/downloads
            'security' => 0.85,    // IAM, encryption, versioning
            'latency' => 0.6,      // Acceptable for large files
            'throughput' => 0.7,   // Good for large file transfers
            'reliability' => 0.999, // Eleven 9s durability
            'scalability' => 0.99  // Virtually unlimited scaling
        ],
        'metadata' => [
            'version' => 'MinIO RELEASE.2024',
            'storage_capacity_pb' => 100,
            'replication' => 'erasure-coding',
            'availability_zones' => 3
        ]
    ],

    // Notification Services
    [
        'service_id' => 'notification-service-001',
        'name' => 'Multi-Channel Notification Service',
        'description' => 'Sends notifications via email, SMS, push, and webhooks',
        'endpoint' => 'https://notifications.example.com',
        'capabilities' => [
            'storage' => 0.3,      // Template and queue storage
            'compute' => 0.4,      // Template rendering
            'network' => 0.95,     // PRIMARY: High external communication
            'security' => 0.8,     // API keys, encrypted channels
            'latency' => 0.7,      // Quick notification dispatch
            'throughput' => 0.88,  // High volume notifications
            'reliability' => 0.9,  // Delivery guarantees with retries
            'scalability' => 0.85  // Horizontal scaling
        ],
        'metadata' => [
            'version' => '1.8.0',
            'channels' => ['email', 'sms', 'push', 'webhook'],
            'max_notifications_per_hour' => 1000000,
            'providers' => ['sendgrid', 'twilio', 'fcm']
        ]
    ],

    // Search Services
    [
        'service_id' => 'elasticsearch-001',
        'name' => 'Elasticsearch Search Cluster',
        'description' => 'Full-text search and analytics engine',
        'endpoint' => 'https://search.example.com:9200',
        'capabilities' => [
            'storage' => 0.8,      // Inverted index storage
            'compute' => 0.85,     // Search and aggregation compute
            'network' => 0.65,     // Distributed search
            'security' => 0.75,    // X-Pack security
            'latency' => 0.8,      // Fast search response
            'throughput' => 0.8,   // High query throughput
            'reliability' => 0.88, // Replica shards
            'scalability' => 0.92  // Excellent horizontal scaling
        ],
        'metadata' => [
            'version' => 'Elasticsearch 8.10',
            'nodes' => 9,
            'shards' => 27,
            'replicas' => 2,
            'index_size_tb' => 10
        ]
    ],

    // Load Balancer
    [
        'service_id' => 'nginx-lb-001',
        'name' => 'NGINX Load Balancer',
        'description' => 'Layer 7 load balancer and reverse proxy',
        'endpoint' => 'https://lb.example.com',
        'capabilities' => [
            'storage' => 0.1,      // Minimal storage
            'compute' => 0.3,      // Request parsing
            'network' => 0.98,     // PRIMARY: Network routing
            'security' => 0.85,    // SSL/TLS termination, WAF
            'latency' => 0.95,     // Ultra-low latency overhead
            'throughput' => 0.95,  // Very high request throughput
            'reliability' => 0.97, // High availability pairs
            'scalability' => 0.8   // Active-passive clustering
        ],
        'metadata' => [
            'version' => 'NGINX Plus R30',
            'max_connections' => 100000,
            'algorithms' => ['round-robin', 'least-conn', 'ip-hash'],
            'health_checks' => true
        ]
    ],

    // Monitoring Service
    [
        'service_id' => 'prometheus-001',
        'name' => 'Prometheus Monitoring',
        'description' => 'Metrics collection and alerting platform',
        'endpoint' => 'http://prometheus:9090',
        'capabilities' => [
            'storage' => 0.7,      // Time-series data storage
            'compute' => 0.6,      // Query processing and aggregation
            'network' => 0.7,      // Metrics scraping
            'security' => 0.65,    // Basic auth, TLS
            'latency' => 0.75,     // Query response time
            'throughput' => 0.85,  // High metrics ingestion rate
            'reliability' => 0.92, // Critical for observability
            'scalability' => 0.75  // Federation for scaling
        ],
        'metadata' => [
            'version' => 'Prometheus 2.47',
            'retention_days' => 15,
            'scrape_interval_sec' => 15,
            'metrics_per_sec' => 500000
        ]
    ],

    // CDN Edge Service
    [
        'service_id' => 'cdn-edge-001',
        'name' => 'CDN Edge Node',
        'description' => 'Content delivery network edge cache',
        'endpoint' => 'https://cdn-edge.example.com',
        'capabilities' => [
            'storage' => 0.6,      // Edge cache storage
            'compute' => 0.25,     // Minimal processing
            'network' => 0.95,     // PRIMARY: High bandwidth network
            'security' => 0.8,     // DDoS protection, SSL
            'latency' => 0.98,     // Ultra-low latency delivery
            'throughput' => 0.97,  // Very high bandwidth
            'reliability' => 0.95, // Geographic redundancy
            'scalability' => 0.9   // Global distribution
        ],
        'metadata' => [
            'version' => 'Varnish 7.4',
            'cache_size_tb' => 50,
            'pop_location' => 'US-EAST-1',
            'bandwidth_gbps' => 100
        ]
    ],

    // Workflow Orchestration
    [
        'service_id' => 'airflow-001',
        'name' => 'Apache Airflow Orchestrator',
        'description' => 'Workflow orchestration and scheduling platform',
        'endpoint' => 'https://airflow.example.com',
        'capabilities' => [
            'storage' => 0.4,      // DAG and metadata storage
            'compute' => 0.7,      // Task execution coordination
            'network' => 0.6,      // Distributed task execution
            'security' => 0.7,     // RBAC, auth integration
            'latency' => 0.5,      // Scheduled workflows, not real-time
            'throughput' => 0.75,  // High concurrent workflows
            'reliability' => 0.9,  // Critical for data pipelines
            'scalability' => 0.85  // Horizontal worker scaling
        ],
        'metadata' => [
            'version' => 'Airflow 2.7',
            'workers' => 20,
            'max_concurrent_dags' => 100,
            'executor' => 'CeleryExecutor'
        ]
    ]
];

// Build topology structure with all services
$topologyServices = [];

foreach ($services as $service) {
    $topologyServices[$service['service_id']] = [
        'point' => array_values($service['capabilities']), // Convert to indexed array
        'dependencies' => [],
        'metadata' => array_merge(
            [
                'name' => $service['name'],
                'description' => $service['description'],
                'endpoint' => $service['endpoint']
            ],
            $service['metadata']
        )
    ];
}

// Register all services via geometric_store_topology
$registered = 0;
$failed = 0;

echo "Registering real services in geometric capability space...\n\n";

try {
    $result = $client->executeCommand('geometric_store_topology', [
        'data' => [
            'services' => $topologyServices,
            'dimensions' => 8,
            'capability_dimensions' => [
                'storage', 'compute', 'network', 'security',
                'latency', 'throughput', 'reliability', 'scalability'
            ]
        ]
    ]);

    if ($result && isset($result['status']) && $result['status'] === 'ok') {
        $registered = count($services);
        foreach ($services as $index => $service) {
            echo sprintf(
                "✓ [%2d/%2d] %-30s - %s\n",
                $index + 1,
                count($services),
                $service['service_id'],
                $service['name']
            );
        }
    } else {
        $failed = count($services);
        echo "✗ Failed to register services: " . json_encode($result) . "\n";
    }
} catch (Exception $e) {
    $failed = count($services);
    echo "✗ Failed to register services: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "Service Registration Summary:\n";
echo "  ✓ Registered: $registered services\n";
if ($failed > 0) {
    echo "  ✗ Failed:     $failed services\n";
}
echo str_repeat("=", 70) . "\n\n";

// Demonstrate service discovery based on capabilities
echo "Demonstrating geometric service discovery...\n\n";

$discoveryTests = [
    [
        'name' => 'High Storage Services',
        'description' => 'Services with strong storage capabilities',
        'capabilities' => [
            'storage' => 0.9,
            'compute' => 0.0,
            'network' => 0.0,
            'security' => 0.0,
            'latency' => 0.0,
            'throughput' => 0.0,
            'reliability' => 0.0,
            'scalability' => 0.0
        ]
    ],
    [
        'name' => 'Real-time Low-Latency Services',
        'description' => 'Services optimized for ultra-low latency',
        'capabilities' => [
            'storage' => 0.0,
            'compute' => 0.0,
            'network' => 0.0,
            'security' => 0.0,
            'latency' => 0.95,
            'throughput' => 0.0,
            'reliability' => 0.0,
            'scalability' => 0.0
        ]
    ],
    [
        'name' => 'High Security Services',
        'description' => 'Services with strong security capabilities',
        'capabilities' => [
            'storage' => 0.0,
            'compute' => 0.0,
            'network' => 0.0,
            'security' => 0.9,
            'latency' => 0.0,
            'throughput' => 0.0,
            'reliability' => 0.0,
            'scalability' => 0.0
        ]
    ],
    [
        'name' => 'High Throughput Services',
        'description' => 'Services optimized for high message/request throughput',
        'capabilities' => [
            'storage' => 0.0,
            'compute' => 0.0,
            'network' => 0.0,
            'security' => 0.0,
            'latency' => 0.0,
            'throughput' => 0.9,
            'reliability' => 0.0,
            'scalability' => 0.0
        ]
    ],
    [
        'name' => 'Compute-Intensive Services',
        'description' => 'Services with high computational capabilities',
        'capabilities' => [
            'storage' => 0.0,
            'compute' => 0.9,
            'network' => 0.0,
            'security' => 0.0,
            'latency' => 0.0,
            'throughput' => 0.0,
            'reliability' => 0.0,
            'scalability' => 0.0
        ]
    ]
];

foreach ($discoveryTests as $test) {
    echo "Finding: {$test['name']}\n";
    echo "  → {$test['description']}\n";

    try {
        $result = $client->executeCommand('geometric_discover', [
            'capabilities' => $test['capabilities']
        ]);

        if ($result && isset($result['matches'])) {
            $matches = $result['matches'];
            echo "  → Found " . count($matches) . " matching service(s):\n";

            foreach (array_slice($matches, 0, 5) as $match) {
                $serviceId = $match['service_id'] ?? 'unknown';
                $distance = isset($match['distance']) ? sprintf("%.4f", $match['distance']) : 'N/A';
                echo "    • $serviceId (distance: $distance)\n";
            }

            if (count($matches) > 5) {
                echo "    ... and " . (count($matches) - 5) . " more\n";
            }
        } else {
            echo "  → No matches found\n";
        }
    } catch (Exception $e) {
        echo "  ✗ Discovery failed: " . $e->getMessage() . "\n";
    }

    echo "\n";
}

echo str_repeat("=", 70) . "\n";
echo "Geometric Service Mesh Demo Complete!\n";
echo "\n";
echo "Registered " . $registered . " real services across 8-dimensional capability space:\n";
echo "  • Authentication & Security\n";
echo "  • Database (SQL & NoSQL)\n";
echo "  • Caching (Redis)\n";
echo "  • API Gateway & Load Balancing\n";
echo "  • Message Queue\n";
echo "  • Analytics & Processing\n";
echo "  • Object Storage\n";
echo "  • Notifications\n";
echo "  • Search Engine\n";
echo "  • CDN Edge\n";
echo "  • Workflow Orchestration\n";
echo "  • Monitoring\n";
echo "\n";
echo "Services can now discover each other based on capability requirements!\n";
echo str_repeat("=", 70) . "\n";
