# gNode-Client :: CONTRACT primer (SCN)

> one-line: SCN primer — TRUTH = code on disk, this file is a point-in-time compression. Companion: CONTRACT.md (authoritative).

## ::ROLE
PHP client lib for the gNode daemon (gNode=Sun) over RESP3/ValKey (=common backend). FCALL-only ops + stream-based async queueing + geometric topology discovery. Stateless yet state-aware via shared ValKey streams/hashes/sets. PSR-4 `gCore\gNode`, Composer-installable. Consumed by gCore/gTemplate/gCube.

## ::ANCHOR
- entry: `gNodeClient` class `src/gNodeClient.php` · `implements gNodeClientInterface`
- factory: `forSite(siteId, environment='production', overrides=[])` (canonical)
- cache: `luaGet` → `FCALL GNODE_CACHE_GET` · `luaSet` → `FCALL GNODE_CACHE_SET`
- fcall gate: `fcall(fn,keys,args)`; allowlist regex `^(GNODE|GCUBE|COMMS|GC)_[A-Z0-9_]+$` (throws pre-wire)
- comms: `queueCommsMessage` · `queueContactForm` · `getCommsStream`
- orch: `postToGeodineum` (write-only XADD; the sole orchestration surface)
- stream keys: `getComputeStream` (=unified stream the daemon listens on) · `getHealthStream` · `getBroadcastStream`
- groups: `ensureConsumerGroups` (`gnode-daemon`+`gnode-client` on unified+health) · `getStreamStatus`
- storage: `ValKeyStorage::fcall` `src/Storage/ValKeyStorage.php` · `::xAdd` (RESP3, phpredis, port 47445)
- health: `HealthStreamWriter::publishMetrics` `src/Health/HealthStreamWriter.php` · `HealthMetrics::toCompressedFormat` `src/Health/HealthMetrics.php`
- broadcast: `BroadcastReader::read(count=100,blockMs=0,typeFilter=null)` `src/Broadcast/BroadcastReader.php`
- creds: `src/Config/CredentialResolver.php` 5-level chain
- streams (∀ brace-literal `{siteId}` = Cluster hash-tag):
  `{siteId}:gnode:unified:{env}` · `{siteId}:gnode:health:{env}` · `{siteId}:gnode:broadcast:global` · `{siteId}:gnode:comms:{env}` · `geodineum:gnode:orchestration` (HARDCODED)

## ::ARCHITECTURE
PHP/PSR-4, single public class `gNodeClient` (80+ methods) delegating to submodules: Storage(ValKeyStorage RESP3/phpredis port 47445) · ConsumerGroupHandler · Health(Metrics+StreamWriter, compressed names) · Broadcast(Reader+Message) · Discovery/* · Format/* · Config/*(CredentialResolver,gNodeConfig) · Queue/*(CommandQueue) · Exception/* · Fallback/*.
KEY design: FCALL-only (no raw Redis cmds; ACL-enforced Lua) · stream async (XADD fire-and-forget + consumer groups for reliable delivery) · per-env DTAP isolation (stream keys carry `{environment}`, NOT node_id) · hash-tagged keys (braces ∀ site_id) for Cluster safety · size-capped JSON decode (4MB/64-depth) as daemon-bug defense · transient-error retry w/ exp backoff · stable client-id (hostname-pid) for group uniqueness · best-effort metrics (INCR+PFADD on `{site}:metrics:*` for gDash) · graceful degradation via FallbackHandler (local exec if daemon unreachable) · environment scalar = authoritative for non-prod gating (NOT nested metadata).

## ::IO
IN ← ValKey daemon (port 47445): FCALL responses (JSON str via safeJsonDecode 4MB/64-depth → default on fail, or scalar); creds (64-hex plaintext, file chain `VALKEY_PASSWORD_FILE`→`/etc/geodineum/credentials/{site}.password`→legacy); registered Lua fns (`FUNCTION LOAD`, names `GNODE|GCUBE|COMMS|GC_*`).
IN ← daemon as stream writer: broadcast stream (no group, self-track position).
OUT → ValKey XADD:
- comms `{siteId}:gnode:comms:{env}`: scalar `id,type,timestamp,site_id,environment(AUTH),priority` + JSON `sender,content,metadata,dispatch`
- orchestration `geodineum:gnode:orchestration`: `type,site_id,environment,data(JSON),timestamp`
- health `{siteId}:gnode:health:{env}` `HealthMetrics.php`: `t='lu',si,l,[cpu],[mem],[rq],[lat],[err],ts` (∀ numeric string-encoded)
- unified `{siteId}:gnode:unified:{env}` (Proto v2, `CLAUDE.md`): `t,c,p(JSON),id,ss,sn,ts,[bi],[tc],[m],[_gh],[_cr]`
OUT → FCALL: `GNODE_CACHE_*`, `GNODE_HASH_*`, `GNODE_SERVICE_RATE_LIMIT`, etc.

## ::CONTRACT
PROVIDES → consumers rely on: comms wire shape (gNode-COMMS parse_message) · unified field names `t/c/p/id/ss/sn/ts` (daemon utils::field_names) · FCALL allowlist superset · health compressed schema · stream-key format accessors · `forSite` factory · typed Exception hierarchy.
CONSUMES ← it requires: RESP3 FCALL+XADD on port 47445 (ValKey daemon) · Lua fns matching allowlist (daemon registers) · consumer groups `gnode-daemon`+`gnode-client` (daemon auto-creates+claims; mismatch ⇒ orphan groups + PEL stall) · creds file chain (installer) · orchestration stream read by Geodineum (HARDCODED key) · ACL user `gnode_client_{site_id}` key-pattern `~{site_id}:gnode:*` + DTAP rules.

## ::USECASES
cache get/set/del/exists/incr/decr (`GNODE_CACHE_*`) · hash hset/hget/hgetall (`GNODE_HASH_*`) · service discovery (geometric capability vectors, n-dim) · contact-form → COMMS email/SMS/Telegram · health metrics → load-based routing · broadcast (topology/config changes) · manifest bundles (asset store/retrieve, build status) · format translation (endpoint schema reg) · template render via daemon · rate-limit checks · orchestration notify (write-only `postToGeodineum`, e.g. service_request) · dependency topology mgmt.

## ::LIMITATIONS
- raw Redis/ValKey exposure BLOCKED: `getRedis()` logs the audit line then THROWS StorageException (raw connection bypasses the FCALL allowlist); typed storage API (incr/pfAdd/xAdd/…) covers legit internal needs
- premium gate = ONE style: `fcallDecode()` prefix map (DEP_/REGISTRY_/CROSS_→gNode-TOPO · FEATURE_/EXPERIMENT_/SESSION_/TRACE_→gNode-OBSERVE · ENDPOINT_→gNode-BROKER) returns `{error,premium:true,feature}` — base never puts a Pro function name on the wire; bool/nullable wrappers degrade per signature; legacy register/get/list/translate/deregister endpoint duals now DELEGATE to canonical endpoint* methods (raw rawCommand surface deleted)
- FCALL allowlist restrictive — fns outside `GNODE|GCUBE|COMMS|GC_` uninvokable
- no HTTP/REST surface — RESP3 only
- PHP-side signed-extension (extension.sig) NOT implemented (Rust/Lua only, `README.md`)
- light unit coverage (2) vs integration (8) — most validation needs live ValKey+daemon
- single class (split would fragment wire-contract surface)
- response cache request-scoped in-mem, no persistent backend
- metrics need ACL on `{site}:metrics:*`; deny ⇒ `recordFcallMetrics` silently fails (best-effort)
- health requires daemon read via group `gnode-daemon`; misconfig ⇒ PEL buildup
- broadcast has NO consumer group — reader tracks own position or replays history
- stream trimming = daemon XTRIM, not client-enforced
GOTCHAS: environment double-stamped (top-level AUTH + nested `metadata.environment`) — safe ONLY because COMMS reads top-level; metadata-read would bypass non-prod gating. `ts` ms on ALL command paths incl. consumer-group batch (`ConsumerGroupHandler.php`, `t='bc'`, now `(int)(microtime(true)*1000)`) and main paths; no residual seconds path. DOCSTRING stale: `HealthStreamWriter.php` says `{node_id}`, code builds `{environment}` (code correct).

## ::GRAPH
DEPENDS_ON: ValKey daemon (RESP3 FCALL+XADD, port 47445) · gNode daemon (Lua fn registration + consumer-group claiming + stream reads) · Geodineum installer (creds) · phpredis ext.
PROVIDES_TO: gCore · gTemplate · gCube (via Composer) · gNode-COMMS (comms stream) · Geodineum orchestration (orchestration stream) · gDash (metrics keys).
ADHERES_TO: comms-message contract of gNode-COMMS (parse_message stream_reader.rs) · unified field_names contract of gNode daemon (utils.rs) · FCALL allowlist contract.
ADHERENCE: comms shape=ADHERES · environment-authority=ADHERES (top-level read) · unified fields=ADHERES (no legacy st/n; `ds`/`dn` never emitted — daemon-side aliases only) · allowlist=ADHERES (superset) · type-enum=ADHERES (docblock aligned to contact|alert|error|test|system 2026-06-22) · `ts` unit=ADHERES on ALL paths incl. CGH batch (ms, no residual) · health field names=ADHERES (t/si/l/cpu/mem/rq/lat/err/ts == daemon integration/processor/health_processor.rs, verified).
ISOLATED_FROM: HTTP/REST clients · raw Redis cmd callers · non-RESP3 transports.

## ::LATENT
- "FCALL-only, allowlist `^(GNODE|GCUBE|COMMS|GC)_`, throws pre-wire"
- "brace-literal `{siteId}` hash-tag, per-env DTAP stream keys, NOT node_id"
- "top-level scalar environment is authoritative for non-prod gating, double-stamped in metadata"
- "stream async XADD + consumer groups gnode-daemon/gnode-client, PEL stall on group mismatch"
- "health compressed fields t='lu',si,l,cpu,mem,rq,lat,err,ts string-encoded"
- "gNodeClient, forSite factory, fallback local-exec, port 47445 phpredis"
- "orchestration key geodineum:gnode:orchestration is HARDCODED; postToGeodineum is the sole orch surface (registerWithGeodineum REMOVED — FCALLed a nonexistent Lua fn)"
- "registerCapabilityDimension REMOVED (no daemon handler; dims are schema-driven via service_schema.yaml)"
- "getComputeStream = unified-stream accessor (getUnifiedStream name is dead)"
- "ts ms on ALL paths incl. CGH batch (no residual); health-reader adherence VERIFIED (field names match)"
