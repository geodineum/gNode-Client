<?php
declare(strict_types=1);
/**
 * ServiceProxy - Transparent service discovery and invocation via magic __call
 *
 * Features:
 * - Magic __call routing to service methods
 * - Automatic service discovery based on method→capability mapping
 * - Service result caching
 * - Transparent auto-batching integration
 * - Load-aware service selection
 *
 * Usage:
 *   $proxy = new ServiceProxy($client, $registry, $cache);
 *   $result = $proxy->renderTemplate('mytemplate', ['var' => 'value']);
 *   // Automatically discovers template service and invokes it
 *
 * @package gCore\gNode\Discovery
 */

namespace gCore\gNode\Discovery;

use gCore\gNode\gNodeClientInterface;
use gCore\gNode\Queue\DeferredResult;
use gCore\gNode\Exception\gNodeException;

class ServiceProxy
{
    /** @var gNodeClientInterface */
    private $client;

    /** @var ServiceRegistry */
    private $registry;

    /** @var ServiceCache */
    private $cache;

    /** @var bool Whether to use load-aware discovery */
    private $loadAware;

    /** @var bool Whether discovery caching is enabled */
    private $cacheEnabled;

    /** @var array Statistics */
    private $stats = [
        'calls' => 0,
        'discoveries' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'errors' => 0,
    ];

    /**
     * @param gNodeClientInterface $client gNode client instance
     * @param ServiceRegistry $registry Service registry for method→capability mapping
     * @param ServiceCache $cache Service cache for discovered services
     * @param array $options Configuration options
     */
    public function __construct(
        gNodeClientInterface $client,
        ServiceRegistry $registry,
        ServiceCache $cache,
        array $options = []
    ) {
        $this->client = $client;
        $this->registry = $registry;
        $this->cache = $cache;

        $this->loadAware = $options['load_aware'] ?? true;
        $this->cacheEnabled = $options['cache_enabled'] ?? true;
    }

    /**
     * Magic method router for transparent service calls
     *
     * Handles calls like:
     *   $proxy->renderTemplate($id, $vars)
     *   $proxy->optimizeImage($path, $options)
     *   $proxy->parseMarkdown($content)
     *
     * @param string $method Method name
     * @param array $args Method arguments
     * @return mixed|DeferredResult Service result, or DeferredResult if queued
     * @throws gNodeException If service discovery fails
     */
    public function __call(string $method, array $args)
    {
        $this->stats['calls']++;

        try {
            // Convert method name to snake_case command
            $command = $this->methodToCommand($method);

            // Get capability requirements for this method
            $capabilities = $this->registry->getCapabilities($method);

            if ($capabilities === null) {
                throw new gNodeException(
                    "Unknown method '{$method}' - not registered in service registry. " .
                    "Register it with ServiceRegistry::register() or use a known method."
                );
            }

            // Discover service if needed
            $service = $this->discoverService($capabilities);

            if ($service === null) {
                throw new gNodeException(
                    "No service found for method '{$method}' with capabilities: " .
                    json_encode($capabilities)
                );
            }

            // Build parameters from arguments
            $params = $this->buildParams($method, $args);

            // Execute command via client
            // If queue is enabled on client, this will return DeferredResult
            return $this->client->executeCommand($command, $params);

        } catch (gNodeException $e) {
            $this->stats['errors']++;
            throw $e;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new gNodeException(
                "Service proxy error for method '{$method}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Manually flush pending commands (if queue enabled)
     *
     * @return array Results indexed by command ID
     */
    public function flush(): array
    {
        $queue = $this->client->getQueue();

        if ($queue === null) {
            return [];
        }

        return $queue->flush(true);
    }

    /**
     * Discover service for given capabilities
     *
     * Uses cache if enabled, otherwise performs fresh discovery.
     *
     * @param array $capabilities Capability requirements
     * @return array|null Service data
     */
    private function discoverService(array $capabilities): ?array
    {
        // Check cache first
        if ($this->cacheEnabled) {
            $cached = $this->cache->get($capabilities);

            if ($cached !== null) {
                $this->stats['cache_hits']++;
                return $cached;
            }

            $this->stats['cache_misses']++;
        }

        // Perform discovery
        $this->stats['discoveries']++;

        try {
            $result = $this->client->geometricDiscover($capabilities, $this->loadAware);

            if (isset($result['service_id'])) {
                // Cache discovered service
                if ($this->cacheEnabled) {
                    $this->cache->set($capabilities, $result);
                }

                return $result;
            }

            return null;

        } catch (\Exception $e) {
            // Discovery failed
            return null;
        }
    }

    /**
     * Convert camelCase method name to snake_case command
     *
     * Examples:
     *   renderTemplate -> render_template
     *   optimizeImage -> optimize_image
     *   parseMarkdown -> parse_markdown
     *
     * @param string $method Method name
     * @return string Command name
     */
    private function methodToCommand(string $method): string
    {
        // Insert underscores before uppercase letters
        $command = preg_replace('/([a-z])([A-Z])/', '$1_$2', $method);

        // Convert to lowercase
        return strtolower($command);
    }

    /**
     * Build parameter array from method arguments
     *
     * Handles different argument patterns:
     *   - Named parameters: ['key' => 'value']
     *   - Positional parameters: converted to param_0, param_1, etc.
     *   - Mixed: named params take precedence
     *
     * @param string $method Method name
     * @param array $args Method arguments
     * @return array Parameter map
     */
    private function buildParams(string $method, array $args): array
    {
        if (empty($args)) {
            return [];
        }

        // If first arg is array with string keys, use it as params
        if (count($args) === 1 && is_array($args[0])) {
            $firstArg = $args[0];

            // Check if associative array
            if (!array_is_list($firstArg)) {
                return $firstArg;
            }
        }

        // Convert positional args to named params
        $params = [];

        foreach ($args as $index => $value) {
            if (is_array($value) && !array_is_list($value)) {
                // Merge associative arrays
                $params = array_merge($params, $value);
            } else {
                // Use positional parameter names
                $params["param_{$index}"] = $value;
            }
        }

        return $params;
    }

    /**
     * Get proxy statistics
     *
     * @return array Statistics
     */
    public function getStats(): array
    {
        $cacheStats = $this->cache->getStats();

        return array_merge($this->stats, [
            'cache_hit_rate' => $cacheStats['hit_rate_percent'] ?? 0,
            'cached_services' => $cacheStats['size'] ?? 0,
        ]);
    }

    /**
     * Clear service cache
     */
    public function clearCache(): void
    {
        $this->cache->clear();
    }

    /**
     * Invalidate cache for specific service
     *
     * @param string $serviceId Service identifier
     */
    public function invalidateService(string $serviceId): void
    {
        $this->cache->invalidateService($serviceId);
    }

    /**
     * Enable or disable load-aware discovery
     *
     * @param bool $enabled
     */
    public function setLoadAware(bool $enabled): void
    {
        $this->loadAware = $enabled;
    }

    /**
     * Enable or disable discovery caching
     *
     * @param bool $enabled
     */
    public function setCacheEnabled(bool $enabled): void
    {
        $this->cacheEnabled = $enabled;
    }

    /**
     * Get the underlying client
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Get the service registry
     *
     * @return ServiceRegistry
     */
    public function getRegistry(): ServiceRegistry
    {
        return $this->registry;
    }

    /**
     * Get the service cache
     *
     * @return ServiceCache
     */
    public function getCache(): ServiceCache
    {
        return $this->cache;
    }

    /**
     * Check if a method is available
     *
     * @param string $method Method name
     * @return bool
     */
    public function hasMethod(string $method): bool
    {
        return $this->registry->has($method);
    }

    /**
     * Get list of available methods
     *
     * @return array Method names
     */
    public function getMethods(): array
    {
        return $this->registry->getMethods();
    }

    /**
     * Register a new method dynamically
     *
     * @param string $method Method name
     * @param array $capabilities Capability requirements
     */
    public function registerMethod(string $method, array $capabilities): void
    {
        $this->registry->register($method, $capabilities);
    }

    /**
     * Pre-discover and cache services for registered methods
     *
     * Useful for warming up the cache at initialization.
     *
     * @param array|null $methods Specific methods to pre-discover, or null for all
     * @return int Number of services discovered
     */
    public function warmupCache(?array $methods = null): int
    {
        $methodsToWarmup = $methods ?? $this->registry->getMethods();
        $discovered = 0;

        foreach ($methodsToWarmup as $method) {
            $capabilities = $this->registry->getCapabilities($method);

            if ($capabilities !== null) {
                $service = $this->discoverService($capabilities);

                if ($service !== null) {
                    $discovered++;
                }
            }
        }

        return $discovered;
    }
}
