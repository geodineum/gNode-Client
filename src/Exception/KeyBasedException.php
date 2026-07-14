<?php
declare(strict_types=1);

namespace gCore\gNode\Exception;

/**
 * KeyBasedException - Exception for key-based gNode operations
 *
 * Thrown when key-based operations fail (cache misses, timeouts, invalid responses, etc.)
 *
 * @package gCore\gNode\Exception
 * @version 2.0.0
 */
class KeyBasedException extends gNodeException
{
    /** @var string|null Request ID that caused the exception */
    protected $requestId;

    /** @var string|null Command that failed */
    protected $command;

    /** @var array|null Parameters that were used */
    protected $parameters;

    /**
     * Create exception with context
     *
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @param string|null $requestId Request ID
     * @param string|null $command Command name
     * @param array|null $parameters Command parameters
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $requestId = null,
        ?string $command = null,
        ?array $parameters = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->requestId = $requestId;
        $this->command = $command;
        $this->parameters = $parameters;
    }

    /**
     * Get request ID
     *
     * @return string|null Request ID
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Get command name
     *
     * @return string|null Command name
     */
    public function getCommand(): ?string
    {
        return $this->command;
    }

    /**
     * Get command parameters
     *
     * @return array|null Parameters
     */
    public function getParameters(): ?array
    {
        return $this->parameters;
    }

    /**
     * Get exception context as array
     *
     * @return array Context information
     */
    public function getContext(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'request_id' => $this->requestId,
            'command' => $this->command,
            'parameters' => $this->parameters,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }

    /**
     * Create exception for cache miss
     *
     * @param string $command Command name
     * @param array $parameters Command parameters
     * @return self
     */
    public static function cacheMiss(string $command, array $parameters = []): self
    {
        return new self(
            "Cache miss for command: {$command}",
            1001,
            null,
            null,
            $command,
            $parameters
        );
    }

    /**
     * Create exception for timeout
     *
     * @param string $requestId Request ID
     * @param string $command Command name
     * @param int $timeoutMs Timeout duration in milliseconds
     * @return self
     */
    public static function timeout(string $requestId, string $command, int $timeoutMs): self
    {
        return new self(
            "Timeout after {$timeoutMs}ms waiting for response to command: {$command}",
            1002,
            null,
            $requestId,
            $command
        );
    }

    /**
     * Create exception for invalid response
     *
     * @param string $requestId Request ID
     * @param string $reason Reason for invalidity
     * @return self
     */
    public static function invalidResponse(string $requestId, string $reason): self
    {
        return new self(
            "Invalid response for request {$requestId}: {$reason}",
            1003,
            null,
            $requestId
        );
    }

    /**
     * Create exception for bundle not available
     *
     * @param string $siteId Site identifier
     * @return self
     */
    public static function bundleNotAvailable(string $siteId): self
    {
        return new self(
            "Bundle not available for site: {$siteId}",
            1004,
            null,
            null,
            'getBundle'
        );
    }

    /**
     * Create exception for decompression failure
     *
     * @param string $siteId Site identifier
     * @return self
     */
    public static function decompressionFailed(string $siteId): self
    {
        return new self(
            "Bundle decompression failed for site: {$siteId}",
            1005,
            null,
            null,
            'getBundle'
        );
    }

    /**
     * Create exception for JSON parse error
     *
     * @param string $error JSON error message
     * @return self
     */
    public static function jsonParseError(string $error): self
    {
        return new self(
            "JSON parse error: {$error}",
            1006
        );
    }

    /**
     * Create exception for storage operation failure
     *
     * @param string $operation Operation name (get, set, publish, etc.)
     * @param string $key Key name
     * @return self
     */
    public static function storageOperationFailed(string $operation, string $key): self
    {
        return new self(
            "Storage operation '{$operation}' failed for key: {$key}",
            1007
        );
    }
}
