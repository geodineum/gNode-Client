<p align="center">
  <a href="https://geodineum.com">
    <img src="https://geodineum.com/wp-content/uploads/2026/07/logo_geodineum_launch.png" alt="Geodineum" width="128">
  </a>
</p>

# gNode-Client

The reference PHP client for the gNode daemon: it speaks the gNode wire contract over ValKey (RESP3), so PHP code never touches the protocol directly.

Built by **Niels Erik Toren** · distributed as `gcore/gnode-client` (Composer)

---

## What it is

gNode-Client is a single-class library (`gCore\gNode\gNodeClient`) that turns the gNode wire protocol into ordinary PHP method calls - FCALL-backed cache and Lua operations, message queueing to Geodineum-COMMS, health-metric streaming, broadcast read/write, and geometric service discovery. All communication is RESP3 over ValKey; the client opens no HTTP connections.

It is the canonical reference implementation of the contract documented in `gNode/COMMAND_SCHEMA.md` - other languages can implement compatible clients against the same protocol. gCore and the WordPress themes reach the daemon through this library.

## Public build surface

- **`gNodeClient`** - the single entry point. Construct it with the `forSite(...)` or `fromEnvironment(...)` factory, then call the wire methods on it.
- **Supporting types** you construct or receive directly: `Storage\ValKeyStorage` (the RESP3 connection), `Health\HealthStreamWriter` + `Health\HealthMetrics` (metric publishing), `Broadcast\BroadcastReader` + `Broadcast\BroadcastMessage` (one-to-many announcements), and the typed `Exception\*` classes.
- **Internal** - everything else under `gCore\gNode\` (`Discovery`, `Format`, `Config`, `Queue`, `Fallback`, `Template`, `Utils`) is plumbing behind the client and may change.

The complete method index - every signature with a one-line summary - is **generated** into **[`PUBLIC_API.md`](PUBLIC_API.md)** (`php scripts/gen-public-api.php`). Prose and wire formats live in **[`CONTRACT.md`](CONTRACT.md)**; this README re-hosts neither.

**Premium-gated families.** Some methods are callable in a base install but return a `{ error, premium: true, feature }` response until their Chapter-2 gNode extension is loaded - `dep*` / `registry*` (gNode-TOPO), `endpoint*` / `translate*` (gNode-BROKER), and `feature*` / `experiment*` / `session*` / `trace*` (gNode-OBSERVE). They are marked _premium_ in the generated index. A base install never puts a Pro function on the wire.

## Capabilities

- **FCALL cache & Lua ops** - `luaGet`/`luaSet`/`luaDel`/`luaIncrBy`/`luaHSet`… and a generic `fcall()` gated to the allowlist `^(GNODE|GCUBE|COMMS|GC)_[A-Z0-9_]+$` (rejected before any wire I/O).
- **Comms queueing** - `queueCommsMessage` / `queueContactForm` XADD to `{siteId}:gnode:comms:{env}` in exactly the shape Geodineum-COMMS reads, stamping the top-level `environment` that gates non-production sends.
- **Health streaming** - `HealthStreamWriter::publishMetrics` writes compressed metrics; `HealthMetrics` is the value object.
- **Broadcast** - `BroadcastReader` reads and writes the global one-to-many announcement stream.
- **Geometric discovery** - `geometricDiscover` and capability-vector helpers resolve services by capability, not by name.
- **Stream wiring** - `forSite`, `ensureConsumerGroups`, `getStreamStatus`, and the `get*Stream` accessors set up and inspect the daemon streams.

## Contract

The precise integration surface - provided methods and signatures, required streams and credentials, and every wire format (comms, health, unified, orchestration) - is in **[`CONTRACT.md`](CONTRACT.md)**. Agents should prime from **[`CONTRACT.scn.md`](CONTRACT.scn.md)**. The daemon-side command catalogue is `gNode/COMMAND_SCHEMA.md`.

## Quick start

```php
use gCore\gNode\gNodeClient;

// Construct from /etc/geodineum/credentials/<site>.password + bootstrap.env
$client = gNodeClient::forSite('acme', 'production');

// Cache via FCALL-backed Lua functions
$client->luaSet('homepage:hero', ['title' => 'Welcome'], 3600);
$hero = $client->luaGet('homepage:hero');

// Queue a contact form → gNode-COMMS dispatches the email
$msgId = $client->queueContactForm(
    'Jane Doe', 'jane@example.com', 'Hello', 'Interested in a demo.'
);

// Inspect the stream wiring
$status = $client->getStreamStatus();
// ['site_id'=>'acme','environment'=>'production','connected'=>true,'streams'=>[...], ...]
```

Install with `composer require gcore/gnode-client` (PHP ≥ 7.4, `ext-redis ^5.2`, `ext-json`).

## Limits worth knowing

- **RESP3 only - no HTTP on the client.** Services register endpoint metadata (`registerEndpoint*`) for gNode-side translation, but the client itself never emits HTTP.
- **ValKey is required** (port 47445); operations beyond raw ValKey need a reachable gNode daemon.
- **Premium families degrade, they don't error** - the `dep*`/`registry*`/`endpoint*`/`feature*`/`experiment*`/`session*`/`trace*` methods return `premium: true` until their extension is installed.
- **`getRedis()` throws.** The raw-connection escape hatch that bypassed the gated FCALL layer is closed; all I/O goes through the client.
- **One large class by design** - the wire surface is large; specialised behaviour is factored into the submodules rather than the call site.

## Collaborate

Contributions are welcome. Open issues and pick up work on the ecosystem board
at [geodineum.com](https://geodineum.com); issues tagged `good-first-issue` are
a good place to start.

- Fork, branch, and open a pull request against `main`.
- Any change to a wire contract must update **both** `CONTRACT.md` and
  `CONTRACT.scn.md` in the same commit.
- A change to a signed extension must be re-signed in the same commit.

## Author & support

Built by **Niels Erik Toren**.

If you want to support the work:

| Currency | Address |
|---|---|
| Bitcoin (BTC) | `bc1qwf78fjgapt2gcts4mwf3gnfkclvqgtlg4gpu4d` |
| Ethereum (ETH) | `0xf38b517Dd2005d93E0BDc1e9807665074c5eC731` / `nierto.eth` |
| Monero (XMR) | `8BPaSoq1pEJH4LgbGNQ92kFJA3oi2frE4igHvdP9Lz2giwhFo2VnNvGT8XABYasjtoVY2Qb3LVHv6CP3qwcJ8UnyRtjWRZ5` |

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

## License

Licensed under either of

* [Apache License, Version 2.0](LICENSE-APACHE)
* [MIT License](LICENSE-MIT)

at your option.
