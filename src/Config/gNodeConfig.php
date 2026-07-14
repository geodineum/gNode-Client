<?php
declare(strict_types=1);

namespace gCore\gNode\Config;

/**
 * gNodeConfig - Centralized configuration for gNode-Client
 *
 * This class encapsulates ALL knowledge about gNode infrastructure:
 * - ValKey host/port
 * - Password locations
 * - Default settings
 *
 * gCore/gCube should NEVER need to know these details.
 * They just provide: user, site_id, environment - gNode-Client handles the rest.
 *
 * @package gCore\gNode\Config
 */
class gNodeConfig
{
    /** @var string Default ValKey host */
    private const DEFAULT_HOST = '127.0.0.1';

    /** @var int Default ValKey port */
    private const DEFAULT_PORT = 47445;

    /** @var int Future standardized port (for migration) */
    private const STANDARD_PORT = 47445;

    /** @var float Default connection timeout */
    private const DEFAULT_TIMEOUT = 2.5;

    /** @var float Default read timeout */
    private const DEFAULT_READ_TIMEOUT = 2.5;

    /** @var int Default retry interval (ms) */
    private const DEFAULT_RETRY_INTERVAL = 100;

    /** @var int Default database number */
    private const DEFAULT_DATABASE = 0;

    /** @var array Configuration values */
    private array $config;

    /**
     * Create a new gNode configuration
     *
     * Minimal required config:
     *   ['user' => 'gnode_client_mysite']
     *
     * Everything else is auto-discovered or uses sensible defaults.
     *
     * @param array $config Configuration overrides
     */
    public function __construct(array $config = [])
    {
        $this->config = $this->buildConfig($config);
    }

    /**
     * Build complete configuration from partial input
     *
     * @param array $config Partial configuration
     * @return array Complete configuration
     */
    private function buildConfig(array $config): array
    {
        // Get host from env or config or default
        $host = $config['host']
            ?? getenv('VALKEY_HOST') ?: self::DEFAULT_HOST;

        // Get port from env or config or default
        $port = $config['port']
            ?? (getenv('VALKEY_PORT') ? (int) getenv('VALKEY_PORT') : self::DEFAULT_PORT);

        // Get user from env or config (required for password resolution)
        $user = $config['user']
            ?? getenv('VALKEY_USER') ?: null;

        // Resolve password using CredentialResolver
        $password = $config['password'] ?? null;
        if ($password === null && $user !== null) {
            $password = CredentialResolver::tryResolve($user);
        }

        return [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'password' => $password,
            'timeout' => $config['timeout'] ?? self::DEFAULT_TIMEOUT,
            'read_timeout' => $config['read_timeout'] ?? self::DEFAULT_READ_TIMEOUT,
            'retry_interval' => $config['retry_interval'] ?? self::DEFAULT_RETRY_INTERVAL,
            'database' => $config['database'] ?? self::DEFAULT_DATABASE,
            'prefix' => $config['prefix'] ?? '',

            // gNode-specific settings
            'site_id' => $config['site_id'] ?? getenv('GNODE_SITE_ID') ?: 'default',
            'node_id' => $config['node_id'] ?? getenv('GNODE_NODE_ID') ?: 'default',
            'environment' => $config['environment'] ?? getenv('GNODE_ENVIRONMENT') ?: 'production',
            'stream_prefix' => $config['stream_prefix'] ?? 'gnode',

            // Topology namespace - shared across all sites for unified service discovery
            // All services register to {topology_namespace}:gnode:topology
            // Default: "geodineum" → creates key {geodineum}:gnode:topology
            'topology_namespace' => $config['topology_namespace'] ?? getenv('GNODE_TOPOLOGY_NAMESPACE') ?: 'geodineum',
        ];
    }

    /**
     * Get configuration value
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if not set
     * @return mixed Configuration value
     */
    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get all configuration as array
     *
     * @return array Complete configuration
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Get ValKey connection configuration
     *
     * Returns only the connection-related settings for ValKeyStorage
     *
     * @return array ValKey connection config
     */
    public function getValKeyConfig(): array
    {
        return [
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'user' => $this->config['user'],
            'password' => $this->config['password'],
            'timeout' => $this->config['timeout'],
            'read_timeout' => $this->config['read_timeout'],
            'retry_interval' => $this->config['retry_interval'],
            'database' => $this->config['database'],
            'prefix' => $this->config['prefix'],
        ];
    }

    /**
     * Get stream key for unified stream
     *
     * Pattern: {site_id}:gnode:unified:{environment}
     * The {} around site_id is for Redis Cluster hash tag routing.
     *
     * @return string Stream key
     */
    public function getUnifiedStreamKey(): string
    {
        $siteId = $this->config['site_id'];
        $prefix = $this->config['stream_prefix'];
        $env = $this->config['environment'];

        return "{{$siteId}}:{$prefix}:unified:{$env}";
    }

    /**
     * Get stream key for health stream
     *
     * Pattern: {site_id}:gnode:health:{environment}
     *
     * @return string Stream key
     */
    public function getHealthStreamKey(): string
    {
        $siteId = $this->config['site_id'];
        $prefix = $this->config['stream_prefix'];
        $env = $this->config['environment'];

        return "{{$siteId}}:{$prefix}:health:{$env}";
    }

    /**
     * Get stream key for broadcast stream
     *
     * Pattern: {site_id}:gnode:broadcast:global
     *
     * @return string Stream key
     */
    public function getBroadcastStreamKey(): string
    {
        $siteId = $this->config['site_id'];
        $prefix = $this->config['stream_prefix'];

        return "{{$siteId}}:{$prefix}:broadcast:global";
    }

    /**
     * Get request key for key-based pattern
     *
     * Pattern: {site_id}:req:{request_id}
     *
     * @param string $requestId Request identifier
     * @return string Request key
     */
    public function getRequestKey(string $requestId): string
    {
        $siteId = $this->config['site_id'];
        return "{{$siteId}}:req:{$requestId}";
    }

    /**
     * Get response key for key-based pattern
     *
     * Pattern: {site_id}:res:{request_id}
     *
     * @param string $requestId Request identifier
     * @return string Response key
     */
    public function getResponseKey(string $requestId): string
    {
        $siteId = $this->config['site_id'];
        return "{{$siteId}}:res:{$requestId}";
    }

    /**
     * Get topology key
     *
     * Pattern: {topology_namespace}:gnode:topology
     * Default: {geodineum}:gnode:topology
     *
     * This is the shared topology key where ALL services across all sites register.
     * Using a unified namespace (instead of per-site keys) ensures the daemon can
     * discover all services regardless of which site they belong to.
     *
     * @return string Topology key
     */
    public function getTopologyKey(): string
    {
        $namespace = $this->config['topology_namespace'];
        return "{{$namespace}}:gnode:topology";
    }

    /**
     * Get topology namespace
     *
     * @return string Topology namespace (default: "geodineum")
     */
    public function getTopologyNamespace(): string
    {
        return $this->config['topology_namespace'] ?? 'geodineum';
    }

    /**
     * Get the site ID from configuration
     *
     * @return string Site identifier
     */
    public function getSiteId(): string
    {
        return $this->config['site_id'] ?? 'default';
    }

    /**
     * Get the environment from configuration
     *
     * @return string DTAP environment
     */
    public function getEnvironment(): string
    {
        return $this->config['environment'] ?? 'production';
    }

    /**
     * Create a copy of this config with a different environment
     *
     * Useful for testing against different DTAP environments.
     *
     * @param string $environment New environment (testing/staging/acceptance/production)
     * @return self New configuration instance
     */
    public function withEnvironment(string $environment): self
    {
        return new self(array_merge($this->config, [
            'environment' => $environment,
        ]));
    }

    /**
     * Check if configuration is valid for connection
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return !empty($this->config['host'])
            && !empty($this->config['port'])
            && !empty($this->config['user'])
            && !empty($this->config['password']);
    }

    /**
     * Get validation errors
     *
     * @return array List of validation errors
     */
    public function getValidationErrors(): array
    {
        $errors = [];

        if (empty($this->config['host'])) {
            $errors[] = 'Missing host (set VALKEY_HOST or pass in config)';
        }

        if (empty($this->config['port'])) {
            $errors[] = 'Missing port (set VALKEY_PORT or pass in config)';
        }

        if (empty($this->config['user'])) {
            $errors[] = 'Missing user (set VALKEY_USER or pass in config)';
        }

        if (empty($this->config['password'])) {
            $errors[] = 'Missing password (set VALKEY_PASSWORD or VALKEY_PASSWORD_FILE, or ensure password file exists)';
        }

        return $errors;
    }

    /**
     * Create configuration from environment variables only
     *
     * Useful when running in containers or systemd services
     * where all config comes from environment.
     *
     * Required env vars:
     *   VALKEY_USER - ValKey ACL username
     *
     * Optional env vars:
     *   VALKEY_HOST - ValKey host (default: 127.0.0.1)
     *   VALKEY_PORT - ValKey port (default: 47445)
     *   VALKEY_PASSWORD - Direct password
     *   VALKEY_PASSWORD_FILE - Path to password file
     *   GNODE_SITE_ID - Site identifier
     *   GNODE_NODE_ID - Node identifier
     *   GNODE_ENVIRONMENT - DTAP environment
     *
     * @return self Configuration instance
     */
    public static function fromEnvironment(): self
    {
        return new self([]);
    }

    /**
     * Create configuration for a specific site
     *
     * This is the recommended way for gCore to create a client.
     * Just pass site_id and optional environment - gNode-Client figures out the rest.
     *
     * @param string $siteId Site identifier (e.g., 'staging_example_com')
     * @param string $environment DTAP environment (default: 'production')
     * @param array $overrides Optional configuration overrides
     * @return self Configuration instance
     */
    public static function forSite(string $siteId, string $environment = 'production', array $overrides = []): self
    {
        $user = 'gnode_client_' . $siteId;

        return new self(array_merge([
            'user' => $user,
            'site_id' => $siteId,
            'environment' => $environment,
        ], $overrides));
    }

}
