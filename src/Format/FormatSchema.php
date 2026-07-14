<?php

declare(strict_types=1);

namespace gCore\gNode\Format;

use gCore\gNode\Exception\gNodeException;

/**
 * FormatSchema - JSONSchema validation and parsing
 *
 * Provides JSONSchema draft-7 compliant validation for custom message formats.
 * Validates data structures against schema definitions and provides parsing utilities.
 *
 * Supported JSONSchema draft-7 features:
 * - Type validation (string, number, integer, boolean, array, object, null)
 * - Required fields
 * - Properties and additionalProperties
 * - Pattern validation (regex)
 * - Minimum/maximum values
 * - Array items and minItems/maxItems
 * - Enum values
 * - Format strings (basic support)
 *
 * @package gCore\gNode\Format
 */
class FormatSchema
{
    /**
     * @var array JSONSchema definition
     */
    private array $schema;

    /**
     * @var array Validation errors (populated during validate())
     */
    private array $errors = [];

    /**
     * FormatSchema constructor
     *
     * @param array $schema JSONSchema definition (draft-7 compliant)
     * @throws gNodeException if schema is invalid
     */
    public function __construct(array $schema)
    {
        if (empty($schema)) {
            throw new gNodeException('Schema cannot be empty');
        }

        // Basic schema structure validation
        if (!isset($schema['type']) && !isset($schema['properties']) && !isset($schema['$ref'])) {
            throw new gNodeException('Schema must define "type", "properties", or "$ref"');
        }

        $this->schema = $schema;
        $this->validateSchemaStructure();
    }

    /**
     * Create FormatSchema from JSON string
     *
     * @param string $json JSON-encoded schema
     * @return self
     * @throws gNodeException on JSON decode error or invalid schema
     */
    public static function fromJson(string $json): self
    {
        $schema = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new gNodeException(
                'Invalid JSON schema: ' . json_last_error_msg()
            );
        }

        return new self($schema);
    }

    /**
     * Validate data against schema
     *
     * Performs JSONSchema draft-7 validation.
     * Returns array of validation errors (empty if valid).
     *
     * @param mixed $data Data to validate
     * @return array Validation errors (empty if valid)
     */
    public function validate($data): array
    {
        $this->errors = [];
        $this->validateValue($data, $this->schema, '');
        return $this->errors;
    }

    /**
     * Parse JSON string and validate
     *
     * @param string $json JSON string to parse
     * @return mixed Parsed data
     * @throws gNodeException on JSON parse error or validation failure
     */
    public function parse(string $json)
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new gNodeException(
                'JSON parse error: ' . json_last_error_msg()
            );
        }

        $errors = $this->validate($data);

        if (!empty($errors)) {
            throw new gNodeException(
                'Validation failed: ' . implode(', ', $errors)
            );
        }

        return $data;
    }

    /**
     * Get schema properties
     *
     * @return array Properties definition
     */
    public function getProperties(): array
    {
        return $this->schema['properties'] ?? [];
    }

    /**
     * Get required fields
     *
     * @return array Required field names
     */
    public function getRequired(): array
    {
        return $this->schema['required'] ?? [];
    }

    /**
     * Get full schema definition
     *
     * @return array Schema
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * Get schema type
     *
     * @return string|array|null Type(s) defined in schema
     */
    public function getType()
    {
        return $this->schema['type'] ?? null;
    }

    /**
     * Check if schema defines a specific property
     *
     * @param string $propertyName Property name
     * @return bool
     */
    public function hasProperty(string $propertyName): bool
    {
        $properties = $this->getProperties();
        return isset($properties[$propertyName]);
    }

    /**
     * Get property schema
     *
     * @param string $propertyName Property name
     * @return array|null Property schema, or null if not defined
     */
    public function getPropertySchema(string $propertyName): ?array
    {
        $properties = $this->getProperties();
        return $properties[$propertyName] ?? null;
    }

    /**
     * Validate schema structure
     *
     * @throws gNodeException on invalid schema structure
     */
    private function validateSchemaStructure(): void
    {
        // Validate type if present
        if (isset($this->schema['type'])) {
            $validTypes = ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'];
            $type = $this->schema['type'];

            if (is_array($type)) {
                foreach ($type as $t) {
                    if (!in_array($t, $validTypes, true)) {
                        throw new gNodeException("Invalid schema type: {$t}");
                    }
                }
            } elseif (!in_array($type, $validTypes, true)) {
                throw new gNodeException("Invalid schema type: {$type}");
            }
        }

        // Validate properties structure
        if (isset($this->schema['properties']) && !is_array($this->schema['properties'])) {
            throw new gNodeException('Schema "properties" must be an object');
        }

        // Validate required structure
        if (isset($this->schema['required'])) {
            if (!is_array($this->schema['required'])) {
                throw new gNodeException('Schema "required" must be an array');
            }

            foreach ($this->schema['required'] as $field) {
                if (!is_string($field)) {
                    throw new gNodeException('Schema "required" must contain strings');
                }
            }
        }
    }

    /**
     * Validate a value against schema definition
     *
     * @param mixed $value Value to validate
     * @param array $schema Schema to validate against
     * @param string $path Current path (for error messages)
     */
    private function validateValue($value, array $schema, string $path): void
    {
        // Type validation
        if (isset($schema['type'])) {
            $this->validateType($value, $schema['type'], $path);
        }

        // Enum validation
        if (isset($schema['enum'])) {
            $this->validateEnum($value, $schema['enum'], $path);
        }

        // Type-specific validations
        if (is_array($value) && $this->isAssociativeArray($value)) {
            // Object validation
            $this->validateObject($value, $schema, $path);
        } elseif (is_array($value)) {
            // Array validation
            $this->validateArray($value, $schema, $path);
        } elseif (is_string($value)) {
            // String validation
            $this->validateString($value, $schema, $path);
        } elseif (is_numeric($value)) {
            // Number validation
            $this->validateNumber($value, $schema, $path);
        }
    }

    /**
     * Validate type
     *
     * @param mixed $value Value to check
     * @param string|array $type Expected type(s)
     * @param string $path Current path
     */
    private function validateType($value, $type, string $path): void
    {
        $actualType = $this->getValueType($value);
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $t) {
            if ($this->matchesType($value, $actualType, $t)) {
                return; // Type matches
            }
        }

        $expectedTypes = implode('|', $types);
        $this->errors[] = "{$path}: Expected type {$expectedTypes}, got {$actualType}";
    }

    /**
     * Validate enum
     *
     * @param mixed $value Value to check
     * @param array $enum Allowed values
     * @param string $path Current path
     */
    private function validateEnum($value, array $enum, string $path): void
    {
        if (!in_array($value, $enum, true)) {
            $allowed = implode(', ', array_map('json_encode', $enum));
            $this->errors[] = "{$path}: Value must be one of: {$allowed}";
        }
    }

    /**
     * Validate object
     *
     * @param array $value Object to validate
     * @param array $schema Schema definition
     * @param string $path Current path
     */
    private function validateObject(array $value, array $schema, string $path): void
    {
        // Required fields
        if (isset($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (!array_key_exists($field, $value)) {
                    $this->errors[] = "{$path}: Missing required field '{$field}'";
                }
            }
        }

        // Properties
        if (isset($schema['properties'])) {
            foreach ($value as $key => $val) {
                if (isset($schema['properties'][$key])) {
                    $newPath = $path ? "{$path}.{$key}" : $key;
                    $this->validateValue($val, $schema['properties'][$key], $newPath);
                } elseif (isset($schema['additionalProperties'])) {
                    if ($schema['additionalProperties'] === false) {
                        $this->errors[] = "{$path}: Additional property '{$key}' not allowed";
                    } elseif (is_array($schema['additionalProperties'])) {
                        $newPath = $path ? "{$path}.{$key}" : $key;
                        $this->validateValue($val, $schema['additionalProperties'], $newPath);
                    }
                }
            }
        }

        // Min/max properties
        if (isset($schema['minProperties'])) {
            if (count($value) < $schema['minProperties']) {
                $this->errors[] = "{$path}: Object must have at least {$schema['minProperties']} properties";
            }
        }

        if (isset($schema['maxProperties'])) {
            if (count($value) > $schema['maxProperties']) {
                $this->errors[] = "{$path}: Object must have at most {$schema['maxProperties']} properties";
            }
        }
    }

    /**
     * Validate array
     *
     * @param array $value Array to validate
     * @param array $schema Schema definition
     * @param string $path Current path
     */
    private function validateArray(array $value, array $schema, string $path): void
    {
        // Min/max items
        if (isset($schema['minItems'])) {
            if (count($value) < $schema['minItems']) {
                $this->errors[] = "{$path}: Array must have at least {$schema['minItems']} items";
            }
        }

        if (isset($schema['maxItems'])) {
            if (count($value) > $schema['maxItems']) {
                $this->errors[] = "{$path}: Array must have at most {$schema['maxItems']} items";
            }
        }

        // Items validation
        if (isset($schema['items'])) {
            if (is_array($schema['items']) && !$this->isAssociativeArray($schema['items'])) {
                // Tuple validation
                foreach ($value as $index => $item) {
                    if (isset($schema['items'][$index])) {
                        $this->validateValue($item, $schema['items'][$index], "{$path}[{$index}]");
                    }
                }
            } else {
                // All items same schema
                foreach ($value as $index => $item) {
                    $this->validateValue($item, $schema['items'], "{$path}[{$index}]");
                }
            }
        }
    }

    /**
     * Validate string
     *
     * @param string $value String to validate
     * @param array $schema Schema definition
     * @param string $path Current path
     */
    private function validateString(string $value, array $schema, string $path): void
    {
        // Min/max length
        if (isset($schema['minLength'])) {
            if (mb_strlen($value) < $schema['minLength']) {
                $this->errors[] = "{$path}: String must be at least {$schema['minLength']} characters";
            }
        }

        if (isset($schema['maxLength'])) {
            if (mb_strlen($value) > $schema['maxLength']) {
                $this->errors[] = "{$path}: String must be at most {$schema['maxLength']} characters";
            }
        }

        // Pattern validation
        if (isset($schema['pattern'])) {
            if (!preg_match('/' . str_replace('/', '\\/', $schema['pattern']) . '/', $value)) {
                $this->errors[] = "{$path}: String does not match pattern: {$schema['pattern']}";
            }
        }

        // Format validation (basic support)
        if (isset($schema['format'])) {
            $this->validateFormat($value, $schema['format'], $path);
        }
    }

    /**
     * Validate number
     *
     * @param float|int $value Number to validate
     * @param array $schema Schema definition
     * @param string $path Current path
     */
    private function validateNumber($value, array $schema, string $path): void
    {
        // Minimum
        if (isset($schema['minimum'])) {
            if ($value < $schema['minimum']) {
                $this->errors[] = "{$path}: Value must be >= {$schema['minimum']}";
            }
        }

        // Maximum
        if (isset($schema['maximum'])) {
            if ($value > $schema['maximum']) {
                $this->errors[] = "{$path}: Value must be <= {$schema['maximum']}";
            }
        }

        // Exclusive minimum
        if (isset($schema['exclusiveMinimum'])) {
            if ($value <= $schema['exclusiveMinimum']) {
                $this->errors[] = "{$path}: Value must be > {$schema['exclusiveMinimum']}";
            }
        }

        // Exclusive maximum
        if (isset($schema['exclusiveMaximum'])) {
            if ($value >= $schema['exclusiveMaximum']) {
                $this->errors[] = "{$path}: Value must be < {$schema['exclusiveMaximum']}";
            }
        }

        // Multiple of
        if (isset($schema['multipleOf'])) {
            if (fmod($value, $schema['multipleOf']) !== 0.0) {
                $this->errors[] = "{$path}: Value must be a multiple of {$schema['multipleOf']}";
            }
        }
    }

    /**
     * Validate format (basic support)
     *
     * @param string $value Value to validate
     * @param string $format Format type
     * @param string $path Current path
     */
    private function validateFormat(string $value, string $format, string $path): void
    {
        switch ($format) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[] = "{$path}: Invalid email format";
                }
                break;

            case 'uri':
            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->errors[] = "{$path}: Invalid URL format";
                }
                break;

            case 'date-time':
                if (!strtotime($value)) {
                    $this->errors[] = "{$path}: Invalid date-time format";
                }
                break;

            case 'ipv4':
                if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $this->errors[] = "{$path}: Invalid IPv4 format";
                }
                break;

            case 'ipv6':
                if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $this->errors[] = "{$path}: Invalid IPv6 format";
                }
                break;
        }
    }

    /**
     * Get type of value for validation
     *
     * @param mixed $value Value to check
     * @return string Type name
     */
    private function getValueType($value): string
    {
        if (is_null($value)) {
            return 'null';
        } elseif (is_bool($value)) {
            return 'boolean';
        } elseif (is_int($value)) {
            return 'integer';
        } elseif (is_float($value)) {
            return 'number';
        } elseif (is_string($value)) {
            return 'string';
        } elseif (is_array($value)) {
            return $this->isAssociativeArray($value) ? 'object' : 'array';
        } else {
            return 'unknown';
        }
    }

    /**
     * Check if type matches
     *
     * @param mixed $value Value to check
     * @param string $actualType Actual type
     * @param string $expectedType Expected type
     * @return bool
     */
    private function matchesType($value, string $actualType, string $expectedType): bool
    {
        if ($actualType === $expectedType) {
            return true;
        }

        // Integer is also a number
        if ($expectedType === 'number' && $actualType === 'integer') {
            return true;
        }

        return false;
    }

    /**
     * Check if array is associative (object-like)
     *
     * @param array $array Array to check
     * @return bool
     */
    private function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
