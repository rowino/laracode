<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class StopCommand extends Command
{
    protected $signature = 'stop {path : Path to index.lock file}';

    protected $description = 'Stop the current Claude process and signal build loop to continue';

    public function handle(): int
    {
        /** @var string $lockPath */
        $lockPath = $this->argument('path');

        if (! file_exists($lockPath)) {
            $this->error("Lock file not found: {$lockPath}");

            return self::FAILURE;
        }

        $content = file_get_contents($lockPath);
        if ($content === false) {
            $this->error("Cannot read lock file: {$lockPath}");

            return self::FAILURE;
        }

        $lock = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($lock)) {
            $this->error('Invalid JSON in lock file: '.json_last_error_msg());

            return self::FAILURE;
        }

        if (! isset($lock['pid']) || ! is_int($lock['pid'])) {
            $this->error("Lock file missing 'pid' field");

            return self::FAILURE;
        }

        $pid = $lock['pid'];

        if (posix_kill($pid, 0)) {
            $this->line("Sending SIGTERM to process {$pid}...");
            posix_kill($pid, SIGTERM);

            usleep(500000);

            // @phpstan-ignore if.alwaysTrue (process may or may not have terminated from SIGTERM)
            if (posix_kill($pid, 0)) {
                $this->line('Process still running, sending SIGKILL...');
                posix_kill($pid, SIGKILL);
            }
        } else {
            $this->line("Process {$pid} is not running");
        }

        if (! @unlink($lockPath)) {
            $this->warn("Could not delete lock file: {$lockPath}");
        }

        $this->info('✓ Stopped build and cleaned up lock file');

        return self::SUCCESS;
    }
}
