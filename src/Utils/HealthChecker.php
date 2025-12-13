<?php

namespace gCore\GSD\Utils;

use gCore\GSD\Storage\StorageInterface;

/**
 * HealthChecker - Check GSD daemon health
 *
 * @package gCore\GSD\Utils
 */
class HealthChecker
{
    /** @var StorageInterface Storage for communication */
    protected $storage;

    /** @var string Site identifier */
    protected $siteId;

    /** @var string Node identifier */
    protected $nodeId;

    /** @var array Configuration */
    protected $config;

    /**
     * Constructor
     *
     * @param StorageInterface $storage Storage for communication
     * @param string $siteId Site identifier
     * @param string $nodeId Node identifier
     * @param array $config Configuration options
     */
    public function __construct(
        StorageInterface $storage,
        string $siteId = 'default',
        string $nodeId = 'default',
        array $config = []
    ) {
        $this->storage = $storage;
        $this->siteId = $siteId;
        $this->nodeId = $nodeId;
        $this->config = array_merge([
            'debug' => false,
            'daemon_path' => null,
            'stream_prefix' => 'gsd',
            'log_path' => '/tmp/gsd-daemon.log'
        ], $config);
    }

    /**
     * Check daemon health
     *
     * @return array Health status
     */
    public function check(): array
    {
        $result = [
            'storage_connected' => false,
            'daemon_running' => false,
            'pid' => null,
            'command_stream' => null,
            'message' => '',
            'status' => 'unhealthy'
        ];

        // Check storage connection
        try {
            $result['storage_connected'] = $this->storage->ping();
        } catch (\Exception $e) {
            $result['message'] = "Storage connection error: " . $e->getMessage();
            return $result;
        }

        if (!$result['storage_connected']) {
            $result['message'] = "Cannot connect to Redis/ValKey";
            return $result;
        }

        // Get daemon PID
        $pidKey = sprintf(
            '{%s}:%s:daemon:pid:%s',
            $this->siteId,
            $this->config['stream_prefix'],
            $this->nodeId
        );
        $pid = $this->storage->get($pidKey);
        $result['pid'] = $pid;

        // Check if daemon process is running
        if ($pid) {
            $command = "ps -p {$pid} > /dev/null 2>&1 || echo 'not-running'";
            $output = exec($command);
            $result['daemon_running'] = ($output !== 'not-running');
        }

        // Check for command stream
        $commandStream = sprintf(
            '{%s}:%s:stream:%s:commands',
            $this->siteId,
            $this->config['stream_prefix'],
            $this->nodeId
        );
        $result['command_stream'] = $commandStream;
        $streamExists = $this->storage->exists($commandStream);

        // Set status based on checks
        if ($result['daemon_running'] && $streamExists) {
            $result['status'] = 'healthy';
        } elseif ($result['daemon_running']) {
            $result['status'] = 'partial';
            $result['message'] = "Daemon running but stream not found";
        } else {
            $result['status'] = 'unhealthy';
            $result['message'] = "Daemon not running";
        }

        return $result;
    }

    /**
     * Start the daemon
     *
     * @return bool Success status
     */
    public function startDaemon(): bool
    {
        $daemonPath = $this->config['daemon_path'];
        if (!$daemonPath || !file_exists($daemonPath)) {
            return false;
        }

        try {
            // Build environment variables
            $env = [
                'REDIS_HOST' => $this->config['redis_host'] ?? '127.0.0.1',
                'REDIS_PORT' => $this->config['redis_port'] ?? '6379',
                'REDIS_AUTH' => $this->config['redis_auth'] ?? '',
                'SITE_ID' => $this->siteId,
                'NODE_ID' => $this->nodeId,
                'STREAM_PREFIX' => $this->config['stream_prefix'],
                'RUST_LOG' => 'info',
                'DEBUG' => $this->config['debug'] ? '1' : '0'
            ];

            // Build environment string
            $envStr = '';
            foreach ($env as $key => $value) {
                $envStr .= "{$key}='{$value}' ";
            }

            // Start daemon in background
            $logPath = $this->config['log_path'];
            $command = "{$envStr} {$daemonPath} --site-id {$this->siteId} --node-id {$this->nodeId} --debug > {$logPath} 2>&1 & echo $!";
            $pid = exec($command);

            if (!$pid) {
                return false;
            }

            // Store PID for future reference
            $pidKey = sprintf(
                '{%s}:%s:daemon:pid:%s',
                $this->siteId,
                $this->config['stream_prefix'],
                $this->nodeId
            );
            $this->storage->set($pidKey, $pid);

            // Wait for daemon to initialize
            sleep(2);

            // Check if daemon is running
            $command = "ps -p {$pid} > /dev/null 2>&1 || echo 'not-running'";
            $output = exec($command);

            return ($output !== 'not-running');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Stop the daemon
     *
     * @return bool Success status
     */
    public function stopDaemon(): bool
    {
        $pidKey = sprintf(
            '{%s}:%s:daemon:pid:%s',
            $this->siteId,
            $this->config['stream_prefix'],
            $this->nodeId
        );
        $pid = $this->storage->get($pidKey);

        if (!$pid) {
            return false;
        }

        try {
            // Send SIGTERM to daemon
            $command = "kill {$pid} 2>/dev/null || true";
            exec($command);

            // Wait for daemon to stop
            sleep(1);

            // Check if process is still running
            $command = "ps -p {$pid} > /dev/null 2>&1 || echo 'not-running'";
            $result = exec($command);

            if ($result === 'not-running') {
                $this->storage->delete($pidKey);
                return true;
            }

            // Force kill if still running
            $command = "kill -9 {$pid} 2>/dev/null || true";
            exec($command);

            $this->storage->delete($pidKey);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
