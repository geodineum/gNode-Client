<?php
declare(strict_types=1);
/**
 * CommandQueue - Auto-batching command queue with multiple flush strategies
 *
 * Features:
 * - Array-based queue storage (O(1) enqueue)
 * - Auto-flush on size threshold (default: 100 commands)
 * - Auto-flush on timeout (default: 10ms since first enqueue)
 * - Manual flush support
 * - Shutdown handler registration
 * - Batch ID generation and sequence tracking
 * - DeferredResult return values for transparent batching
 *
 * Performance characteristics:
 * - Enqueue: O(1)
 * - Flush: O(n) where n = queue size
 * - Memory: ~150 bytes per queued command
 * - Overhead: <1ms queue management + 10ms flush timeout
 *
 * @package gCore\gNode\Queue
 */

namespace gCore\gNode\Queue;

use gCore\gNode\gNodeClientInterface;
use gCore\gNode\Queue\DeferredResult;

class CommandQueue
{
    /** @var gNodeClientInterface */
    private $client;

    /** @var array Queue of commands [{cmd, params, cmdId, timestamp}, ...] */
    private $queue = [];

    /** @var array Map of cmdId => DeferredResult */
    private $deferredResults = [];

    /** @var int Maximum queue size before auto-flush */
    private $maxSize;

    /** @var float Maximum time in seconds before auto-flush */
    private $maxTimeSeconds;

    /** @var float|null Timestamp when first command was enqueued */
    private $firstEnqueueTime = null;

    /** @var int Sequence counter for command IDs */
    private $sequence = 0;

    /** @var string|null Current batch ID */
    private $batchId = null;

    /** @var bool Whether shutdown handler is registered */
    private $shutdownRegistered = false;

    /** @var bool Flag to prevent recursive flush calls */
    private $flushing = false;

    /** @var array Statistics */
    private $stats = [
        'enqueued' => 0,
        'flushed' => 0,
        'auto_flush_size' => 0,
        'auto_flush_time' => 0,
        'manual_flush' => 0,
        'shutdown_flush' => 0,
    ];

    /**
     * @param gNodeClientInterface $client gNode client instance
     * @param int $maxSize Maximum queue size (default: 100)
     * @param float $maxTimeMs Maximum time in milliseconds (default: 10)
     */
    public function __construct(gNodeClientInterface $client, int $maxSize = 100, float $maxTimeMs = 10.0)
    {
        $this->client = $client;
        $this->maxSize = $maxSize;
        $this->maxTimeSeconds = $maxTimeMs / 1000.0;

        // Register shutdown handler to flush pending commands
        if (!$this->shutdownRegistered) {
            register_shutdown_function([$this, 'shutdownFlush']);
            $this->shutdownRegistered = true;
        }
    }

    /**
     * Enqueue a command for batched execution
     *
     * @param string $cmd Command name
     * @param array $params Command parameters
     * @return DeferredResult Promise-like result object
     */
    public function enqueue(string $cmd, array $params = []): DeferredResult
    {
        // Generate unique command ID
        $cmdId = $this->generateCommandId();

        // Store command in queue
        $this->queue[] = [
            'cmd' => $cmd,
            'params' => $params,
            'cmdId' => $cmdId,
            'timestamp' => microtime(true),
        ];

        // Track first enqueue time for timeout-based flushing
        if ($this->firstEnqueueTime === null) {
            $this->firstEnqueueTime = microtime(true);
        }

        // Create deferred result
        $deferred = new DeferredResult($this, $cmdId);
        $this->deferredResults[$cmdId] = $deferred;

        $this->stats['enqueued']++;

        // Check if we should auto-flush
        $this->checkAutoFlush();

        return $deferred;
    }

    /**
     * Flush all queued commands and return results
     *
     * @param bool $isManual Whether this is a manual flush
     * @return array Map of cmdId => result
     */
    public function flush(bool $isManual = true): array
    {
        // Prevent recursive flush calls
        if ($this->flushing) {
            return [];
        }

        if (empty($this->queue)) {
            return [];
        }

        $this->flushing = true;

        try {
            // Build batch commands array
            $batchCommands = [];
            foreach ($this->queue as $item) {
                $batchCommands[] = [
                    'cmd' => $item['cmd'],
                    'params' => $item['params'],
                    'id' => $item['cmdId'],
                ];
            }

            // Execute batch
            $results = $this->client->executeBatch($batchCommands);

            // Map results back to command IDs
            // executeBatch returns results by numeric index, not by command ID
            $resultMap = [];
            foreach ($this->queue as $index => $item) {
                $cmdId = $item['cmdId'];
                if (isset($results[$index])) {
                    // Add command ID to result for consistency
                    $result = $results[$index];
                    $result['id'] = $cmdId;
                    $resultMap[$cmdId] = $result;
                } else {
                    // No result received for this command
                    $resultMap[$cmdId] = ['error' => 'No result received', 'id' => $cmdId];
                }
            }

            // Resolve all deferred results
            foreach ($this->deferredResults as $cmdId => $deferred) {
                $result = $resultMap[$cmdId] ?? ['error' => 'No result received'];
                $deferred->resolve($result);
            }

            // Update statistics
            $this->stats['flushed'] += count($this->queue);
            if ($isManual) {
                $this->stats['manual_flush']++;
            }

            // Clear queue
            $this->queue = [];
            $this->deferredResults = [];
            $this->firstEnqueueTime = null;
            $this->batchId = null;

            $this->flushing = false;

            return $resultMap;

        } catch (\Exception $e) {
            $this->flushing = false;

            // Reject all deferred results with error
            foreach ($this->deferredResults as $deferred) {
                $deferred->resolve(['error' => $e->getMessage()]);
            }

            // Clear queue
            $this->queue = [];
            $this->deferredResults = [];
            $this->firstEnqueueTime = null;

            throw $e;
        }
    }

    /**
     * Shutdown handler to flush pending commands
     */
    public function shutdownFlush(): void
    {
        if (!empty($this->queue)) {
            $this->stats['shutdown_flush']++;
            $this->flush(false);
        }
    }

    /**
     * Check if auto-flush conditions are met
     */
    private function checkAutoFlush(): void
    {
        // Size-based flush
        if (count($this->queue) >= $this->maxSize) {
            $this->stats['auto_flush_size']++;
            $this->flush(false);
            return;
        }

        // Time-based flush
        if ($this->firstEnqueueTime !== null) {
            $elapsed = microtime(true) - $this->firstEnqueueTime;
            if ($elapsed >= $this->maxTimeSeconds) {
                $this->stats['auto_flush_time']++;
                $this->flush(false);
            }
        }
    }

    /**
     * Generate unique command ID
     */
    private function generateCommandId(): string
    {
        $this->sequence++;
        return sprintf(
            'cmd-%d-%d-%d',
            getmypid(),
            (int)(microtime(true) * 1000000),
            $this->sequence
        );
    }

    /**
     * Get current queue size
     */
    public function getSize(): int
    {
        return count($this->queue);
    }

    /**
     * Get batch ID (lazy generation)
     */
    public function getBatchId(): string
    {
        if ($this->batchId === null) {
            $this->batchId = sprintf(
                'batch-%d-%d',
                getmypid(),
                (int)(microtime(true) * 1000000)
            );
        }
        return $this->batchId;
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'queue_size' => count($this->queue),
            'pending_results' => count($this->deferredResults),
        ]);
    }

    /**
     * Check if queue is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->queue);
    }

    /**
     * Clear queue without flushing (use with caution!)
     */
    public function clear(): void
    {
        // Reject all pending deferred results
        foreach ($this->deferredResults as $deferred) {
            $deferred->resolve(['error' => 'Queue cleared']);
        }

        $this->queue = [];
        $this->deferredResults = [];
        $this->firstEnqueueTime = null;
        $this->batchId = null;
    }

    /**
     * Get deferred result by command ID
     *
     * @internal Used by DeferredResult
     */
    public function getDeferredResult(string $cmdId): ?DeferredResult
    {
        return $this->deferredResults[$cmdId] ?? null;
    }
}
