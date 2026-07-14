<?php
declare(strict_types=1);
/**
 * DeferredResult - Promise-like placeholder for queued command results
 *
 * Features:
 * - Lazy evaluation on access (triggers flush when needed)
 * - Automatic flush trigger on get()
 * - Value caching after resolution
 * - Timeout support
 * - Synchronous promise pattern (PHP single-threaded)
 *
 * Usage:
 *   $result = $queue->enqueue('ping', []);
 *   // ... more commands ...
 *   $value = $result->get();  // Triggers flush if not already done
 *
 * Performance:
 * - No overhead until get() is called
 * - Caches result after first resolution
 * - Thread-safe (PHP request-scoped)
 *
 * @package gCore\gNode\Queue
 */

namespace gCore\gNode\Queue;

use gCore\gNode\Queue\CommandQueue;

class DeferredResult
{
    /** @var CommandQueue */
    private $queue;

    /** @var string Command ID */
    private $cmdId;

    /** @var mixed Resolved value */
    private $value = null;

    /** @var bool Whether result has been resolved */
    private $resolved = false;

    /** @var float|null Timestamp when deferred was created */
    private $createdAt;

    /**
     * @param CommandQueue $queue Queue instance
     * @param string $cmdId Command ID
     */
    public function __construct(CommandQueue $queue, string $cmdId)
    {
        $this->queue = $queue;
        $this->cmdId = $cmdId;
        $this->createdAt = microtime(true);
    }

    /**
     * Get the result value (blocks until resolved)
     *
     * This will trigger a flush if the queue hasn't been flushed yet.
     *
     * @param float|null $timeoutSeconds Maximum time to wait in seconds
     * @return mixed Result value
     * @throws \RuntimeException If timeout is exceeded
     */
    public function get(?float $timeoutSeconds = null)
    {
        // If already resolved, return cached value
        if ($this->resolved) {
            return $this->extractValue($this->value);
        }

        // Check timeout
        if ($timeoutSeconds !== null) {
            $elapsed = microtime(true) - $this->createdAt;
            if ($elapsed > $timeoutSeconds) {
                throw new \RuntimeException(
                    "Timeout waiting for command result (cmdId: {$this->cmdId})"
                );
            }
        }

        // Trigger flush if queue is not empty
        if (!$this->queue->isEmpty()) {
            $this->queue->flush(false); // Auto-flush
        }

        // Check if resolved after flush
        if (!$this->resolved) {
            throw new \RuntimeException(
                "Command result not resolved after flush (cmdId: {$this->cmdId})"
            );
        }

        return $this->extractValue($this->value);
    }

    /**
     * Resolve the deferred result with a value
     *
     * @internal Called by CommandQueue
     * @param mixed $value Result value
     */
    public function resolve($value): void
    {
        if ($this->resolved) {
            return; // Already resolved
        }

        $this->value = $value;
        $this->resolved = true;
    }

    /**
     * Check if result has been resolved
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * Wait for result to be resolved (with optional timeout)
     *
     * @param float|null $timeoutSeconds Maximum time to wait
     * @throws \RuntimeException If timeout is exceeded
     */
    public function wait(?float $timeoutSeconds = null): void
    {
        $this->get($timeoutSeconds);
    }

    /**
     * Get command ID
     */
    public function getCommandId(): string
    {
        return $this->cmdId;
    }

    /**
     * Get elapsed time since creation
     */
    public function getElapsedTime(): float
    {
        return microtime(true) - $this->createdAt;
    }

    /**
     * Extract the actual value from the result
     *
     * Handles different result formats:
     * - ['status' => 'ok', 'result' => $value] -> $value
     * - ['status' => 'error', 'error' => $msg] -> throws exception
     * - ['error' => $msg] -> throws exception
     * - $value -> $value
     *
     * @param mixed $result Result from command execution
     * @return mixed Extracted value
     * @throws \RuntimeException If result contains an error
     */
    private function extractValue($result)
    {
        if (!is_array($result)) {
            return $result;
        }

        // Check for error
        if (isset($result['error'])) {
            throw new \RuntimeException(
                "Command failed (cmdId: {$this->cmdId}): {$result['error']}"
            );
        }

        // Check for status-based result
        if (isset($result['status'])) {
            if ($result['status'] === 'error') {
                $errorMsg = $result['error'] ?? 'Unknown error';
                throw new \RuntimeException(
                    "Command failed (cmdId: {$this->cmdId}): {$errorMsg}"
                );
            }

            // Status is 'ok', return result field if present
            if (isset($result['result'])) {
                return $result['result'];
            }

            // Return whole result if no specific result field
            return $result;
        }

        // Return raw result
        return $result;
    }

    /**
     * Get raw value without throwing exceptions
     *
     * Returns null if not resolved or on error.
     */
    public function getRaw()
    {
        if (!$this->resolved) {
            return null;
        }
        return $this->value;
    }

    /**
     * Check if result contains an error
     */
    public function hasError(): bool
    {
        if (!$this->resolved) {
            return false;
        }

        if (!is_array($this->value)) {
            return false;
        }

        return isset($this->value['error']) ||
               (isset($this->value['status']) && $this->value['status'] === 'error');
    }

    /**
     * Get error message if present
     */
    public function getError(): ?string
    {
        if (!$this->hasError()) {
            return null;
        }

        if (isset($this->value['error'])) {
            return $this->value['error'];
        }

        return 'Unknown error';
    }

    /**
     * Magic method to allow using deferred result directly
     *
     * Example: echo $result; // Automatically calls get()
     */
    public function __toString(): string
    {
        try {
            $value = $this->get();
            if (is_scalar($value)) {
                return (string)$value;
            }
            if (is_array($value)) {
                return json_encode($value);
            }
            return (string)$value;
        } catch (\Exception $e) {
            return "Error: " . $e->getMessage();
        }
    }

    /**
     * Magic method to allow isset() checks on deferred result
     */
    public function __isset($name): bool
    {
        if (!$this->resolved) {
            return false;
        }

        if (is_array($this->value)) {
            return isset($this->value[$name]);
        }

        return false;
    }

    /**
     * Magic method to allow property access on array results
     */
    public function __get($name)
    {
        $value = $this->get();

        if (is_array($value) && isset($value[$name])) {
            return $value[$name];
        }

        if (is_object($value) && isset($value->$name)) {
            return $value->$name;
        }

        return null;
    }
}
