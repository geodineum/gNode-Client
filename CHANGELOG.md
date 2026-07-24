# Changelog

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