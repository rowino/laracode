<?php

declare(strict_types=1);

namespace App\Tui\Components;

use App\Tui\Html;

/**
 * Usage: Renders the list of active build sessions for the show command's list view.
 */
class SessionList
{
    /**
     * @param  array<array{tasksPath: string, pid: int, startedAt: string, mode: string, agent: string, projectPath: string, status?: string, completedAt?: string}>  $sessions
     */
    public function render(array $sessions, int $selectedIndex): string
    {
        if ($sessions === []) {
            return <<<'HTML'
                <div class="my-1 px-2 text-gray">No active sessions</div>
            HTML;
        }

        $rows = '';
        foreach ($sessions as $index => $session) {
            $rows .= $this->renderRow($session, $index === $selectedIndex);
        }

        return <<<HTML
            <div class="my-0">
                {$rows}
            </div>
        HTML;
    }

    /**
     * @param  array{tasksPath: string, pid: int, startedAt: string, mode: string, agent: string, projectPath: string, status?: string, completedAt?: string}  $session
     */
    private function renderRow(array $session, bool $selected): string
    {
        $taskData = $this->readTasksFile($session['tasksPath']);

        $selector = $selected ? '<span class="text-cyan">▸</span>' : ' ';
        $rawTitle = Html::escape($taskData['title'] ?? 'Untitled');
        $title = $selected ? "<span class=\"font-bold\">{$rawTitle}</span>" : $rawTitle;

        $tasks = $taskData['tasks'] ?? [];
        $total = count($tasks);
        $completed = count(array_filter($tasks, fn (array $t) => $t['status'] === 'completed'));

        $elapsed = $this->formatElapsed($session['startedAt'], $session['completedAt'] ?? null);

        $path = '<span class="text-gray">'.Html::escape($this->truncateLeft($session['projectPath'], 50)).'</span>';

        $branch = $taskData['branch'] ?? '';
        $branchHtml = ($branch !== '' && $branch !== 'unknown')
            ? ' <span class="text-cyan">('.Html::escape($branch).')</span>'
            : '';

        $activeTaskName = $this->activeTaskName($tasks);
        $registryStatus = $session['status'] ?? 'running';
        $statusLabel = $this->sessionStatus($tasks, $registryStatus, (int) $session['pid']);

        $selectedClass = $selected ? 'text-white' : '';

        $line1 = "{$selector} {$title}";
        $line2 = "  {$path}{$branchHtml}";
        $tasksColor = ($total > 0 && $completed === $total) ? 'text-green' : 'text-yellow';
        $modeLabel = Html::escape($session['mode']);
        $agentLabel = Html::escape($session['agent']);
        $line3 = "  <span class=\"text-gray\">Mode:</span> <span class=\"text-cyan\">{$modeLabel}</span><span class=\"ml-3 text-gray\">Agent:</span> <span class=\"text-cyan\">{$agentLabel}</span><span class=\"ml-3 text-gray\">Tasks:</span> <span class=\"{$tasksColor}\">{$completed}/{$total}</span><span class=\"ml-3 text-gray\">Status:</span> <span>{$statusLabel}</span><span class=\"ml-3 text-gray\">Elapsed:</span> {$elapsed}";

        $html = "<div class=\"{$selectedClass} px-2\">{$line1}</div>"
            ."<div class=\"{$selectedClass} px-2\">{$line2}</div>";

        if ($activeTaskName !== null) {
            $activeLabel = '<span class="text-cyan">'.Html::escape($activeTaskName).'</span>';
            $line4 = "  {$activeLabel}";

            $html .= "<div class=\"{$selectedClass} px-2\">{$line3}</div>"
                ."<div class=\"{$selectedClass} px-2 mb-1\">{$line4}</div>";
        } else {
            $html .= "<div class=\"{$selectedClass} px-2 mb-1\">{$line3}</div>";
        }

        return $html;
    }

    private function truncateLeft(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return '…'.mb_substr($text, -($max - 1));
    }

    private function formatElapsed(string $startedAt, ?string $completedAt = null): string
    {
        $start = strtotime($startedAt);
        if ($start === false) {
            return '<span class="text-yellow">--</span>';
        }

        $end = ($completedAt !== null) ? (strtotime($completedAt) ?: time()) : time();
        $seconds = $end - $start;
        if ($seconds < 0) {
            $seconds = 0;
        }

        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;

        $formatted = $minutes > 0 ? "{$minutes}m{$secs}s" : "{$secs}s";

        return "<span class=\"text-yellow\">{$formatted}</span>";
    }

    /**
     * @param  array<array{id: int, status: string, title?: string}>  $tasks
     */
    private function activeTaskName(array $tasks): ?string
    {
        foreach ($tasks as $task) {
            if ($task['status'] === 'in_progress') {
                return $task['title'] ?? "Task #{$task['id']}";
            }
        }

        return null;
    }

    /**
     * @param  array<array{id: int, status: string, title?: string}>  $tasks
     */
    private function sessionStatus(array $tasks, string $registryStatus, int $pid): string
    {
        if ($registryStatus === 'completed') {
            return '<span class="text-green">complete</span>';
        }

        if (! $this->isProcessAlive($pid)) {
            return '<span class="text-red">crashed</span>';
        }

        foreach ($tasks as $task) {
            if ($task['status'] === 'in_progress') {
                return '<span class="text-cyan">active</span>';
            }
        }

        if ($tasks !== [] && count(array_filter($tasks, fn (array $t) => $t['status'] === 'completed')) === count($tasks)) {
            return '<span class="text-green">complete</span>';
        }

        return '<span class="text-cyan">running</span>';
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

    /**
     * @return array{title?: string, branch?: string, tasks?: array<array{id: int, status: string, title?: string}>}
     */
    private function readTasksFile(string $tasksPath): array
    {
        if (! file_exists($tasksPath)) {
            return [];
        }

        $contents = file_get_contents($tasksPath);
        if ($contents === false || $contents === '') {
            return [];
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : [];
    }
}
