# GSD Client Development Guidelines

---

## ⚠️ CRITICAL: WE USE VALKEY, NOT REDIS

**IMPORTANT FOR ALL LLM SESSIONS:**

This system uses **ValKey** (an open-source Redis fork), **NOT Redis**.

- ❌ **NEVER** use `redis-cli` command
- ❌ **NEVER** suggest Redis-specific PHP libraries
- ❌ **NEVER** reference Redis documentation
- ✅ **ALWAYS** use `docker exec valkey valkey-cli` (ValKey runs in Docker)
- ✅ **ALWAYS** use phpredis extension (it's ValKey-compatible)
- ✅ **ALWAYS** reference ValKey documentation
- ✅ **ALWAYS** test compatibility with ValKey 7.2+

**ValKey CLI Access:**
```bash
# Via Docker (REQUIRED - ValKey runs in container)
docker exec valkey valkey-cli -a "$PASSWORD" PING

# Get password
PASSWORD=$(cat .gsd/valkey.password)
```

**Why ValKey, not Redis:**
- Open-source license (Redis went proprietary in 2024)
- Community-driven development
- Already has behavioral differences from Redis
- This is a permanent architectural decision

---

## Overview

The gCore GSD Client is a PHP client library for the Geodineum Service Daemon (GSD), providing service discovery with geometric topology capabilities based on n-dimensional capability spaces. This library enables PHP applications to interact with the GSD daemon through **ValKey** streams (RESP3 protocol), supporting both high-throughput consumer group operations and fallback mechanisms for maximum reliability.

Services are represented as points in an n-dimensional capability space, allowing for O(1) discovery based on capability requirements. This geometric approach enables efficient service discovery, load sequence optimization, and dynamic topology management.

## Commands
- **Run all tests**: `composer test`
- **Run single test**: `vendor/bin/phpunit --filter methodName tests/Path/To/TestFile.php`
- **Code style check**: `composer cs`
- **Fix code style**: `composer cs:fix`
- **Static analysis**: `composer analyse`
- **Test with coverage**: `composer test:coverage`
- **Benchmark consumer groups**: `php tools/benchmarks/consumer_group_benchmark.php`
- **Benchmark multi-client**: `php tools/benchmarks/multi_client_benchmark.php`
- **Fix stream & consumer groups**: `php test_fix_unified.php`

## IMPORTANT NOTES
- **VALKEY IS RUNNING IN DOCKER - USE `docker exec valkey valkey-cli` FOR ALL REDIS/VALKEY COMMANDS**
- The client uses a unified stream approach for both commands and responses
- Protocol v2 with dedicated message types ('c', 'r', 'bc', 'br') is fully implemented
- Consumer group approach is used by default for significantly higher throughput
- Field abbreviations are used to reduce network traffic

## Unified Stream Communication

The GSD client now uses a unified stream approach for communication with the GSD daemon:

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
| _gh   | Group hint (routing field) |
| _cr   | Client readable flag (routing field) |

### Important Requirements
- Use the unified stream for both commands and responses (`{site_id}:gsd:unified:{node_id}`)
- Message type field ('t') must indicate appropriate type:
  - 'c' for regular commands
  - 'r' for regular responses
  - 'bc' for batch commands
  - 'br' for batch responses
- Consumer group names are "gsd-client" and "gsd-daemon"
- Client consumer name format: client-{site_id}-{node_id}-{client_id}
- Always use unique consumer names when running multiple clients
- Response messages for batches include _gh and _cr routing fields

### Abbreviated Commands
To reduce network traffic, commands use abbreviated names:

| Original Command | Abbreviated |
|------------------|------------|
| geometric_discover | geo_disc |
| geometric_store_topology | geo_store |
| geometric_load_sequence | geo_seq |
| geometric_dimensions | geo_dim |
| geometric_distance | geo_dist |

### Troubleshooting
If you encounter connectivity issues with the unified stream:

1. Run `test_fix_unified.php` to recreate streams and consumer groups with proper settings
2. Verify ValKey/Redis server is running: `docker exec valkey valkey-cli PING`
3. Check if consumer groups exist: `docker exec valkey valkey-cli XINFO GROUPS {site_id}:gsd:unified:{node_id}`
4. Check if consumer groups have the correct ID ('0-0'): `docker exec valkey valkey-cli XINFO GROUPS {site_id}:gsd:unified:{node_id}`
5. Reset streams if necessary: `docker exec valkey valkey-cli DEL {site_id}:gsd:unified:{node_id}`
6. Enable debug mode in the client config for detailed logs

## Client Capabilities

### Core Features
- **Unified Stream Communication**: Efficient single-stream architecture for commands and responses
- **Consumer Group Processing**: High-throughput message processing (4-8x faster than script approach)
- **Protocol v2 Support**: Full implementation of message types (c, r, bc, br)
- **Fallback Mechanism**: Automatic fallback for resilience with local implementation
- **Daemon Auto-start**: Can auto-start the GSD daemon if configured
- **Response Caching**: Intelligent caching for read-only operations
- **Batch Operations**: Support for efficient batch command execution
- **Stream Maintenance**: Automatic stream trimming and cleanup

### Geometric Service Capabilities
- **Service Registration**: Register services with n-dimensional capabilities
- **Service Discovery**: O(1) discovery of services based on capability requirements
- **Load Sequence Optimization**: Determine optimal service load ordering
- **Capability Management**: Register and manage capability dimensions
- **Geometric Distance Calculation**: Calculate distances between capability points

### Connection Management
- **Multiple Connection Modes**: Direct ValKey commands with script fallback
- **Connection Pooling**: Efficient reuse of connections
- **Multi-Node Architecture**: Support for multiple nodes with one daemon
- **Multi-Site Support**: Proper isolation between different sites

### Security & Resilience
- **Multi-Tier Execution Strategy**: ValKey Functions → Scripts → Direct commands
- **Exponential Backoff**: Smart retry for transient failures
- **Local Fallback**: Optional local execution mode when daemon unavailable
- **Stream Maintenance**: Intelligent stream trimming to prevent memory issues
- **Pending Message Recovery**: Automatic claiming of abandoned messages

### Performance
- **Benchmarked Throughput**: ~3,800 ops/sec single node, 10,000+ ops/sec in batch mode
- **Adaptive Polling**: Efficient response waiting algorithm with backoff
- **Field Optimization**: Shortened field names reduce network traffic
- **Batch Processing**: Process multiple commands in single network roundtrip
- **Concurrent Processing**: Near-linear scaling with multiple nodes
- **Message ID Tracking**: Efficient message ID tracking for quick response matching

## Architecture Components

### Core Classes

#### Client
The main client interface providing high-level API for GSD daemon communication. Handles command dispatching, response processing, fallback management, and batch operations.

#### ConsumerGroupHandler
Manages stream operations using consumer groups. Handles command messages, response parsing, message claiming, acknowledgment, and batch processing with protocol v2 support.

#### StorageInterface / ValKeyStorage
Abstracts storage layer operations for interaction with ValKey/Redis. Handles connection management, stream operations, and protocol functions.

#### ScriptManager
Manages ValKey script operations, including loading, executing, and caching. Provides fallback for direct command operations.

#### FallbackHandler
Implements local execution fallbacks for when the daemon is unavailable. Provides critical operations to maintain functionality.

### Support Components

#### IntegrationHelper
Simplifies GSD client initialization and environment setup with auto-start capabilities.

#### HealthChecker
Provides detailed health information about the GSD environment, including daemon status and consumer group configuration.

## Code Style Guidelines
- Follow **PSR-12** coding standards
- **Namespaces**: Use `gCore\GSD` as the base namespace
- **Class structure**: Place properties at top, then constructor, then methods
- **Error handling**: Use custom exception classes from `gCore\GSD\Exception` namespace
- **Documentation**: Include docblocks with `@param`, `@return`, and `@throws` tags
- **Type hints**: Use PHP 7.4+ type hints for parameters, properties, and return values
- **Naming**: Use camelCase for methods/properties, PascalCase for classes
- **Protected methods**: Use for internal functionality that may be extended
- **Error logging**: Use `debug()` method for internal logging when debug mode enabled

## Testing Guidelines
- Place unit tests in `tests/Unit/`
- Use mock objects for dependencies
- Name test methods with `it_can_*` or `it_*` pattern
- Use `@test` annotation for test methods
- Implement tests for all public methods and error cases
- Test both success and failure paths
- Verify consumer group functionality with dedicated tests

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
| client_id | Unique client identifier | auto-generated | string |