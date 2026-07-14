<?php
declare(strict_types=1);
/**
 * gNode Endpoint Introspector
 *
 * Auto-detects service endpoints using PHP Reflection and PHPDoc parsing.
 * When a service registers with gNode, this class introspects the service class
 * to discover all public methods, their signatures, parameters, and return types.
 *
 * This enables automatic API documentation and format generation for
 * inter-service communication.
 *
 * @package gCore\gNode\Discovery
 */

namespace gCore\gNode\Discovery;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionNamedType;
use ReflectionUnionType;

class EndpointIntrospector
{
    /** @var array Cached introspection results */
    private array $cache = [];

    /** @var array Methods to exclude from introspection */
    private array $excludedMethods = [
        '__construct', '__destruct', '__clone', '__wakeup', '__sleep',
        '__toString', '__invoke', '__set', '__get', '__isset', '__unset',
        '__call', '__callStatic', '__set_state', '__debugInfo', '__serialize',
        '__unserialize'
    ];

    /** @var array Additional method prefixes to exclude */
    private array $excludedPrefixes = ['get', 'set', 'is', 'has'];

    /** @var bool Whether to include getters/setters */
    private bool $includeAccessors = false;

    /**
     * Set whether to include getter/setter methods
     */
    public function setIncludeAccessors(bool $include): self
    {
        $this->includeAccessors = $include;
        return $this;
    }

    /**
     * Add methods to exclusion list
     */
    public function excludeMethods(array $methods): self
    {
        $this->excludedMethods = array_merge($this->excludedMethods, $methods);
        return $this;
    }

    /**
     * Introspect a class or object to discover all endpoints
     *
     * @param string|object $classOrObject Class name or instance
     * @param array $options Introspection options
     * @return array Discovered endpoints with full signatures
     */
    public function introspect($classOrObject, array $options = []): array
    {
        $className = is_object($classOrObject) ? get_class($classOrObject) : $classOrObject;

        // Check cache
        $cacheKey = $className . ':' . md5(json_encode($options));
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $reflection = new ReflectionClass($className);
        $endpoints = [];

        // Get class-level metadata
        $classDoc = $this->parseDocComment($reflection->getDocComment() ?: '');
        $basePath = $options['base_path'] ?? $this->extractBasePath($classDoc);
        $serviceId = $options['service_id'] ?? $this->generateServiceId($className);

        // Introspect all public methods
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip excluded methods
            if ($this->shouldExclude($method)) {
                continue;
            }

            // Skip inherited methods from base classes (optional)
            if (($options['own_methods_only'] ?? false) && $method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            $endpoint = $this->introspectMethod($method, $serviceId, $basePath);
            if ($endpoint) {
                $endpoints[] = $endpoint;
            }
        }

        // Cache and return
        $this->cache[$cacheKey] = [
            'service_id' => $serviceId,
            'class' => $className,
            'base_path' => $basePath,
            'description' => $classDoc['description'] ?? null,
            'version' => $classDoc['version'] ?? '1.0.0',
            'endpoints' => $endpoints,
            'endpoint_count' => count($endpoints),
            'introspected_at' => time()
        ];

        return $this->cache[$cacheKey];
    }

    /**
     * Introspect a single method to extract endpoint definition
     */
    private function introspectMethod(ReflectionMethod $method, string $serviceId, string $basePath): ?array
    {
        $methodName = $method->getName();
        $doc = $this->parseDocComment($method->getDocComment() ?: '');

        // Check for @gNodeIgnore annotation
        if (isset($doc['tags']['gNodeIgnore'])) {
            return null;
        }

        // Extract path from annotation or generate from method name
        $path = $doc['tags']['gNodePath'] ?? $doc['tags']['route'] ?? $this->methodNameToPath($methodName, $basePath);

        // Extract HTTP method
        $httpMethod = $doc['tags']['gNodeMethod'] ?? $doc['tags']['method'] ?? $this->inferHttpMethod($methodName);

        // Build parameter schema
        $parameters = [];
        $requestSchema = ['type' => 'object', 'properties' => [], 'required' => []];

        foreach ($method->getParameters() as $param) {
            $paramInfo = $this->introspectParameter($param, $doc);
            $parameters[] = $paramInfo;

            // Add to request schema
            $requestSchema['properties'][$paramInfo['name']] = $paramInfo['schema'];
            if ($paramInfo['required']) {
                $requestSchema['required'][] = $paramInfo['name'];
            }
        }

        // Build response schema from return type
        $returnType = $method->getReturnType();
        $responseSchema = $this->typeToSchema($returnType);
        $responseDescription = $doc['return'] ?? null;

        // Check for @gNodeResponseFormat annotation
        if (isset($doc['tags']['gNodeResponseFormat'])) {
            $responseSchema = array_merge($responseSchema, json_decode($doc['tags']['gNodeResponseFormat'], true) ?? []);
        }

        return [
            'endpoint_id' => $serviceId . ':' . $methodName,
            'service_id' => $serviceId,
            'method_name' => $methodName,
            'path' => $path,
            'http_method' => $httpMethod,
            'description' => $doc['description'] ?? null,
            'deprecated' => isset($doc['tags']['deprecated']),
            'request' => [
                'content_type' => 'application/json',
                'schema' => $requestSchema,
                'parameters' => $parameters,
                'field_mapping' => $this->buildFieldMapping($parameters)
            ],
            'response' => [
                'content_type' => 'application/json',
                'schema' => $responseSchema,
                'description' => $responseDescription
            ],
            'metadata' => [
                'class' => $method->getDeclaringClass()->getName(),
                'file' => $method->getFileName(),
                'line' => $method->getStartLine(),
                'is_static' => $method->isStatic(),
                'visibility' => 'public'
            ]
        ];
    }

    /**
     * Introspect a method parameter
     */
    private function introspectParameter(ReflectionParameter $param, array $methodDoc): array
    {
        $name = $param->getName();
        $type = $param->getType();
        $schema = $this->typeToSchema($type);

        // Get description from PHPDoc @param
        $description = null;
        if (isset($methodDoc['params'][$name])) {
            $description = $methodDoc['params'][$name]['description'] ?? null;
        }

        // Check for default value
        $default = null;
        $hasDefault = $param->isDefaultValueAvailable();
        if ($hasDefault) {
            $default = $param->getDefaultValue();
            $schema['default'] = $default;
        }

        // Check for validation constraints from annotations
        $constraints = $this->extractConstraints($methodDoc, $name);
        if ($constraints) {
            $schema = array_merge($schema, $constraints);
        }

        return [
            'name' => $name,
            'type' => $this->getTypeName($type),
            'schema' => $schema,
            'required' => !$param->isOptional() && !$param->allowsNull(),
            'nullable' => $param->allowsNull(),
            'default' => $default,
            'has_default' => $hasDefault,
            'description' => $description,
            'position' => $param->getPosition()
        ];
    }

    /**
     * Convert PHP type to JSON Schema
     */
    private function typeToSchema($type): array
    {
        if ($type === null) {
            return ['type' => 'any'];
        }

        if ($type instanceof ReflectionUnionType) {
            $types = array_map(fn($t) => $this->typeToSchema($t), $type->getTypes());
            return ['oneOf' => $types];
        }

        if (!($type instanceof ReflectionNamedType)) {
            return ['type' => 'any'];
        }

        $typeName = $type->getName();

        $typeMap = [
            'int' => ['type' => 'integer'],
            'integer' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'double' => ['type' => 'number'],
            'string' => ['type' => 'string'],
            'bool' => ['type' => 'boolean'],
            'boolean' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'any']],
            'object' => ['type' => 'object', 'additionalProperties' => true],
            'mixed' => ['type' => 'any'],
            'null' => ['type' => 'null'],
            'void' => ['type' => 'null'],
            'callable' => ['type' => 'string', 'description' => 'Callable reference'],
            'iterable' => ['type' => 'array'],
            'self' => ['type' => 'object'],
            'static' => ['type' => 'object'],
            'parent' => ['type' => 'object'],
        ];

        if (isset($typeMap[$typeName])) {
            $schema = $typeMap[$typeName];
        } elseif (class_exists($typeName) || interface_exists($typeName)) {
            // Complex type - try to introspect it
            $schema = $this->classToSchema($typeName);
        } else {
            $schema = ['type' => 'any', 'php_type' => $typeName];
        }

        // Handle nullable
        if ($type->allowsNull()) {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    /**
     * Convert a class to JSON Schema (for complex types)
     */
    private function classToSchema(string $className): array
    {
        // Handle common types
        if ($className === 'DateTime' || $className === 'DateTimeInterface' || $className === 'DateTimeImmutable') {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if (is_subclass_of($className, 'JsonSerializable')) {
            return ['type' => 'object', 'additionalProperties' => true, 'php_class' => $className];
        }

        // For other classes, try to extract public properties
        try {
            $reflection = new ReflectionClass($className);
            $properties = [];

            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                $propType = $prop->getType();
                $properties[$prop->getName()] = $this->typeToSchema($propType);
            }

            if (!empty($properties)) {
                return [
                    'type' => 'object',
                    'properties' => $properties,
                    'php_class' => $className
                ];
            }
        } catch (\Exception $e) {
            // Fall through to default
        }

        return ['type' => 'object', 'php_class' => $className];
    }

    /**
     * Get type name as string
     */
    private function getTypeName($type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn($t) => $t->getName(), $type->getTypes()));
        }

        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        return 'mixed';
    }

    /**
     * Parse PHPDoc comment
     */
    private function parseDocComment(string $docComment): array
    {
        $result = [
            'description' => '',
            'params' => [],
            'return' => null,
            'tags' => []
        ];

        if (empty($docComment)) {
            return $result;
        }

        // Remove comment markers
        $lines = preg_split('/\r?\n/', $docComment);
        $description = [];
        $inDescription = true;

        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\/?\*+\/?/', '', $line);
            $line = trim($line);

            if (empty($line)) {
                if ($inDescription && !empty($description)) {
                    $inDescription = false;
                }
                continue;
            }

            // Check for @tag
            if (preg_match('/^@(\w+)\s*(.*)$/', $line, $matches)) {
                $inDescription = false;
                $tag = $matches[1];
                $value = trim($matches[2]);

                switch ($tag) {
                    case 'param':
                        if (preg_match('/^(\S+)\s+\$(\w+)\s*(.*)$/', $value, $paramMatches)) {
                            $result['params'][$paramMatches[2]] = [
                                'type' => $paramMatches[1],
                                'description' => trim($paramMatches[3])
                            ];
                        }
                        break;
                    case 'return':
                    case 'returns':
                        $result['return'] = $value;
                        break;
                    case 'version':
                        $result['version'] = $value;
                        break;
                    default:
                        $result['tags'][$tag] = $value ?: true;
                        break;
                }
            } elseif ($inDescription) {
                $description[] = $line;
            }
        }

        $result['description'] = implode(' ', $description);

        return $result;
    }

    /**
     * Extract base path from class doc
     */
    private function extractBasePath(array $classDoc): string
    {
        return $classDoc['tags']['gNodeBasePath'] ?? $classDoc['tags']['basePath'] ?? '/api';
    }

    /**
     * Generate service ID from class name
     */
    private function generateServiceId(string $className): string
    {
        // Extract just the class name without namespace
        $parts = explode('\\', $className);
        $shortName = end($parts);

        // Convert CamelCase to kebab-case
        $serviceId = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $shortName));

        return $serviceId;
    }

    /**
     * Convert method name to API path
     */
    private function methodNameToPath(string $methodName, string $basePath): string
    {
        // Convert camelCase to kebab-case
        $path = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $methodName));

        return rtrim($basePath, '/') . '/' . $path;
    }

    /**
     * Infer HTTP method from method name
     */
    private function inferHttpMethod(string $methodName): string
    {
        $prefixMap = [
            'get' => 'GET',
            'fetch' => 'GET',
            'find' => 'GET',
            'list' => 'GET',
            'search' => 'GET',
            'query' => 'GET',
            'create' => 'POST',
            'add' => 'POST',
            'insert' => 'POST',
            'store' => 'POST',
            'save' => 'POST',
            'update' => 'PUT',
            'modify' => 'PUT',
            'patch' => 'PATCH',
            'delete' => 'DELETE',
            'remove' => 'DELETE',
            'destroy' => 'DELETE'
        ];

        $lowerName = strtolower($methodName);
        foreach ($prefixMap as $prefix => $httpMethod) {
            if (str_starts_with($lowerName, $prefix)) {
                return $httpMethod;
            }
        }

        return 'POST'; // Default to POST for actions
    }

    /**
     * Check if method should be excluded
     */
    private function shouldExclude(ReflectionMethod $method): bool
    {
        $name = $method->getName();

        // Check explicit exclusions
        if (in_array($name, $this->excludedMethods, true)) {
            return true;
        }

        // Check accessor prefixes
        if (!$this->includeAccessors) {
            foreach ($this->excludedPrefixes as $prefix) {
                if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                    // Check if next char is uppercase (indicating getter/setter)
                    $nextChar = $name[strlen($prefix)];
                    if (ctype_upper($nextChar)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Extract validation constraints from annotations
     */
    private function extractConstraints(array $methodDoc, string $paramName): ?array
    {
        $constraints = [];

        // Look for @gNodeValidate annotations
        $validateKey = "gNodeValidate:{$paramName}";
        if (isset($methodDoc['tags'][$validateKey])) {
            $validation = json_decode($methodDoc['tags'][$validateKey], true);
            if ($validation) {
                return $validation;
            }
        }

        return null;
    }

    /**
     * Build field mapping from parameters
     */
    private function buildFieldMapping(array $parameters): array
    {
        $mapping = [];

        foreach ($parameters as $param) {
            // Check for @gNodeMap annotation (stored in param info)
            if (isset($param['mapped_to']) && $param['mapped_to'] !== $param['name']) {
                $mapping[$param['name']] = $param['mapped_to'];
            }
        }

        return $mapping;
    }

    /**
     * Generate OpenAPI spec from introspection results
     */
    public function toOpenAPI(array $introspection): array
    {
        $paths = [];

        foreach ($introspection['endpoints'] as $endpoint) {
            $path = $endpoint['path'];
            $method = strtolower($endpoint['http_method']);

            if (!isset($paths[$path])) {
                $paths[$path] = [];
            }

            $operation = [
                'operationId' => $endpoint['endpoint_id'],
                'summary' => $endpoint['description'] ?? $endpoint['method_name'],
                'tags' => [$introspection['service_id']],
                'responses' => [
                    '200' => [
                        'description' => 'Successful response',
                        'content' => [
                            'application/json' => [
                                'schema' => $endpoint['response']['schema']
                            ]
                        ]
                    ]
                ]
            ];

            // Add request body for POST/PUT/PATCH
            if (in_array($method, ['post', 'put', 'patch'])) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $endpoint['request']['schema']
                        ]
                    ]
                ];
            }

            // Add parameters for GET/DELETE
            if (in_array($method, ['get', 'delete'])) {
                $operation['parameters'] = array_map(function($param) {
                    return [
                        'name' => $param['name'],
                        'in' => 'query',
                        'required' => $param['required'],
                        'schema' => $param['schema'],
                        'description' => $param['description']
                    ];
                }, $endpoint['request']['parameters']);
            }

            if ($endpoint['deprecated']) {
                $operation['deprecated'] = true;
            }

            $paths[$path][$method] = $operation;
        }

        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $introspection['service_id'],
                'version' => $introspection['version'] ?? '1.0.0',
                'description' => $introspection['description'] ?? ''
            ],
            'paths' => $paths
        ];
    }
}
