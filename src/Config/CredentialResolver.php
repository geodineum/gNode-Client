<?php
declare(strict_types=1);

namespace gCore\gNode\Config;

use gCore\gNode\Exception\ConfigException;

/**
 * CredentialResolver - Auto-discovers ValKey credentials
 *
 * This class encapsulates ALL knowledge about credential locations,
 * keeping gCore/gCube completely decoupled from gNode internals.
 *
 * Resolution order (first match wins):
 * 1. Environment variable: VALKEY_PASSWORD (direct password)
 * 2. Environment variable: VALKEY_PASSWORD_FILE (path to file)
 * 3. Centralized ecosystem: /etc/geodineum/credentials/{user}.password
 * 4. Standard location: /opt/geodineum/gNode/.gnode/{user}.password
 * 5. Legacy location: /opt/gNode/.gnode/{user}.password
 *
 * The centralized path (/etc/geodineum/credentials/) is the recommended
 * location for production deployments. It's defined in bootstrap.env as
 * GEODINEUM_CREDENTIALS_DIR and follows FHS conventions.
 *
 * @package gCore\gNode\Config
 */
class CredentialResolver
{
    /** @var string Centralized ecosystem credentials path (FHS-compliant) */
    private const CENTRALIZED_PATH = '/etc/geodineum/credentials';

    /** @var string Standard gNode installation path */
    private const STANDARD_PATH = '/opt/geodineum/gNode';

    /** @var string Legacy gNode installation path */
    private const LEGACY_PATH = '/opt/gNode';

    /** @var string Password directory within gNode installations */
    private const PASSWORD_DIR = '.gnode';

    /** @var array Cached resolved passwords */
    private static array $cache = [];

    /**
     * Resolve password for a ValKey user
     *
     * @param string $user ValKey ACL username (e.g., 'gnode_client_staging_example_com')
     * @return string The resolved password
     * @throws ConfigException If password cannot be resolved
     */
    public static function resolve(string $user): string
    {
        // Check cache first
        if (isset(self::$cache[$user])) {
            return self::$cache[$user];
        }

        $password = self::tryResolve($user);

        if ($password === null) {
            throw new ConfigException(
                "Cannot resolve password for ValKey user: {$user}. " .
                "Set VALKEY_PASSWORD or VALKEY_PASSWORD_FILE environment variable, " .
                "or ensure password file exists at standard location."
            );
        }

        // Cache for subsequent calls
        self::$cache[$user] = $password;

        return $password;
    }

    /**
     * Try to resolve password, return null if not found
     *
     * @param string $user ValKey ACL username
     * @return string|null Password or null if not found
     */
    public static function tryResolve(string $user): ?string
    {
        // 1. Direct password from environment (highest priority)
        $envPassword = getenv('VALKEY_PASSWORD');
        if ($envPassword !== false && $envPassword !== '') {
            return $envPassword;
        }

        // 2. Password file path from environment
        $envPasswordFile = getenv('VALKEY_PASSWORD_FILE');
        if ($envPasswordFile !== false && $envPasswordFile !== '') {
            return self::readPasswordFile($envPasswordFile);
        }

        // 3. Centralized ecosystem location (/etc/geodineum/credentials/)
        $centralizedFile = self::getCentralizedPasswordPath($user);
        if ($centralizedFile !== null) {
            $password = self::readPasswordFile($centralizedFile);
            if ($password !== null) {
                return $password;
            }
        }

        // 4. Standard location based on username
        $standardFile = self::getStandardPasswordPath($user);
        if ($standardFile !== null) {
            $password = self::readPasswordFile($standardFile);
            if ($password !== null) {
                return $password;
            }
        }

        // 5. Legacy location
        $legacyFile = self::getLegacyPasswordPath($user);
        if ($legacyFile !== null) {
            $password = self::readPasswordFile($legacyFile);
            if ($password !== null) {
                return $password;
            }
        }

        return null;
    }

    /**
     * Get centralized ecosystem password file path for a user
     *
     * This is the recommended location for production deployments.
     * Path: /etc/geodineum/credentials/{user}.password
     *
     * @param string $user ValKey username
     * @return string|null Path or null if cannot be determined
     */
    public static function getCentralizedPasswordPath(string $user): ?string
    {
        $filename = self::usernameToFilename($user);

        if ($filename === null) {
            return null;
        }

        return self::CENTRALIZED_PATH . '/' . $filename;
    }

    /**
     * Get standard password file path for a user
     *
     * @param string $user ValKey username
     * @return string|null Path or null if cannot be determined
     */
    public static function getStandardPasswordPath(string $user): ?string
    {
        // For client users: gnode_client_xxx → valkey_client_xxx.password
        // For daemon user: gnode_daemon → valkey_daemon.password
        $filename = self::usernameToFilename($user);

        if ($filename === null) {
            return null;
        }

        return self::STANDARD_PATH . '/' . self::PASSWORD_DIR . '/' . $filename;
    }

    /**
     * Get legacy password file path for a user
     *
     * @param string $user ValKey username
     * @return string|null Path or null if cannot be determined
     */
    public static function getLegacyPasswordPath(string $user): ?string
    {
        $filename = self::usernameToFilename($user);

        if ($filename === null) {
            return null;
        }

        return self::LEGACY_PATH . '/' . self::PASSWORD_DIR . '/' . $filename;
    }

    /**
     * Convert ValKey username to password filename
     *
     * Examples:
     *   gnode_client_staging_example_com → valkey_client_staging_example_com.password
     *   gnode_daemon → valkey_daemon.password
     *   gnode_client → valkey_client.password
     *
     * @param string $user ValKey username
     * @return string|null Filename or null if invalid format
     */
    private static function usernameToFilename(string $user): ?string
    {
        // Replace gnode_ prefix with valkey_ and add .password extension
        if (strpos($user, 'gnode_') === 0) {
            return 'valkey_' . substr($user, 6) . '.password';
        }

        // If no gnode_ prefix, just add .password
        return $user . '.password';
    }

    /**
     * Read password from file.
     *
     * Commit 1.12.b (NC-D2.04): TOCTOU hardening. The previous
     * `file_exists` + `is_readable` + `file_get_contents` sequence left a
     * symlink-swap race on shared hosts — an adversary who can plant /
     * swap a symlink between the existence check and the read could
     * redirect to an attacker-controlled file. Fix: skip the existence
     * checks, read directly, test the return. `@file_get_contents`
     * suppresses the E_WARNING that would otherwise appear when the
     * file is absent or unreadable — the semantically-meaningful signal
     * is the `false` return.
     *
     * @param string $path File path
     * @return string|null Password, or null if the read failed
     */
    private static function readPasswordFile(string $path): ?string
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            return null;
        }

        // Trim whitespace/newlines from password
        return trim($content);
    }

    /**
     * Clear the password cache
     *
     * Useful for testing or when credentials change
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Check if a password can be resolved for a user
     *
     * @param string $user ValKey username
     * @return bool True if password can be resolved
     */
    public static function canResolve(string $user): bool
    {
        return self::tryResolve($user) !== null;
    }

    /**
     * Resolve the PATH to the password file for a user, without reading
     * the file's contents.
     *
     * Commit NC-D2.05.b: the `startDaemon` path passes this path via
     * `GNODE_REDIS_AUTH_FILE` to the daemon instead of shell-
     * interpolating the password itself. Closes the
     * `/proc/<pid>/environ` + `/proc/<pid>/cmdline` leak.
     *
     * Resolution order mirrors tryResolve's precedence:
     *   1. env VALKEY_PASSWORD_FILE (if set, it is used verbatim — the
     *      operator is trusted to point us at a real file)
     *   2. centralized `/etc/geodineum/credentials/{name}` (production)
     *   3. standard `/opt/geodineum/gNode/.gnode/{name}`
     *   4. legacy `/opt/gNode/.gnode/{name}`
     *
     * Returns the first path that EXISTS and is readable; null if none
     * resolve. Crucially: does NOT fall through to the env-inline
     * VALKEY_PASSWORD source, because that's a secret-in-env scenario
     * that the daemon-side can't consume as a file.
     *
     * @param string $user ValKey ACL username
     * @return string|null Path to the password file, or null if none
     *                     is usable on this host
     */
    public static function tryResolveFilePath(string $user): ?string
    {
        $envPasswordFile = getenv('VALKEY_PASSWORD_FILE');
        if ($envPasswordFile !== false && $envPasswordFile !== ''
            && @is_readable($envPasswordFile)
        ) {
            return $envPasswordFile;
        }

        $candidates = [
            self::getCentralizedPasswordPath($user),
            self::getStandardPasswordPath($user),
            self::getLegacyPasswordPath($user),
        ];

        foreach ($candidates as $path) {
            if ($path !== null && @is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get debug info about credential resolution
     *
     * @param string $user ValKey username
     * @return array Debug information
     */
    public static function getDebugInfo(string $user): array
    {
        $centralizedPath = self::getCentralizedPasswordPath($user);
        $standardPath = self::getStandardPasswordPath($user);
        $legacyPath = self::getLegacyPasswordPath($user);

        return [
            'user' => $user,
            'env_VALKEY_PASSWORD' => getenv('VALKEY_PASSWORD') !== false ? '(set)' : '(not set)',
            'env_VALKEY_PASSWORD_FILE' => getenv('VALKEY_PASSWORD_FILE') ?: '(not set)',
            'centralized_path' => $centralizedPath,
            'centralized_exists' => $centralizedPath && file_exists($centralizedPath),
            'standard_path' => $standardPath,
            'standard_exists' => $standardPath && file_exists($standardPath),
            'legacy_path' => $legacyPath,
            'legacy_exists' => $legacyPath && file_exists($legacyPath),
            'can_resolve' => self::canResolve($user),
        ];
    }
}
