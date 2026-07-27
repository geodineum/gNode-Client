<?php

declare(strict_types=1);

namespace gCore\gNode\Receipt;

/**
 * A signed receipt — the PHP producer for the receipt-stream contract.
 *
 * A receipt is the durable, tamper-evident record of a command's outcome. It is
 * METADATA + a REFERENCE, never the body: status, timing, command, correlation,
 * lineage, plus `body_ref` (where the full result lives) and `body_hash` (a
 * content-anomaly signal that needs no body read).
 *
 * WHY THIS LIVES IN gNode-Client
 * gNode-Client is the PHP client for gNode, so this is the ONE place PHP
 * consumers get a receipt producer: Geodine already requires this package, and
 * gCore suggests it. Putting it here rather than in each consumer means one
 * implementation to validate and one to fix.
 *
 * That is a different judgement from the one made for Geodineum-COMMS, which
 * carries its own Rust implementation. Independent implementations are
 * justified across a LANGUAGE boundary, where sharing is impossible — Rust,
 * PHP, and SB-8.88's Python/C/TypeScript cannot share code, so shared TEST
 * VECTORS are the only thing keeping them aligned. Within one language, where a
 * dependency already exists, a second copy buys nothing and doubles the surface
 * that can drift.
 *
 * The vectors are the safety mechanism: CONTRACTS/receipt-vectors.json, with
 * byte-identical copies in gNode and Geodineum-COMMS. This implementation is
 * asserted against them exactly as the other two are. Drift in a signed format
 * is invisible in normal operation and then presents as a signature failure,
 * which reads as tampering rather than as a regression.
 */
final class Receipt
{
    /** Must match the daemon's RECEIPT_SCHEMA_VERSION. */
    public const SCHEMA_VERSION = 1;

    /** 30 days, matching the daemon's retention floor. */
    public const RETENTION_MS = 30 * 24 * 60 * 60 * 1000;

    public string $correlationId;
    public string $command;
    public string $status;
    public ?string $error;
    public string $site;
    public string $node;
    public int $tsMs;
    public string $bodyRef;
    public string $bodyHash;
    public ?string $parentId = null;
    public ?string $flowId = null;
    public int $v = self::SCHEMA_VERSION;
    public string $alg = '';
    public string $sig = '';
    public string $signer = '';

    public function __construct(
        string $correlationId,
        string $command,
        string $status,
        ?string $error,
        string $site,
        string $node,
        int $tsMs,
        string $bodyRef,
        string $body
    ) {
        $this->correlationId = $correlationId;
        $this->command = $command;
        $this->status = $status;
        $this->error = $error;
        $this->site = $site;
        $this->node = $node;
        $this->tsMs = $tsMs;
        $this->bodyRef = $bodyRef;
        $this->bodyHash = self::bodyHash($body);
    }

    /**
     * The exact bytes a signature covers.
     *
     * Field ORDER is part of the signature, and an absent optional renders as
     * the EMPTY STRING rather than an omitted line. A verifier that skips the
     * line computes different bytes and rejects precisely the HEALTHY receipts
     * — the ones with no error and no lineage.
     *
     * Byte-identical to the daemon's `canonical_bytes()` and COMMS's; all three
     * are pinned to receipt-vectors.json.
     */
    public function canonicalBytes(): string
    {
        return 'v=' . $this->v . "\n"
            . 'alg=' . $this->alg . "\n"
            . 'cid=' . $this->correlationId . "\n"
            . 'cmd=' . $this->command . "\n"
            . 'st=' . $this->status . "\n"
            . 'e=' . ($this->error ?? '') . "\n"
            . 'ss=' . $this->site . "\n"
            . 'sn=' . $this->node . "\n"
            . 'ts=' . $this->tsMs . "\n"
            . 'bref=' . $this->bodyRef . "\n"
            . 'bh=' . $this->bodyHash . "\n"
            . 'pid=' . ($this->parentId ?? '') . "\n"
            . 'fid=' . ($this->flowId ?? '') . "\n";
    }

    /**
     * Sign in place. `alg` is set BEFORE the canonical bytes are taken, so the
     * algorithm is covered by the signature and cannot be silently downgraded.
     */
    public function sign(NodeSigner $signer): void
    {
        $this->alg = 'ed25519';
        $this->sig = bin2hex($signer->sign($this->canonicalBytes()));
        $this->signer = $signer->signerId();
    }

    /**
     * Wire fields for XADD. Short names, matching the daemon and COMMS.
     * Empty optionals are omitted from the wire but still SIGNED as empty —
     * the two are not in conflict: the wire is a projection, the canonical
     * bytes are the contract.
     *
     * @return array<string, string> flat field => value
     */
    public function toFields(): array
    {
        $f = [
            'v'    => (string) $this->v,
            'cid'  => $this->correlationId,
            'cmd'  => $this->command,
            'st'   => $this->status,
            'ss'   => $this->site,
            'sn'   => $this->node,
            'ts'   => (string) $this->tsMs,
            'bref' => $this->bodyRef,
            'bh'   => $this->bodyHash,
        ];
        if ($this->error !== null)    { $f['e'] = $this->error; }
        if ($this->parentId !== null) { $f['pid'] = $this->parentId; }
        if ($this->flowId !== null)   { $f['fid'] = $this->flowId; }
        if ($this->alg !== '')        { $f['alg'] = $this->alg; }
        if ($this->sig !== '')        { $f['sig'] = $this->sig; }
        if ($this->signer !== '')     { $f['signer'] = $this->signer; }

        return $f;
    }

    /** Hex sha256 of the body. */
    public static function bodyHash(string $body): string
    {
        return hash('sha256', $body);
    }

    /** `{site}:gnode:receipts:{env}` — the shape every producer writes. */
    public static function streamKey(string $site, string $environment): string
    {
        return sprintf('{%s}:gnode:receipts:%s', $site, $environment);
    }

    /** Registry of verifier keys: field = fingerprint, value = `alg:pubkey_hex`. */
    public static function pubkeyRegistryKey(string $topologyNs): string
    {
        return sprintf('{%s}:gnode:receipt_pubkeys', $topologyNs);
    }

    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
