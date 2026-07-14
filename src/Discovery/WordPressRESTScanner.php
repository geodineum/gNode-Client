<?php
declare(strict_types=1);
/**
 * gNode WordPress REST API Scanner
 *
 * Scans WordPress themes/plugins for REST API endpoint registrations
 * using `register_rest_route()` calls and extracts endpoint definitions.
 *
 * This enables auto-detection of WordPress REST endpoints for gNode registration.
 *
 * @package gCore\gNode\Discovery
 */

namespace gCore\gNode\Discovery;

class WordPressRESTScanner
{
    /** @var array Discovered endpoints */
    private array $endpoints = [];

    /** @var array Scanned files */
    private array $scannedFiles = [];

    /** @var array Errors during scanning */
    private array $errors = [];

    /**
     * Scan a directory for WordPress REST API endpoints
     *
     * @param string $directory Directory to scan
     * @param array $options Scan options
     * @return self
     */
    public function scanDirectory(string $directory, array $options = []): self
    {
        $pattern = $options['pattern'] ?? '*.php';
        $recursive = $options['recursive'] ?? true;
        $excludeDirs = $options['exclude_dirs'] ?? ['vendor', 'node_modules', '.git'];

        $iterator = $recursive
            ? new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    function ($file, $key, $iterator) use ($excludeDirs) {
                        if ($iterator->hasChildren()) {
                            return !in_array($file->getFilename(), $excludeDirs);
                        }
                        return true;
                    }
                )
              )
            : new \DirectoryIterator($directory);

        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $this->scanFile($file->getPathname());
            }
        }

        return $this;
    }

    /**
     * Scan a single PHP file for REST endpoint registrations
     *
     * @param string $filePath Path to PHP file
     * @return self
     */
    public function scanFile(string $filePath): self
    {
        if (!file_exists($filePath)) {
            $this->errors[] = "File not found: {$filePath}";
            return $this;
        }

        $content = file_get_contents($filePath);
        $this->scannedFiles[] = $filePath;

        // Find all register_rest_route() calls
        $this->parseRegisterRestRouteCalls($content, $filePath);

        return $this;
    }

    /**
     * Parse register_rest_route() calls from PHP content
     */
    private function parseRegisterRestRouteCalls(string $content, string $filePath): void
    {
        // Pattern to match register_rest_route() calls
        // Handles both inline and multi-line formats
        $pattern = '/register_rest_route\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*,\s*(\[[\s\S]*?\])\s*\)/';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $namespace = $match[1];
                $route = $match[2];
                $argsString = $match[3];

                $endpoint = $this->parseEndpointArgs($namespace, $route, $argsString, $filePath);
                if ($endpoint) {
                    $this->endpoints[] = $endpoint;
                }
            }
        }

        // Also try to extract callback function docblocks for descriptions
        $this->extractFunctionDocblocks($content, $filePath);
    }

    /**
     * Parse endpoint arguments array
     */
    private function parseEndpointArgs(string $namespace, string $route, string $argsString, string $filePath): ?array
    {
        // Extract key information from the args array
        $endpoint = [
            'namespace' => $namespace,
            'route' => $route,
            'path' => '/wp-json/' . $namespace . $route,
            'methods' => ['GET'],
            'callback' => null,
            'permission_callback' => null,
            'args' => [],
            'source_file' => $filePath,
        ];

        // Extract methods
        if (preg_match('/[\'"]methods[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $argsString, $methodMatch)) {
            $endpoint['methods'] = array_map('trim', explode(',', strtoupper($methodMatch[1])));
        } elseif (preg_match('/[\'"]methods[\'"]\s*=>\s*WP_REST_Server::([A-Z]+)/', $argsString, $methodMatch)) {
            $methodMap = [
                'READABLE' => ['GET'],
                'CREATABLE' => ['POST'],
                'EDITABLE' => ['POST', 'PUT', 'PATCH'],
                'DELETABLE' => ['DELETE'],
                'ALLMETHODS' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            ];
            $endpoint['methods'] = $methodMap[$methodMatch[1]] ?? ['GET'];
        }

        // Extract callback
        if (preg_match('/[\'"]callback[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $argsString, $callbackMatch)) {
            $endpoint['callback'] = $callbackMatch[1];
        } elseif (preg_match('/[\'"]callback[\'"]\s*=>\s*\[?\s*\$?this\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]?/', $argsString, $callbackMatch)) {
            $endpoint['callback'] = $callbackMatch[1];
        }

        // Extract permission_callback
        if (preg_match('/[\'"]permission_callback[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $argsString, $permMatch)) {
            $endpoint['permission_callback'] = $permMatch[1];
            $endpoint['is_public'] = ($permMatch[1] === '__return_true');
        } elseif (strpos($argsString, '__return_true') !== false) {
            $endpoint['is_public'] = true;
        }

        // Extract args (parameter definitions)
        if (preg_match('/[\'"]args[\'"]\s*=>\s*(\[[\s\S]*?\])(?=\s*[\],])/', $argsString, $argsMatch)) {
            $endpoint['args'] = $this->parseArgsDefinition($argsMatch[1]);
        }

        // Generate endpoint_id
        $routeSlug = str_replace(['/', '{', '}', '(?P<', '>\d+)', '>\w+)', '>'], ['_', '', '', '', '', '', ''], $route);
        $routeSlug = trim($routeSlug, '_');
        $method = $endpoint['methods'][0] ?? 'GET';
        $endpoint['endpoint_id'] = str_replace('/', '-', $namespace) . ':' . strtolower($method) . ':' . $routeSlug;

        return $endpoint;
    }

    /**
     * Parse argument definitions from WordPress REST API format
     */
    private function parseArgsDefinition(string $argsString): array
    {
        $args = [];

        // Pattern to match individual argument definitions
        $pattern = '/[\'"](\w+)[\'"]\s*=>\s*\[([\s\S]*?)\](?=\s*[,\]])/';

        if (preg_match_all($pattern, $argsString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $argName = $match[1];
                $argDef = $match[2];

                $arg = [
                    'name' => $argName,
                    'required' => false,
                    'type' => 'string',
                    'description' => null,
                    'default' => null,
                    'minimum' => null,
                    'maximum' => null,
                    'enum' => null,
                ];

                // Extract required
                if (preg_match('/[\'"]required[\'"]\s*=>\s*(true|false)/i', $argDef, $reqMatch)) {
                    $arg['required'] = strtolower($reqMatch[1]) === 'true';
                }

                // Extract type
                if (preg_match('/[\'"]type[\'"]\s*=>\s*[\'"](\w+)[\'"]/', $argDef, $typeMatch)) {
                    $arg['type'] = $typeMatch[1];
                }

                // Extract description
                if (preg_match('/[\'"]description[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $argDef, $descMatch)) {
                    $arg['description'] = $descMatch[1];
                }

                // Extract default
                if (preg_match('/[\'"]default[\'"]\s*=>\s*([^\],]+)/', $argDef, $defMatch)) {
                    $default = trim($defMatch[1]);
                    if ($default === 'true') $arg['default'] = true;
                    elseif ($default === 'false') $arg['default'] = false;
                    elseif ($default === 'null') $arg['default'] = null;
                    elseif (is_numeric($default)) $arg['default'] = (int)$default;
                    elseif (preg_match('/^[\'"](.+)[\'"]$/', $default, $strMatch)) $arg['default'] = $strMatch[1];
                    else $arg['default'] = $default;
                }

                // Extract minimum
                if (preg_match('/[\'"]minimum[\'"]\s*=>\s*(\d+)/', $argDef, $minMatch)) {
                    $arg['minimum'] = (int)$minMatch[1];
                }

                // Extract maximum
                if (preg_match('/[\'"]maximum[\'"]\s*=>\s*(\d+)/', $argDef, $maxMatch)) {
                    $arg['maximum'] = (int)$maxMatch[1];
                }

                // Extract enum
                if (preg_match('/[\'"]enum[\'"]\s*=>\s*\[([^\]]+)\]/', $argDef, $enumMatch)) {
                    $enumValues = [];
                    if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $enumMatch[1], $enumItems)) {
                        $enumValues = $enumItems[1];
                    }
                    $arg['enum'] = $enumValues;
                }

                $args[] = $arg;
            }
        }

        return $args;
    }

    /**
     * Extract function docblocks for additional endpoint information
     */
    private function extractFunctionDocblocks(string $content, string $filePath): void
    {
        // Find function definitions with docblocks
        $pattern = '/\/\*\*([\s\S]*?)\*\/\s*function\s+(\w+)\s*\(/';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $docblock = $match[1];
                $functionName = $match[2];

                // Find matching endpoint by callback name
                foreach ($this->endpoints as &$endpoint) {
                    if ($endpoint['callback'] === $functionName) {
                        // Extract description from docblock
                        $description = $this->parseDocblockDescription($docblock);
                        if ($description) {
                            $endpoint['description'] = $description;
                        }

                        // Extract @param tags
                        $params = $this->parseDocblockParams($docblock);
                        if ($params) {
                            foreach ($endpoint['args'] as &$arg) {
                                if (isset($params[$arg['name']]) && !$arg['description']) {
                                    $arg['description'] = $params[$arg['name']];
                                }
                            }
                        }

                        // Extract @return tag
                        if (preg_match('/@return\s+(\S+)\s*(.*)/', $docblock, $returnMatch)) {
                            $endpoint['return_type'] = $returnMatch[1];
                            $endpoint['return_description'] = trim($returnMatch[2]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Parse description from docblock
     */
    private function parseDocblockDescription(string $docblock): ?string
    {
        $lines = array_map('trim', explode("\n", $docblock));
        $description = [];

        foreach ($lines as $line) {
            $line = preg_replace('/^\*\s*/', '', $line);
            if (str_starts_with($line, '@')) {
                break; // Stop at first tag
            }
            if (!empty($line)) {
                $description[] = $line;
            }
        }

        return !empty($description) ? implode(' ', $description) : null;
    }

    /**
     * Parse @param tags from docblock
     */
    private function parseDocblockParams(string $docblock): array
    {
        $params = [];
        if (preg_match_all('/@param\s+\S+\s+\$(\w+)\s*(.*)/', $docblock, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $params[$match[1]] = trim($match[2]);
            }
        }
        return $params;
    }

    /**
     * Convert discovered endpoints to gNode format
     *
     * @param string $serviceId Service ID for the endpoints
     * @return array gNode endpoint definitions
     */
    public function toGNodeEndpoints(string $serviceId = null): array
    {
        $gNodeEndpoints = [];

        foreach ($this->endpoints as $endpoint) {
            // Build request schema from args
            $requestSchema = [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ];

            $parameters = [];
            foreach ($endpoint['args'] as $arg) {
                $paramSchema = $this->argTypeToJsonSchema($arg['type']);

                if ($arg['minimum'] !== null) $paramSchema['minimum'] = $arg['minimum'];
                if ($arg['maximum'] !== null) $paramSchema['maximum'] = $arg['maximum'];
                if ($arg['enum'] !== null) $paramSchema['enum'] = $arg['enum'];
                if ($arg['default'] !== null) $paramSchema['default'] = $arg['default'];
                if ($arg['description']) $paramSchema['description'] = $arg['description'];

                $requestSchema['properties'][$arg['name']] = $paramSchema;
                if ($arg['required']) {
                    $requestSchema['required'][] = $arg['name'];
                }

                $parameters[] = [
                    'name' => $arg['name'],
                    'in' => in_array('GET', $endpoint['methods']) ? 'path_or_query' : 'body',
                    'type' => $arg['type'],
                    'schema' => $paramSchema,
                    'required' => $arg['required'],
                    'description' => $arg['description'],
                    'default' => $arg['default'],
                ];
            }

            $gNodeServiceId = $serviceId ?? str_replace('/', '-', $endpoint['namespace']);

            $gNodeEndpoints[] = [
                'endpoint_id' => $gNodeServiceId . ':' . $endpoint['endpoint_id'],
                'service_id' => $gNodeServiceId,
                'path' => $endpoint['path'],
                'method' => $endpoint['methods'][0] ?? 'GET',
                'methods' => $endpoint['methods'],
                'description' => $endpoint['description'] ?? null,
                'callback' => $endpoint['callback'],
                'is_public' => $endpoint['is_public'] ?? false,
                'request' => [
                    'content_type' => 'application/json',
                    'schema' => $requestSchema,
                    'parameters' => $parameters,
                    'field_mapping' => [],
                ],
                'response' => [
                    'content_type' => 'application/json',
                    'schema' => ['type' => 'object', 'additionalProperties' => true],
                    'description' => $endpoint['return_description'] ?? null,
                ],
                'metadata' => [
                    'source' => 'wordpress-rest-api',
                    'namespace' => $endpoint['namespace'],
                    'route' => $endpoint['route'],
                    'source_file' => $endpoint['source_file'],
                ],
            ];
        }

        return [
            'service_id' => $serviceId ?? 'wordpress-rest',
            'source' => 'wordpress-rest-api',
            'endpoints' => $gNodeEndpoints,
            'endpoint_count' => count($gNodeEndpoints),
            'scanned_files' => count($this->scannedFiles),
            'errors' => $this->errors,
            'scanned_at' => time(),
        ];
    }

    /**
     * Convert WordPress arg type to JSON Schema type
     */
    private function argTypeToJsonSchema(string $type): array
    {
        $typeMap = [
            'string' => ['type' => 'string'],
            'integer' => ['type' => 'integer'],
            'number' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'any']],
            'object' => ['type' => 'object', 'additionalProperties' => true],
        ];

        return $typeMap[$type] ?? ['type' => 'string'];
    }

    /**
     * Get discovered endpoints
     */
    public function getEndpoints(): array
    {
        return $this->endpoints;
    }

    /**
     * Get scanned files
     */
    public function getScannedFiles(): array
    {
        return $this->scannedFiles;
    }

    /**
     * Get errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Clear scanner state
     */
    public function clear(): self
    {
        $this->endpoints = [];
        $this->scannedFiles = [];
        $this->errors = [];
        return $this;
    }
}
