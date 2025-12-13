# GSD Command Signatures Reference

> This document provides a detailed reference for all command signatures in the Geodineum Service Daemon (GSD) system, including parameter formats, expected return values, and usage examples.

## Command Format
All commands follow this standard format with optimized field names:

```json
{
  "t": "c",                    // Type: "c" for command
  "id": "cmd-123",             // Unique identifier
  "c": "command_name",         // Command name (see below)
  "p": "{}",                   // JSON-encoded parameters (detailed below)
  "ss": "nierto.com",          // Source site identifier
  "sn": "host_www_1",          // Source node identifier
  "ts": 1619712345.678         // Unix timestamp with μs precision
}
```

## Response Format
All responses follow this standard format with optimized field names:

```json
{
  "t": "r",                    // Type: "r" for response 
  "id": "cmd-123",             // Original command ID
  "s": "ok",                   // "ok" or "error"
  "r": "{}",                   // JSON-encoded result data (present on success)
  "m": null,                   // Error message (present on failure)
  "ts": 1619712345.789         // Response timestamp
}
```

## Field Name Mapping

| Optimized | Full Name    | Description                            |
|-----------|--------------|----------------------------------------|
| `t`       | `type`       | Message type (c=command, r=response)   |
| `c`       | `command`    | Command name                           |
| `p`       | `params`     | Command parameters                     |
| `id`      | `id`         | Message ID                             |
| `r`       | `result`     | Command result                         |
| `s`       | `status`     | Response status (ok, error)            |
| `m`       | `message`    | Response message                       |
| `ts`      | `timestamp`  | Message timestamp                      |
| `ss`      | `source_site`| Source site ID                         |
| `sn`      | `source_node`| Source node ID                         |
| `ds`      | `dest_site`  | Destination site ID                    |
| `dn`      | `dest_node`  | Destination node ID                    |

## Command Name Abbreviations

To optimize message size, commands use abbreviated names in the unified stream protocol:

| Full Command Name            | Abbreviated Name  |
|------------------------------|-------------------|
| `ping`                       | `ping`            |
| `status`                     | `status`          |
| `health`                     | `health`          |
| `version`                    | `version`         |
| `geometric_discover`         | `geo_disc`        |
| `geometric_store_topology`   | `geo_store`       |
| `geometric_load_sequence`    | `geo_seq`         |
| `geometric_distance`         | `geo_dist`        |
| `geometric_dimensions`       | `geo_dim`         |
| `stream_info`                | `str_info`        |
| `stream_group_info`          | `str_group`       |
| `stream_consumer_info`       | `str_cons`        |
| `stream_pending`             | `str_pend`        |
| `get_node_info`              | `node_info`       |
| `get_site_info`              | `site_info`       |
| `registerCapabilityDimension`| `reg_dim`         |
| `registerService`            | `reg_svc`         |
| `findServices`               | `find_svc`        |
| `getServiceDetails`          | `get_svc`         |
| `getLoadSequence`            | `geo_seq`         |
| `getCapabilityDimensions`    | `geo_dim`         |

---

## System Commands

### ping
Simple health check that returns a boolean response.

**Parameters:**
- `message` (string, optional): Custom message to echo back

**Returns:**
- If message is provided: The message string
- Otherwise: Boolean `true`

**Example:**
```json
// Request
{
  "command": "ping",
  "parameters": {
    "message": "Hello GSD"
  }
}

// Response
{
  "status": "ok",
  "result": "Hello GSD"
}
```

### status / info
Get the GSD daemon status information.

**Parameters:**
- `detail` (string, optional): Detail level - "basic" (default) or "full"

**Returns:**
- JSON object with version, uptime, timestamp
- With "full" detail: additional connection pool stats, Redis info, supported commands, ValKey functions status

**Example:**
```json
// Request
{
  "command": "status",
  "parameters": {
    "detail": "full"
  }
}

// Response
{
  "status": "ok",
  "result": {
    "version": "1.2.3",
    "uptime": 12345,
    "timestamp": 1619712345.678,
    "connections": {
      "active": 5,
      "idle": 3,
      "max": 20
    },
    "valkey_functions": {
      "loaded": 106,
      "available": 106
    }
  }
}
```

### health
Comprehensive health check of the system's components.

**Parameters:**
- None

**Returns:**
- JSON object with overall health status and component statuses

**Example:**
```json
// Request
{
  "command": "health",
  "parameters": {}
}

// Response
{
  "status": "ok",
  "result": {
    "overall": "healthy",
    "components": {
      "valkey": "healthy",
      "connection_pool": "healthy",
      "valkey_functions": "healthy",
      "stream_processor": "healthy"
    }
  }
}
```

### version
Get version information about the daemon.

**Parameters:**
- None

**Returns:**
- JSON object with version details

**Example:**
```json
// Request
{
  "command": "version",
  "parameters": {}
}

// Response
{
  "status": "ok",
  "result": {
    "version": "1.2.3",
    "build_date": "2025-04-01",
    "build_number": "12345",
    "patch_level": "1",
    "valkey_functions_version": "1.0.0",
    "rust_version": "1.75.0"
  }
}
```

### echo
Echo back the input (for testing).

**Parameters:**
- `text` (string, optional): Text to echo back, defaults to "Hello"

**Returns:**
- The provided text string

**Example:**
```json
// Request
{
  "command": "echo",
  "parameters": {
    "text": "Testing 123"
  }
}

// Response
{
  "status": "ok",
  "result": "Testing 123"
}
```

---

## Geometric Commands

### geometric_discover
Find services that meet capability requirements.

**Parameters:**
- `capabilities` (array of objects): Required capabilities to search for
- `limit` (integer, optional): Maximum number of services to return, defaults to 10

**Returns:**
- JSON object with matching services, count, and total available

**Example:**
```json
// Request
{
  "command": "geometric_discover",
  "parameters": {
    "capabilities": [
      {"dimension": "performance", "value": 0.7},
      {"dimension": "reliability", "value": 0.8}
    ],
    "limit": 5
  }
}

// Response
{
  "status": "ok",
  "result": {
    "services": [
      {"id": "service1", "distance": 0.1, "capabilities": {...}, "metadata": {...}},
      {"id": "service2", "distance": 0.2, "capabilities": {...}, "metadata": {...}}
    ],
    "count": 2,
    "total": 10
  }
}
```

### geometric_store_topology
Store topology information in the system.

**Parameters:**
- `topology` (JSON object): Topology structure to store
- `dimensions` (integer, optional): Number of dimensions, defaults to 8

**Returns:**
- Success status

**Example:**
```json
// Request
{
  "command": "geometric_store_topology",
  "parameters": {
    "topology": {
      "dimensions": {
        "performance": 0,
        "reliability": 1,
        "scalability": 2
      },
      "services": {
        "service1": {
          "point": [0.9, 0.8, 0.7],
          "metadata": {"name": "Service 1"}
        },
        "service2": {
          "point": [0.8, 0.9, 0.6],
          "metadata": {"name": "Service 2"}
        }
      }
    },
    "dimensions": 3
  }
}

// Response
{
  "status": "ok",
  "result": true
}
```

### geometric_load_sequence
Get the optimal service load sequence.

**Parameters:**
- `group` (string, optional): Group name, defaults to "default"

**Returns:**
- JSON array of service IDs in optimal load order

**Example:**
```json
// Request
{
  "command": "geometric_load_sequence",
  "parameters": {
    "group": "web_services"
  }
}

// Response
{
  "status": "ok",
  "result": ["service2", "service1", "service3"]
}
```

### geometric_distance
Calculate the Euclidean distance between two points in n-dimensional space.

**Parameters:**
- `point1` (array of floats): First point coordinates
- `point2` (array of floats): Second point coordinates

**Returns:**
- JSON object with distance and dimensions

**Example:**
```json
// Request
{
  "command": "geometric_distance",
  "parameters": {
    "point1": [0.1, 0.2, 0.3],
    "point2": [0.4, 0.5, 0.6]
  }
}

// Response
{
  "status": "ok",
  "result": {
    "distance": 0.5196,
    "dimensions": 3
  }
}
```

### geometric_dimensions
Get information about registered capability dimensions.

**Parameters:**
- None

**Returns:**
- JSON object mapping capability names to dimensions

**Example:**
```json
// Request
{
  "command": "geometric_dimensions",
  "parameters": {}
}

// Response
{
  "status": "ok",
  "result": {
    "performance": 0,
    "reliability": 1,
    "scalability": 2
  }
}
```

---

## Stream Commands

### stream_info
Get information about a stream.

**Parameters:**
- `stream` (string): Stream name

**Returns:**
- JSON object with stream information

**Example:**
```json
// Request
{
  "command": "stream_info",
  "parameters": {
    "stream": "{nierto.com}:gsd:stream:host_www_1:commands"
  }
}

// Response
{
  "status": "ok",
  "result": {
    "length": 1024,
    "radix_tree_keys": 1,
    "radix_tree_nodes": 2,
    "groups": 1,
    "first_entry": ["1619712345678-0", {"id": "cmd-123", "command": "ping"}],
    "last_entry": ["1619712345789-0", {"id": "cmd-124", "command": "status"}]
  }
}
```

### stream_group_info
Get information about consumer groups for a stream.

**Parameters:**
- `stream` (string): Stream name
- `group` (string, optional): Group name to filter results

**Returns:**
- JSON object/array with consumer group information

**Example:**
```json
// Request
{
  "command": "stream_group_info",
  "parameters": {
    "stream": "{nierto.com}:gsd:stream:host_www_1:commands",
    "group": "gsd-daemon"
  }
}

// Response
{
  "status": "ok",
  "result": {
    "name": "gsd-daemon",
    "consumers": 2,
    "pending": 5,
    "last_delivered_id": "1619712345789-0"
  }
}
```

### stream_consumer_info
Get information about consumers in a consumer group.

**Parameters:**
- `stream` (string): Stream name
- `group` (string): Group name
- `consumer` (string, optional): Consumer name to filter results

**Returns:**
- JSON object/array with consumer information

**Example:**
```json
// Request
{
  "command": "stream_consumer_info",
  "parameters": {
    "stream": "{nierto.com}:gsd:stream:host_www_1:commands",
    "group": "gsd-daemon",
    "consumer": "consumer1"
  }
}

// Response
{
  "status": "ok",
  "result": {
    "name": "consumer1",
    "pending": 3,
    "idle": 10000
  }
}
```

### stream_pending
Get information about pending messages in a consumer group.

**Parameters:**
- `stream` (string): Stream name
- `group` (string): Group name
- `count` (integer, optional): Maximum number of pending entries to return, defaults to 10

**Returns:**
- JSON array of pending messages

**Example:**
```json
// Request
{
  "command": "stream_pending",
  "parameters": {
    "stream": "{nierto.com}:gsd:stream:host_www_1:commands",
    "group": "gsd-daemon",
    "count": 5
  }
}

// Response
{
  "status": "ok",
  "result": [
    {"id": "1619712345678-0", "consumer": "consumer1", "idle": 5000, "deliveries": 1},
    {"id": "1619712345679-0", "consumer": "consumer2", "idle": 4000, "deliveries": 2}
  ]
}
```

---

## Node/Site Commands

### get_node_info / node_info
Get information about a specific node.

**Parameters:**
- `node` (string, optional): Node ID, defaults to "default"

**Returns:**
- JSON object with node statistics, thread pools, consumer status

**Example:**
```json
// Request
{
  "command": "get_node_info",
  "parameters": {
    "node": "host_www_1"
  }
}

// Response
{
  "status": "ok",
  "result": {
    "id": "host_www_1",
    "uptime": 12345,
    "threads": 8,
    "commands_processed": 1000,
    "responses_sent": 998,
    "consumers": [
      {"name": "consumer1", "group": "gsd-daemon", "stream": "commands"}
    ]
  }
}
```

### get_site_info / site_info
Get information about a specific site.

**Parameters:**
- `site` (string, optional): Site ID, defaults to "default"

**Returns:**
- JSON object with site statistics, dimensions, ValKey functions information

**Example:**
```json
// Request
{
  "command": "get_site_info",
  "parameters": {
    "site": "nierto.com"
  }
}

// Response
{
  "status": "ok",
  "result": {
    "id": "nierto.com",
    "nodes": 3,
    "services": 15,
    "dimensions": 8,
    "valkey_functions": {
      "loaded": 106,
      "available": 106
    }
  }
}
```

---

## Client-level Commands

### registerCapabilityDimension
Register a capability dimension in the geometric topology.

**Parameters:**
```json
{
  "name": "dimension_name",
  "dimension": integer_value
}
```

**Returns:**
- Boolean success status

**Example:**
```json
// Request
{
  "command": "registerCapabilityDimension",
  "parameters": {
    "name": "performance",
    "dimension": 0
  }
}

// Response
{
  "status": "ok",
  "result": true
}
```

**PHP Usage:**
```php
$client->registerCapabilityDimension('performance', 0);
```

### registerService
Register a service with capabilities.

**Parameters:**
```json
{
  "id": "service_identifier",
  "capabilities": {
    "dimension1": float_value,
    "dimension2": float_value,
    "dimension3": float_value
  },
  "metadata": {
    "key1": "value1",
    "key2": "value2"
  }
}
```

**Returns:**
- Boolean success status

**Example:**
```json
// Request
{
  "command": "registerService",
  "parameters": {
    "id": "database-service",
    "capabilities": {
      "performance": 0.9,
      "reliability": 0.8,
      "scalability": 0.7
    },
    "metadata": {
      "name": "Database Service",
      "version": "1.0",
      "description": "Primary database service"
    }
  }
}

// Response
{
  "status": "ok",
  "result": true
}
```

**PHP Usage:**
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

### findServices
Find services based on capability requirements.

**Parameters:**
```json
{
  "requirements": {
    "dimension1": float_min_value,
    "dimension2": float_min_value
  }
}
```

**Returns:**
- Array of matching services with capabilities and metadata

**Example:**
```json
// Request
{
  "command": "findServices",
  "parameters": {
    "requirements": {
      "performance": 0.7,
      "reliability": 0.5
    }
  }
}

// Response
{
  "status": "ok",
  "result": [
    {
      "id": "database-service",
      "distance": 0.1,
      "capabilities": {
        "performance": 0.9,
        "reliability": 0.8,
        "scalability": 0.7
      },
      "metadata": {
        "name": "Database Service",
        "version": "1.0"
      }
    },
    {
      "id": "cache-service",
      "distance": 0.15,
      "capabilities": {
        "performance": 0.95,
        "reliability": 0.7,
        "scalability": 0.8
      },
      "metadata": {
        "name": "Cache Service",
        "version": "2.1"
      }
    }
  ]
}
```

**PHP Usage:**
```php
$services = $client->findServices([
    'performance' => 0.7,
    'reliability' => 0.5
]);
```

### getServiceDetails
Get details about a specific service.

**Parameters:**
```json
{
  "id": "service_identifier"
}
```

**Returns:**
- JSON object with service capabilities and metadata

**Example:**
```json
// Request
{
  "command": "getServiceDetails",
  "parameters": {
    "id": "database-service"
  }
}

// Response
{
  "status": "ok",
  "result": {
    "capabilities": {
      "performance": 0.9,
      "reliability": 0.8,
      "scalability": 0.7
    },
    "metadata": {
      "name": "Database Service",
      "version": "1.0",
      "description": "Primary database service"
    }
  }
}
```

**PHP Usage:**
```php
$details = $client->getServiceDetails('database-service');
```

### getLoadSequence
Get the optimal service load sequence.

**Parameters:**
```json
{}
```

**Returns:**
- Array of service IDs in optimal load order

**Example:**
```json
// Request
{
  "command": "getLoadSequence",
  "parameters": {}
}

// Response
{
  "status": "ok",
  "result": [
    "config-service",
    "database-service",
    "cache-service",
    "api-service"
  ]
}
```

**PHP Usage:**
```php
$sequence = $client->getLoadSequence();
```

---

## ValKey Stream Functions

These functions are used internally by the GSD daemon for stream operations:

### GSD_STREAM_ADD
Add an entry to a stream.

**Redis Function Signature:**
```
GSD_STREAM_ADD stream_key request_id command_name command_parameters [site_id] [node_id] [timestamp]
```

### GSD_STREAM_GROUP_READ
Read messages from a stream using consumer groups.

**Redis Function Signature:**
```
GSD_STREAM_GROUP_READ stream_key group_name consumer_name [count] [block_time] [starting_id]
```

### GSD_STREAM_ACK
Acknowledge messages in a consumer group.

**Redis Function Signature:**
```
GSD_STREAM_ACK stream_key group_name message_ids
```

### GSD_STREAM_RESPOND
Add a response entry to a stream.

**Redis Function Signature:**
```
GSD_STREAM_RESPOND stream_key message_id response_json
```

---

## Implementation Notes

1. **Capability Values:**
   - Should be floating-point numbers between 0 and 1
   - Higher values indicate better capability
   - Values are stored in n-dimensional capability space

2. **Unique Identifiers:**
   - Service IDs must be unique within a site/territory
   - Command IDs should be unique, preferably using UUID v4

3. **Connection Management:**
   - Support both direct communication mode and fallback mode
   - Implement proper error handling and retry logic
   - Pool connections for optimal performance

4. **Stream Pattern:**
   - Unified Stream: `{site_id}:gsd:unified:{node_id}`
   - Message Type Field: 't' ("c" for commands, "r" for responses)
   - Consumer Groups: 'gsd-command-processor' (daemon), 'gsd-client' (clients)

5. **Performance Tuning:**
   - Use batch operations for higher throughput
   - Reuse connections to reduce latency
   - Consider connection pooling for concurrent operations

6. **Client Security:**
   - Validate all inputs before sending commands
   - Implement proper authentication with ValKey/Redis
   - Avoid exposing GSD streams directly to end users

7. **Error Handling:**
   - Parse response status field to determine success
   - Handle transient failures with exponential backoff
   - Implement proper logging and monitoring
   - Provide debugging information as needed

8. **Multi-Language Considerations:**
   - Follow JSON standards for cross-language compatibility
   - Use portable numeric timestamp formats
   - Avoid language-specific serialization formats