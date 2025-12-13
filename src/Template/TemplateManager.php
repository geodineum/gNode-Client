<?php

declare(strict_types=1);

namespace gCore\GSD\Template;

use gCore\GSD\Client;
use gCore\GSD\Exception\GSDException;
use gCore\GSD\Storage\StorageInterface;

/**
 * TemplateManager - Facade for Tera-powered template system
 *
 * Integrates with GSD daemon's template rendering engine (daemon/src/integration/template_renderer.rs)
 * providing 8D geometric capability discovery, dependency DAG management, and server-side rendering.
 *
 * Daemon Features (PRODUCTION-READY):
 * - Tera template engine with Jinja2-like syntax
 * - 8D geometric vectors: html, complexity, interactivity, data_density, reusability,
 *   cacheability, semantic_layout, render_cost
 * - Dependency tracking with cycle detection
 * - Transitive invalidation via reverse dependency map
 * - Auto-escaping for XSS prevention
 * - ValKey persistence with metadata and output caching
 *
 * @package gCore\GSD\Template
 */
class TemplateManager
{
    /**
     * @var Client GSD client instance
     */
    private Client $client;

    /**
     * @var StorageInterface ValKey storage interface
     */
    private StorageInterface $storage;

    /**
     * @var string Site ID for stream naming
     */
    private string $siteId;

    /**
     * @var string Node ID for stream naming
     */
    private string $nodeId;

    /**
     * @var array<string, mixed> Configuration options
     */
    private array $config;

    /**
     * @var array<string, int> Statistics tracking
     */
    private array $stats = [
        'templates_registered' => 0,
        'renders_performed' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'invalidations' => 0,
        'errors' => 0
    ];

    /**
     * TemplateManager constructor
     *
     * @param Client $client GSD client instance
     * @param StorageInterface $storage ValKey storage interface
     * @param string $siteId Site ID (default: "default")
     * @param string $nodeId Node ID (default: "default")
     * @param array<string, mixed> $config Configuration options
     */
    public function __construct(
        Client $client,
        StorageInterface $storage,
        string $siteId = 'default',
        string $nodeId = 'default',
        array $config = []
    ) {
        $this->client = $client;
        $this->storage = $storage;
        $this->siteId = $siteId;
        $this->nodeId = $nodeId;
        $this->config = array_merge([
            'cache_ttl' => 300,
            'use_valkey_functions' => true,
            'debug' => false
        ], $config);
    }

    /**
     * Register a template with the daemon
     *
     * Stores template content and automatically extracts 8D geometric capabilities
     * for discovery. Template is persisted in ValKey with metadata.
     *
     * Usage:
     * ```php
     * $manager->registerTemplate('user-profile',
     *     '<h1>{{ user.name }}</h1><p>{{ user.bio }}</p>',
     *     ['dependencies' => ['header', 'footer']]
     * );
     * ```
     *
     * @param string $templateId Unique template identifier
     * @param string $content Template content (Tera syntax)
     * @param array<string, mixed> $config Optional configuration (dependencies, variables, etc.)
     * @return array<string, mixed> Registration result with dependencies array
     * @throws GSDException On registration failure
     */
    public function registerTemplate(string $templateId, string $content, array $config = []): array
    {
        if (empty($templateId)) {
            $this->stats['errors']++;
            throw new GSDException('Template ID cannot be empty');
        }

        if (empty($content)) {
            $this->stats['errors']++;
            throw new GSDException('Template content cannot be empty');
        }

        try {
            // Use template_fragment command (daemon's actual command name)
            $response = $this->client->executeCommand('template_fragment', [
                'template_id' => $templateId,
                'content' => $content,
                'variables' => $config['variables'] ?? new \stdClass(),
                'ttl' => $config['ttl'] ?? 7200
            ]);

            if (!isset($response['stored']) || $response['stored'] !== true) {
                $this->stats['errors']++;
                throw new GSDException('Template registration failed: ' . ($response['error'] ?? 'unknown error'));
            }

            $this->stats['templates_registered']++;

            return [
                'success' => true,
                'template_id' => $response['template_id'] ?? $templateId,
                'dependencies' => $response['dependencies'] ?? [],
                'registered_in_topology' => $response['registered_in_topology'] ?? false,
                'ttl' => $response['ttl'] ?? 7200
            ];
        } catch (GSDException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new GSDException(
                "Failed to register template '{$templateId}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Render a template with variables
     *
     * Uses daemon's Tera engine for server-side rendering with auto-escaping.
     *
     * Usage:
     * ```php
     * $html = $manager->renderTemplate('user-profile', [
     *     'user' => ['name' => 'Alice', 'bio' => 'Developer']
     * ]);
     * ```
     *
     * @param string $templateId Template identifier
     * @param array<string, mixed> $variables Template variables (will be JSON-encoded)
     * @param array<string, mixed> $config Render configuration
     * @return string Rendered HTML output
     * @throws GSDException On render failure
     */
    public function renderTemplate(string $templateId, array $variables = [], array $config = []): string
    {
        try {
            $response = $this->client->executeCommand('render_template', [
                'template_id' => $templateId,
                'variables' => (object)$variables, // Ensure JSON object, not array
                'config' => $config
            ]);

            // Extract HTML from response
            // executeCommand() already parses batched responses, so we get the result directly
            $html = $response['html'] ?? null;

            if (!$html) {
                $this->stats['errors']++;
                $debug = json_encode($response);
                throw new GSDException("Template rendering failed: no html received (got: {$debug})");
            }

            $this->stats['renders_performed']++;
            return $html;
        } catch (GSDException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new GSDException(
                "Failed to render template '{$templateId}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Serve cached HTML fragment with HTMX-compatible headers
     *
     * Retrieves a cached HTML fragment and generates appropriate HTTP headers
     * for HTMX-driven applications, including:
     * - Content-Type: text/html; charset=utf-8
     * - Cache-Control: Configurable cache policy
     * - ETag: Weak ETag based on content hash
     * - HX-Trigger: Optional HTMX event trigger
     *
     * This is useful for serving pre-rendered template fragments in HTMX workflows,
     * where the daemon has cached the rendered HTML output.
     *
     * @param string $key Cache key for HTML fragment
     * @param string|null $hx_trigger Optional HTMX trigger header value
     * @param string|null $cache_control Cache-Control header (default: "public, max-age=31536000, immutable")
     * @return array Response with 'html' and 'headers' keys
     * @throws GSDException If fragment retrieval fails
     */
    public function serveFragment(
        string $key,
        ?string $hx_trigger = null,
        ?string $cache_control = 'public, max-age=31536000, immutable'
    ): array {
        try {
            $params = ['key' => $key];
            if ($hx_trigger !== null) {
                $params['hx_trigger'] = $hx_trigger;
            }
            if ($cache_control !== null) {
                $params['cache_control'] = $cache_control;
            }

            $response = $this->client->executeCommand('serve_fragment', $params);

            if (!isset($response['html'])) {
                $this->stats['errors']++;
                throw new GSDException('Fragment serving failed: no html content received');
            }

            $this->stats['renders_performed']++;
            return $response;
        } catch (GSDException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new GSDException(
                "Failed to serve fragment '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Delete a template from daemon storage
     *
     * Note: The daemon does not currently provide a delete_template command.
     * Templates are cached with TTL and expire automatically.
     *
     * @param string $templateId Template identifier
     * @param array<string, mixed> $config Delete configuration
     * @return bool Success status
     * @throws GSDException Always throws (not implemented in daemon)
     */
    public function deleteTemplate(string $templateId, array $config = []): bool
    {
        throw new GSDException(
            "delete_template command not available in daemon. Templates expire based on TTL."
        );
    }

    /**
     * List all registered templates
     *
     * @param array<string, mixed> $config List configuration
     * @return array<int, string> Array of template identifiers
     * @throws GSDException On retrieval failure
     */
    public function listTemplates(array $config = []): array
    {
        try {
            $response = $this->client->executeCommand('list_templates', [
                'config' => $config
            ]);

            // Parse batched response format
            if (isset($response['messages']) && !empty($response['messages'])) {
                $message = $response['messages'][0];
                if (isset($message[2])) {
                    $result = json_decode($message[2], true);
                    return $result['templates'] ?? [];
                }
            }

            return $response['templates'] ?? [];
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new GSDException(
                'Failed to list templates: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get template capabilities (8D geometric vector and metadata)
     *
     * Uses get_template_capabilities daemon command to retrieve the template's
     * geometric capability vector and metadata.
     *
     * @param string $templateId Template identifier
     * @param array<string, mixed> $config Metadata configuration
     * @return array<string, mixed>|null Capabilities or null if not found
     * @throws GSDException On retrieval failure
     */
    public function getTemplateMetadata(string $templateId, array $config = []): ?array
    {
        try {
            $response = $this->client->executeCommand('get_template_capabilities', [
                'template_id' => $templateId
            ]);

            return $response ?? null;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new GSDException(
                "Failed to get capabilities for template '{$templateId}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get template dependencies (other templates this template includes)
     *
     * Note: The daemon doesn't have a dedicated get_dependencies command.
     * Dependencies are returned as part of get_template_capabilities.
     *
     * @param string $templateId Template identifier
     * @return array<int, string> Array of template IDs this template depends on
     * @throws GSDException On retrieval failure
     */
    public function getTemplateDependencies(string $templateId): array
    {
        try {
            $capabilities = $this->getTemplateMetadata($templateId);
            return $capabilities['dependencies'] ?? [];
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new GSDException(
                "Failed to get dependencies for template '{$templateId}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Invalidate template cache (and transitively invalidate dependents)
     *
     * Note: The daemon does not currently provide an invalidate_template command.
     * Templates expire based on TTL.
     *
     * @param string $templateId Template identifier to invalidate
     * @param array<string, mixed> $config Invalidation configuration
     * @return array<int, string> Array of invalidated template IDs
     * @throws GSDException Always throws (not implemented in daemon)
     */
    public function invalidateTemplate(string $templateId, array $config = []): array
    {
        throw new GSDException(
            "invalidate_template command not available in daemon. Templates expire based on TTL."
        );
    }

    /**
     * Discover templates similar to a reference template using geometric search
     *
     * Uses daemon's discover_similar_templates command which finds templates
     * similar to a reference template using 8D Euclidean distance.
     *
     * @param string $templateId Reference template ID
     * @param int $limit Maximum results
     * @return array<int, array<string, mixed>> Array of matching template IDs with distances
     * @throws GSDException On discovery failure
     */
    public function discoverSimilarTemplates(string $templateId, int $limit = 10): array
    {
        try {
            $response = $this->client->executeCommand('discover_similar_templates', [
                'template_id' => $templateId,
                'limit' => $limit
            ]);

            return $response['similar_templates'] ?? [];
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new GSDException(
                'Failed to discover similar templates: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Discover templates by capability constraints
     *
     * Uses daemon's discover_templates_by_capability command which filters
     * templates by capability constraints in 8D space.
     *
     * @param array<string, float> $capabilities Capability constraints (8D vector or subset)
     * @param int $limit Maximum results
     * @return array<int, array<string, mixed>> Array of matching templates
     * @throws GSDException On discovery failure
     */
    public function discoverTemplatesByCapability(array $capabilities, int $limit = 100): array
    {
        try {
            $response = $this->client->executeCommand('discover_templates_by_capability', [
                'capabilities' => $capabilities,
                'limit' => $limit
            ]);

            return $response['templates'] ?? [];
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new GSDException(
                'Failed to discover templates by capability: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Render a template string without registration (one-off rendering)
     *
     * Note: The daemon does not provide a render_string command.
     * Use registerTemplate() + renderTemplate() instead, or use a short TTL.
     *
     * @param string $template Template content (Tera syntax)
     * @param array<string, mixed> $variables Template variables
     * @param array<string, mixed> $config Render configuration
     * @return string Rendered output
     * @throws GSDException Always throws (not implemented in daemon)
     */
    public function renderString(string $template, array $variables = [], array $config = []): string
    {
        throw new GSDException(
            "render_string command not available in daemon. Use registerTemplate() with short TTL instead."
        );
    }

    /**
     * Get metadata for all registered templates
     *
     * Note: The daemon provides list_templates which returns template IDs.
     * To get full metadata, call getTemplateMetadata() for each template.
     *
     * @param array<string, mixed> $config Configuration
     * @return array<string, array<string, mixed>> Array of template metadata indexed by template ID
     * @throws GSDException On retrieval failure
     */
    public function getAllTemplateMetadata(array $config = []): array
    {
        try {
            // Get list of all templates
            $templateIds = $this->listTemplates($config);

            // Get metadata for each template
            $allMetadata = [];
            foreach ($templateIds as $templateId) {
                try {
                    $metadata = $this->getTemplateMetadata($templateId);
                    if ($metadata !== null) {
                        $allMetadata[$templateId] = $metadata;
                    }
                } catch (\Exception $e) {
                    // Skip templates that can't be retrieved
                    $this->debug("Failed to get metadata for template '{$templateId}': " . $e->getMessage());
                }
            }

            return $allMetadata;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new GSDException(
                'Failed to get all template metadata: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get statistics about template operations
     *
     * @return array<string, int> Statistics data
     */
    public function getStatistics(): array
    {
        return $this->stats;
    }

    /**
     * Reset statistics
     *
     * @return void
     */
    public function resetStatistics(): void
    {
        $this->stats = array_fill_keys(array_keys($this->stats), 0);
    }

    /**
     * Debug logging helper
     *
     * @param string $message Debug message
     * @return void
     */
    private function debug(string $message): void
    {
        if ($this->config['debug']) {
            error_log("[TemplateManager] {$message}");
        }
    }
}
