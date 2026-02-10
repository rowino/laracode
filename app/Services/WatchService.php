<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BuildMode;

/**
 * Usage: Coordinates file watching, comment processing, and orchestration logic.
 */
class WatchService
{
    private const DEFAULT_NOTIFICATION_TIMEOUT = 3600; // 1 hour

    private const POLL_INTERVAL_MS = 100000; // 100ms

    /**
     * @param  array{comments: array<array{file: string, line: int, text: string}>, metadata: array{stopWordFound: bool, stopWordFile: string|null, filesScanned: int, timestamp: string}}  $comments
     */
    public function createCommentsJson(array $comments, string $outputPath): void
    {
        $json = json_encode($comments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode comments to JSON: '.json_last_error_msg());
        }

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_put_contents($outputPath, $json) === false) {
            throw new \RuntimeException("Failed to write comments file: {$outputPath}");
        }
    }

    /**
     * @return array{status: string, action?: string, data?: mixed}
     */
    public function waitForNotification(string $notificationFile, int $timeout = self::DEFAULT_NOTIFICATION_TIMEOUT): array
    {
        $startTime = time();

        while ((time() - $startTime) < $timeout) {
            if (file_exists($notificationFile)) {
                $content = file_get_contents($notificationFile);
                if ($content !== false) {
                    $notification = json_decode($content, true);
                    @unlink($notificationFile);

                    if (is_array($notification) && isset($notification['status']) && is_string($notification['status'])) {
                        /** @var array{status: string, action?: string, data?: mixed} $notification */
                        return $notification;
                    }
                }
            }

            usleep(self::POLL_INTERVAL_MS);
        }

        return ['status' => 'timeout'];
    }

    /**
     * @param  resource  $process
     */
    public function monitorProcess(mixed $process, string $lockPath, callable $onLockRemoved): void
    {
        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            if (! file_exists($lockPath)) {
                $onLockRemoved($status['pid']);
                break;
            }

            usleep(self::POLL_INTERVAL_MS);
        }
    }

    /**
     * @param  resource  $process
     */
    public function terminateProcess(mixed $process, int $pid): void
    {
        posix_kill($pid, SIGTERM);
        usleep(500000); // 500ms grace period

        $status = proc_get_status($process);
        if ($status['running']) {
            posix_kill($pid, SIGKILL);
        }

        proc_close($process);
        $this->restoreTerminal();
    }

    private function restoreTerminal(): void
    {
        if (defined('STDOUT') && function_exists('posix_isatty') && posix_isatty(STDOUT)) {
            echo "\e[?25h";    // Show cursor
            echo "\e[?1004l"; // Disable focus reporting
            system('stty sane 2>/dev/null');
        }
    }

    public function cleanupLockFile(string $lockPath): void
    {
        @unlink($lockPath);
    }

    /**
     * @return array{pid: int, started: string, mode?: string}|null
     */
    public function readLockFile(string $lockPath): ?array
    {
        if (! file_exists($lockPath)) {
            return null;
        }

        $content = file_get_contents($lockPath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (! is_array($data) || ! isset($data['pid'], $data['started'])) {
            return null;
        }

        return $data;
    }

    public function buildClaudePrompt(BuildMode $mode): string
    {
        return "Process @claude comments from changed files.\nMode: {$mode->description()}";
    }

    /**
     * @param  array{comments: array<array{file: string, line: int, text: string}>, metadata: array{stopWordFound: bool, stopWordFile: string|null, filesScanned: int, timestamp: string}}  $comments
     * @return array<string, array<array{line: int, text: string}>>
     */
    public function groupCommentsByFile(array $comments): array
    {
        $grouped = [];

        foreach ($comments['comments'] as $comment) {
            $file = $comment['file'];
            if (! isset($grouped[$file])) {
                $grouped[$file] = [];
            }
            $grouped[$file][] = [
                'line' => $comment['line'],
                'text' => $comment['text'],
            ];
        }

        return $grouped;
    }
}
