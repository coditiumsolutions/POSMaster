<?php

namespace App\Services;

use RuntimeException;

/**
 * Remote shell/SCP helper for Hostinger.
 * When $localMode is true (app already running on the Hostinger account),
 * file writes use the local filesystem — required because Hostinger disables proc_open/exec.
 */
class HostingerSshClient
{
    public function __construct(
        protected string $host,
        protected int $port,
        protected string $user,
        protected string $identityFile,
        protected bool $localMode = false,
    ) {
    }

    public static function detectLocalMode(?string $username = null): bool
    {
        $username = $username ?: (string) config('new_setup.hostinger.username', '');
        if ($username === '') {
            return false;
        }
        $domains = '/home/' . $username . '/domains';
        if (!is_dir($domains) || !is_writable($domains)) {
            return false;
        }
        // Prefer explicit env; otherwise auto-enable on the Hostinger box.
        $forced = env('NEW_SETUP_HOSTINGER_LOCAL');
        if ($forced !== null && $forced !== '') {
            return filter_var($forced, FILTER_VALIDATE_BOOLEAN);
        }

        return true;
    }

    public function isLocalMode(): bool
    {
        return $this->localMode;
    }

    public function configured(): bool
    {
        if ($this->localMode) {
            return $this->user !== ''
                && is_dir('/home/' . $this->user . '/domains')
                && is_writable('/home/' . $this->user . '/domains');
        }

        return $this->host !== ''
            && $this->user !== ''
            && $this->identityFile !== ''
            && is_file($this->identityFile);
    }

    public function run(string $remoteCommand, int $timeoutSeconds = 600): string
    {
        if ($this->localMode) {
            throw new RuntimeException(
                'Shell commands are not available in Hostinger local mode (proc_open/exec disabled).'
            );
        }

        if (!$this->configured()) {
            throw new RuntimeException('Hostinger SSH is not configured (host/user/identity file).');
        }

        $cmd = [
            $this->sshBinary(),
            '-i', $this->identityFile,
            '-p', (string) $this->port,
            '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'ConnectTimeout=20',
            sprintf('%s@%s', $this->user, $this->host),
            $remoteCommand,
        ];

        return $this->runProcess($cmd, $timeoutSeconds, 'SSH');
    }

    public function uploadString(string $remotePath, string $contents): void
    {
        if ($this->localMode) {
            $dir = dirname($remotePath);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create directory for upload: ' . $dir);
            }
            if (@file_put_contents($remotePath, $contents) === false) {
                throw new RuntimeException('Could not write file: ' . $remotePath);
            }

            return;
        }

        if (!$this->configured()) {
            throw new RuntimeException('Hostinger SSH is not configured (host/user/identity file).');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'hup');
        if ($tmp === false || file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException('Could not stage temporary upload file.');
        }

        try {
            $target = sprintf('%s@%s:%s', $this->user, $this->host, $remotePath);
            $cmd = [
                $this->scpBinary(),
                '-i', $this->identityFile,
                '-P', (string) $this->port,
                '-o', 'BatchMode=yes',
                '-o', 'IdentitiesOnly=yes',
                '-o', 'StrictHostKeyChecking=accept-new',
                $tmp,
                $target,
            ];
            $this->runProcess($cmd, 120, 'SCP');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Recursively delete a path (files/dirs). Used to clear public_html before git deploy.
     */
    public function deletePath(string $path): void
    {
        if ($this->localMode) {
            $this->deleteLocalPath($path);

            return;
        }

        $this->run('rm -rf ' . escapeshellarg($path), 120);
    }

    /**
     * Ensure directories exist.
     *
     * @param  list<string>  $dirs
     */
    public function ensureDirs(array $dirs): void
    {
        foreach ($dirs as $dir) {
            if ($this->localMode) {
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException('Could not create directory: ' . $dir);
                }
                continue;
            }
            $this->run('mkdir -p ' . escapeshellarg($dir), 60);
        }
    }

    protected function deleteLocalPath(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        $items = @scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deleteLocalPath($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    }

    /**
     * @param  list<string>  $cmd
     */
    protected function runProcess(array $cmd, int $timeoutSeconds, string $label): string
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException(
                "Hostinger {$label} requires proc_open, which is disabled on this PHP. "
                . 'Use NEW_SETUP_HOSTINGER_LOCAL=true on the server, or run New Setup from your local PC.'
            );
        }

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptor, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException("Unable to start {$label} process for Hostinger.");
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = time();

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((time() - $start) > $timeoutSeconds) {
                proc_terminate($process, 9);
                throw new RuntimeException("Hostinger {$label} command timed out.");
            }
            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException("Hostinger {$label} failed: " . ($detail !== '' ? $detail : "exit {$code}"));
        }

        return $stdout;
    }

    protected function sshBinary(): string
    {
        return 'ssh';
    }

    protected function scpBinary(): string
    {
        return 'scp';
    }
}
