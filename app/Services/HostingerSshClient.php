<?php

namespace App\Services;

use RuntimeException;

class HostingerSshClient
{
    public function __construct(
        protected string $host,
        protected int $port,
        protected string $user,
        protected string $identityFile,
    ) {
    }

    public function configured(): bool
    {
        return $this->host !== ''
            && $this->user !== ''
            && $this->identityFile !== ''
            && is_file($this->identityFile);
    }

    public function run(string $remoteCommand, int $timeoutSeconds = 600): string
    {
        if (!$this->configured()) {
            throw new RuntimeException('Hostinger SSH is not configured (host/user/identity file).');
        }

        $ssh = $this->sshBinary();
        $cmd = [
            $ssh,
            '-i', $this->identityFile,
            '-p', (string) $this->port,
            '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'ConnectTimeout=20',
            sprintf('%s@%s', $this->user, $this->host),
            $remoteCommand,
        ];

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptor, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start SSH process for Hostinger.');
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
                throw new RuntimeException('Hostinger SSH command timed out.');
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
            throw new RuntimeException('Hostinger SSH failed: ' . ($detail !== '' ? $detail : "exit {$code}"));
        }

        return $stdout;
    }

    public function uploadString(string $remotePath, string $contents): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hup');
        if ($tmp === false || file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException('Could not stage temporary upload file.');
        }

        try {
            $scp = $this->scpBinary();
            $target = sprintf('%s@%s:%s', $this->user, $this->host, $remotePath);
            $cmd = [
                $scp,
                '-i', $this->identityFile,
                '-P', (string) $this->port,
                '-o', 'BatchMode=yes',
                '-o', 'IdentitiesOnly=yes',
                '-o', 'StrictHostKeyChecking=accept-new',
                $tmp,
                $target,
            ];

            $descriptor = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($cmd, $descriptor, $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start SCP process for Hostinger.');
            }
            fclose($pipes[0]);
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            if ($code !== 0) {
                throw new RuntimeException('Hostinger SCP failed: ' . trim($stderr));
            }
        } finally {
            @unlink($tmp);
        }
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
