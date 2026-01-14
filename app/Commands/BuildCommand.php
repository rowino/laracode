<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class BuildCommand extends Command
{
    protected $signature = 'build
        {path : Path to tasks.json file}
        {--iterations=100 : Maximum iterations}
        {--delay=3 : Delay between tasks in seconds}
        {--mode=yolo : Permission mode: yolo (auto-approve), accept (interactive), default}';

    protected $description = 'Run autonomous build loop from tasks.json';

    public function handle(): int
    {
        /** @var string $tasksPath */
        $tasksPath = $this->argument('path');
        $maxIterations = (int) $this->option('iterations');
        $delay = (int) $this->option('delay');
        /** @var string $mode */
        $mode = $this->option('mode');

        if (! in_array($mode, ['yolo', 'accept', 'default'], true)) {
            $this->error("Invalid mode: {$mode}. Valid modes: yolo, accept, default");

            return self::FAILURE;
        }

        // Validate tasks file exists
        if (! file_exists($tasksPath)) {
            $this->error("Tasks file not found: {$tasksPath}");

            return self::FAILURE;
        }

        // Parse and validate JSON
        $content = file_get_contents($tasksPath);
        if ($content === false) {
            $this->error("Cannot read tasks file: {$tasksPath}");

            return self::FAILURE;
        }

        $tasks = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($tasks)) {
            $this->error('Invalid JSON in tasks file: '.json_last_error_msg());

            return self::FAILURE;
        }

        if (! isset($tasks['tasks']) || ! is_array($tasks['tasks'])) {
            $this->error("Tasks file must contain a 'tasks' array");

            return self::FAILURE;
        }

        // Determine project path (go up from .laracode/specs/feature/tasks.json)
        $realTasksPath = realpath($tasksPath);
        $projectPath = $realTasksPath ? dirname($realTasksPath) : dirname($tasksPath);
        // Try to find project root by looking for .claude or .laracode directory
        while ($projectPath !== '/' && ! is_dir($projectPath.'/.claude') && ! is_dir($projectPath.'/.laracode')) {
            $projectPath = dirname($projectPath);
        }

        if ($projectPath === '/') {
            $projectPath = $realTasksPath ? dirname($realTasksPath, 3) : dirname($tasksPath, 3);
        }

        // Compute lock path next to tasks.json
        $lockPath = dirname($realTasksPath ?: $tasksPath).'/index.lock';

        $this->info("Project path: {$projectPath}");
        $this->displayStats($tasks);

        $iteration = 0;

        while ($iteration < $maxIterations) {
            // Reload tasks on each iteration (Claude may have updated them)
            $content = file_get_contents($tasksPath);
            if ($content === false) {
                continue;
            }
            /** @var array{title: string, tasks: array<array{id: int, status: string, description?: string, title?: string, dependencies?: array<int>}>} $tasks */
            $tasks = json_decode($content, true);

            // Find pending tasks
            $pending = array_filter($tasks['tasks'], fn ($t) => $t['status'] === 'pending');

            if (empty($pending)) {
                $this->newLine();
                $this->info('✓ All tasks completed!');

                return self::SUCCESS;
            }

            $iteration++;
            $nextTask = reset($pending);

            $this->newLine();
            $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info("Iteration {$iteration}/{$maxIterations}");
            $taskLabel = $nextTask['title'] ?? $nextTask['description'] ?? 'Untitled';
            $this->line("<comment>Next Task:</comment> #{$nextTask['id']} - {$taskLabel}");

            $this->runClaude($projectPath, $mode, $tasksPath, $lockPath);

            // Reload and display stats
            $content = file_get_contents($tasksPath);
            if ($content !== false) {
                /** @var array{title: string, tasks: array<array{id: int, status: string, description?: string, title?: string, dependencies?: array<int>}>} $tasks */
                $tasks = json_decode($content, true);
                $this->displayStats($tasks);
            }

            // Check if there are still pending tasks
            $pending = array_filter($tasks['tasks'], fn ($t) => $t['status'] === 'pending');
            if (! empty($pending) && $iteration < $maxIterations) {
                $this->info("Sleeping {$delay}s before next task...");
                sleep($delay);
            }
        }

        $this->newLine();
        $this->warn("Reached max iterations ({$maxIterations})");
        $this->displayStats($tasks);

        return self::SUCCESS;
    }

    private function runClaude(string $projectPath, string $mode, string $tasksPath, string $lockPath): void
    {
        $command = ['claude'];

        if ($mode === 'yolo') {
            $this->line('<comment>Mode:</comment> yolo (auto-approve all)');
            $command[] = '--dangerously-skip-permissions';
        } elseif ($mode === 'accept') {
            $this->line('<comment>Mode:</comment> accept (acceptEdits)');
            $command = array_merge($command, ['--permission-mode', 'acceptEdits']);
        } else {
            $this->line('<comment>Mode:</comment> default');
        }

        $command[] = "'/build-next $tasksPath'";
        $this->line('Running: '.implode(' ', $command));

        $this->newLine();

        $descriptorspec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open(
            implode(' ', $command),
            $descriptorspec,
            $pipes,
            $projectPath,
            null
        );

        if (! is_resource($process)) {
            return;
        }

        // Get PID and write lock file
        $status = proc_get_status($process);
        $pid = $status['pid'];
        file_put_contents($lockPath, json_encode(['pid' => $pid, 'started' => date('c')], JSON_PRETTY_PRINT));

        // Monitor for lock file deletion OR process exit
        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            // Check if lock file was deleted (by StopCommand)
            if (! file_exists($lockPath)) {
                $this->newLine();
                $this->line('<comment>Lock file removed, terminating Claude...</comment>');

                // Send SIGTERM to Claude
                posix_kill($pid, SIGTERM);

                // Wait for graceful shutdown
                usleep(500000);

                // Force kill if still running
                $status = proc_get_status($process);
                if ($status['running']) {
                    posix_kill($pid, SIGKILL);
                }

                break;
            }

            usleep(100000); // 100ms
        }

        proc_close($process);

        // Clean up lock file if it still exists
        @unlink($lockPath);
    }

    /**
     * @param  array<string, mixed>  $tasks
     */
    private function displayStats(array $tasks): void
    {
        $total = count($tasks['tasks']);
        $completed = count(array_filter($tasks['tasks'], fn ($t) => $t['status'] === 'completed'));
        $pending = count(array_filter($tasks['tasks'], fn ($t) => $t['status'] === 'pending'));
        $blocked = $this->countBlockedTasks($tasks['tasks']);
        $percentage = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        $barLength = 20;
        $filled = (int) round($barLength * $percentage / 100);
        $bar = str_repeat('█', $filled).str_repeat('░', $barLength - $filled);

        $featureName = $tasks['title'] ?? $tasks['feature'] ?? 'Unknown';

        $this->newLine();
        $this->line("Feature: <info>{$featureName}</info>");

        if (! empty($tasks['branch'])) {
            $this->line("Branch:  <comment>{$tasks['branch']}</comment>");
        }

        if (! empty($tasks['created'])) {
            $createdDate = date('Y-m-d H:i', strtotime($tasks['created']));
            $this->line("Created: {$createdDate}");
        }

        $this->line("Progress: [{$bar}] {$percentage}%");

        $statsLine = "Tasks: <info>{$completed}</info>/{$total} completed | <comment>{$pending}</comment> pending";
        if ($blocked > 0) {
            $statsLine .= " | <fg=red>{$blocked}</> blocked";
        }
        $this->line($statsLine);
    }

    /**
     * @param  array<array{id: int, status: string, dependencies?: array<int>}>  $taskList
     */
    private function countBlockedTasks(array $taskList): int
    {
        $completedIds = [];
        foreach ($taskList as $task) {
            if ($task['status'] === 'completed') {
                $completedIds[$task['id']] = true;
            }
        }

        $blocked = 0;
        foreach ($taskList as $task) {
            if ($task['status'] !== 'pending') {
                continue;
            }

            $dependencies = $task['dependencies'] ?? [];
            if (empty($dependencies)) {
                continue;
            }

            foreach ($dependencies as $depId) {
                if (! isset($completedIds[$depId])) {
                    $blocked++;
                    break;
                }
            }
        }

        return $blocked;
    }
}
