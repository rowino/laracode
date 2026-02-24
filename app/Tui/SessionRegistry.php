<?php

declare(strict_types=1);

namespace App\Tui;

/**
 * Usage: Tracks active build sessions in ~/.laracode/sessions.json for the show command dashboard.
 */
class SessionRegistry
{
    private string $registryPath;

    public function __construct(?string $registryPath = null)
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
        $dir = $registryPath ? dirname($registryPath) : $home.'/.laracode';

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->registryPath = $registryPath ?? $dir.'/sessions.json';
    }

    public function register(string $tasksPath, int $pid, string $mode, string $projectPath): void
    {
        $this->withLock(function (array &$data) use ($tasksPath, $pid, $mode, $projectPath) {
            $data['sessions'] = array_values(array_filter(
                $data['sessions'] ?? [],
                fn (array $session) => $session['tasksPath'] !== $tasksPath
            ));

            $data['sessions'][] = [
                'tasksPath' => $tasksPath,
                'pid' => $pid,
                'startedAt' => date('c'),
                'mode' => $mode,
                'projectPath' => $projectPath,
            ];
        });
    }

    public function deregister(string $tasksPath): void
    {
        $this->withLock(function (array &$data) use ($tasksPath) {
            $data['sessions'] = array_values(array_filter(
                $data['sessions'] ?? [],
                fn (array $session) => $session['tasksPath'] !== $tasksPath
            ));
        });
    }

    /**
     * @return array<array{tasksPath: string, pid: int, startedAt: string, mode: string, projectPath: string}>
     */
    public function getActiveSessions(): array
    {
        $data = $this->readRegistry();

        return array_values(array_filter(
            $data['sessions'] ?? [],
            fn (array $session) => $this->isProcessAlive((int) $session['pid'])
        ));
    }

    public function cleanup(): void
    {
        $this->withLock(function (array &$data) {
            $data['sessions'] = array_values(array_filter(
                $data['sessions'] ?? [],
                fn (array $session) => $this->isProcessAlive((int) $session['pid'])
            ));
        });
    }

    public function getRegistryPath(): string
    {
        return $this->registryPath;
    }

    /**
     * @return array{sessions?: array<array{tasksPath: string, pid: int, startedAt: string, mode: string, projectPath: string}>}
     */
    private function readRegistry(): array
    {
        if (! file_exists($this->registryPath)) {
            return ['sessions' => []];
        }

        $contents = file_get_contents($this->registryPath);
        if ($contents === false || $contents === '') {
            return ['sessions' => []];
        }

        $data = json_decode($contents, true);
        if (! is_array($data)) {
            return ['sessions' => []];
        }

        return $data;
    }

    /**
     * @param  callable(array<string, mixed>&): void  $callback
     */
    private function withLock(callable $callback): void
    {
        $handle = fopen($this->registryPath, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return;
            }

            $contents = stream_get_contents($handle);
            $data = ($contents !== false && $contents !== '')
                ? (json_decode($contents, true) ?? ['sessions' => []])
                : ['sessions' => []];

            if (! is_array($data)) {
                $data = ['sessions' => []];
            }

            $callback($data);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (! function_exists('posix_kill')) {
            return file_exists("/proc/$pid");
        }

        return posix_kill($pid, 0);
    }
}
