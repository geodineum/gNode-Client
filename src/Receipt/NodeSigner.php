<?php

declare(strict_types=1);

namespace gCore\gNode\Receipt;

use RuntimeException;

/**
 * A producer's Ed25519 signing identity.
 *
 * The private key never leaves the host. Only the public half is published, to
 * the topology registry, where verifiers resolve a receipt's `signer`
 * fingerprint back to a key.
 *
 * Uses ext-sodium (bundled since PHP 7.2), which is the same Ed25519 as
 * ed25519-dalek on the Rust side — verified by reproducing the shared vectors,
 * not assumed from the algorithm name.
 */
final class NodeSigner
{
    /** 32-byte seed. Never logged, never serialized. */
    private string $seed;
    private string $publicKey;

    private function __construct(string $seed, string $publicKey)
    {
        $this->seed = $seed;
        $this->publicKey = $publicKey;
    }

    public static function fromSeed(string $seed): self
    {
        if (strlen($seed) !== 32) {
            throw new RuntimeException('ed25519 seed must be exactly 32 bytes');
        }
        $kp = sodium_crypto_sign_seed_keypair($seed);

        return new self($seed, sodium_crypto_sign_publickey($kp));
    }

    /**
     * Load this producer's key, generating and persisting one on first use.
     *
     * File format is one line `<alg>:<seed_hex>`, matching the daemon's and
     * COMMS's key files so an operator sees ONE scheme across the ecosystem.
     *
     * Written 0600. A signing key readable beyond its owner is a forgeable
     * identity — and the permission sweep has flattened a 0600 key to
     * group-readable before now, which is why both existing key paths carry an
     * explicit exemption in geodeploy's config pass. A new key path needs the
     * same exemption; see CONTRACTS/permission-model.md.
     */
    public static function loadOrGenerate(string $path): self
    {
        if (is_readable($path)) {
            $raw = trim((string) file_get_contents($path));
            $parts = explode(':', $raw, 2);
            if (count($parts) !== 2 || $parts[0] !== 'ed25519') {
                throw new RuntimeException(
                    "unreadable or non-ed25519 signing key at {$path}"
                );
            }
            $seed = @hex2bin(trim($parts[1]));
            if ($seed === false || strlen($seed) !== 32) {
                throw new RuntimeException("malformed ed25519 seed at {$path}");
            }

            return self::fromSeed($seed);
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create key directory {$dir}");
        }

        $seed = random_bytes(32);
        // Create 0600 BEFORE writing, so the secret is never briefly readable
        // at the umask default between creation and chmod.
        $fh = @fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException("cannot write signing key to {$path}");
        }
        @chmod($path, 0600);
        fwrite($fh, 'ed25519:' . bin2hex($seed) . "\n");
        fclose($fh);

        return self::fromSeed($seed);
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function publicKeyHex(): string
    {
        return bin2hex($this->publicKey);
    }

    /**
     * Short fingerprint: first 8 bytes of sha256(public key), hex.
     *
     * Verifiers resolve this to the published key, so it must be derived
     * identically in every implementation — and a verifier must check that the
     * id IS the fingerprint of the key it names, or a registry can be repointed
     * at an attacker's key while still looking internally consistent.
     */
    public function signerId(): string
    {
        return substr(bin2hex(hash('sha256', $this->publicKey, true)), 0, 16);
    }

    /** Raw detached Ed25519 signature over $message. */
    public function sign(string $message): string
    {
        $kp = sodium_crypto_sign_seed_keypair($this->seed);

        return sodium_crypto_sign_detached(
            $message,
            sodium_crypto_sign_secretkey($kp)
        );
    }

    /** The registry value a verifier stores: `alg:pubkey_hex`. */
    public function registryValue(): string
    {
        return 'ed25519:' . $this->publicKeyHex();
    }
}
