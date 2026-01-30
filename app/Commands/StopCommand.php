<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

/**
 * Usage: Backward-compatible wrapper for NotifyCommand build action.
 *
 * @deprecated Use `laracode notify build` instead.
 */
class StopCommand extends Command
{
    protected $signature = 'stop {path : Path to index.lock file} {--task= : Completed task ID}';

    protected $description = '[DEPRECATED] Stop the current Claude process (use "notify build" instead)';

    public function handle(): int
    {
        $this->warn('⚠ "laracode stop" is deprecated. Use "laracode notify build" instead.');

        /** @var string $lockPath */
        $lockPath = $this->argument('path');

        $arguments = [
            'action' => 'build',
            'data' => $lockPath,
        ];

        $options = [];

        $taskId = $this->option('task');
        if ($taskId !== null) {
            $options['--task'] = $taskId;
        }

        return $this->call('notify', array_merge($arguments, $options));
    }
}
