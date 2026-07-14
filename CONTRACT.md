# gNode-Client — Integration Contract

PHP client library for the gNode daemon over RESP3/ValKey, with FCALL-only operations, stream-based message queueing, and geometric topology discovery. PSR-4 namespace `gCore\gNode`, Composer-installable.

---

## 1. PROVIDES

Interfaces other components may rely on. Signatures and stream-key formats are exact; `{siteId}` and `{environment}` are substituted at runtime.

### 1.1 Entry point

| Symbol | Signature | Source |
|---|---|---|
| `gNodeClient` (class) | `gCore\gNode\gNodeClient implements gNodeClientInterface` (PSR-4) | `src/gNodeClient.php` |
| `forSite` (static factory) | `static forSite(string $siteId, string $environment='production', array $overrides=[]): self` — canonical factory | `src/gNodeClient.php` |

### 1.2 Cache (FCALL-backed)

| Method | Signature | Source |
|---|---|---|
| `luaGet` | `luaGet(string $key): mixed` (via `FCALL GNODE_CACHE_GET`) | `src/gNodeClient.php` |
| `luaSet` | `luaSet(string $key, mixed $value, ?int $ttl, ?string $mode): bool` (via `FCALL GNODE_CACHE_SET`) | `src/gNodeClient.php` |

### 1.3 FCALL surface

| Method | Signature | Source |
|---|---|---|
| `fcall` | `fcall(string $function, array $keys, array $args): mixed` — enforces allowlist `^(GNODE\|GCUBE\|COMMS\|GC)_[A-Z0-9_]+$` | `src/gNodeClient.php` |
| `ValKeyStorage::fcall` | `fcall(string $function, array $keys, array $args): mixed` — RESP3 `rawCommand FCALL [function] [numkeys] [keys...] [args...]` | `src/Storage/ValKeyStorage.php` |
| `ValKeyStorage::xAdd` | `xAdd(string $key, string $id, array $fields): string` (RESP3 XADD) | `src/Storage/ValKeyStorage.php` |

`fcall()` rejects any function name not matching the allowlist regex with `InvalidArgumentException` **before** any wire I/O (`src/gNodeClient.php`).

**Premium gate (one style).** `fcallDecode()` checks the function name against
`PREMIUM_FCALL_PREFIXES` before any wire I/O and returns the structured
premium-required shape `{error, premium: true, feature}` for Pro-extension
functions — a base install never puts a Pro function name on the wire:

| Prefixes | Extension | feature |
|---|---|---|
| `GNODE_DEP_*`, `GNODE_REGISTRY_*`, `GNODE_CROSS_*` | gNode-TOPO | `multi_topology` |
| `GNODE_FEATURE_*`, `GNODE_EXPERIMENT_*`, `GNODE_SESSION_*`, `GNODE_TRACE_*` | gNode-OBSERVE | `observability` |
| `GNODE_ENDPOINT_*` | gNode-BROKER | `endpoint_translation` |

Wrapper methods degrade per their signatures (`bool` → `false`, `?array`
getters may return the premium shape — branch on `['premium']`). The legacy
`registerEndpoint`/`getEndpoint`/`listEndpoints`/`findEndpoints`/
`translate*`/`deregisterEndpoint`/`getEndpointSchema`/`registerTranslationRule`
duals now delegate to the canonical `endpoint*` methods; their former raw
`getRedis()->rawCommand` path is gone, and `ValKeyStorage::getRedis()` now
**throws** `StorageException` (the raw connection bypassed this whole layer).

### 1.4 Comms (→ gNode-COMMS)

| Method | Signature | Source |
|---|---|---|
| `queueCommsMessage` | `queueCommsMessage(string $type, array $sender, array $content, array $metadata=[], int $priority=3, array $channels=['email']): string\|false` — XADD to comms stream | `src/gNodeClient.php` |
| `queueContactForm` | `queueContactForm(string $name, string $email, string $subject, string $message, array $metadata=[]): string\|false` — convenience wrapper, XADD via `queueCommsMessage` | `src/gNodeClient.php` |
| `getCommsStream` | `getCommsStream(): string` → `{siteId}:gnode:comms:{environment}` | `src/gNodeClient.php` |

### 1.5 Orchestration / registration (→ Geodineum)

| Method | Signature | Source |
|---|---|---|
| `postToGeodineum` | `postToGeodineum(string $messageType, array $data): ?string` — XADD to orchestration stream | `src/gNodeClient.php` |

### 1.6 Stream-key accessors

| Method | Returns | Source |
|---|---|---|
| `getComputeStream` | `{siteId}:gnode:unified:{environment}` (the unified stream the daemon listens on) | `src/gNodeClient.php` |
| `getHealthStream` | `{siteId}:gnode:health:{environment}` | `src/gNodeClient.php` |
| `getBroadcastStream` | `{siteId}:gnode:broadcast:global` | `src/gNodeClient.php` |

### 1.7 Consumer groups & status

| Method | Signature | Source |
|---|---|---|
| `ensureConsumerGroups` | `ensureConsumerGroups(): array` — creates `gnode-daemon` and `gnode-client` groups on unified+health | `src/gNodeClient.php` |
| `getStreamStatus` | `getStreamStatus(): array` — keys: `site_id, environment, node_id, streams, consumer_groups, connected, using_fallback` | `src/gNodeClient.php` |

### 1.8 Health publishing

| Method | Signature | Source |
|---|---|---|
| `HealthStreamWriter::publishMetrics` | `publishMetrics(HealthMetrics $metrics): string` — XADD to health stream (compressed field names) | `src/Health/HealthStreamWriter.php` |
| `HealthMetrics::toCompressedFormat` | `toCompressedFormat(): array` — fields `t='lu', si, l, cpu, mem, rq, lat, err, ts` | `src/Health/HealthMetrics.php` |

### 1.9 Broadcast reading

| Method | Signature | Source |
|---|---|---|
| `BroadcastReader::read` | `read(int $count=100, int $blockMs=0, ?string $typeFilter=null): BroadcastMessage[]` | `src/Broadcast/BroadcastReader.php` |

---

## 2. CONSUMES / REQUIRES

What this library needs, and from which component.

| Requirement | Expected format | From | Source |
|---|---|---|---|
| RESP3 FCALL | `FCALL [function:string] [numkeys:int] [keys...] [args:scalar_or_json]` | ValKey daemon, port **47445** | `src/Storage/ValKeyStorage.php`; `README.md` |
| RESP3 XADD | `XADD [key] [id (*=autogen)] [field value]...` → `[message_id]` | ValKey daemon, port 47445 | `src/Storage/ValKeyStorage.php`; `src/gNodeClient.php` |
| Credentials (password) | Plain-text 64-char hex; file chain `env VALKEY_PASSWORD_FILE` → `/etc/geodineum/credentials/{site}.password` → legacy | Geodineum installer + filesystem | `src/Config/CredentialResolver.php` (5-level chain); `CLAUDE.md` |
| Lua functions in ValKey | `FUNCTION LOAD`; names `GNODE_* \| GCUBE_* \| COMMS_* \| GC_*`; invoked via FCALL | gNode daemon (function registration) | `README.md`; `src/gNodeClient.php` |
| Stream `{siteId}:gnode:unified:{environment}` | RESP3 stream; consumer groups `gnode-daemon`, `gnode-client`; hash-tagged key | gNode daemon (reader) | `src/gNodeClient.php`; `src/ConsumerGroupHandler.php` |
| Stream `{siteId}:gnode:health:{environment}` | RESP3 stream; consumer group `gnode-daemon`; compressed schema | gNode daemon (reader) | `src/Health/HealthStreamWriter.php`; `src/Health/HealthMetrics.php` |
| Stream `{siteId}:gnode:broadcast:global` | RESP3 stream; **no consumer groups**; readers track own position | gNode daemon (writer); PHP clients (readers) | `src/gNodeClient.php`; `src/Broadcast/BroadcastReader.php` |
| Stream `geodineum:gnode:orchestration` | RESP3 stream; fields `type, site_id, environment, data(JSON), timestamp` | Geodineum orchestration (reader) | `src/gNodeClient.php` |
| Consumer group `gnode-daemon` | Named group on unified+health; daemon auto-creates and claims | gNode daemon | `src/gNodeClient.php`; `src/ConsumerGroupHandler.php` |
| Consumer group `gnode-client` | Named group on unified+health (client-side consumers if enabled) | Client application | `src/gNodeClient.php`; `CLAUDE.md` |

ACL user pattern: username `gnode_client_{site_id}`, key pattern `~{site_id}:gnode:*` plus DTAP environment rules (`CLAUDE.md`).

---

## 3. WIRE FORMATS

All stream keys carry literal braces `{siteId}` for Redis Cluster hash-tag routing. Marked **R** = required, **O** = optional. Field type **scalar** = plain string; **JSON** = `json_encode`'d string value of one XADD field.

### 3.1 Comms message stream — `XADD {siteId}:gnode:comms:{environment}`
Source: `src/gNodeClient.php`.

| Field | Req | Type | Notes |
|---|---|---|---|
| `id` | R | scalar | uuid |
| `type` | R | scalar | `contact \| alert \| error \| test \| system` (free-form on the wire; `test` env-filtered, `system` never dispatched) |
| `timestamp` | R | scalar | iso8601 |
| `site_id` | R | scalar | |
| `environment` | R | scalar | **AUTHORITATIVE** for non-prod gating; consumed directly, NOT from `metadata.environment` |
| `priority` | R | scalar | `1`–`5` |
| `sender` | R | JSON | `{name,email,phone,user_agent,ip}` |
| `content` | R | JSON | `{subject,body,attachments}` |
| `metadata` | R | JSON | `{form_type,source_url,face_id,environment}` |
| `dispatch` | R | JSON | `{channels,status,attempts,last_attempt,next_retry}` |

ContactForm specialization (`queueContactForm`, `src/gNodeClient.php`): `type='contact'`, `priority=3`, `metadata.form_type='contact'`, `dispatch.channels=['email']`, `dispatch.status='pending'`.

### 3.2 Orchestration message stream — `XADD geodineum:gnode:orchestration`
Source: `src/gNodeClient.php`. Stream key is **hardcoded / not parameterized**.

| Field | Req | Type |
|---|---|---|
| `type` | R | scalar (messageType) |
| `site_id` | R | scalar |
| `environment` | R | scalar |
| `data` | R | JSON-encoded string |
| `timestamp` | R | scalar (unix timestamp) |

### 3.3 Health metrics stream — `XADD {siteId}:gnode:health:{environment}`
Source: `src/Health/HealthMetrics.php`. All numeric fields **string-encoded**.

| Field | Req | Type | Meaning |
|---|---|---|---|
| `t` | R | scalar | constant `'lu'` |
| `si` | R | scalar | serviceId |
| `l` | R | scalar(float) | loadFactor |
| `cpu` | O | scalar(float) | cpuUsage |
| `mem` | O | scalar(float) | memUsage |
| `rq` | O | scalar(int) | requestCount |
| `lat` | O | scalar(int) | latencyMs |
| `err` | O | scalar(float) | errorRate |
| `ts` | R | scalar(int) | timestampMs |

### 3.4 Unified stream message (Protocol v2) — `XADD {siteId}:gnode:unified:{environment}`
Source: `CLAUDE.md`.

| Field | Req | Type | Meaning |
|---|---|---|---|
| `t` | R | scalar | message type `c\|r\|bc\|br\|i\|lu` |
| `c` | R | scalar | command name |
| `p` | R | JSON | params |
| `id` | R | scalar | request id |
| `ss` | R | scalar | source site id |
| `sn` | R | scalar | source node id |
| `ts` | R | scalar | timestamp |
| `bi` | O | scalar | batch id |
| `tc` | O | scalar | total count |
| `m` | O | array | message array |
| `_gh` | O | scalar | group hint |
| `_cr` | O | scalar | client-readable flag |

### 3.5 FCALL response decoding
Source: `src/gNodeClient.php`. Response is a JSON string decoded via `safeJsonDecode` (**max 4MB, max 64-level depth**, returns default on failure), or a scalar (bool/int/string/null). Non-JSON responses are treated as literals.

---

## 4. PUBLIC TYPES

```
gCore\gNode\gNodeClient                 — main entry point (PSR-4)
gCore\gNode\gNodeClientInterface        — implementation contract
gCore\gNode\Storage\StorageInterface    — RESP3 abstraction
gCore\gNode\Storage\ValKeyStorage       — RESP3 implementation (phpredis, port 47445)
gCore\gNode\Health\HealthMetrics        — health structure (t,si,l,cpu,mem,rq,lat,err,ts)
gCore\gNode\Health\HealthStreamWriter   — metrics publisher
gCore\gNode\Broadcast\BroadcastMessage  — broadcast event structure
gCore\gNode\Broadcast\BroadcastReader   — broadcast listener
gCore\gNode\ConsumerGroupHandler        — consumer group operations
gCore\gNode\Queue\CommandQueue          — batching queue
gCore\gNode\Exception\*                 — ConnectionException, StorageException,
                                          ConfigException, ScriptException, ...
```

---

## 5. EXAMPLE

```php
use gCore\gNode\gNodeClient;

// Canonical construction.
$client = gNodeClient::forSite('acme', 'production');

// Cache via FCALL-backed Lua functions.
$client->luaSet('homepage:hero', ['title' => 'Welcome'], 3600);
$hero = $client->luaGet('homepage:hero');

// Contact form → gNode-COMMS for email dispatch.
$msgId = $client->queueContactForm(
    name:    'Jane Doe',
    email:   'jane@example.com',
    subject: 'Hello',
    message: 'Interested in a demo.'
);
// XADD to {acme}:gnode:comms:production → returns stream message id or false.

// Inspect topology / stream wiring.
$status = $client->getStreamStatus();
// ['site_id'=>'acme','environment'=>'production','node_id'=>...,
//  'streams'=>[...],'consumer_groups'=>[...],'connected'=>true,'using_fallback'=>false]
```

---

## 6. ADHERENCE

Cross-component status (from ecosystem cross-check). Items below either involve this component directly or are gotchas a producer/consumer must honor.

- **ADHERES — comms wire shape.** `queueCommsMessage`/`queueContactForm` (`src/gNodeClient.php`) emit exactly the fields gNode-COMMS `parse_message` reads (`stream_reader.rs`): brace-literal `{site_id}:gnode:comms:{env}` key, scalar `id/type/timestamp/site_id/environment/priority`, JSON `sender/content/metadata/dispatch`. Field-by-field match.
- **ADHERES — environment authority.** Top-level scalar `environment` is read directly by COMMS as authoritative (`stream_reader.rs`). The field is **double-stamped** (also nested in `metadata.environment`); this is harmless **only because** COMMS reads the top-level flat field. Any consumer that reads `metadata.environment` instead would let non-prod messages on a prod stream bypass gating.
- **ADHERES — unified command fields.** Client writes canonical-compact `t/c/p/id/ss/sn/ts`, matching the daemon's `field_names` resolver (`utils.rs`); no legacy `st/n` aliases emitted. The client never emits `ds`/`dn` (dest addressing); those aliases exist daemon-side only.
- **ADHERES — FCALL allowlist.** Client regex `^(GNODE|GCUBE|COMMS|GC)_` is a correct superset of all registered `GNODE_*` Lua functions.
- **RESOLVED — message-type enum drift.** This client's docblock now matches the wire convention `contact | alert | error | test | system` (`src/gNodeClient.php`); the earlier `contact-form` entry is gone. `parse_message` still does not validate `type` against an enum, so any residual divergence in COMMS-side docs degrades gracefully.
- **RESOLVED — `ts` unit.** All command paths emit `ts` in milliseconds, including the consumer-group batch path (`src/ConsumerGroupHandler.php`, `t='bc'` message, now `(string)(int)(microtime(true)*1000)`) and the main paths. No residual float-seconds path remains.
- **ADHERES — health field names.** Producer emits abbreviated `t='lu', si, l, cpu, mem, rq, lat, err, ts` (`src/Health/HealthMetrics.php`); the daemon's health reader consumes exactly these names — `t=='lu'` gate plus required `si/l/ts` and optional `cpu/mem/rq/lat/err` (`gNode daemon/src/integration/processor/health_processor.rs`).

### Internal docstring bugs (do not affect wire interop)
- `src/Health/HealthStreamWriter.php` docstring says `{site_id}:gnode:health:{node_id}`, but code builds `{environment}`. Code is correct; docstring is stale.

### Consumer-leniency notes (latent, not live failures)
- COMMS `CONTRACT.md` marks `environment` and `content` "required" but `stream_reader.rs` treats both as optional with fallbacks. Live producers (including this client) always stamp them, so no message trips the fallback today.
