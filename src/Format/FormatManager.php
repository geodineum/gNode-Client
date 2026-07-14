<?php

declare(strict_types=1);

namespace gCore\gNode\Format;

use gCore\gNode\gNodeClientInterface;
use gCore\gNode\Exception\gNodeException;
use gCore\gNode\Storage\StorageInterface;

/**
 * FormatManager - Facade for format system operations
 *
 * Routes all operations through the gNode daemon's CMS extension commands:
 *   register_format, list_formats, detect_format, convert_format
 *
 * Local FormatRegistry cache reduces daemon roundtrips for repeated reads.
 *
 * @package gCore\gNode\Format
 */
class FormatManager
{
    private gNodeClientInterface $client;
    private StorageInterface $storage;
    private FormatRegistry $registry;
    private string $siteId;
    private string $nodeId;
    private array $config;

    private array $stats = [
        'formats_registered' => 0,
        'detections_performed' => 0,
        'conversions_performed' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'errors' => 0
    ];

    public function __construct(
        gNodeClientInterface $client,
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
            'validate_schemas' => true,
            'debug' => false
        ], $config);

        $this->registry = new FormatRegistry($this->config['cache_ttl']);
    }

    /**
     * Register a custom message format via daemon command
     *
     * @param array $definition Format definition with name, schema, patterns
     * @return bool True on success
     * @throws gNodeException on validation failure or daemon error
     */
    public function registerFormat(array $definition): bool
    {
        if (empty($definition['name'])) {
            $this->stats['errors']++;
            throw new gNodeException('Format definition missing required field: name');
        }

        if (empty($definition['schema'])) {
            $this->stats['errors']++;
            throw new gNodeException('Format definition missing required field: schema');
        }

        if (empty($definition['patterns']) || !is_array($definition['patterns'])) {
            $this->stats['errors']++;
            throw new gNodeException('Format definition missing required field: patterns (must be array)');
        }

        $formatName = $definition['name'];

        // Client-side schema validation
        if ($this->config['validate_schemas']) {
            try {
                $schema = new FormatSchema($definition['schema']);
            } catch (\Exception $e) {
                $this->stats['errors']++;
                throw new gNodeException(
                    "Invalid JSONSchema for format '{$formatName}': " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        // Client-side pattern validation
        foreach ($definition['patterns'] as $pattern) {
            if (@preg_match($pattern, '') === false) {
                $this->stats['errors']++;
                throw new gNodeException(
                    "Invalid regex pattern for format '{$formatName}': {$pattern}"
                );
            }
        }

        try {
            $result = $this->client->executeCommand('register_format', [
                'format_definition' => $definition
            ]);

            // Update local cache
            $this->registry->set($formatName, $definition);
            $this->stats['formats_registered']++;

            return true;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new gNodeException(
                "Failed to register format '{$formatName}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Detect message format via daemon command
     *
     * @param string $message Message to analyze
     * @return string|null Format name, or null if no match
     * @throws gNodeException on detection failure
     */
    public function detectFormat(string $message): ?string
    {
        if (empty($message)) {
            return null;
        }

        $this->stats['detections_performed']++;

        // Try local detection first (cached patterns)
        $localResult = $this->detectFormatLocal($message);
        if ($localResult !== null) {
            $this->stats['cache_hits']++;
            return $localResult;
        }

        $this->stats['cache_misses']++;

        try {
            $result = $this->client->executeCommand('detect_format', [
                'message' => $message
            ]);

            $formatName = $result['format_name'] ?? $result['result']['format_name'] ?? null;

            if ($formatName && $formatName !== 'unknown') {
                return $formatName;
            }

            return null;
        } catch (\Exception $e) {
            if ($this->config['debug']) {
                error_log("Format detection error: " . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Convert message between formats via daemon command
     *
     * @param string $message Message to convert
     * @param string $fromFormat Source format name
     * @param string $toFormat Target format name
     * @return string Converted message
     * @throws gNodeException on conversion failure
     */
    public function convertFormat(string $message, string $fromFormat, string $toFormat): string
    {
        if (empty($message)) {
            $this->stats['errors']++;
            throw new gNodeException('Cannot convert empty message');
        }

        if ($fromFormat === $toFormat) {
            return $message;
        }

        $this->stats['conversions_performed']++;

        try {
            $result = $this->client->executeCommand('convert_format', [
                'message' => $message,
                'source_format' => $fromFormat,
                'target_format' => $toFormat
            ]);

            return $result['converted'] ?? $result['message'] ?? $message;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new gNodeException(
                "Format conversion error ({$fromFormat} → {$toFormat}): " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * List all registered formats via daemon command
     *
     * @return array Array of format definitions
     * @throws gNodeException on retrieval failure
     */
    public function listFormats(): array
    {
        try {
            $result = $this->client->executeCommand('list_formats', []);

            $formats = $result['formats'] ?? $result ?? [];

            if (!is_array($formats)) {
                return [];
            }

            // Update local cache
            foreach ($formats as $format) {
                if (isset($format['name'])) {
                    $this->registry->set($format['name'], $format);
                }
            }

            return $formats;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new gNodeException(
                "Failed to list formats: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get JSONSchema for a specific format
     *
     * Uses local cache first, falls back to listFormats() to populate cache.
     *
     * @param string $formatName Format name
     * @return array|null Schema definition, or null if not found
     */
    public function getSchema(string $formatName): ?array
    {
        // Check cache first
        if ($this->registry->has($formatName)) {
            $format = $this->registry->get($formatName);
            $this->stats['cache_hits']++;
            return $format['schema'] ?? null;
        }

        $this->stats['cache_misses']++;

        // Populate cache from daemon
        try {
            $this->listFormats();

            if ($this->registry->has($formatName)) {
                $format = $this->registry->get($formatName);
                return $format['schema'] ?? null;
            }
        } catch (\Exception $e) {
            if ($this->config['debug']) {
                error_log("Failed to get schema for format '{$formatName}': " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * @return FormatRegistry
     */
    public function getRegistry(): FormatRegistry
    {
        return $this->registry;
    }

    /**
     * @return array Statistics data
     */
    public function getStatistics(): array
    {
        return $this->stats;
    }

    /**
     * Clear local format cache
     */
    public function clearCache(): void
    {
        $this->registry->clear();
    }

    /**
     * Detect format using local pattern matching (cached patterns)
     *
     * @param string $message Message to analyze
     * @return string|null Format name, or null if no match
     */
    private function detectFormatLocal(string $message): ?string
    {
        $allFormats = $this->registry->getAll();

        if (empty($allFormats)) {
            return null;
        }

        $bestMatch = null;
        $bestScore = 0;

        foreach ($allFormats as $format) {
            $patterns = $format['patterns'] ?? [];
            $matchCount = 0;

            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $message)) {
                    $matchCount++;
                }
            }

            if ($matchCount > 0) {
                $score = $matchCount / count($patterns);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $format['name'];
                }
            }
        }

        return $bestMatch;
    }
}
