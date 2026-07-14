<?php
declare(strict_types=1);
/**
 * ServiceRegistry - Method name to capability vector mapping
 *
 * Features:
 * - Register method names with their required capabilities
 * - Array-based in-memory storage
 * - 8D capability vector support
 * - Default capability templates
 * - Wildcard/pattern matching support
 *
 * Usage:
 *   $registry = new ServiceRegistry();
 *   $registry->register('renderTemplate', ['html' => 1.0, 'template' => 1.0]);
 *   $caps = $registry->getCapabilities('renderTemplate');
 *
 * @package gCore\gNode\Discovery
 */

namespace gCore\gNode\Discovery;

class ServiceRegistry
{
    /** @var array Map of method name => capability vector */
    private $methods = [];

    /** @var array Default capability dimensions */
    private $defaultDimensions = [
        'service_discovery',
        'distributed_cache',
        'geometric_topology',
        'template_rendering',
        'load_balancing',
        'format_conversion',
        'content_delivery',
        'health_monitoring',
    ];

    /** @var array Predefined service templates */
    private $templates = [
        'template_service' => [
            'html' => 1.0,
            'template' => 1.0,
            'render_cost' => 0.5,
        ],
        'image_service' => [
            'image' => 1.0,
            'optimize' => 1.0,
            'transform' => 0.8,
        ],
        'markdown_service' => [
            'markdown' => 1.0,
            'parse' => 1.0,
            'html' => 0.8,
        ],
        'cache_service' => [
            'distributed_cache' => 1.0,
            'persistence' => 1.0,
            'ttl_support' => 1.0,
        ],
    ];

    /**
     * Constructor
     *
     * @param array $customDimensions Optional custom dimension names
     */
    public function __construct(array $customDimensions = [])
    {
        if (!empty($customDimensions)) {
            $this->defaultDimensions = $customDimensions;
        }

        // Register default methods
        $this->registerDefaults();
    }

    /**
     * Register a method with its capability requirements
     *
     * @param string $methodName Method name
     * @param array $capabilities Capability vector (dimension => value)
     * @param array $options Optional metadata (description, etc.)
     */
    public function register(string $methodName, array $capabilities, array $options = []): void
    {
        $this->methods[$methodName] = [
            'capabilities' => $this->normalizeCapabilities($capabilities),
            'registered_at' => microtime(true),
            'options' => $options,
        ];
    }

    /**
     * Register multiple methods at once
     *
     * @param array $methods Map of method name => capabilities
     */
    public function registerBulk(array $methods): void
    {
        foreach ($methods as $methodName => $capabilities) {
            $this->register($methodName, $capabilities);
        }
    }

    /**
     * Get capability requirements for a method
     *
     * @param string $methodName Method name
     * @return array|null Capability vector, or null if not found
     */
    public function getCapabilities(string $methodName): ?array
    {
        if (isset($this->methods[$methodName])) {
            return $this->methods[$methodName]['capabilities'];
        }

        // Try pattern matching
        return $this->findByPattern($methodName);
    }

    /**
     * Check if a method is registered
     *
     * @param string $methodName Method name
     * @return bool
     */
    public function has(string $methodName): bool
    {
        return isset($this->methods[$methodName]) || $this->findByPattern($methodName) !== null;
    }

    /**
     * Unregister a method
     *
     * @param string $methodName Method name
     */
    public function unregister(string $methodName): void
    {
        unset($this->methods[$methodName]);
    }

    /**
     * Get all registered methods
     *
     * @return array List of method names
     */
    public function getMethods(): array
    {
        return array_keys($this->methods);
    }

    /**
     * Get full registry data
     *
     * @return array Complete registry
     */
    public function getAll(): array
    {
        return $this->methods;
    }

    /**
     * Clear all registrations
     */
    public function clear(): void
    {
        $this->methods = [];
    }

    /**
     * Register a method using a template
     *
     * @param string $methodName Method name
     * @param string $templateName Template name
     * @param array $overrides Optional capability overrides
     */
    public function registerFromTemplate(string $methodName, string $templateName, array $overrides = []): void
    {
        if (!isset($this->templates[$templateName])) {
            throw new \InvalidArgumentException("Unknown template: {$templateName}");
        }

        $capabilities = array_merge($this->templates[$templateName], $overrides);
        $this->register($methodName, $capabilities, ['template' => $templateName]);
    }

    /**
     * Add a custom template
     *
     * @param string $name Template name
     * @param array $capabilities Capability vector
     */
    public function addTemplate(string $name, array $capabilities): void
    {
        $this->templates[$name] = $this->normalizeCapabilities($capabilities);
    }

    /**
     * Get available templates
     *
     * @return array List of template names
     */
    public function getTemplates(): array
    {
        return array_keys($this->templates);
    }

    /**
     * Normalize capability vector
     *
     * Ensures all values are floats between 0.0 and 1.0
     *
     * @param array $capabilities Raw capability vector
     * @return array Normalized capability vector
     */
    private function normalizeCapabilities(array $capabilities): array
    {
        $normalized = [];

        foreach ($capabilities as $dimension => $value) {
            // Convert to float
            $floatValue = (float)$value;

            // Clamp to [0.0, 1.0]
            $floatValue = max(0.0, min(1.0, $floatValue));

            $normalized[$dimension] = $floatValue;
        }

        return $normalized;
    }

    /**
     * Find capabilities by pattern matching
     *
     * Supports patterns like:
     * - render* -> renderTemplate, renderMarkdown, etc.
     * - *Template -> renderTemplate, deleteTemplate, etc.
     *
     * @param string $methodName Method name or pattern
     * @return array|null Capability vector, or null if not found
     */
    private function findByPattern(string $methodName): ?array
    {
        // Convert method name to regex pattern
        $pattern = '/^' . str_replace('*', '.*', preg_quote($methodName, '/')) . '$/i';

        foreach ($this->methods as $registeredMethod => $data) {
            if (preg_match($pattern, $registeredMethod)) {
                return $data['capabilities'];
            }
        }

        return null;
    }

    /**
     * Register default methods with common capability patterns
     */
    private function registerDefaults(): void
    {
        // System commands
        $this->register('ping', [
            'service_discovery' => 0.1,
            'health_monitoring' => 1.0,
        ]);

        $this->register('status', [
            'service_discovery' => 0.5,
            'health_monitoring' => 1.0,
        ]);

        // Geometric discovery
        $this->register('geometricDiscover', [
            'service_discovery' => 1.0,
            'geometric_topology' => 1.0,
        ]);

        $this->register('registerService', [
            'service_discovery' => 1.0,
            'geometric_topology' => 1.0,
        ]);

        // Template rendering
        $this->register('renderTemplate', [
            'template_rendering' => 1.0,
            'html' => 1.0,
        ]);

        $this->register('registerTemplate', [
            'template_rendering' => 1.0,
            'geometric_topology' => 0.5,
        ]);

        // Format conversion
        $this->register('convertFormat', [
            'format_conversion' => 1.0,
            'service_discovery' => 0.3,
        ]);

        $this->register('registerFormat', [
            'format_conversion' => 1.0,
            'geometric_topology' => 0.5,
        ]);

        // Health monitoring
        $this->register('loadUpdate', [
            'health_monitoring' => 1.0,
            'load_balancing' => 1.0,
        ]);

        // Broadcast
        $this->register('broadcastPublish', [
            'distributed_cache' => 0.5,
            'content_delivery' => 1.0,
        ]);
    }

    /**
     * Export registry to JSON
     *
     * @return string JSON representation
     */
    public function toJson(): string
    {
        return json_encode($this->methods, JSON_PRETTY_PRINT);
    }

    /**
     * Import registry from JSON
     *
     * @param string $json JSON data
     * @param bool $merge Whether to merge with existing or replace
     */
    public function fromJson(string $json, bool $merge = false): void
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \InvalidArgumentException("Invalid JSON data");
        }

        if (!$merge) {
            $this->methods = [];
        }

        foreach ($data as $methodName => $methodData) {
            if (isset($methodData['capabilities'])) {
                $this->register(
                    $methodName,
                    $methodData['capabilities'],
                    $methodData['options'] ?? []
                );
            }
        }
    }
}
