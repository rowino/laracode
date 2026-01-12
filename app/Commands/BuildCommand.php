<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class BuildCommand extends Command
{
    protected $signature = 'build
        {path : Path to tasks.json file}
        {--iterations=100 : Maximum iterations}
        {--delay=3 : Delay between tasks in seconds}';

    protected $description = 'Run autonomous build loop from tasks.json';

    public function handle(): int
    {
        $tasksPath = $this->argument('path');
        $maxIterations = (int) $this->option('iterations');
        $delay = (int) $this->option('delay');

        // Validate tasks file exists
        if (! file_exists($tasksPath)) {
            $this->error("Tasks file not found: {$tasksPath}");

            return self::FAILURE;
        }

        // Parse and validate JSON
        $content = file_get_contents($tasksPath);
        $tasks = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON in tasks file: '.json_last_error_msg());

            return self::FAILURE;
        }

        if (! isset($tasks['tasks']) || ! is_array($tasks['tasks'])) {
            $this->error("Tasks file must contain a 'tasks' array");

            return self::FAILURE;
        }

        // Determine project path (go up from .laracode/specs/feature/tasks.json)
        $projectPath = dirname(realpath($tasksPath));
        // Try to find project root by looking for .claude or .laracode directory
        while ($projectPath !== '/' && ! is_dir($projectPath.'/.claude') && ! is_dir($projectPath.'/.laracode')) {
            $projectPath = dirname($projectPath);
        }

        if ($projectPath === '/') {
            $projectPath = dirname(realpath($tasksPath), 3); // Fallback: go up 3 levels
        }

        $this->info("Project path: {$projectPath}");
        $this->displayStats($tasks);

        $iteration = 0;

        while ($iteration < $maxIterations) {
            // Reload tasks on each iteration (Claude may have updated them)
            $content = file_get_contents($tasksPath);
            $tasks = json_decode($content, true);

            // Find pending tasks
            $pending = array_filter($tasks['tasks'], fn ($t) => $t['status'] === 'pending');

            if (empty($pending)) {
                $this->newLine();
                $this->info('✓ All tasks completed!');

                return self::SUCCESS;
            }

            $iteration++;
            $this->newLine();
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("Iteration {$iteration}/{$maxIterations}");

            // Run Claude Code with /build-next command
            $this->line('Running: claude /build-next');

            $result = Process::path($projectPath)
                ->timeout(600)
                ->run([
                    'claude',
                    '--print',
                    '--permission-mode', 'default',
                    '--dangerously-skip-permissions',
                    '/build-next',
                ]);

            if ($result->successful()) {
                $this->line($result->output());
            } else {
                $this->error('Claude exited with error:');
                $this->line($result->errorOutput() ?: $result->output());
            }

            // Reload and display stats
            $content = file_get_contents($tasksPath);
            $tasks = json_decode($content, true);
            $this->displayStats($tasks);

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

    private function displayStats(array $tasks): void
    {
        $total = count($tasks['tasks']);
        $completed = count(array_filter($tasks['tasks'], fn ($t) => $t['status'] === 'completed'));
        $pending = count(array_filter($tasks['tasks'], fn ($t) => $t['status'] === 'pending'));
        $percentage = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        $barLength = 20;
        $filled = (int) round($barLength * $percentage / 100);
        $bar = str_repeat('█', $filled).str_repeat('░', $barLength - $filled);

        $this->newLine();
        $this->line("Feature: <info>{$tasks['feature']}</info>");
        $this->line("Progress: [{$bar}] {$percentage}%");
        $this->line("Tasks: <info>{$completed}</info>/{$total} completed | <comment>{$pending}</comment> pending");
    }
}
