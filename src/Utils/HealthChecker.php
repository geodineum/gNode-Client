<?php
declare(strict_types=1);

namespace gCore\gNode\Utils;

use gCore\gNode\Config\CredentialResolver;
use gCore\gNode\Storage\StorageInterface;

/**
 * HealthChecker - Check gNode daemon health
 *
 * @package gCore\gNode\Utils
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
            'stream_prefix' => 'gnode',
            'log_path' => '/tmp/gnode-daemon.log'
        ], $config);
    }

    /**
     * Commit 1.12.b (NC-D2.02): validate a PID string before shell
     * interpolation. `$pid` is read from ValKey via `$this->storage->get()`
     * and a compromised sibling-ACL client could write `0; rm -rf / ;#`
     * to the PID key, turning every `ps -p {$pid}` / `kill {$pid}` into
     * RCE. Accept only non-empty digit strings; return empty string on
     * any other input so callers see a missing-pid branch rather than
     * a smuggled command.
     *
     * @param mixed $raw
     * @return string validated digit-only PID, or '' if invalid
     */
    private static function safePid($raw): string
    {
        if (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
            return $raw;
        }
        return '';
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
        $pid = self::safePid($this->storage->get($pidKey));
        $result['pid'] = $pid !== '' ? $pid : null;

        // Check if daemon process is running
        if ($pid !== '') {
            $command = "ps -p " . escapeshellarg($pid) . " > /dev/null 2>&1 || echo 'not-running'";
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
            // Commit 1.12.b (NC-D2.02): build environment string with
            // escapeshellarg on every value. Single-quote wrapping pre-fix
            // was a no-op whenever a value contained `'` (trivial to hit
            // for the VALKEY_AUTH password).
            //
            // Commit NC-D2.05.b: prefer GNODE_REDIS_AUTH_FILE (a path) over
            // VALKEY_AUTH (the password) so the password never lands in
            // /proc/<pid>/environ or /proc/<pid>/cmdline. Paired with
            // daemon --redis-auth-file support (gNode SHA 6fe264f).
            $authFile = $this->config['redis_auth_file']
                ?? CredentialResolver::tryResolveFilePath('gnode_daemon');

            $env = [
                'VALKEY_HOST' => $this->config['redis_host'] ?? getenv('VALKEY_HOST') ?: '127.0.0.1',
                'VALKEY_PORT' => $this->config['redis_port'] ?? getenv('VALKEY_PORT') ?: '47445',
                'SITE_ID' => $this->siteId,
                'NODE_ID' => $this->nodeId,
                'STREAM_PREFIX' => $this->config['stream_prefix'],
                'RUST_LOG' => 'info',
                'DEBUG' => $this->config['debug'] ? '1' : '0'
            ];
            if ($authFile !== null && $authFile !== '') {
                $env['GNODE_REDIS_AUTH_FILE'] = $authFile;
            } else {
                // Dev fallback: pass password inline.
                $env['VALKEY_AUTH'] = $this->config['redis_auth'] ?? '';
                error_log(
                    "[HealthChecker] NC-D2.05: no readable daemon credential "
                    . "file found; falling back to VALKEY_AUTH env. Provision "
                    . "/etc/geodineum/credentials/valkey_daemon.password to "
                    . "enable the file-path path."
                );
            }

            $envStr = '';
            foreach ($env as $key => $value) {
                // Key must be a POSIX env identifier; reject anything else
                // so we can't be tricked into `FOO=bar; rm -rf / ;#` via
                // malicious config.
                if (!preg_match('/\A[A-Z_][A-Z0-9_]*\z/', (string) $key)) {
                    throw new \RuntimeException("invalid env var name: {$key}");
                }
                $envStr .= $key . '=' . escapeshellarg((string) $value) . ' ';
            }

            // Start daemon in background
            $logPath = $this->config['log_path'];
            $command = $envStr
                . escapeshellarg((string) $daemonPath)
                . ' --site-id ' . escapeshellarg($this->siteId)
                . ' --node-id ' . escapeshellarg($this->nodeId)
                . ' --debug > ' . escapeshellarg((string) $logPath)
                . ' 2>&1 & echo $!';
            $pid = self::safePid(exec($command));

            if ($pid === '') {
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
            $command = "ps -p " . escapeshellarg($pid) . " > /dev/null 2>&1 || echo 'not-running'";
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
        $pid = self::safePid($this->storage->get($pidKey));

        if ($pid === '') {
            return false;
        }

        try {
            // Commit 1.12.b (NC-D2.02): escapeshellarg guards against a
            // malicious PID value from ValKey. safePid already restricts
            // to ctype_digit so this is belt-and-suspenders.
            $pidArg = escapeshellarg($pid);

            // Send SIGTERM to daemon
            $command = "kill {$pidArg} 2>/dev/null || true";
            exec($command);

            // Wait for daemon to stop
            sleep(1);

            // Check if process is still running
            $command = "ps -p {$pidArg} > /dev/null 2>&1 || echo 'not-running'";
            $result = exec($command);

            if ($result === 'not-running') {
                $this->storage->delete($pidKey);
                return true;
            }

            // Force kill if still running
            $command = "kill -9 {$pidArg} 2>/dev/null || true";
            exec($command);

            $this->storage->delete($pidKey);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
