# gCore GSD Client

A PHP client library for the Geodineum Service Daemon (GSD), providing service discovery with n-dimensional geometric topology capabilities and high-performance streams.

## Overview

The Geodineum Service Daemon (GSD) provides a robust framework for service discovery and topology management in distributed systems. This client library enables PHP applications to interact with the GSD daemon through ValKey/Redis streams, supporting both high-throughput consumer group operations and fallback mechanisms for maximum reliability.

Services are represented as points in an n-dimensional capability space, allowing for O(1) discovery based on capability requirements. This geometric approach enables efficient service discovery, load sequence optimization, and dynamic topology management.

The GSD client uses a unified stream approach for communication with the daemon, featuring Protocol v2 support with optimized field naming and message types, ensuring efficient and reliable operation even at high throughput.

## Features

- **Unified Stream Communication**: Efficient single-stream architecture for commands and responses
- **Consumer Group Support**: High-throughput message processing with optimized protocol
- **Geometric Service Discovery**: Find services based on n-dimensional capability requirements
- **Optimal Load Sequencing**: Determine service startup order using topology analysis
- **Multiple Connection Modes**: Support for direct ValKey commands and legacy Lua scripts
- **Fallback Mechanism**: Automatic fallback for resilience during daemon unavailability
- **Daemon Management**: Auto-start and health monitoring capabilities
- **Intelligent Caching**: Response caching for read-only operations
- **Batch Processing**: Support for efficient batch operations
- **Stream Maintenance**: Automatic stream trimming and message cleaning
- **Multi-Node Architecture**: One daemon handles multiple nodes simultaneously
- **Multi-Site Support**: Independent site isolation with appropriate boundaries
- **Optimized Protocol**: Reduced memory footprint with field shortening
- **Parameter Adaptation**: Automatic parameter format adaptation for compatibility with daemon

## Requirements

- PHP 7.4+
- ext-redis
- ext-json
- ValKey/Redis server

## Installation

### Option 1: Via Composer (Recommended)

You can install the package via composer:

```bash
composer require gcore/gsd-client
```

If this is a private package not published on Packagist, you'll need to define its repository in your project's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "/path/to/gsd-client"
    }
]
```

Then require it as normal:

```bash
composer require gcore/gsd-client
```

### Option 2: Manual Installation

For projects where Composer isn't an option:

1. Place the entire package directory somewhere in your project (e.g., `vendor/gcore/gsd-client/`)

2. Set up autoloading in your project's composer.json:
   ```json
   "autoload": {
       "psr-4": {
           "gCore\\GSD\\": "vendor/gcore/gsd-client/src/"
       }
   }
   ```

3. Run `composer dump-autoload`

### Option 3: Integrating with gCore Framework

Copy the `src/` directory to your gCore installation and configure autoloading:

```php
// In your autoloader configuration
$autoloader->addNamespace('gCore\\GSD', __DIR__ . '/lib/GSD/src');

// Basic usage
use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;

$storage = new ValKeyStorage(['host' => '127.0.0.1', 'port' => 6379]);
$client = new Client($storage, 'your-site-id', 'your-node-id');
```

## Basic Usage

### Standard PHP Project

```php
<?php

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;
use gCore\GSD\Exception\GSDException;

// Create ValKey storage connection
$storage = new ValKeyStorage([
    'host' => '127.0.0.1',
    'port' => 6379
]);

// Create GSD client
$client = new Client(
    $storage, 
    'default',       // site ID
    'client-demo',   // node ID
    [
        'debug' => true,
        'use_fallback' => true,
        'timeout' => 5.0
    ]
);

// Get information about dimensions
$dimensions = $client->geometricDimensions();
echo "Available dimensions: " . json_encode($dimensions) . "\n";

// Store service topology
$client->geometricStoreTopology([
    'id' => 'example-service',
    'capabilities' => [
        'performance' => 0.9,
        'reliability' => 0.8,
        'memory' => 0.7
    ],
    'metadata' => [
        'description' => 'Example service',
        'version' => '1.0',
        'endpoints' => ['http://example.com/api']
    ]
]);

// Discover services
$services = $client->geometricDiscover(['performance', 'reliability']);
echo "Found services: " . json_encode($services) . "\n";

// Get optimal load sequence
$sequence = $client->geometricLoadSequence();
echo "Load sequence: " . implode(' -> ', $sequence) . "\n";
```

### Using with gCore Framework

```php
<?php

namespace gCore\YourModule;

class ServiceManager
{
    protected $gsd;
    
    public function __construct($gsd)
    {
        $this->gsd = $gsd;
    }
    
    public function registerService($id, $capabilities, $metadata)
    {
        return $this->gsd->geometricStoreTopology([
            'id' => $id,
            'capabilities' => $capabilities,
            'metadata' => $metadata
        ]);
    }
    
    public function findServicesByCapabilities($capabilities)
    {
        return $this->gsd->geometricDiscover($capabilities);
    }
    
    public function getServiceLoadSequence()
    {
        return $this->gsd->geometricLoadSequence();
    }
}

// Using with dependency injection:
$serviceManager = new ServiceManager($app->get('gsd'));
```

## Command Reference

### Geometric Service Commands

#### `geometricDimensions(): array`

Gets information about the dimensions in the geometric space.

```php
$dimensions = $client->geometricDimensions();
// Returns: ['dimensions' => 3, 'labels' => ['performance', 'reliability', 'memory']]
```

#### `geometricStoreTopology(array $data): bool`

Stores geometric topology data.

```php
$client->geometricStoreTopology([
    'id' => 'database-service',
    'capabilities' => [
        'performance' => 0.9,
        'reliability' => 0.8,
        'scalability' => 0.7
    ],
    'metadata' => [
        'name' => 'Database Service',
        'version' => '1.0',
        'description' => 'Primary database service'
    ]
]);
```

#### `geometricDiscover(array $capabilities, int $limit = 10, int $dimensions = 0, int $distance = 0): array`

Discover services based on capabilities.

```php
// Using array of capability names
$services = $client->geometricDiscover(['performance', 'reliability']);

// Using legacy format with minimum values
$services = $client->geometricDiscover([
    'performance' => 0.7,
    'reliability' => 0.5
]);
```

#### `geometricLoadSequence(string $group = 'default'): array`

Get the service load sequence based on dependencies.

```php
$sequence = $client->geometricLoadSequence();
// Returns: ['config-service', 'database-service', 'cache-service', 'api-service']
```

#### `geometricDistance(array $point1, array $point2): array`

Calculate distance between two points in n-dimensional space.

```php
$distance = $client->geometricDistance([0.5, 0.8, 0.3], [0.7, 0.6, 0.2]);
// Returns: ['distance' => 0.36, 'dimensions' => 3]
```

### Batch Operations

#### `executeBatch(array $commands): array`

Execute multiple commands in a single batch for improved performance. The batch is sent as a single message with type 'bc' (batch command).

```php
$commands = [
    ['ping', []],
    ['geometric_dimensions', []],
    ['geometric_discover', ['capabilities' => ['performance', 'reliability']]],
    ['get_site_info', []]
];

$results = $client->executeBatch($commands);
// Returns array of responses in the same order as commands
```

The executeBatch method offers significant performance improvements when sending multiple commands, especially in high-latency environments.

### Legacy Service Commands (Maintained for Backward Compatibility)

#### `registerCapabilityDimension(string $name, int $dimension): bool`

Registers a capability dimension in the geometric topology. This is mapped to `geometricDimensions` in the unified protocol.

```php
$client->registerCapabilityDimension('performance', 0);
$client->registerCapabilityDimension('reliability', 1);
$client->registerCapabilityDimension('memory', 2);
```

#### `registerService(string $id, array $capabilities, array $metadata = []): bool`

Registers a service with capabilities. This is mapped to `geometricStoreTopology` in the unified protocol.

```php
$client->registerService(
    'database-service',
    [
        'performance' => 0.9,
        'reliability' => 0.8,
        'scalability' => 0.7
    ],
    [
        'name' => 'Database Service',
        'version' => '1.0',
        'description' => 'Primary database service'
    ]
);
```

#### `findServices(array $requirements): array`

Finds services based on capability requirements. This is mapped to `geometricDiscover` in the unified protocol.

```php
$services = $client->findServices([
    'performance' => 0.7,
    'reliability' => 0.5
]);
```

#### `getLoadSequence(): array`

Gets the optimal service load sequence. This is mapped to `geometricLoadSequence` in the unified protocol.

```php
$sequence = $client->getLoadSequence();
// Returns: ['config-service', 'database-service', 'cache-service', 'api-service']
```

#### `getCapabilityDimensions(): array`

Gets information about registered capability dimensions. This is mapped to `geometricDimensions` in the unified protocol.

```php
$dimensions = $client->getCapabilityDimensions();
// Returns: ['performance' => 0, 'reliability' => 1, 'memory' => 2]
```

### Client Management Commands

#### `ping(): bool`

Pings the daemon to check connectivity.

```php
if ($client->ping()) {
    echo "Connected to GSD daemon\n";
} else {
    echo "Failed to connect to GSD daemon\n";
}
```

#### `startDaemon(): bool`

Starts the daemon process if it's not running.

```php
if ($client->startDaemon()) {
    echo "Daemon started successfully\n";
} else {
    echo "Failed to start daemon\n";
}
```

#### `stopDaemon(): bool`

Stops the running daemon process.

```php
if ($client->stopDaemon()) {
    echo "Daemon stopped successfully\n";
} else {
    echo "Failed to stop daemon\n";
}
```

#### `getDaemonStatus(): array`

Gets the daemon status information.

```php
$status = $client->getDaemonStatus();
// Returns: ['running' => true, 'pid' => 12345, 'connected' => true, 'uptime' => '10:30:15']
```

#### `getStatus(): array`

Gets the client status information.

```php
$status = $client->getStatus();
// Returns detailed client status information
```

#### `enableConsumerGroups(): bool`

Enables the consumer group approach for stream operations.

```php
$client->enableConsumerGroups();
```

#### `disableConsumerGroups(): void`

Disables the consumer group approach and falls back to script-based polling.

```php
$client->disableConsumerGroups();
```

#### `clearCache(): void`

Clears the response cache.

```php
$client->clearCache();
```

## Advanced Usage

### Integration Helper

The `IntegrationHelper` utility simplifies GSD client initialization and environment setup.

```php
<?php

use gCore\GSD\Utils\IntegrationHelper;

// Initialize GSD with auto-start
$result = IntegrationHelper::initialize([
    'host' => '127.0.0.1',
    'port' => 6379,
    'site_id' => 'production',
    'node_id' => 'gsd-main',
    'client_id' => 'my-app',
    'debug' => false,
    'use_fallback' => true,
    'daemon_path' => '/path/to/gsd-daemon'  // Set actual path here
], true);

// Check initialization result
if ($result['status'] === 'healthy' || $result['status'] === 'fallback') {
    $client = $result['client'];
    // Use the client
    echo "Connected to GSD: " . ($client->isConnected() ? "Yes" : "No") . "\n";
    echo "Using fallback: " . ($client->isUsingFallback() ? "Yes" : "No") . "\n";
} else {
    echo "Failed to initialize GSD: {$result['message']}\n";
}
```

### Health Checker

The `HealthChecker` utility provides detailed health information about the GSD environment.

```php
<?php

use gCore\GSD\Utils\HealthChecker;
use gCore\GSD\Storage\ValKeyStorage;

// Create a health checker
$healthChecker = new HealthChecker(
    new ValKeyStorage([
        'host' => '127.0.0.1',
        'port' => 6379
    ]),
    'default', // site ID
    'default'  // node ID
);

// Check daemon health
$health = $healthChecker->check();

if ($health['daemon_running']) {
    echo "GSD daemon is running\n";
    echo "Script count: {$health['script_count']}\n";
} else {
    echo "GSD daemon is not running\n";
    
    // Start the daemon if needed
    if ($healthChecker->startDaemon()) {
        echo "Daemon started successfully\n";
    } else {
        echo "Failed to start daemon: {$health['message']}\n";
    }
}
```

### Direct Script Operations

For advanced use cases, you can interact directly with the `ScriptManager`.

```php
<?php

use gCore\GSD\Scripts\ScriptManager;
use gCore\GSD\Storage\ValKeyStorage;

// Create script manager
$scriptManager = new ScriptManager(
    new ValKeyStorage([
        'host' => '127.0.0.1',
        'port' => 6379
    ]),
    'default', // site ID
    [
        'debug' => true,
        'template_vars' => [
            'TERRITORY' => 'default',
            'MAX_BATCH_SIZE' => 1000
        ]
    ]
);

// Execute a batch operation script
$result = $scriptManager->executeScript(
    'BATCH_OPERATIONS',
    [], // keys
    ['default', 'JSON', json_encode([
        ['SET', 'test:key1', 'value1'],
        ['GET', 'test:key1']
    ])]
);

echo "Batch operation result: {$result}\n";
```

### Stream Operations with the Unified Stream

The client now uses a unified stream approach for communication with the GSD daemon. Here's how to directly interact with the unified stream:

```php
<?php

use gCore\GSD\ConsumerGroupHandler;
use gCore\GSD\Storage\ValKeyStorage;

// Create the storage connection
$storage = new ValKeyStorage([
    'host' => '127.0.0.1',
    'port' => 6379
]);

// Create consumer group handler
$handler = new ConsumerGroupHandler(
    $storage,
    'default', // site ID
    'default', // node ID
    [
        'debug' => true,
        'stream_prefix' => 'gsd',
        'batch_size' => 10
    ]
);

// Initialize the unified stream and consumer groups
$handler->initialize();

// Send a geometric discover command
$requestId = uniqid('req_', true);
$messageId = $handler->sendCommand('geometric_discover', ['capabilities' => ['storage']], $requestId);
echo "Sent discover command with ID: {$requestId}, message ID: {$messageId}\n";

// Read the response
$response = $handler->readResponse($requestId, 5000); // 5 second timeout
if ($response) {
    echo "Received response: " . json_encode($response) . "\n";
} else {
    echo "Timed out waiting for response\n";
}

// Send a ping command
$requestId = uniqid('req_', true);
$messageId = $handler->sendCommand('ping', [], $requestId);
echo "Sent ping command with ID: {$requestId}, message ID: {$messageId}\n";

// Read the response
$response = $handler->readResponse($requestId, 2000); // 2 second timeout
if ($response) {
    echo "Received ping response: " . json_encode($response) . "\n";
} else {
    echo "Timed out waiting for ping response\n";
}
```

This approach provides direct access to the unified stream protocol with optimized field naming and proper response handling.

## Consumer Group Handler

For direct interaction with consumer groups, you can use the `ConsumerGroupHandler` class.

```php
<?php

use gCore\GSD\ConsumerGroupHandler;
use gCore\GSD\Storage\ValKeyStorage;

// Create consumer group handler
$handler = new ConsumerGroupHandler(
    new ValKeyStorage([
        'host' => '127.0.0.1',
        'port' => 6379
    ]),
    'default', // site ID
    'default', // node ID
    [
        'debug' => true,
        'batch_size' => 100,
        'max_idle_time' => 30000,
        'trim_threshold' => 10000
    ]
);

// Initialize streams and consumer groups
$handler->initialize();

// Send a command
$requestId = uniqid('req_', true);
$messageId = $handler->sendCommand('ping', [], $requestId);
echo "Sent ping command with ID: {$requestId}, message ID: {$messageId}\n";

// Read response
$response = $handler->readResponse($requestId, 5000); // 5 second timeout
if ($response) {
    echo "Received response: " . json_encode($response) . "\n";
} else {
    echo "Timed out waiting for response\n";
}
```

## Command Format & Unified Stream Communication

The GSD client now uses a unified stream approach for both commands and responses:

```
XADD {site_id}:gsd:unified:{node_id} "*" 
  t "c" 
  c "your_command" 
  p "{\"param\":\"value\"}" 
  ss "your_site_id" 
  sn "your_node_id" 
  id "unique-request-id" 
  ts "1619712345.678"
```

### Optimized Field Format
| Field | Description              |
|-------|--------------------------|
| t     | Message type ('c'=command, 'r'=response, 'bc'=batch command, 'br'=batch response) |
| c     | Command name             |
| p     | JSON-encoded parameters  |
| id    | Unique request ID        |
| ss    | Source site ID           |
| sn    | Source node ID           |
| ts    | Timestamp                |
| bi    | Batch ID (for batch messages) |
| tc    | Total count (for batch messages) |
| m     | Message array (for batch messages) |

### Important Requirements
- Use the unified stream for both commands and responses
- Message type field ('t') is required with appropriate type:
  - 'c' for regular commands
  - 'r' for regular responses
  - 'bc' for batch commands (replacing legacy 'b' type)
  - 'br' for batch responses (replacing legacy 'b' type)
- Consumer group names are "gsd-command-processor" (daemon) and "gsd-client" (client)
- Client consumer name format: client-{site_id}-{node_id}-{client_id}
- Always use unique consumer names when running multiple clients

### ValKey Integration
The client supports both direct stream operations and ValKey protocol functions:
- `GSD_PROTOCOL_ENCODE`: For efficient message encoding with optimized RESP3 format
- `GSD_PROTOCOL_READ_GROUP`: For reading and decoding messages from consumer groups
- `GSD_PROTOCOL_ACK`: For acknowledging processed messages
- Automatic fallback to direct operations if ValKey functions are not available

### Command Abbreviations
For efficiency, the unified stream protocol uses abbreviated command names:
- `geo_dim` - geometric_dimensions
- `geo_store` - geometric_store_topology
- `geo_seq` - geometric_load_sequence
- `geo_disc` - geometric_discover
- `geo_dist` - geometric_distance
- `str_info` - stream_info
- `str_group` - stream_group_info
- `str_cons` - stream_consumer_info
- `str_pend` - stream_pending
- `node_info` - get_node_info
- `site_info` - get_site_info

Legacy compatibility is maintained for older commands:
- `reg_dim` - registerCapabilityDimension
- `reg_svc` - registerService
- `find_svc` - findServices
- `get_svc` - getServiceDetails

### Troubleshooting

If you encounter connectivity issues with the unified stream:

1. Try running test_fix_unified.php which recreates the unified stream and consumer groups.
2. Make sure your ValKey/Redis server is running and accessible.
3. Check that both consumer groups (gsd-client and gsd-command-processor) exist.
4. If the client timeouts while waiting for responses, try cleaning up old messages with:
   ```bash
   # Using ValKey CLI
   docker exec valkey valkey-cli XTRIM "{your_site_id}:gsd:unified:{your_node_id}" MAXLEN 0
   ```
5. Verify that the GSD daemon is running and has initialized properly.
6. Enable debug mode in the client config to see detailed logs of stream interactions.
7. Ensure your parameter formats match what the daemon expects.

## Configuration Options

| Option | Description | Default | Type |
|--------|-------------|---------|------|
| stream_prefix | Prefix for stream keys | gsd | string |
| debug | Enable debug mode | false | bool |
| use_fallback | Use fallback mode if daemon is unavailable | true | bool |
| timeout | Command timeout in seconds | 5.0 | float |
| retry_count | Number of command retries | 3 | int |
| retry_delay | Delay between retries in seconds | 0.1 | float |
| cache_expiration | Cache expiration in seconds | 300 | int |
| allow_local_execution | Allow local execution in fallback mode | false | bool |
| daemon_path | Path to daemon executable | null | string |
| auto_start_daemon | Auto-start daemon if not running | false | bool |
| use_consumer_groups | Use consumer group approach | true | bool |
| batch_size | Batch size for reading/writing messages | 100 | int |
| max_idle_time | Max idle time for pending message claiming (ms) | 30000 | int |
| trim_threshold | Stream trim threshold (number of messages) | 10000 | int |
| client_id | Unique client identifier | uniqid('', true) | string |

## Configuration Management Commands

The GSD Client provides commands to query and update the daemon's runtime configuration.

### Available Methods

```php
<?php
// Get a specific configuration value
$dimensions = $client->configGet('dimensions');
echo "Dimensions: " . $dimensions . "\n";

// Set a configuration value
$client->configSet('log_level', 'debug');

// List all configuration and runtime info
$config = $client->configList();
print_r($config);
```

### Valid Configuration Keys

The following keys can be queried or updated:

- **`threads`** - Number of worker threads (default: auto)
- **`dimensions`** - Geometric space dimensions (default: 8, max: 32)
- **`site_id`** - Multi-tenant namespace identifier
- **`node_id`** - Horizontal scaling node identifier
- **`debug`** - Debug mode flag (true/false)
- **`log_level`** - Logging verbosity (error, warn, info, debug, trace)

### Monitoring Example

```php
<?php
// Monitor daemon configuration
$config = $client->configList();
echo "Worker threads: " . ($config['threads'] ?? 'N/A') . "\n";
echo "Dimensions: " . ($config['dimensions'] ?? 'N/A') . "\n";
echo "Site ID: " . ($config['site_id'] ?? 'N/A') . "\n";
echo "Node ID: " . ($config['node_id'] ?? 'N/A') . "\n";

// Adjust logging for debugging
if ($config['log_level'] !== 'debug') {
    $client->configSet('log_level', 'debug');
    echo "Debug logging enabled\n";
}
```

## Template System

The GSD Client integrates with the daemon's Tera-powered template engine for server-side HTML rendering with geometric capability discovery.

### Features

- **Tera Template Engine** - Jinja2-like syntax for dynamic templates
- **8D Geometric Capability Discovery** - Find templates by characteristics in n-dimensional space
- **Dependency Tracking** - Automatic detection of template includes with cycle prevention
- **Auto-Escaping** - Built-in XSS prevention through automatic HTML escaping
- **ValKey Persistence** - Templates stored in ValKey with TTL-based expiry (default: 7200s)

### Basic Usage

```php
<?php
use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;

$client = new Client(
    new ValKeyStorage(['password' => 'your-password']),
    'default',
    'default'
);

$tm = $client->getTemplateManager();

// Register a template
$result = $tm->registerTemplate(
    'user_card',
    '<div class="user-card"><h3>{{name}}</h3><p>{{email}}</p></div>',
    [
        'variables' => ['name' => 'string', 'email' => 'string'],
        'ttl' => 3600  // 1 hour cache
    ]
);

// Render the template (note: render_template support depends on daemon version)
try {
    $html = $tm->renderTemplate('user_card', [
        'name' => 'John Doe',
        'email' => 'john@example.com'
    ]);
    echo $html;
} catch (\Exception $e) {
    echo "Template rendering not available in this daemon version\n";
}

// List all templates
$templates = $tm->listTemplates();
print_r($templates);

// Get template statistics
$stats = $tm->getStats();
echo "Templates registered: " . $stats['templates_registered'] . "\n";
```

### 8D Geometric Capabilities

Templates are indexed in 8-dimensional capability space for efficient discovery:

- **`html`** (1.0) - Template type identifier for HTML templates
- **`complexity`** (0.0-1.0) - Lines of code / 100 (capped at 1.0)
- **`interactivity`** (0.0-1.0) - Forms + inputs + scripts density
- **`data_density`** (0.0-1.0) - Variables per 100 characters (dynamic content ratio)
- **`reusability`** (0.0-1.0) - Include count / 5 (component reuse metric)
- **`cacheability`** (0.0-1.0) - 1 - (data_density × interactivity)
- **`semantic_layout`** (0.0-1.0) - HTML5 structural elements / 5 (semantic richness)
- **`render_cost`** (0.0-1.0) - Default 0.5, learned from historical metrics

These capabilities enable discovery queries like "find all highly cacheable, low-complexity templates" using the geometric service discovery system.

### HTMX Fragment Serving

```php
<?php
// Serve a cached HTML fragment with HTMX headers
$result = $tm->serveFragment(
    'fragment-key',
    'fragmentLoaded',                    // HX-Trigger header
    'public, max-age=3600'               // Cache-Control header
);

// Response includes:
// - html: The fragment content
// - headers: Content-Type, Cache-Control, ETag, HX-Trigger
echo $result['html'];
header('Cache-Control: ' . $result['headers']['Cache-Control']);
header('ETag: ' . $result['headers']['ETag']);
```

### Template Manager API Reference

**Registration & Management**
- `registerTemplate(string $id, string $content, array $config): array`
- `deleteTemplate(string $id, array $config): bool` (note: not supported, templates expire via TTL)
- `listTemplates(array $config): array`

**Rendering**
- `renderTemplate(string $id, array $variables, array $config): string`
- `serveFragment(string $key, ?string $hx_trigger, ?string $cache_control): array`

**Discovery by Capabilities**
- `discoverByCapability(array $requirements, array $config): array`
- `discoverSimilar(string $templateId, array $config): array`
- `getTemplateCapabilities(string $templateId, array $config): array`

**Metadata & Statistics**
- `getStats(): array`
- `clearStats(): void`

## Health Stream & Load Reporting

Services built with the GSD Client can report load metrics for the daemon's load-aware service discovery and routing.

### Architecture

- **High-frequency updates** - Supports >10,000 messages/second capability
- **Direct XADD writes** - Messages written directly to `{site_id}:gsd:health:{node_id}` stream
- **No command/response cycle** - Fire-and-forget pattern for minimal overhead
- **Passive daemon reading** - Daemon consumes health stream for load-aware service selection

### Available Methods

```php
<?php
// Send a single health update
$messageId = $client->sendHealthUpdate([
    'service_id' => 'api-server-1',
    'load_factor' => 0.65,           // 0.0-1.0 (required)
    'cpu_usage' => 0.45,             // 0.0-1.0 (optional)
    'memory_usage' => 0.72,          // 0.0-1.0 (optional)
    'active_requests' => 23,         // int (optional)
    'avg_latency_ms' => 45,          // milliseconds (optional)
    'error_rate' => 0.02             // 0.0-1.0 (optional)
]);

// Send batch updates (more efficient for multiple services)
$messageIds = $client->sendHealthUpdateBatch([
    [
        'service_id' => 'api-server-1',
        'load_factor' => 0.65,
        'cpu_usage' => 0.45
    ],
    [
        'service_id' => 'api-server-2',
        'load_factor' => 0.82,
        'cpu_usage' => 0.78
    ]
]);
```

### Heartbeat Pattern

For continuous load reporting, use the heartbeat system:

```php
<?php
// Start a heartbeat that reports every 5 seconds
$client->startHealthHeartbeat(
    'api-server-1',
    5000,  // interval in milliseconds
    function() {
        // Callback to gather current metrics
        return [
            'load_factor' => sys_getloadavg()[0] / 4.0,  // Normalize by CPU count
            'cpu_usage' => getCpuUsage(),
            'memory_usage' => getMemoryUsage(),
            'active_requests' => getActiveRequestCount()
        ];
    }
);

// In your application's main loop
while (true) {
    // Process application work...

    // Tick heartbeats to send updates at their intervals
    $client->tickHealthHeartbeats();

    usleep(100000);  // 100ms sleep
}
```

### Load-Aware Service Selection

The daemon uses these metrics for intelligent service selection with composite scoring:

**Scoring Formula:**
```
score = (load_factor × 0.6) + (cpu_usage × 0.2) + (memory_usage × 0.1) + (latency_ms/1000 × 0.1)
```

Services with lower scores are preferred. The daemon filters out unhealthy services (load ≥ 0.95 or error_rate ≥ 0.05) before selection.

### Compressed Field Format

Health updates use abbreviated field names to reduce network traffic:

- `t`: "lu" (message type: load update)
- `si`: service_id
- `l`: load_factor
- `cpu`: cpu_usage (optional)
- `mem`: memory_usage (optional)
- `rq`: active_requests (optional)
- `lat`: avg_latency_ms (optional)
- `err`: error_rate (optional)
- `ts`: timestamp (milliseconds)

## Diagnostic & Observability

The GSD Client provides comprehensive diagnostic commands for monitoring daemon health and performance.

### Available Commands

```php
<?php
// Get daemon status (basic or full detail)
$status = $client->status('full');
print_r($status);
// Returns: version, uptime, timestamp, connection_pool, supported_commands,
//          valkey_functions, redis_info

// Get detailed debug information
$debug = $client->getDebugInfo();
echo "Services registered: " . ($debug['services_count'] ?? 0) . "\n";
echo "Dimensions available: " . ($debug['dimensions'] ?? 0) . "\n";

// Memory statistics
$memory = $client->getMemoryStats();
echo "Resident memory: " . $memory['resident_mb'] . " MB\n";

// Thread pool status
$threads = $client->getThreadStatus();
echo "Active threads: " . $threads['active_threads'] . "\n";

// ValKey connection health
$conn = $client->getConnectionStatus();
echo "Connection pool size: " . $conn['pool_size'] . "\n";

// Performance metrics (throughput and latency)
$perf = $client->getPerformanceMetrics();
echo "Ops/sec: " . $perf['ops_per_second'] . "\n";

// Security configuration status
$security = $client->getSecurityStatus();
echo "Auth enabled: " . ($security['auth_enabled'] ? 'yes' : 'no') . "\n";

// Service topology status
$topology = $client->getTopologyStatus();
echo "Registered services: " . $topology['service_count'] . "\n";
```

### Monitoring Dashboard Example

```php
<?php
function displayDaemonHealth(Client $client): void {
    echo "=== GSD Daemon Health Dashboard ===\n\n";

    // Basic status
    $status = $client->status('basic');
    echo "Version: " . $status['version'] . "\n";
    echo "Uptime: " . formatUptime($status['uptime']) . "\n\n";

    // Performance metrics
    $perf = $client->getPerformanceMetrics();
    echo "Performance:\n";
    echo "  - Throughput: " . $perf['ops_per_second'] . " ops/sec\n";
    echo "  - Latency p50: " . $perf['latency_p50_ms'] . " ms\n";
    echo "  - Latency p95: " . $perf['latency_p95_ms'] . " ms\n\n";

    // Memory usage
    $memory = $client->getMemoryStats();
    echo "Memory:\n";
    echo "  - Resident: " . $memory['resident_mb'] . " MB\n";
    echo "  - Virtual: " . $memory['virtual_mb'] . " MB\n\n";

    // Connection pool
    $status_full = $client->status('full');
    $pool = $status_full['connection_pool'];
    echo "Connections:\n";
    echo "  - Total: " . $pool['total_connections'] . "\n";
    echo "  - Idle: " . $pool['idle_connections'] . "\n\n";

    // Service topology
    $topology = $client->getTopologyStatus();
    echo "Services: " . $topology['service_count'] . " registered\n";
}

function formatUptime(int $seconds): string {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $mins = floor(($seconds % 3600) / 60);
    return "{$days}d {$hours}h {$mins}m";
}
```

### Health Check Example

```php
<?php
function isDaemonHealthy(Client $client): bool {
    try {
        // Check basic connectivity
        if (!$client->ping()) {
            return false;
        }

        // Check connection pool
        $status = $client->status('full');
        $pool = $status['connection_pool'];

        // Ensure we have available connections
        if ($pool['idle_connections'] < 1) {
            error_log("Warning: No idle connections in pool");
            return false;
        }

        // Check performance metrics
        $perf = $client->getPerformanceMetrics();
        if (($perf['latency_p95_ms'] ?? 0) > 100) {
            error_log("Warning: High latency detected");
            // Don't fail, just log warning
        }

        return true;
    } catch (\Exception $e) {
        error_log("Daemon health check failed: " . $e->getMessage());
        return false;
    }
}
```

### Performance Tracking Example

```php
<?php
class PerformanceMonitor {
    private Client $client;
    private array $history = [];

    public function recordMetrics(): void {
        $metrics = $this->client->getPerformanceMetrics();
        $this->history[] = [
            'timestamp' => time(),
            'ops_per_second' => $metrics['ops_per_second'] ?? 0,
            'latency_p95' => $metrics['latency_p95_ms'] ?? 0
        ];

        // Keep last 100 readings
        if (count($this->history) > 100) {
            array_shift($this->history);
        }
    }

    public function getAverageOpsPerSecond(): float {
        if (empty($this->history)) return 0.0;

        $sum = array_sum(array_column($this->history, 'ops_per_second'));
        return $sum / count($this->history);
    }

    public function detectPerformanceDegradation(): bool {
        if (count($this->history) < 10) return false;

        $recent = array_slice($this->history, -5);
        $earlier = array_slice($this->history, -10, 5);

        $recentAvg = array_sum(array_column($recent, 'ops_per_second')) / 5;
        $earlierAvg = array_sum(array_column($earlier, 'ops_per_second')) / 5;

        // Alert if throughput dropped by >25%
        return $recentAvg < ($earlierAvg * 0.75);
    }
}
```

## Error Handling

The client provides several exception types for proper error handling:

```php
<?php

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;
use gCore\GSD\Exception\ConnectionException;
use gCore\GSD\Exception\ScriptException;
use gCore\GSD\Exception\StorageException;
use gCore\GSD\Exception\GSDException;

try {
    $client = new Client(
        new ValKeyStorage([
            'host' => '127.0.0.1',
            'port' => 6379
        ]),
        'default',
        'client-demo'
    );
    
    $services = $client->findServices(['performance' => 0.8]);
} catch (ConnectionException $e) {
    // Handle connection errors
    echo "Connection error: " . $e->getMessage() . "\n";
} catch (ScriptException $e) {
    // Handle script execution errors
    echo "Script error: " . $e->getMessage() . "\n";
} catch (StorageException $e) {
    // Handle storage-related errors
    echo "Storage error: " . $e->getMessage() . "\n";
} catch (GSDException $e) {
    // Handle other GSD errors
    echo "GSD error: " . $e->getMessage() . "\n";
}
```

## Performance Considerations

- **Connection Pooling**: Reuse connections to reduce latency
- **Batch Operations**: Use batch operations for higher throughput
- **Consumer Groups**: Enable consumer groups for efficient message handling (now the default)
- **Response Caching**: Cache responses for read-only operations
- **Stream Maintenance**: Regularly trim streams to avoid excessive memory usage
- **Parameter Adaptation**: The client now automatically adapts parameter formats to match daemon expectations
- **Command Abbreviations**: Reduced network traffic with shortened field names
- **Node Filtering**: Responses are now filtered by node_id, improving multi-node efficiency

Single node performance: ~3,800 ops/sec on modest hardware  
Batch mode: 10,000+ ops/sec with optimized batch sizes  
Multi-node: Near-linear scaling with additional nodes
Unified stream: Improved throughput with reduced protocol overhead

## Quick Start Guide

### 1. Installation and Setup

First, ensure you have PHP 7.4+ and a running ValKey/Redis server (Docker recommended).

```bash
# Install via Composer
composer require gcore/gsd-client

# Start ValKey in Docker if needed
docker run -d --name valkey -p 6379:6379 kishorevpatil/valkey:latest
```

### 2. Initialize and Fix Streams (First Time Setup)

Before using the client, run the stream initialization script to set up the required streams and consumer groups:

```bash
# This creates the required unified stream and consumer groups
php test_fix_unified.php
```

### 3. Basic Client Usage

```php
<?php
// Basic client initialization
require_once 'vendor/autoload.php';

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;

$storage = new ValKeyStorage([
    'host' => '127.0.0.1',
    'port' => 6379
]);

$client = new Client(
    $storage,
    'default',    // site ID
    'myapp-node', // node ID
    [
        'debug' => true,
        'use_fallback' => true,
        'timeout' => 5.0
    ]
);

// Check connection with ping
if ($client->ping()) {
    echo "✅ Connected to GSD daemon\n";
} else {
    echo "❌ Failed to connect to GSD daemon\n";
}

// Register a service with capabilities
$client->geometricStoreTopology([
    'id' => 'api-service',
    'capabilities' => [
        'performance' => 0.9,
        'reliability' => 0.8
    ],
    'metadata' => [
        'name' => 'API Service',
        'version' => '1.0'
    ]
]);

// Discover services matching capabilities
$services = $client->geometricDiscover(['performance', 'reliability']);
echo "Found services: " . json_encode($services) . "\n";
```

### 4. Batch Operations for Performance

For higher throughput, use batch operations to execute multiple commands in a single network roundtrip:

```php
// Execute multiple commands in a single batch
$commands = [
    ['ping', []],
    ['geometric_dimensions', []],
    ['geometric_discover', ['capabilities' => ['performance', 'reliability']]],
    ['get_site_info', []]
];

$results = $client->executeBatch($commands);
// Process multiple results at once
```

### 5. Troubleshooting

If you encounter connectivity issues:

```bash
# Fix unified stream and consumer groups
php test_fix_unified.php

# Check ValKey connection
docker exec valkey valkey-cli PING

# Check consumer groups
docker exec valkey valkey-cli XINFO GROUPS default:gsd:unified:default
```

## Development Tools

### Testing

```bash
# Run all tests
composer test

# Run a specific test
vendor/bin/phpunit --filter methodName tests/Path/To/TestFile.php

# Run tests with coverage
composer test:coverage

# Benchmark consumer groups
php tools/benchmarks/consumer_group_benchmark.php

# Benchmark multi-client throughput
php tools/benchmarks/multi_client_benchmark.php
```

### Code Quality

```bash
# Run code style checks
composer cs

# Fix code style issues
composer cs:fix

# Run static analysis
composer analyse
```

## Project Repository Structure

```
gsd-client/
├── src/                    # Core client implementation
│   ├── Client.php          # Main client class
│   ├── ConsumerGroupHandler.php # Stream consumer group handling
│   ├── Broadcast/          # Broadcast stream handling
│   ├── Discovery/          # Service discovery utilities
│   ├── Exception/          # Custom exception classes
│   ├── Fallback/           # Fallback implementation
│   ├── Format/             # Format detection/conversion
│   ├── Health/             # Health stream reporting
│   ├── Queue/              # Command queue management
│   ├── Storage/            # Storage abstractions
│   ├── Template/           # Template management
│   └── Utils/              # Utility classes
├── tests/                  # Automated tests (PHPUnit)
├── tools/benchmarks/       # Performance benchmarks
├── demo/                   # Interactive demo application
├── docs/                   # Documentation
├── examples/               # Usage examples
├── test_fix_unified.php    # Utility to reset streams
├── CLAUDE.md               # Development guidelines
└── README.md               # This file
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.