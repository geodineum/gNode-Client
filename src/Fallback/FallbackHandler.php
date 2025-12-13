<?php

namespace gCore\GSD\Fallback;

use gCore\GSD\Exception\GSDException;

/**
 * FallbackHandler - Local implementation of GSD functionality
 *
 * Provides local fallback when the GSD daemon is unavailable.
 *
 * @package gCore\GSD\Fallback
 */
class FallbackHandler
{
    /** @var array Registered capability dimensions */
    protected $capabilityDimensions = [];

    /** @var array Registered services */
    protected $services = [];

    /** @var bool Allow local execution */
    protected $allowLocalExecution;

    /**
     * Constructor
     *
     * @param bool $allowLocalExecution Whether to allow local execution of operations
     */
    public function __construct(bool $allowLocalExecution = false)
    {
        $this->allowLocalExecution = $allowLocalExecution;
    }

    /**
     * Execute a command locally
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return mixed Command result
     * @throws GSDException If local execution is not allowed or command is not supported
     */
    public function executeCommand(string $command, array $parameters = [])
    {
        if (!$this->allowLocalExecution && $command !== 'ping') {
            throw new GSDException("Local execution not allowed for command: {$command}");
        }

        switch ($command) {
            case 'ping':
                return true;

            case 'registerCapabilityDimension':
                $name = $parameters['name'] ?? '';
                $dimension = $parameters['dimension'] ?? 0;

                if (empty($name)) {
                    throw new GSDException("Missing name parameter for registerCapabilityDimension");
                }

                return $this->registerCapabilityDimension($name, (int)$dimension);

            case 'registerService':
                $id = $parameters['id'] ?? '';
                $capabilities = $parameters['capabilities'] ?? [];
                $metadata = $parameters['metadata'] ?? [];

                if (empty($id)) {
                    throw new GSDException("Missing id parameter for registerService");
                }

                return $this->registerService($id, $capabilities, $metadata);

            case 'findServices':
                $requirements = $parameters['requirements'] ?? [];

                if (empty($requirements)) {
                    throw new GSDException("Missing requirements parameter for findServices");
                }

                return $this->findServices($requirements);

            case 'getServiceDetails':
                $id = $parameters['id'] ?? '';

                if (empty($id)) {
                    throw new GSDException("Missing id parameter for getServiceDetails");
                }

                return $this->getServiceDetails($id);

            case 'getLoadSequence':
                return $this->getLoadSequence();

            case 'getCapabilityDimensions':
                return $this->getCapabilityDimensions();

            case 'geometric_discover_range':
                $requirements = $parameters['requirements'] ?? [];
                if (empty($requirements)) {
                    throw new GSDException("Missing requirements parameter for geometric_discover_range");
                }
                return $this->discoverRange($requirements);

            default:
                throw new GSDException("Unsupported command in fallback mode: {$command}");
        }
    }

    /**
     * Register a capability dimension
     *
     * @param string $name Name of the capability
     * @param int $dimension Dimension index
     * @return bool Success
     */
    protected function registerCapabilityDimension(string $name, int $dimension): bool
    {
        $this->capabilityDimensions[$name] = $dimension;
        return true;
    }

    /**
     * Register a service with the topology
     *
     * @param string $id Service ID
     * @param array $capabilities Array of capabilities [name => value]
     * @param array $metadata Optional metadata
     * @return bool Success
     */
    protected function registerService(string $id, array $capabilities, array $metadata = []): bool
    {
        $this->services[$id] = [
            'id' => $id,
            'capabilities' => $capabilities,
            'metadata' => $metadata,
            'registered_at' => time()
        ];

        return true;
    }

    /**
     * Find services matching requirements
     *
     * @param array $requirements Array of requirements [name => min_value]
     * @return array Array of service IDs
     */
    protected function findServices(array $requirements): array
    {
        $matching = [];

        foreach ($this->services as $id => $service) {
            $matches = true;

            foreach ($requirements as $name => $minValue) {
                if (!isset($service['capabilities'][$name]) || $service['capabilities'][$name] < $minValue) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $matching[] = $id;
            }
        }

        return $matching;
    }

    /**
     * Discover services using range query operators
     *
     * Supports operators: eq, neq, gt, gte, lt, lte
     * Requirements format: ['dimension_index' => ['operator' => value]]
     *
     * @param array $requirements Range requirements with operators
     * @return array Array of matching service IDs
     */
    protected function discoverRange(array $requirements): array
    {
        $matching = [];

        foreach ($this->services as $id => $service) {
            $matches = true;

            foreach ($requirements as $dimIndex => $operators) {
                // Get the dimension value from capabilities (use 0.0 if not set - sparse support)
                $value = 0.0;
                if (isset($service['capabilities'][$dimIndex])) {
                    $value = (float)$service['capabilities'][$dimIndex];
                }

                // Check each operator
                foreach ($operators as $op => $target) {
                    $target = (float)$target;
                    $match = false;

                    switch ($op) {
                        case 'eq':
                            $match = abs($value - $target) < 0.005;
                            break;
                        case 'neq':
                            $match = abs($value - $target) >= 0.005;
                            break;
                        case 'gt':
                            $match = $value > $target;
                            break;
                        case 'gte':
                            $match = $value >= $target;
                            break;
                        case 'lt':
                            $match = $value < $target;
                            break;
                        case 'lte':
                            $match = $value <= $target;
                            break;
                    }

                    if (!$match) {
                        $matches = false;
                        break 2;  // Break out of both foreach loops
                    }
                }
            }

            if ($matches) {
                $matching[] = $id;
            }
        }

        return $matching;
    }

    /**
     * Get service details by ID
     *
     * @param string $serviceId Service ID
     * @return array Service details with capabilities and metadata
     */
    protected function getServiceDetails(string $serviceId): array
    {
        if (isset($this->services[$serviceId])) {
            return $this->services[$serviceId];
        }

        return [
            'id' => $serviceId,
            'capabilities' => [],
            'metadata' => []
        ];
    }

    /**
     * Get the load sequence based on registered services
     *
     * This is a simple implementation that sorts by performance if available,
     * or by registration time otherwise.
     *
     * @return array Array of service IDs in load order
     */
    protected function getLoadSequence(): array
    {
        $services = $this->services;

        if (empty($services)) {
            return [];
        }

        // Check if performance capability is available
        $hasPerformance = false;
        foreach ($services as $service) {
            if (isset($service['capabilities']['performance'])) {
                $hasPerformance = true;
                break;
            }
        }

        if ($hasPerformance) {
            // Sort by performance (highest first)
            uasort($services, function ($a, $b) {
                $aPerf = $a['capabilities']['performance'] ?? 0;
                $bPerf = $b['capabilities']['performance'] ?? 0;
                return $bPerf <=> $aPerf;
            });
        } else {
            // Sort by registration time (oldest first)
            uasort($services, function ($a, $b) {
                return ($a['registered_at'] ?? 0) <=> ($b['registered_at'] ?? 0);
            });
        }

        return array_keys($services);
    }

    /**
     * Get registered capability dimensions
     *
     * @return array Map of capability names to dimensions
     */
    protected function getCapabilityDimensions(): array
    {
        return $this->capabilityDimensions;
    }

    /**
     * Get registered services
     *
     * @return array Map of service IDs to service details
     */
    public function getRegisteredServices(): array
    {
        return $this->services;
    }
}
