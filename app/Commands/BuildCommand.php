<?php

declare(strict_types=1);

namespace App\Commands;

use App\Enums\BuildMode;
use App\Services\AgentRunner;
use App\Services\Settings\SettingsService;
use App\Services\TaskSelector;
use App\Tui\DashboardRenderer;
use App\Tui\DashboardState;
use App\Tui\SessionRegistry;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\BufferedOutput;

use function Termwind\renderUsing;

class BuildCommand extends Command
{
    protected $signature = 'build
        {path : Path to tasks.json file}
        {--iterations=100 : Maximum iterations}
        {--delay=3 : Delay between tasks in seconds}
        {--mode= : Permission mode: yolo, accept, interactive, plan (defaults to settings)}';

    protected $description = 'Run autonomous build loop from tasks.json';

    public function __construct(
        private AgentRunner $agentRunner,
        private SettingsService $settingsService,
        private DashboardRenderer $renderer,
        private TaskSelector $taskSelector,
        private SessionRegistry $registry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startTime = time();

        /** @var string $tasksPath */
        $tasksPath = $this->argument('path');
        $maxIterations = (int) $this->option('iterations');
        $delay = (int) $this->option('delay');

        if (! file_exists($tasksPath)) {
            $this->error("Tasks file not found: {$tasksPath}");

            return self::FAILURE;
        }

        $realTasksPath = realpath($tasksPath);
        $projectPath = $realTasksPath ? dirname($realTasksPath) : dirname($tasksPath);
        while ($projectPath !== '/' && ! is_dir($projectPath.'/.claude') && ! is_dir($projectPath.'/.laracode')) {
            $projectPath = dirname($projectPath);
        }

        if ($projectPath === '/') {
            $projectPath = $realTasksPath ? dirname($realTasksPath, 3) : dirname($tasksPath, 3);
        }

        $modeStr = $this->resolveModeOption($projectPath);

        $mode = BuildMode::tryFrom($modeStr);
        if ($mode === null) {
            $validModes = implode(', ', array_column(BuildMode::cases(), 'value'));
            $this->error("Invalid mode: {$modeStr}. Valid modes: {$validModes}");

            return self::FAILURE;
        }

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

        $lockPath = dirname($realTasksPath ?: $tasksPath).'/index.lock';
        $canonicalTasksPath = $realTasksPath ?: $tasksPath;

        $this->registry->register($canonicalTasksPath, (int) getmypid(), $mode->value, $projectPath);

        $this->renderDashboard($tasks, 0, $maxIterations, $startTime, null, $mode);

        $this->registerSignalHandlers($lockPath, $canonicalTasksPath);

        $iteration = 0;

        while ($iteration < $maxIterations) {
            $content = file_get_contents($tasksPath);
            if ($content === false) {
                $iteration++;

                continue;
            }
            $decoded = json_decode($content, true);

            if (! is_array($decoded) || ! isset($decoded['tasks'])) {
                $iteration++;

                continue;
            }

            /** @var array{title: string, tasks: array<array{id: int, status: string, description?: string, title?: string, dependencies?: array<int>}>} $decoded */
            $tasks = $decoded;

            $nextTask = $this->taskSelector->selectNextTask($tasks['tasks']);

            if ($nextTask === null) {
                $this->registry->deregister($canonicalTasksPath);
                $this->renderDashboard($tasks, $iteration, $maxIterations, $startTime, null, $mode, 'All tasks completed!');
                $this->captureTermwindOutput(fn () => $this->renderer->renderFinalStats($tasks));

                return self::SUCCESS;
            }

            $iteration++;
            $taskLabel = $nextTask['title'] ?? $nextTask['description'] ?? 'Untitled';
            $this->renderDashboard($tasks, $iteration, $maxIterations, $startTime, $nextTask['id'], $mode, "Task #{$nextTask['id']}: {$taskLabel}");

            $this->runAgent($projectPath, $mode, $tasksPath, $lockPath, $nextTask);

            $completedPath = dirname($realTasksPath ?: $tasksPath).'/completed.json';
            if (file_exists($completedPath)) {
                $completionContent = file_get_contents($completedPath);
                if ($completionContent !== false) {
                    $completion = json_decode($completionContent, true);
                    if (is_array($completion) && isset($completion['taskId'], $completion['startedAt'], $completion['completedAt'])) {
                        $gitStats = $this->getGitStats($projectPath);
                        $this->updateTaskStats(
                            $tasksPath,
                            (int) $completion['taskId'],
                            (string) $completion['startedAt'],
                            (string) $completion['completedAt'],
                            $gitStats
                        );
                    }
                }
                @unlink($completedPath);
            }

            $content = file_get_contents($tasksPath);
            if ($content !== false) {
                /** @var array{title: string, tasks: array<array{id: int, status: string, description?: string, title?: string, dependencies?: array<int>}>} $tasks */
                $tasks = json_decode($content, true);
                $this->renderDashboard($tasks, $iteration, $maxIterations, $startTime, null, $mode);
            }

            $pending = array_filter($tasks['tasks'], fn ($t) => $t['status'] === 'pending');
            if (! empty($pending) && $iteration < $maxIterations) {
                sleep($delay);
            }
        }

        $this->registry->deregister($canonicalTasksPath);
        $this->renderDashboard($tasks, $iteration, $maxIterations, $startTime, null, $mode, "Reached max iterations ({$maxIterations})");

        return self::SUCCESS;
    }

    private function resolveModeOption(string $projectPath): string
    {
        /** @var string|null $cliMode */
        $cliMode = $this->option('mode');

        if ($cliMode !== null && $cliMode !== '') {
            return $cliMode;
        }

        $this->settingsService->setProjectPath($projectPath);
        /** @var string|null $defaultMode */
        $defaultMode = $this->settingsService->get('defaultMode');

        return $defaultMode ?? 'interactive';
    }

    /**
     * @param  array{id: int, status: string, description?: string, title?: string}  $currentTask
     */
    private function runAgent(string $projectPath, BuildMode $mode, string $tasksPath, string $lockPath, array $currentTask): void
    {
        $prompt = "/build-next $tasksPath";

        $process = $this->agentRunner->run(
            $mode,
            $prompt,
            $projectPath,
            $lockPath,
            null,
            [
                'tasksPath' => $tasksPath,
                'currentTask' => [
                    'id' => $currentTask['id'],
                    'title' => $currentTask['title'] ?? $currentTask['description'] ?? 'Untitled',
                ],
            ]
        );

        if ($process === false) {
            $this->error('Failed to spawn agent process');

            return;
        }

        $this->agentRunner->monitor($process, $lockPath, function (int $pid) use ($process): void {
            $this->agentRunner->terminate($process, $pid);
        });

        @unlink($lockPath);
        $this->restoreTerminal();
    }

    private function restoreTerminal(): void
    {
        if (defined('STDOUT') && function_exists('posix_isatty') && posix_isatty(STDOUT)) {
            echo "\e[?25h";
            echo "\e[?1004l";
            system('stty sane 2>/dev/null');
        }
    }

    private function registerSignalHandlers(string $lockPath, string $tasksPath): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $cleanup = function () use ($lockPath, $tasksPath): void {
            $this->registry->deregister($tasksPath);
            @unlink($lockPath);
            $this->restoreTerminal();
            exit(130);
        };

        pcntl_signal(SIGINT, $cleanup);
        pcntl_signal(SIGTERM, $cleanup);
        pcntl_async_signals(true);
    }

    /**
     * @param  array<string, mixed>  $tasks
     */
    private function renderDashboard(
        array $tasks,
        int $iteration,
        int $maxIterations,
        int $startTime,
        ?int $activeTaskId,
        BuildMode $mode,
        string $statusMessage = '',
    ): void {
        if ($statusMessage === '') {
            $statusMessage = $this->buildStatusMessage($tasks['tasks'] ?? []);
        }

        $state = DashboardState::fromTasksArray(
            $tasks,
            $iteration,
            $maxIterations,
            time() - $startTime,
            $activeTaskId,
            $mode->value,
            $statusMessage,
        );

        $this->captureTermwindOutput(fn () => $this->renderer->render($state));
    }

    private function captureTermwindOutput(callable $callback): void
    {
        $buffer = new BufferedOutput;
        renderUsing($buffer);
        $callback();
        $captured = $buffer->fetch();
        renderUsing(null);

        foreach (explode("\n", $captured) as $line) {
            if ($line !== '') {
                $this->line($line);
            }
        }
    }

    /**
     * @param  array<array{id: int, status: string, dependencies?: array<int>}>  $taskList
     */
    private function buildStatusMessage(array $taskList): string
    {
        $total = count($taskList);
        $completed = count(array_filter($taskList, fn ($t) => $t['status'] === 'completed'));
        $pending = count(array_filter($taskList, fn ($t) => $t['status'] === 'pending'));
        $blocked = $this->taskSelector->countBlockedTasks($taskList);

        $parts = ["{$completed}/{$total} completed"];
        if ($pending > 0) {
            $parts[] = "{$pending} pending";
        }
        if ($blocked > 0) {
            $parts[] = "{$blocked} blocked";
        }

        return implode(' | ', $parts);
    }

    /**
     * @return array{filesChanged: int, linesAdded: int, linesRemoved: int}
     */
    private function getGitStats(string $projectPath): array
    {
        $process = proc_open(
            ['git', 'diff', '--stat', 'HEAD~1'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $projectPath
        );

        if (! is_resource($process)) {
            return ['filesChanged' => 0, 'linesAdded' => 0, 'linesRemoved' => 0];
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        preg_match('/(\d+) files? changed/', $output ?: '', $files);
        preg_match('/(\d+) insertions?\(\+\)/', $output ?: '', $added);
        preg_match('/(\d+) deletions?\(-\)/', $output ?: '', $removed);

        return [
            'filesChanged' => (int) ($files[1] ?? 0),
            'linesAdded' => (int) ($added[1] ?? 0),
            'linesRemoved' => (int) ($removed[1] ?? 0),
        ];
    }

    /**
     * @param  array{filesChanged: int, linesAdded: int, linesRemoved: int}  $gitStats
     */
    private function updateTaskStats(
        string $tasksPath,
        int $taskId,
        string $startedAt,
        string $completedAt,
        array $gitStats
    ): void {
        $content = file_get_contents($tasksPath);
        if ($content === false) {
            return;
        }

        $tasks = json_decode($content, true);
        if (! is_array($tasks)) {
            return;
        }

        $start = strtotime($startedAt);
        $end = strtotime($completedAt);
        $durationSeconds = ($start !== false && $end !== false) ? max(0, $end - $start) : 0;

        foreach ($tasks['tasks'] as &$task) {
            if ($task['id'] === $taskId) {
                $task['stats'] = [
                    'startedAt' => $startedAt,
                    'completedAt' => $completedAt,
                    'durationSeconds' => $durationSeconds,
                    'filesChanged' => $gitStats['filesChanged'],
                    'linesAdded' => $gitStats['linesAdded'],
                    'linesRemoved' => $gitStats['linesRemoved'],
                ];
                break;
            }
        }
        unset($task);

        $tasks['stats'] = $tasks['stats'] ?? [];
        $tasks['stats']['filesChanged'] = ($tasks['stats']['filesChanged'] ?? 0) + $gitStats['filesChanged'];
        $tasks['stats']['linesAdded'] = ($tasks['stats']['linesAdded'] ?? 0) + $gitStats['linesAdded'];
        $tasks['stats']['linesRemoved'] = ($tasks['stats']['linesRemoved'] ?? 0) + $gitStats['linesRemoved'];

        file_put_contents($tasksPath, json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
