<?php

declare(strict_types=1);

namespace App\Tui\Components;

/**
 * Usage: Renders the list of active build sessions for the show command's list view.
 */
class SessionList
{
    /**
     * @param  array<array{tasksPath: string, pid: int, startedAt: string, mode: string, projectPath: string}>  $sessions
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
     * @param  array{tasksPath: string, pid: int, startedAt: string, mode: string, projectPath: string}  $session
     */
    private function renderRow(array $session, bool $selected): string
    {
        $taskData = $this->readTasksFile($session['tasksPath']);

        $selector = $selected ? '<span class="text-cyan-400">▸</span>' : ' ';
        $rawTitle = htmlspecialchars($taskData['title'] ?? 'Untitled');
        $title = $selected ? "<span class=\"font-bold\">{$rawTitle}</span>" : $rawTitle;

        $tasks = $taskData['tasks'] ?? [];
        $total = count($tasks);
        $completed = count(array_filter($tasks, fn (array $t) => $t['status'] === 'completed'));

        $elapsed = $this->formatElapsed($session['startedAt']);

        $path = '<span class="text-gray">'.htmlspecialchars($this->truncateLeft($session['projectPath'], 50)).'</span>';

        $branch = $taskData['branch'] ?? '';
        $branchHtml = ($branch !== '' && $branch !== 'unknown')
            ? ' <span class="text-cyan-400">('.htmlspecialchars($branch).')</span>'
            : '';

        $activeTaskName = $this->activeTaskName($tasks);
        $statusLabel = $this->sessionStatus($tasks);

        $selectedClass = $selected ? 'text-white' : '';

        $line1 = "{$selector} {$title}";
        $line2 = "  {$path}{$branchHtml}";
        $tasksColor = ($total > 0 && $completed === $total) ? 'text-green-400' : 'text-yellow-400';
        $line3 = "  <span class=\"text-gray\">Tasks:</span> <span class=\"{$tasksColor}\">{$completed}/{$total}</span><span class=\"ml-5 text-gray\">Status:</span> <span>{$statusLabel}</span><span class=\"ml-5 text-gray\">Elapsed:</span> {$elapsed}";

        $html = "<div class=\"{$selectedClass} px-2\">{$line1}</div>"
            ."<div class=\"{$selectedClass} px-2\">{$line2}</div>";

        if ($activeTaskName !== null) {
            $activeLabel = '<span class="text-cyan-400">'.htmlspecialchars($activeTaskName).'</span>';
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

    private function formatElapsed(string $startedAt): string
    {
        $start = strtotime($startedAt);
        if ($start === false) {
            return '<span class="text-yellow-400">--</span>';
        }

        $seconds = time() - $start;
        if ($seconds < 0) {
            $seconds = 0;
        }

        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;

        $formatted = $minutes > 0 ? "{$minutes}m{$secs}s" : "{$secs}s";

        return "<span class=\"text-yellow-400\">{$formatted}</span>";
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
    private function sessionStatus(array $tasks): string
    {
        foreach ($tasks as $task) {
            if ($task['status'] === 'in_progress') {
                return '<span class="text-cyan-400">active</span>';
            }
        }

        if ($tasks !== [] && count(array_filter($tasks, fn (array $t) => $t['status'] === 'completed')) === count($tasks)) {
            return '<span class="text-green-400">complete</span>';
        }

        return '<span class="text-cyan-400">running</span>';
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
