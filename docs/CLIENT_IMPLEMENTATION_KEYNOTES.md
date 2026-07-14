# Geodineum Service Daemon (gNode) Client Implementation Keynotes

> **Architecture Primer: Modularity • Security • Extensibility • Resilience**

## Key Stream Connection Pattern

**Stream Naming Convention:**
```
{site_id}:gnode:unified:{environment}    # Unified command stream (client → daemon)
{site_id}:res:{request_id}               # Per-request response key (daemon → client, polled)
```

Braces around `site_id` are literal (Redis Cluster hash-tag routing). The stream is
per-DTAP-environment (`testing|staging|acceptance|production`), not per-node
(`src/gNodeClient.php:297-302`).

## Multi-Node Architecture

- One daemon instance handles multiple sites' unified streams simultaneously
- Each site/environment pair has an isolated unified stream
- No explicit configuration required for additional nodes

## Multi-Site Support

- Currently one site_id per daemon instance
- For multiple sites: Run multiple daemon instances (one per site_id)
- Future architecture will enable single daemon handling multiple sites

## Security Model

- **Defense in Depth**: Client → gCore → ValKey → gNode
- **No Direct Client Access**: External clients never interact directly with gNode
- ValKey serves as authentication boundary and transport layer
- Communication limited to well-defined ValKey streams
- Site_id/node_id in stream names creates natural authorization boundaries
- Command validation prevents injection attacks

## Command Format

Commands travel as XADD fields with canonical short names and **full command names**
(`src/gNodeClient.php:3341-3365`):
```json
{
  "t": "c",                        // Type: command
  "id": "example.com:6864...",     // Request id (uniqid, site-prefixed)
  "c": "geometric_discover",       // Full command name (no abbreviation on the wire)
  "p": "{\"capabilities\":[...],\"_request_id\":\"...\"}", // JSON-encoded params
  "ss": "example.com",             // Source site
  "sn": "client",                  // Source node
  "ts": "1751500000000.123"        // Unix timestamp, MILLISECONDS
}
```

Long-form aliases (`command`, `params`, `site_id`, ...) are accepted daemon-side
(`gNode daemon/src/utils.rs::field_names`) but this client always emits the short forms.

## Response Format

The daemon writes the response JSON to the per-request response key; the client polls it
via `FCALL GNODE_CACHE_GET` with exponential backoff (`src/gNodeClient.php:3466+`):
```json
{
  "status": "ok",              // "ok" or "error"
  "result": {},                // Result data (present on success)
  "error": null                // Error message (present on failure)
}
```

## Command Processing Flow

1. Client XADDs command to the unified stream
2. Daemon reads command via consumer group `gnode-daemon`
3. Command processor validates and routes command
4. Handler processes command and produces result
5. Daemon writes response to `{site_id}:res:{request_id}`
6. Client polls the response key until hit or timeout

## ValKey Functions vs Direct Commands

- **ValKey Functions** (primary): High-performance compiled functions
- **Lua Scripts** (fallback): When ValKey functions unavailable
- **Direct ValKey Commands** (ultimate fallback): For basic functionality

## Batch Processing

- Prefer batch operations where possible
- Correlate commands/responses by their IDs
- Adaptive batch sizes adjust to system load

## Command Library

### System Commands
- `ping` - Health check returning "true"
- `status`/`info` - gNode daemon status
- `health` - Health check
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
- `registerService` - Register service with capabilities
- `findServices` - Discover services by capability
- `getServiceDetails` - Service information
- `getLoadSequence` - Optimal loading sequence

## Error Handling Strategy

- **Transient Failures**: Implement exponential backoff (1s, 2s, 4s...)
- **Persistent Failures**: Fall back to direct ValKey operations
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

- Single-node throughput depends on hardware and command mix — benchmark with
  your own workload.
- Batch mode amortizes round-trips and improves with batch size.
- Multi-node: near-linear scaling with additional nodes.

---

**SPR Tags**: #service-discovery #capability-space #geometric-topology #n-dimensional #valkey-functions #stream-processing #modular-architecture #resilient-design #valkey-streams

**SPR Vectors**: [service_mesh, 0.87], [distributed_systems, 0.92], [microservices, 0.78], [capability_mapping, 0.95], [topology_management, 0.89]

**SPR Prompt**: Consider service capability as coordinates in n-dimensional space for O(1) discovery.