# Geodineum Service Daemon (GSD) Client Implementation Keynotes

> **Architecture Primer: Modularity • Security • Extensibility • Resilience**

## Key Stream Connection Pattern

**Stream Naming Convention:**
```
{site_id}:gsd:stream:{node_id}:commands    # Command Stream
{site_id}:gsd:stream:{node_id}:responses   # Response Stream
```

**Default Values:**
```
{default}:gsd:stream:default:commands
{default}:gsd:stream:default:responses
```

## Multi-Node Architecture

- One daemon instance handles **multiple nodes simultaneously**
- Stream processor auto-discovery finds all command streams matching `{site_id}:gsd:stream:*:commands`
- Each node maintains isolated communication channels
- No explicit configuration required for additional nodes

## Multi-Site Support

- Currently one site_id per daemon instance
- For multiple sites: Run multiple daemon instances (one per site_id)
- Future architecture will enable single daemon handling multiple sites

## Security Model

- **Defense in Depth**: Client → gCore → ValKey → GSD
- **No Direct Client Access**: External clients never interact directly with GSD
- ValKey serves as authentication boundary and transport layer
- Communication limited to well-defined Redis streams
- Site_id/node_id in stream names creates natural authorization boundaries
- Command validation prevents injection attacks

## Command Format

**Required Fields:**
```json
{
  "command": "geometric_discover", // Command name
  "params": {                  // Command-specific parameters
    "capabilities": [{"dimension": "caching", "value": 0.8}]
  },
  "site_id": "nierto.com",     // Site identifier
  "node_id": "host_www_1",     // Node identifier
  "id": "cmd-123",             // Unique identifier
  "timestamp": 1619712345.678  // Unix timestamp with μs precision
}
```

**IMPORTANT**: Note that "params" is the correct field name (not "parameters"), and field ordering matters!

## Response Format

```json
{
  "id": "cmd-123",             // Original command ID
  "status": "ok",              // "ok" or "error"
  "result": {},                // Result data (present on success)
  "error": null,               // Error message (present on failure)
  "timestamp": 1619712345.789  // Response timestamp
}
```

## Command Processing Flow

1. Client writes command to command stream
2. Daemon reads command from stream
3. Command processor validates and routes command
4. Handler processes command and produces result
5. Response sent to response stream
6. Client reads response from response stream

## ValKey Functions vs Direct Commands

- **ValKey Functions** (primary): High-performance compiled functions
- **Lua Scripts** (fallback): When ValKey functions unavailable
- **Direct Redis Commands** (ultimate fallback): For basic functionality

## Batch Processing

- Prefer batch operations where possible
- Correlate commands/responses by their IDs
- Adaptive batch sizes adjust to system load

## Command Library

### System Commands
- `ping` - Health check returning "true"
- `status`/`info` - GSD daemon status
- `health` - Comprehensive health check
- `version` - Version information

### Geometric Commands
- `geometric_discover` - Find services by capabilities
- `geometric_store_topology` - Store topology information
- `geometric_load_sequence` - Optimal service load ordering
- `geometric_distance` - Calculate distance between points
- `geometric_dimensions` - Get capability dimensions

### Stream Commands
- `stream_info` - Stream information
- `stream_group_info` - Consumer group information
- `stream_consumer_info` - Consumer information
- `stream_pending` - Pending message information

### Node/Site Commands
- `get_node_info`/`node_info` - Node information
- `get_site_info`/`site_info` - Site information

### Client-level Commands
- `registerCapabilityDimension` - Register dimension
- `registerService` - Register service with capabilities
- `findServices` - Discover services by capability
- `getServiceDetails` - Service information
- `getLoadSequence` - Optimal loading sequence

## Error Handling Strategy

- **Transient Failures**: Implement exponential backoff (1s, 2s, 4s...)
- **Persistent Failures**: Fall back to direct Redis operations
- **Network Interruptions**: Buffer commands and retry
- **Timeouts**: Default 30s, configurable per command

## Client Implementation Guidelines

1. **Modularity**: Separate connection, command, response handling
2. **Extensibility**: Command handler registry pattern
3. **Error Recovery**: Implement proper fallback mechanisms
4. **Connection Pooling**: Reuse connections
5. **Asynchronous Support**: Non-blocking operations
6. **Logging**: Consistent logging with configurable levels
7. **Validation**: Validate all parameters before sending

## Design Principles

- **Cognitive Economy**: Maximum clarity with minimal cognitive overhead
- **SOLID Architecture**: Single responsibility, open/closed, etc.
- **Resilient Design**: Graceful degradation under failure
- **Performance by Design**: Architectural choices optimize for throughput
- **Geometric Abstraction**: Represent capabilities as n-dimensional points

## Implementation Checklist

- [ ] Stream connection management
- [ ] Command serialization/deserialization
- [ ] Response parsing and routing
- [ ] Error handling and recovery
- [ ] Asynchronous operation
- [ ] Batch operation support
- [ ] Logging and diagnostics
- [ ] Connection pooling
- [ ] Command validation

## Benchmarking Expectations

- Single Node: ~3,800 ops/sec on modest hardware
- Batch Mode: 10,000+ ops/sec with optimized batch sizes
- Multi-Node: Near-linear scaling with additional nodes

---

**SPR Tags**: #service-discovery #capability-space #geometric-topology #n-dimensional #valkey-functions #stream-processing #modular-architecture #resilient-design #redis-streams

**SPR Vectors**: [service_mesh, 0.87], [distributed_systems, 0.92], [microservices, 0.78], [capability_mapping, 0.95], [topology_management, 0.89]

**SPR Prompt**: Consider service capability as coordinates in n-dimensional space for O(1) discovery.