<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

/**
 * Usage: Handles notifications and signals from Claude during watch mode.
 * Actions: build (complete task), plan-ready (plan approval), tasks-ready (start build),
 *          watch-complete (log completion), error (display error).
 */
class NotifyCommand extends Command
{
    protected $signature = 'notify
        {action : Action type: build, plan-ready, tasks-ready, watch-complete, error}
        {data? : Action-specific data (lock path for build, json for others)}
        {--mode=interactive : Mode: interactive (prompt) or yolo (auto-continue)}
        {--message= : Optional message to display}
        {--task= : Completed task ID (for build action)}';

    protected $description = 'Handle notifications and signals during watch/build operations';

    public function handle(): int
    {
        /** @var string $action */
        $action = $this->argument('action');
        /** @var string|null $data */
        $data = $this->argument('data');

        return match ($action) {
            'build' => $this->handleBuild($data),
            'plan-ready' => $this->handlePlanReady($data),
            'tasks-ready' => $this->handleTasksReady($data),
            'watch-complete' => $this->handleWatchComplete($data),
            'error' => $this->handleError($data),
            default => $this->handleUnknownAction($action),
        };
    }

    private function handleBuild(?string $lockPath): int
    {
        if ($lockPath === null || $lockPath === '') {
            $this->error('Lock path is required for build action');

            return self::FAILURE;
        }

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

        // Write completion signal file if task ID provided
        $taskId = $this->option('task');
        if ($taskId !== null) {
            $completedPath = dirname($lockPath).'/completed.json';
            file_put_contents($completedPath, json_encode([
                'taskId' => (int) $taskId,
                'startedAt' => $lock['started'] ?? date('c'),
                'completedAt' => date('c'),
            ], JSON_PRETTY_PRINT));
        }

        if (! @unlink($lockPath)) {
            $this->warn("Could not delete lock file: {$lockPath}");
        }

        $this->info('✓ Build signal processed and lock file cleaned up');

        return self::SUCCESS;
    }

    private function handlePlanReady(?string $data): int
    {
        /** @var string $mode */
        $mode = $this->option('mode');
        /** @var string|null $message */
        $message = $this->option('message');

        if ($message) {
            $this->info($message);
        }

        if ($mode === 'yolo') {
            $this->info('✓ Plan ready - auto-continuing in yolo mode');

            return self::SUCCESS;
        }

        // Interactive mode - prompt for approval
        $this->newLine();
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('Plan Ready for Review');

        if ($data) {
            $planData = json_decode($data, true);
            if (is_array($planData) && isset($planData['planPath'])) {
                $this->line("Plan file: <comment>{$planData['planPath']}</comment>");
            }
        }

        $this->newLine();

        if (! $this->confirm('Approve this plan and continue?', true)) {
            $this->warn('Plan rejected. Watch will continue waiting for changes.');

            return self::FAILURE;
        }

        $this->info('✓ Plan approved - continuing to task generation');

        return self::SUCCESS;
    }

    private function handleTasksReady(?string $data): int
    {
        /** @var string $mode */
        $mode = $this->option('mode');
        /** @var string|null $message */
        $message = $this->option('message');

        if ($message) {
            $this->info($message);
        }

        if ($mode === 'yolo') {
            $this->info('✓ Tasks ready - auto-starting build in yolo mode');

            return self::SUCCESS;
        }

        // Interactive mode - offer to start build
        $this->newLine();
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('Tasks Generated and Ready');

        if ($data) {
            $tasksData = json_decode($data, true);
            if (is_array($tasksData)) {
                if (isset($tasksData['tasksPath'])) {
                    $this->line("Tasks file: <comment>{$tasksData['tasksPath']}</comment>");
                }
                if (isset($tasksData['taskCount'])) {
                    $this->line("Total tasks: <comment>{$tasksData['taskCount']}</comment>");
                }
            }
        }

        $this->newLine();

        if (! $this->confirm('Start the build loop now?', true)) {
            $this->warn('Build skipped. Watch will continue waiting for changes.');

            return self::FAILURE;
        }

        $this->info('✓ Starting build loop');

        return self::SUCCESS;
    }

    private function handleWatchComplete(?string $data): int
    {
        /** @var string|null $message */
        $message = $this->option('message');

        $this->newLine();
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('✓ Watch Cycle Complete');

        if ($message) {
            $this->line($message);
        }

        if ($data) {
            $completionData = json_decode($data, true);
            if (is_array($completionData)) {
                if (isset($completionData['filesProcessed'])) {
                    $this->line("Files processed: <comment>{$completionData['filesProcessed']}</comment>");
                }
                if (isset($completionData['commentsFound'])) {
                    $this->line("Comments found: <comment>{$completionData['commentsFound']}</comment>");
                }
                if (isset($completionData['tasksCompleted'])) {
                    $this->line("Tasks completed: <info>{$completionData['tasksCompleted']}</info>");
                }
            }
        }

        $this->newLine();
        $this->line('Continuing to watch for changes...');

        return self::SUCCESS;
    }

    private function handleError(?string $data): int
    {
        /** @var string|null $message */
        $message = $this->option('message');

        $this->newLine();
        $this->error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->error('Error During Watch/Build');

        if ($message) {
            $this->error($message);
        }

        if ($data) {
            $errorData = json_decode($data, true);
            if (is_array($errorData)) {
                if (isset($errorData['error'])) {
                    $this->error("Error: {$errorData['error']}");
                }
                if (isset($errorData['file'])) {
                    $this->line("File: <comment>{$errorData['file']}</comment>");
                }
                if (isset($errorData['line'])) {
                    $this->line("Line: <comment>{$errorData['line']}</comment>");
                }
            } else {
                $this->error($data);
            }
        }

        return self::FAILURE;
    }

    private function handleUnknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('Valid actions: build, plan-ready, tasks-ready, watch-complete, error');

        return self::FAILURE;
    }
}
