# gNode Command Signatures Reference

> Code-derived reference for the unified-stream commands this client constructs and sends.
> Daemon-side semantics (handlers, return shapes, full schema) are canonical in
> `gNode/COMMAND_SCHEMA.md`; alias resolution lives in the daemon
> (`daemon/src/utils.rs::field_names`), not in this client.

## Transport

- Commands: `XADD {site_id}:gnode:unified:{environment}` (braces literal — Redis Cluster
  hash tag; environment is the DTAP scalar, not node_id). Source: `src/gNodeClient.php:297-302`.
- Responses: the daemon writes a JSON blob to the per-request response key
  `{site_id}:res:{request_id}` (`src/gNodeClient.php:3879-3883`); the client polls that key
  via `FCALL GNODE_CACHE_GET` with exponential backoff until hit or timeout
  (`pollForResponse`, `src/gNodeClient.php:3467`). The client does **not** consume responses
  through a consumer group on the primary path.

## Command Format

Built by `sendCommand()` (`src/gNodeClient.php:3341-3365`). All field names are the
canonical short forms; command names travel **in full** — there is no command-name
abbreviation on the wire.

```json
{
  "t": "c",                       // Type: command
  "id": "example.com:6864...",    // Request id (uniqid, site-prefixed, top-level)
  "c": "geometric_discover",      // Full command name
  "p": "{\"limit\":5,\"_request_id\":\"example.com:6864...\"}", // JSON-encoded params (+_request_id merged in)
  "ss": "example.com",            // Source site
  "sn": "client",                 // Source node ("client" on this path; $nodeId via sendRawCommand)
  "ts": "1751500000000.123"       // Unix timestamp in MILLISECONDS (microtime(true)*1000)
}
```

`sendRawCommand()` (`src/gNodeClient.php:4218-4230`) emits the same fields plus caller-supplied
extras, with `sn` set to the configured node id.

## Field Names

The client always writes the short forms below. The daemon accepts long-form aliases
(`command`, `params`, `site_id`, `source_node`, ...) via `daemon/src/utils.rs::field_names`,
but that resolution is a daemon affordance — nothing in this client emits or parses aliases.

| Field | Meaning | Notes |
|-------|--------------|----------------------------------------|
| `t`   | message type | `c`=command, `r`=response, `bc`/`br`=batch, `i`=init, `lu`=load update |
| `id`  | request id   | top-level; also mirrored as `_request_id` inside `p` |
| `c`   | command name | full name, never abbreviated |
| `p`   | params       | JSON-encoded string |
| `ss`  | source site  | |
| `sn`  | source node  | |
| `ts`  | timestamp    | milliseconds; never `t` (collides with type) |

`ds`/`dn` (dest site/node) exist in the daemon schema for addressed messages; this client
never emits them.

## Response Format

Response-key payload, decoded to an array (`safeJsonDecode`, 4 MB / 64-depth caps,
`src/gNodeClient.php:82-97`):

```json
{
  "status": "ok",   // "ok" or "error"
  "result": {},     // present on success
  "error": null     // present on failure
}
```

On the unified stream itself, daemon responses use the short fields `t='r'`, `id`,
`st|s` (status), `r` (result), `e` (error) per `daemon/src/utils.rs::field_names`.

## Batch Format

- Primary batch path (`executeBatch`, `src/gNodeClient.php:1456`): per-command request
  descriptors stored via `luaSet` on request keys, then one batch-notification XADD to the
  unified stream (`site_id`, `type='batch'`, `request_ids`(JSON), `count`, `priority`,
  `ts` ms — `src/gNodeClient.php:1512-1520`); responses polled per request id.
- Consumer-group batch path (`ConsumerGroupHandler::sendBatchCommand`,
  `src/ConsumerGroupHandler.php:480`): single XADD with `t='bc'`, `bi` (batch id),
  `tc` (total count), `m` (JSON message array), `ss`, `sn`, `ts`. Known residual: this
  path stamps `ts` as float **seconds** (`src/ConsumerGroupHandler.php:526`), unlike the
  millisecond command paths.

## Command Vocabulary

Every command name this client sends over the unified stream, with the public method that
constructs it (all in `src/gNodeClient.php`). Parameter and return semantics:
`gNode/COMMAND_SCHEMA.md`.

| Command (wire) | Client method | Source |
|---|---|---|
| `geometric_store_topology` | `geometricStoreTopology(array $topology, int $dimensions=8): bool` | `:1717` |
| `geometric_discover` | `geometricDiscover(array $capabilities, int $limit=10, ...): array`; also `findServices(array $requirements): array` | `:1743`, `:4383` |
| `geometric_dimensions` | `getCapabilityDimensions(): array` | `:1803` |
| `geometric_distance` | `geometricDistance(array $point1, array $point2): array` | `:4468` |
| `geometric_load_sequence` | `getLoadSequence(string $group='default'): array` | `:4450` |
| `registerService` | `registerService(string $id, array $capabilities, array $metadata=[]): bool` | `:4282` |
| `deregisterService` | `deregisterService(string $serviceId): bool` | `:4354` |
| `service_describe` | `getServiceDetails(string $serviceId): array` | `:4419` |
| `stream_info` | `streamInfo(string $stream): array` | `:4124` |
| `stream_group_info` | `streamGroupInfo(string $stream, ?string $group=null): array` | `:4146` |
| `get_node_info` | `getNodeInfo(string $node='default'): array` | `:5651` |
| `get_site_info` | `getSiteInfo(string $site='default'): array` | `:5668` |
| `template_fragment` | `templateFragment(string $templateId, string $content, ...): array` | `:3633` |
| `content_store` | `contentStore(string $key, string $content, ...): array` | `:5189` |
| `content_retrieve` | `contentRetrieve(string $key): array` | `:5230` |
| `asset_bundle` | `assetBundle(string $bundleId, array $assets, ...): array` | `:5262` |
| `custom_topology_discover` | `discoverCustomPrecise(string $topologyKey, array $requirements, ...): ?array` | `:7585` |
| `custom_topology_distance` | `customTopologyDistance(string $topologyKey, array $point1, array $point2): ?array` | `:7608` |
| `custom_topology_knn` | `customTopologyKnn(string $topologyKey, array $queryPoint, int $k=5): ?array` | `:7631` |
| `custom_topology_similarity` | `customTopologySimilarity(...)` | `:7655` |

`executeCommand(string $command, array $parameters=[]): ?array` (`:3267`) is the public
generic passthrough for any command name.

Everything else on the public surface is an FCALL wrapper, not a stream command —
`GNODE_CACHE_*`, `GNODE_HASH_*`, `GNODE_DEP_*`, `GNODE_REGISTRY_*`, and the rest of the
allowlisted `^(GNODE|GCUBE|COMMS|GC)_[A-Z0-9_]+$` surface. See `CONTRACT.md`.

## Implementation Notes

1. **Consumer groups:** `gnode-daemon` (daemon) and `gnode-client` on the unified and
   health streams (`src/gNodeClient.php:627-652`). There is no `gnode-command-processor`
   group.
2. **Timestamps:** milliseconds everywhere on the command paths; portable string-encoded
   numerics on the wire.
3. **Caching:** `sendCommand` has a request-scoped response cache keyed on the command
   names `findServices`/`getLoadSequence`/`getCapabilityDimensions`
   (`src/gNodeClient.php:3328-3338`) — names the current wrappers no longer emit (they
   send `geometric_*`), so it only engages via `executeCommand` with those literal names.
4. **Failure handling:** first poll timeout sets a sticky daemon-unreachable flag so
   subsequent commands in the request fail fast with full diagnostics
   (`src/gNodeClient.php:3321-3326`); optional `FallbackHandler` degraded mode executes
   supported commands locally.
5. **Security:** FCALL names are allowlist-gated before wire I/O
   (`src/gNodeClient.php:938`); streams are ACL-scoped per site
   (`gnode_client_{site_id}`, `~{site_id}:gnode:*`).
