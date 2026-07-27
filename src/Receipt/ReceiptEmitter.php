<?php

declare(strict_types=1);

namespace gCore\gNode\Receipt;

use Redis;
use Throwable;

/**
 * Emits signed receipts to `{site}:gnode:receipts:{env}`.
 *
 * FAIL-CLOSED ON SIGNING, BEST-EFFORT ON DELIVERY — the two are deliberately
 * different. An unsigned receipt on a shared stream is worse than no receipt:
 * observers are told the stream is trustworthy because every row is signed, so
 * one unsigned row silently breaks that guarantee. A receipt that fails to
 * DELIVER, on the other hand, must never take down the work it describes — the
 * caller's real output has already been produced by then.
 *
 * So: no signer means no receipt, and an XADD failure is swallowed and reported
 * by the return value rather than thrown.
 */
final class ReceiptEmitter
{
    private Redis $redis;
    private NodeSigner $signer;
    private string $node;
    private string $environment;

    public function __construct(
        Redis $redis,
        NodeSigner $signer,
        string $node,
        string $environment
    ) {
        $this->redis = $redis;
        $this->signer = $signer;
        $this->node = $node;
        $this->environment = $environment;
    }

    /**
     * Publish this producer's public key so verifiers can resolve its `signer`.
     * Idempotent — safe to call on every start, and it must be called at least
     * once or every receipt this producer writes is unverifiable.
     */
    public function publishPubkey(string $topologyNs): bool
    {
        try {
            $this->redis->hSet(
                Receipt::pubkeyRegistryKey($topologyNs),
                $this->signer->signerId(),
                $this->signer->registryValue()
            );

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Build, sign and emit a receipt. Returns the stream entry id, or null if
     * anything failed.
     *
     * @param string      $correlationId the request this is a receipt for
     * @param string      $command       the command / action
     * @param string      $status        ok | failed | refused
     * @param string|null $error         reason when status is not ok
     * @param string      $site          producing site (namespaces the stream)
     * @param string      $bodyRef       where the full result lives
     * @param string      $body          the result, hashed but NOT stored here
     */
    public function emit(
        string $correlationId,
        string $command,
        string $status,
        ?string $error,
        string $site,
        string $bodyRef,
        string $body,
        ?int $tsMs = null
    ): ?string {
        try {
            $now = $tsMs ?? Receipt::nowMs();
            $receipt = new Receipt(
                $correlationId, $command, $status, $error,
                $site, $this->node, $now, $bodyRef, $body
            );
            $receipt->sign($this->signer);

            // Trim by AGE, not length: retention is a time policy (30 days), and
            // a length cap would silently evict a quiet site's history while a
            // busy one keeps far more than the policy says.
            $minId = max(0, $now - Receipt::RETENTION_MS) . '-0';

            $id = $this->redis->xAdd(
                Receipt::streamKey($site, $this->environment),
                '*',
                $receipt->toFields(),
                0,
                true
            );

            // MINID trim is a separate call: phpredis's xAdd maxlen argument is
            // a LENGTH cap and cannot express MINID, so expressing the age
            // policy through it would quietly change the policy.
            try {
                $this->redis->rawCommand(
                    'XTRIM',
                    Receipt::streamKey($site, $this->environment),
                    'MINID', '~', $minId
                );
            } catch (Throwable $e) {
                // Trim failure just means the stream is longer than policy;
                // never a reason to lose the receipt that was already written.
            }

            return is_string($id) ? $id : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
