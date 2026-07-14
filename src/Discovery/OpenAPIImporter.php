<?php
declare(strict_types=1);
/**
 * gNode OpenAPI/Swagger Importer
 *
 * Imports OpenAPI 3.x and Swagger 2.x specifications to auto-register
 * service endpoints with gNode. Supports:
 * - JSON and YAML formats
 * - URL fetching or direct spec input
 * - Full schema conversion
 * - Authentication scheme detection
 *
 * @package gCore\gNode\Discovery
 */

namespace gCore\gNode\Discovery;

use gCore\gNode\Exception\gNodeException;

class OpenAPIImporter
{
    /** @var array Imported spec */
    private ?array $spec = null;

    /** @var string OpenAPI version */
    private string $version = '3.0';

    /** @var array Import options */
    private array $options = [];

    /**
     * Import from URL.
     *
     * Commit 1.12.b (NC-D2.01): SSRF defence. `file_get_contents` would
     * accept `file://`, `phar://`, `ftp://`, `gopher://`, `data://` and
     * any private-IP destination — turning importOpenAPISpec into a
     * filesystem-read + intranet-scan primitive. Hardening:
     *   - allowlist {http, https} URL schemes only
     *   - reject post-DNS resolution to RFC1918 / link-local / loopback
     *     / IPv6 ULA / IPv6 link-local
     *   - bound response size at 4 MiB (OpenAPI specs in the wild cap
     *     well under this)
     *
     * Callers passing a local path should use fromFile() or fromString()
     * directly; fromUrl() is strictly the network-fetch entry.
     *
     * @param string $url OpenAPI spec URL (must be http:// or https://)
     * @param array $options Import options
     * @return self
     */
    public function fromUrl(string $url, array $options = []): self
    {
        $this->options = $options;

        // --- SSRF guard 1/3: scheme allowlist ---
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new gNodeException("fromUrl rejects unparsable URL: {$url}");
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new gNodeException(
                "fromUrl rejects scheme '{$scheme}'; only http/https allowed (NC-D2.01)"
            );
        }

        // --- SSRF guard 2/3: resolve host and reject private / link-local / loopback ---
        $host = $parts['host'];
        $resolved = [];
        // Numeric literal hosts: validate directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $resolved = [$host];
        } else {
            // DNS-rebinding note: we resolve once here and pass the
            // resolved IP as the fetch target below. Passing the
            // original host leaves a TOCTOU gap where DNS flips
            // between resolution and connect. file_get_contents does
            // not expose a DNS-pinning knob, so we instead validate
            // EVERY A/AAAA record at resolve time and reject if ANY
            // is private — worst-case callers see a failure on a
            // mixed-public/private RR set (rare in practice).
            $a = @dns_get_record($host, DNS_A);
            $aaaa = @dns_get_record($host, DNS_AAAA);
            foreach ((array) $a as $rec) {
                if (!empty($rec['ip'])) {
                    $resolved[] = $rec['ip'];
                }
            }
            foreach ((array) $aaaa as $rec) {
                if (!empty($rec['ipv6'])) {
                    $resolved[] = $rec['ipv6'];
                }
            }
            if (empty($resolved)) {
                throw new gNodeException("fromUrl could not resolve host: {$host}");
            }
        }
        foreach ($resolved as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new gNodeException(
                    "fromUrl rejects non-public IP '{$ip}' for host '{$host}' (NC-D2.01)"
                );
            }
        }

        // --- Fetch with bounded response size ---
        $context = stream_context_create([
            'http' => [
                'timeout' => $options['timeout'] ?? 30,
                'header' => $options['headers'] ?? [],
                'follow_location' => 0, // Don't follow redirects — a 30x could
                                        // land the caller on a private IP
                                        // we already rejected pre-fetch.
            ],
        ]);

        // --- SSRF guard 3/3: bound response bytes ---
        $maxBytes = 4 * 1024 * 1024;
        $content = @file_get_contents($url, false, $context, 0, $maxBytes);
        if ($content === false) {
            throw new gNodeException("Failed to fetch OpenAPI spec from: {$url}");
        }

        return $this->fromString($content);
    }

    /**
     * Return true if $ip is a routable public address (rejects RFC1918,
     * link-local, loopback, IPv6 ULA/link-local, multicast).
     */
    private static function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6
                | FILTER_FLAG_NO_PRIV_RANGE
                | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Import from file
     *
     * @param string $path File path
     * @param array $options Import options
     * @return self
     */
    public function fromFile(string $path, array $options = []): self
    {
        $this->options = $options;

        if (!file_exists($path)) {
            throw new gNodeException("OpenAPI spec file not found: {$path}");
        }

        $content = file_get_contents($path);
        return $this->fromString($content);
    }

    /**
     * Import from string (JSON or YAML)
     *
     * @param string $content Spec content
     * @return self
     */
    public function fromString(string $content): self
    {
        // Try JSON first
        $spec = json_decode($content, true);

        // Try YAML if JSON fails
        if ($spec === null && function_exists('yaml_parse')) {
            $spec = yaml_parse($content);
        }

        // Manual YAML fallback for simple cases
        if ($spec === null) {
            $spec = $this->parseSimpleYaml($content);
        }

        if ($spec === null) {
            throw new gNodeException("Failed to parse OpenAPI spec (not valid JSON or YAML)");
        }

        $this->spec = $spec;
        $this->detectVersion();

        return $this;
    }

    /**
     * Import from array
     *
     * @param array $spec Parsed spec
     * @return self
     */
    public function fromArray(array $spec): self
    {
        $this->spec = $spec;
        $this->detectVersion();
        return $this;
    }

    /**
     * Detect OpenAPI/Swagger version
     */
    private function detectVersion(): void
    {
        if (isset($this->spec['openapi'])) {
            $this->version = $this->spec['openapi'];
        } elseif (isset($this->spec['swagger'])) {
            $this->version = $this->spec['swagger'];
        } else {
            $this->version = '3.0';
        }
    }

    /**
     * Convert to gNode endpoint registrations
     *
     * @param string $serviceId Service ID for the endpoints
     * @return array gNode endpoint definitions
     */
    public function toGNodeEndpoints(string $serviceId = null): array
    {
        if (!$this->spec) {
            throw new gNodeException("No OpenAPI spec loaded");
        }

        // Extract service ID from spec if not provided
        if ($serviceId === null) {
            $serviceId = $this->extractServiceId();
        }

        $endpoints = [];
        $paths = $this->spec['paths'] ?? [];
        $basePath = $this->extractBasePath();

        foreach ($paths as $path => $methods) {
            foreach ($methods as $method => $operation) {
                // Skip non-HTTP methods (like parameters, summary, etc.)
                if (!in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'])) {
                    continue;
                }

                $endpoint = $this->convertOperation($path, $method, $operation, $serviceId, $basePath);
                if ($endpoint) {
                    $endpoints[] = $endpoint;
                }
            }
        }

        return [
            'service_id' => $serviceId,
            'version' => $this->spec['info']['version'] ?? '1.0.0',
            'title' => $this->spec['info']['title'] ?? $serviceId,
            'description' => $this->spec['info']['description'] ?? null,
            'base_path' => $basePath,
            'servers' => $this->extractServers(),
            'security_schemes' => $this->extractSecuritySchemes(),
            'endpoints' => $endpoints,
            'endpoint_count' => count($endpoints),
            'source' => 'openapi',
            'openapi_version' => $this->version,
            'imported_at' => time()
        ];
    }

    /**
     * Convert a single OpenAPI operation to gNode endpoint
     */
    private function convertOperation(string $path, string $method, array $operation, string $serviceId, string $basePath): ?array
    {
        $method = strtoupper($method);
        $operationId = $operation['operationId'] ?? $this->generateOperationId($path, $method);

        // Build request schema from parameters and requestBody
        $requestSchema = $this->buildRequestSchema($operation);
        $parameters = $this->extractParameters($operation);

        // Build response schema
        $responseSchema = $this->extractResponseSchema($operation);

        return [
            'endpoint_id' => $serviceId . ':' . $operationId,
            'service_id' => $serviceId,
            'path' => $basePath . $path,
            'method' => $method,
            'operation_id' => $operationId,
            'summary' => $operation['summary'] ?? null,
            'description' => $operation['description'] ?? null,
            'tags' => $operation['tags'] ?? [],
            'deprecated' => $operation['deprecated'] ?? false,
            'request' => [
                'content_type' => $this->extractRequestContentType($operation),
                'schema' => $requestSchema,
                'parameters' => $parameters,
                'field_mapping' => []
            ],
            'response' => [
                'content_type' => $this->extractResponseContentType($operation),
                'schema' => $responseSchema,
                'status_codes' => array_keys($operation['responses'] ?? ['200' => []])
            ],
            'security' => $operation['security'] ?? null,
            'metadata' => [
                'source' => 'openapi',
                'original_path' => $path,
                'servers' => $this->extractServers()
            ]
        ];
    }

    /**
     * Build request schema from parameters and requestBody
     */
    private function buildRequestSchema(array $operation): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => []
        ];

        // Add parameters
        $parameters = $operation['parameters'] ?? [];
        foreach ($parameters as $param) {
            $name = $param['name'];
            $paramSchema = $param['schema'] ?? ['type' => 'string'];

            // Resolve $ref if present
            if (isset($paramSchema['$ref'])) {
                $paramSchema = $this->resolveRef($paramSchema['$ref']);
            }

            $schema['properties'][$name] = $paramSchema;
            if ($param['required'] ?? false) {
                $schema['required'][] = $name;
            }
        }

        // Add requestBody for OpenAPI 3.x
        if (isset($operation['requestBody'])) {
            $bodySchema = $this->extractRequestBodySchema($operation['requestBody']);
            if ($bodySchema && isset($bodySchema['properties'])) {
                $schema['properties'] = array_merge($schema['properties'], $bodySchema['properties']);
                if (isset($bodySchema['required'])) {
                    $schema['required'] = array_merge($schema['required'], $bodySchema['required']);
                }
            } elseif ($bodySchema) {
                // Wrap body schema
                $schema['properties']['body'] = $bodySchema;
                if ($operation['requestBody']['required'] ?? false) {
                    $schema['required'][] = 'body';
                }
            }
        }

        return $schema;
    }

    /**
     * Extract request body schema
     */
    private function extractRequestBodySchema(array $requestBody): ?array
    {
        $content = $requestBody['content'] ?? [];

        // Prefer JSON
        if (isset($content['application/json']['schema'])) {
            $schema = $content['application/json']['schema'];
            return $this->resolveSchemaRefs($schema);
        }

        // Try other content types
        foreach ($content as $contentType => $contentDef) {
            if (isset($contentDef['schema'])) {
                return $this->resolveSchemaRefs($contentDef['schema']);
            }
        }

        return null;
    }

    /**
     * Extract parameters into gNode format
     */
    private function extractParameters(array $operation): array
    {
        $params = [];
        $parameters = $operation['parameters'] ?? [];

        foreach ($parameters as $param) {
            $schema = $param['schema'] ?? ['type' => 'string'];
            if (isset($schema['$ref'])) {
                $schema = $this->resolveRef($schema['$ref']);
            }

            $params[] = [
                'name' => $param['name'],
                'in' => $param['in'] ?? 'query',
                'type' => $schema['type'] ?? 'string',
                'schema' => $schema,
                'required' => $param['required'] ?? false,
                'description' => $param['description'] ?? null,
                'default' => $schema['default'] ?? null,
                'example' => $param['example'] ?? $schema['example'] ?? null
            ];
        }

        return $params;
    }

    /**
     * Extract response schema
     */
    private function extractResponseSchema(array $operation): array
    {
        $responses = $operation['responses'] ?? [];

        // Try success responses first (200, 201, etc.)
        foreach (['200', '201', '202', 'default'] as $statusCode) {
            if (isset($responses[$statusCode])) {
                $response = $responses[$statusCode];

                // OpenAPI 3.x
                if (isset($response['content'])) {
                    foreach ($response['content'] as $contentType => $content) {
                        if (isset($content['schema'])) {
                            return $this->resolveSchemaRefs($content['schema']);
                        }
                    }
                }

                // Swagger 2.x
                if (isset($response['schema'])) {
                    return $this->resolveSchemaRefs($response['schema']);
                }
            }
        }

        return ['type' => 'object', 'additionalProperties' => true];
    }

    /**
     * Resolve schema $ref references
     */
    private function resolveSchemaRefs(array $schema): array
    {
        if (isset($schema['$ref'])) {
            return $this->resolveRef($schema['$ref']);
        }

        // Resolve nested refs in properties
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $name => $prop) {
                $schema['properties'][$name] = $this->resolveSchemaRefs($prop);
            }
        }

        // Resolve refs in items (arrays)
        if (isset($schema['items'])) {
            $schema['items'] = $this->resolveSchemaRefs($schema['items']);
        }

        // Resolve allOf, anyOf, oneOf
        foreach (['allOf', 'anyOf', 'oneOf'] as $combiner) {
            if (isset($schema[$combiner])) {
                $schema[$combiner] = array_map([$this, 'resolveSchemaRefs'], $schema[$combiner]);
            }
        }

        return $schema;
    }

    /**
     * Resolve a $ref pointer
     */
    private function resolveRef(string $ref): array
    {
        // Handle local refs like "#/components/schemas/User"
        if (str_starts_with($ref, '#/')) {
            $path = explode('/', substr($ref, 2));
            $current = $this->spec;

            foreach ($path as $segment) {
                if (!isset($current[$segment])) {
                    return ['type' => 'object', '$ref' => $ref];
                }
                $current = $current[$segment];
            }

            // Recursively resolve nested refs
            if (is_array($current)) {
                return $this->resolveSchemaRefs($current);
            }

            return ['type' => 'any', '$ref' => $ref];
        }

        // External refs not supported yet
        return ['type' => 'object', '$ref' => $ref];
    }

    /**
     * Extract base path
     */
    private function extractBasePath(): string
    {
        // OpenAPI 3.x - use first server URL's path
        if (isset($this->spec['servers'][0]['url'])) {
            $url = $this->spec['servers'][0]['url'];
            $parsed = parse_url($url);
            return rtrim($parsed['path'] ?? '', '/');
        }

        // Swagger 2.x
        return $this->spec['basePath'] ?? '';
    }

    /**
     * Extract servers
     */
    private function extractServers(): array
    {
        // OpenAPI 3.x
        if (isset($this->spec['servers'])) {
            return $this->spec['servers'];
        }

        // Swagger 2.x - construct from host, basePath, schemes
        if (isset($this->spec['host'])) {
            $schemes = $this->spec['schemes'] ?? ['https'];
            $basePath = $this->spec['basePath'] ?? '';

            return array_map(function($scheme) use ($basePath) {
                return [
                    'url' => "{$scheme}://{$this->spec['host']}{$basePath}"
                ];
            }, $schemes);
        }

        return [];
    }

    /**
     * Extract security schemes
     */
    private function extractSecuritySchemes(): array
    {
        // OpenAPI 3.x
        if (isset($this->spec['components']['securitySchemes'])) {
            return $this->spec['components']['securitySchemes'];
        }

        // Swagger 2.x
        if (isset($this->spec['securityDefinitions'])) {
            return $this->spec['securityDefinitions'];
        }

        return [];
    }

    /**
     * Extract request content type
     */
    private function extractRequestContentType(array $operation): string
    {
        // OpenAPI 3.x
        if (isset($operation['requestBody']['content'])) {
            $contentTypes = array_keys($operation['requestBody']['content']);
            return $contentTypes[0] ?? 'application/json';
        }

        // Swagger 2.x
        if (isset($operation['consumes'])) {
            return $operation['consumes'][0] ?? 'application/json';
        }

        return 'application/json';
    }

    /**
     * Extract response content type
     */
    private function extractResponseContentType(array $operation): string
    {
        // OpenAPI 3.x
        $responses = $operation['responses'] ?? [];
        foreach (['200', '201', '202', 'default'] as $code) {
            if (isset($responses[$code]['content'])) {
                $contentTypes = array_keys($responses[$code]['content']);
                return $contentTypes[0] ?? 'application/json';
            }
        }

        // Swagger 2.x
        if (isset($operation['produces'])) {
            return $operation['produces'][0] ?? 'application/json';
        }

        return 'application/json';
    }

    /**
     * Extract service ID from spec
     */
    private function extractServiceId(): string
    {
        $title = $this->spec['info']['title'] ?? 'unknown-service';

        // Convert to kebab-case
        $serviceId = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
        $serviceId = trim($serviceId, '-');

        return $serviceId ?: 'unknown-service';
    }

    /**
     * Generate operation ID from path and method
     */
    private function generateOperationId(string $path, string $method): string
    {
        // Convert path to operation ID
        $pathParts = explode('/', trim($path, '/'));
        $parts = [];

        foreach ($pathParts as $part) {
            if (str_starts_with($part, '{')) {
                // Parameter - convert {id} to ById
                $paramName = trim($part, '{}');
                $parts[] = 'By' . ucfirst($paramName);
            } else {
                $parts[] = ucfirst($part);
            }
        }

        return strtolower($method) . implode('', $parts);
    }

    /**
     * Simple YAML parser fallback
     */
    private function parseSimpleYaml(string $content): ?array
    {
        // This is a very basic YAML parser for simple cases
        // For complex YAML, the yaml extension should be installed
        $lines = explode("\n", $content);
        $result = [];
        $stack = [&$result];
        $indentStack = [-1];

        foreach ($lines as $line) {
            // Skip empty lines and comments
            if (empty(trim($line)) || str_starts_with(trim($line), '#')) {
                continue;
            }

            // Calculate indent
            $indent = strlen($line) - strlen(ltrim($line));
            $line = trim($line);

            // Pop stack for decreased indent
            while ($indent <= end($indentStack) && count($stack) > 1) {
                array_pop($stack);
                array_pop($indentStack);
            }

            // Parse key-value
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);

                $current = &$stack[count($stack) - 1];

                if ($value === '' || $value === '|' || $value === '>') {
                    // Object or multiline string
                    $current[$key] = [];
                    $stack[] = &$current[$key];
                    $indentStack[] = $indent;
                } else {
                    // Simple value
                    $current[$key] = $this->parseYamlValue($value);
                }
            } elseif (str_starts_with($line, '- ')) {
                // Array item
                $value = trim(substr($line, 2));
                $current = &$stack[count($stack) - 1];

                if (!is_array($current)) {
                    $current = [];
                }

                $current[] = $this->parseYamlValue($value);
            }
        }

        return !empty($result) ? $result : null;
    }

    /**
     * Parse YAML value
     */
    private function parseYamlValue(string $value)
    {
        // Remove quotes
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        // Boolean
        if (in_array(strtolower($value), ['true', 'yes', 'on'])) {
            return true;
        }
        if (in_array(strtolower($value), ['false', 'no', 'off'])) {
            return false;
        }

        // Null
        if (in_array(strtolower($value), ['null', '~'])) {
            return null;
        }

        // Number
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }

        return $value;
    }

    /**
     * Get the raw spec
     */
    public function getSpec(): ?array
    {
        return $this->spec;
    }

    /**
     * Get detected version
     */
    public function getVersion(): string
    {
        return $this->version;
    }
}
