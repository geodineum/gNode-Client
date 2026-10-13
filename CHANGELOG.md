# Changelog

## 0.2.0 — unreleased (one topology)

Pre-release clean break: the client declares by name and the daemon codes. Wire shapes
of `registerService` and `geometric_discover` changed with gNode's one topology
(`SPEC-ONE-TOPOLOGY.md`); a daemon on the old tier schema will refuse these.

### Added
- `Topology\Declaration` — shape words → shape keys, legacy axis map → `{id, shape, environment, axes, metadata, on, capacity_weight, heartbeat, holds, requires, peers, constellation}`; numbers refused before the wire.
- `Topology\Query` — `{layer, facets, axes, limit}` from legacy requirement shapes; `relayTarget()` for `_rt`.
- `gNodeClient::query(array)` — one `geometric_discover`, results carry `service_id, shape, environment, colour, metadata`.

### Changed
- `registerService()` sends the declaration by name; registration facts (`shape`, `on`, `capacity_weight`, `heartbeat`, `holds`, `requires`, `peers`, `constellation`) may ride in `$metadata` and are lifted out.
- `geometricDiscover()`, `findServices()`, `discoverByCapabilities()`, `discoverRange()` take words, lists and `[lo, hi]` ranges; `discoverRange()` answers service ids; all gain a `$layer` argument.
- `sendCommandToCapable()` puts the query in `_rt`; the daemon picks service and placement once per relay. `candidate` and `exclude_self` options are gone.
- `getPublishedCapabilitySchema($shape)`, `capabilityDimensionCount($shape)` (24), `capabilityDiscoveryWidth($shape)` (provider-written slots), `getCapabilityDimensions($shape)` read the per-shape schema at `{ns}:gnode:constellation:schema:<shape>`.
- `getServiceCapabilities()` reads the entity record (`HGET …:constellation:entities`) and answers colour words plus derived load and health.

### Removed
- `geometricStoreTopology()`, `translateCapabilitiesToCoordinates()`, `translateCoordinatesToHuman()`, `getBuiltinCapabilitySchema()`, the built-in dimension tables and `getDimensionIndex()`/`normalizeConstraint()`.
- `dep*`, `registry*`, `cross*` wrappers and their interface declarations; `GNODE_DEP_*`/`GNODE_REGISTRY_*`/`GNODE_CROSS_*` leave the premium prefix map (retired with the custom-topology engine).
- `gNodeConfig::getTopologyKey()` (no callers; the one topology key is the daemon's).


## 0.1.2 — unreleased

### Fixed
- `trackMetric()` no longer sends an empty string in the `extra_json` slot of
  `GNODE_MONITORING_TRACK_METRIC`. Lua treats `''` as present, so the daemon
  rejected every call with `Invalid JSON for extra data` — metric detail has
  been silently absent and every client init logged the failure. The argument
  is now omitted when there is no extra data, matching the contract in
  `COMMAND_SCHEMA.md`. The Lua side was fixed in the same change to treat an
  empty argument as absent, so old and new clients both work.

## 0.1.1 — 2026-07-24

Everything under "Unreleased" below ships in 0.1.1. Wire notes for this
release across the ecosystem:

- NEW wire surface (gNode): signed receipt stream `{site}:gnode:receipts:{env}`
  — an ed25519-signed durable receipt XADDed beside every keyed
  `{ss}:res:{id}` reply (fail-closed; body by reference + sha256). Verifier
  pubkeys at `{topology_ns}:gnode:receipt_pubkeys` (field = signer
  fingerprint, value = `alg:pubkey_hex`). Additive: the keyed reply and the
  legacy `t=r`/`br` stream entries are unchanged in this release.
- RETIRED (this repo): the `gnode-client` response consumer group is no longer
  created; response delivery is keyed-rendezvous only. The daemon stays
  tolerant of older clients.

## Unreleased

### Removed
- `registerCapabilityDimension()` — removed from `gNodeClient`, `gNodeClientInterface`, and `FallbackHandler`. It sent a command no daemon handler implements; capability dimensions are schema-driven (`service_schema.yaml`).
- `registerWithGeodineum()` — removed from `gNodeClient` and `gNodeClientInterface`. It invoked a Lua function that exists in no library and always returned failure; site streams are provisioned by the installer/onboard path, not by clients.

### Changed
- Pro-capability gating unified to ONE style: `fcallDecode()` gates Pro-extension
  function prefixes (gNode-TOPO / gNode-OBSERVE / gNode-BROKER) and returns the
  structured `{error, premium: true, feature}` shape; the former mix of raw
  "Function not found" errors and works-when-loaded behavior is gone.
- Legacy endpoint methods (`registerEndpoint`, `getEndpoint`, `listEndpoints`,
  `findEndpoints`, `translateBetweenEndpoints`, `translateToInternal`,
  `translateFromInternal`, `deregisterEndpoint`, `getEndpointSchema`,
  `registerTranslationRule`, `translateEndpointMessage`) now delegate to their
  canonical `endpoint*` siblings; their raw `getRedis()->rawCommand` path is deleted.
- `ValKeyStorage::getRedis()` now throws `StorageException` (was: deprecation
  warning + raw connection). Internal consumers migrated to the typed storage
  API — new `StorageInterface::incr()`/`pfAdd()` for metrics; client `ping()`
  uses `storage->ping()`.

### Fixed
- `postToGeodineum()` called nonexistent `ValKeyStorage::rawCommand()` — a
  fatal `Error` uncaught by its `catch (\Exception)` — now uses `xAdd()`.
- `docs/COMMAND_SIGNATURES.md` rewritten from the command-construction code: commands travel with full names and canonical short fields (`t/id/c/p/ss/sn/ts`, `ts` in milliseconds); the former command-abbreviation table and microsecond-timestamp examples described behavior that does not exist.

## 2.0.0 - 2025-04-28

### Added
- Support for new batch message types (`bc` for batch commands, `br` for batch responses)
- New `executeBatch()` method for efficient batch command execution
- Enhanced batch response handling in ConsumerGroupHandler
- Batch command support in ConsumerGroupHandler with new `sendBatchCommand()` method
- Support for the updated gNode unified stream protocol (v2)

### Changed
- Updated response filtering to recognize 'br' type batch responses
- Modified batch message format according to gNode protocol v2
- Updated README with new message type documentation
- Added detailed batch command and response documentation

### Fixed
- Eliminated processing loop issue by differentiating batch command and response types

## 1.5.0 - 2025-04-15

### Added
- Command name abbreviation support for optimized messaging
- Enhanced protocol support with RESP3 optimization
- Val1Key function integration for protocol operations

### Changed
- Unified stream handling improvements for performance
- Optimized response parsing for reduced protocol overhead

## 1.0.0 - 2025-03-20

### Added
- Initial release of the gNode client library
- Support for geometric service discovery
- Support for consumer group operations
- ValKey stream communication
- Fallback handling for resilience
- Client capabilities API
- Support for multiple connection modes