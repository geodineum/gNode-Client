<?php

declare(strict_types=1);

namespace gCore\gNode\Tests\Unit;

use gCore\gNode\Receipt\NodeSigner;
use gCore\gNode\Receipt\Receipt;
use PHPUnit\Framework\TestCase;

/**
 * The PHP producer must reproduce the SHARED receipt vectors exactly.
 *
 * This is the entire safety mechanism for the ecosystem's receipt format. The
 * format has several INDEPENDENT implementations — the gNode daemon and
 * Geodineum-COMMS in Rust, this one in PHP, and SB-8.88's Python/C/TypeScript
 * to come — because they cannot share code across a language boundary. Shared
 * TEST VECTORS are what keep them from drifting apart.
 *
 * Drift here is invisible in normal operation and then presents as a signature
 * failure, which reads as tampering rather than as a regression. Hence a test,
 * not a comment.
 *
 * Ed25519 is deterministic (RFC 8032): a fixed seed over fixed input has
 * exactly one correct signature, so these values are stable forever.
 */
final class ReceiptVectorsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function vectors(): array
    {
        $path = __DIR__ . '/../receipt-vectors.json';
        self::assertFileExists(
            $path,
            'receipt-vectors.json is missing — it is the shared fixture, and '
            . 'byte-identical copies must exist in gNode, Geodineum-COMMS and '
            . 'Geodineum-pro/CONTRACTS'
        );
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data, 'receipt-vectors.json is not valid JSON');

        return $data;
    }

    public function testPublicKeyAndFingerprintMatchTheSharedVectors(): void
    {
        $v = $this->vectors();
        $seed = hex2bin($v['signer_key']['seed_hex']);
        self::assertIsString($seed);

        $signer = NodeSigner::fromSeed($seed);

        self::assertSame(
            $v['signer_key']['public_hex'],
            $signer->publicKeyHex(),
            'public key derivation drifted from the shared vectors'
        );
        self::assertSame(
            $v['signer_key']['signer_id'],
            $signer->signerId(),
            'signer fingerprint drifted — verifiers resolve receipts by this'
        );
    }

    public function testCanonicalBytesAndSignatureMatchTheSharedVectors(): void
    {
        $v = $this->vectors();
        $seed = hex2bin($v['signer_key']['seed_hex']);
        self::assertIsString($seed);
        $signer = NodeSigner::fromSeed($seed);

        $r = $v['receipt'];
        $receipt = new Receipt(
            $r['correlation_id'], $r['command'], $r['status'], $r['error'],
            $r['site'], $r['node'], $r['ts_ms'], $r['body_ref'], $r['body']
        );
        $receipt->sign($signer);

        self::assertSame(
            $r['body_hash'],
            $receipt->bodyHash,
            'body hash drifted from the shared vectors'
        );
        self::assertSame(
            $v['canonical_bytes_utf8'],
            $receipt->canonicalBytes(),
            'canonical bytes drifted — every other producer and verifier in the '
            . 'ecosystem now disagrees with this one'
        );
        self::assertSame(
            $v['signature_hex'],
            $receipt->sig,
            'signature drifted from the shared vectors'
        );
    }

    /**
     * The subtlety that breaks naive verifiers: an absent optional is SIGNED as
     * an empty line but OMITTED from the wire. A verifier that reconstructs the
     * canonical bytes by skipping absent fields computes something different
     * and rejects exactly the healthy receipts — the ones with no error and no
     * lineage, which is nearly all of them.
     */
    public function testAbsentOptionalsAreSignedEmptyButOmittedOnTheWire(): void
    {
        $receipt = new Receipt('c', 'cmd', 'ok', null, 's', 'n', 1, 'ref', 'b');
        $canonical = $receipt->canonicalBytes();

        self::assertStringContainsString("\ne=\n", $canonical);
        self::assertStringContainsString("\npid=\n", $canonical);
        self::assertStringContainsString("\nfid=\n", $canonical);

        $fields = $receipt->toFields();
        self::assertArrayNotHasKey('e', $fields);
        self::assertArrayNotHasKey('pid', $fields);
        self::assertArrayNotHasKey('fid', $fields);
    }

    /**
     * `alg` must be inside the signed bytes, or an attacker could rewrite it to
     * a weaker scheme without invalidating the signature.
     */
    public function testAlgorithmIsCoveredBySignature(): void
    {
        $v = $this->vectors();
        $seed = hex2bin($v['signer_key']['seed_hex']);
        self::assertIsString($seed);
        $signer = NodeSigner::fromSeed($seed);

        $receipt = new Receipt('c', 'cmd', 'ok', null, 's', 'n', 1, 'ref', 'b');
        self::assertStringNotContainsString('alg=ed25519', $receipt->canonicalBytes());

        $receipt->sign($signer);
        self::assertStringContainsString('alg=ed25519', $receipt->canonicalBytes());
    }

    /**
     * A tampered receipt must NOT verify. A check that never rejects proves
     * nothing, so the negative case is asserted explicitly.
     */
    public function testTamperedReceiptDoesNotVerify(): void
    {
        $v = $this->vectors();
        $seed = hex2bin($v['signer_key']['seed_hex']);
        self::assertIsString($seed);
        $signer = NodeSigner::fromSeed($seed);

        $r = $v['receipt'];
        $receipt = new Receipt(
            $r['correlation_id'], $r['command'], $r['status'], $r['error'],
            $r['site'], $r['node'], $r['ts_ms'], $r['body_ref'], $r['body']
        );
        $receipt->sign($signer);
        $sig = hex2bin($receipt->sig);
        self::assertIsString($sig);

        self::assertTrue(
            sodium_crypto_sign_verify_detached(
                $sig, $receipt->canonicalBytes(), $signer->publicKey()
            ),
            'the untampered receipt must verify'
        );

        $receipt->status = 'failed';
        self::assertFalse(
            sodium_crypto_sign_verify_detached(
                $sig, $receipt->canonicalBytes(), $signer->publicKey()
            ),
            'a receipt altered after signing must NOT verify'
        );
    }
}
