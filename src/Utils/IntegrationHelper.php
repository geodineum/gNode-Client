<?php

namespace gCore\GSD\Utils;

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;
use gCore\GSD\Exception\GSDException;

/**
 * IntegrationHelper - Helper for integrating GSD with applications
 *
 * @package gCore\GSD\Utils
 */
class IntegrationHelper
{
    /**
     * Initialize GSD client
     *
     * @param array $config Configuration options
     * @param bool $ensureDaemonRunning Whether to ensure daemon is running
     * @return array Results with client and status information
     */
    public static function initialize(array $config = [], bool $ensureDaemonRunning = true): array
    {
        $results = [
            'client' => null,
            'status' => 'unknown',
            'message' => '',
            'daemon_running' => false,
            'command_stream' => '',
            'config' => $config
        ];

        // Check for ValKey password from .gsd/valkey.password file if not provided
        if (!isset($config['password']) || $config['password'] === null) {
            $passwordFile = '.gsd/valkey.password';
            if (file_exists($passwordFile)) {
                $password = trim(file_get_contents($passwordFile));
                if (!empty($password)) {
                    $config['password'] = $password;
                }
            }
        }

        // Default configuration
        $config = array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => null,
            'site_id' => 'default',
            'node_id' => 'default',
            'client_id' => 'client-' . uniqid(),
            'debug' => false,
            'use_fallback' => true,
            'timeout' => 5.0,
            'daemon_path' => null,
            'log_path' => null,
            'stream_prefix' => 'gsd'
        ], $config);

        $results['config'] = $config;

        try {
            // Step 1: Create storage
            $storage = new ValKeyStorage([
                'host' => $config['host'],
                'port' => $config['port'],
                'password' => $config['password'] ?? null
            ]);

            // Step 2: Check storage connection
            if (!$storage->ping()) {
                $results['status'] = 'error';
                $results['message'] = 'Cannot connect to Redis/ValKey';
                return $results;
            }

            // Step 3: Check daemon health
            $healthChecker = new HealthChecker(
                $storage,
                $config['site_id'],
                $config['node_id'],
                [
                    'debug' => $config['debug'],
                    'daemon_path' => $config['daemon_path'],
                    'log_path' => $config['log_path'],
                    'stream_prefix' => $config['stream_prefix']
                ]
            );

            $health = $healthChecker->check();
            $results['daemon_running'] = $health['daemon_running'];
            $results['command_stream'] = $health['command_stream'];

            // Step 4: Start daemon if needed and requested
            if (!$health['daemon_running'] && $ensureDaemonRunning && $config['daemon_path']) {
                $started = $healthChecker->startDaemon();
                $results['daemon_running'] = $started;
            }

            // Step 5: Create the client
            $client = new Client(
                $storage,
                $config['site_id'],
                $config['node_id'],
                [
                    'client_id' => $config['client_id'],
                    'stream_prefix' => $config['stream_prefix'],
                    'debug' => $config['debug'],
                    'use_fallback' => $config['use_fallback'],
                    'timeout' => $config['timeout'],
                    'daemon_path' => $config['daemon_path'],
                    'allow_local_execution' => $config['use_fallback']
                ]
            );

            $results['client'] = $client;

            // Step 6: Check if client is connected
            if ($client->isConnected()) {
                if ($client->isUsingFallback()) {
                    $results['status'] = 'fallback';
                    $results['message'] = 'Using fallback mode (daemon not available)';
                } else {
                    $results['status'] = 'healthy';
                    $results['message'] = 'GSD client initialized successfully';
                }
            } else {
                $results['status'] = 'error';
                $results['message'] = 'Cannot connect to daemon and fallback disabled';
                $results['client'] = null;
            }
        } catch (GSDException $e) {
            $results['status'] = 'error';
            $results['message'] = 'Initialization error: ' . $e->getMessage();
        } catch (\Exception $e) {
            $results['status'] = 'error';
            $results['message'] = 'Unexpected error: ' . $e->getMessage();
        }

        return $results;
    }

    /**
     * Get command stream key
     *
     * @param string $siteId Site identifier
     * @param string $nodeId Node identifier
     * @param string $streamPrefix Stream prefix
     * @return string Command stream key
     */
    public static function getCommandStreamKey(
        string $siteId,
        string $nodeId,
        string $streamPrefix = 'gsd'
    ): string {
        return sprintf('{%s}:%s:stream:%s:commands', $siteId, $streamPrefix, $nodeId);
    }

    /**
     * Get response stream key
     *
     * @param string $siteId Site identifier
     * @param string $nodeId Node identifier
     * @param string $streamPrefix Stream prefix
     * @return string Response stream key
     */
    public static function getResponseStreamKey(
        string $siteId,
        string $nodeId,
        string $streamPrefix = 'gsd'
    ): string {
        return sprintf('{%s}:%s:stream:%s:responses', $siteId, $streamPrefix, $nodeId);
    }

    /**
     * Get daemon PID key
     *
     * @param string $siteId Site identifier
     * @param string $nodeId Node identifier
     * @param string $streamPrefix Stream prefix
     * @return string Daemon PID key
     */
    public static function getDaemonPidKey(
        string $siteId,
        string $nodeId,
        string $streamPrefix = 'gsd'
    ): string {
        return sprintf('{%s}:%s:daemon:pid:%s', $siteId, $streamPrefix, $nodeId);
    }
}
