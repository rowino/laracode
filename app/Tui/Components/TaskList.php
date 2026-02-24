<?php

declare(strict_types=1);

namespace App\Tui\Components;

use App\Tui\DashboardState;

class TaskList
{
    public function render(DashboardState $state): string
    {
        $completedIds = [];
        foreach ($state->tasks as $task) {
            if ($task['status'] === 'completed') {
                $completedIds[$task['id']] = true;
            }
        }

        $rows = '';
        foreach ($state->tasks as $task) {
            $isActive = $task['id'] === $state->activeTaskId;
            $icon = $this->statusIcon($task, $completedIds);
            $idLabel = '<span class="text-gray">US-'.$task['id'].'</span>';
            $titleText = htmlspecialchars($task['title'] ?? $task['description'] ?? 'Untitled');
            $titleClass = $this->titleColorClass($task, $completedIds);
            $titleSpan = "<span class=\"{$titleClass}\">{$titleText}</span>";
            $label = "{$idLabel} {$titleSpan}";

            if ($isActive) {
                $rows .= "<div class=\"text-white px-2\">{$icon} {$label}</div>";
            } else {
                $rows .= "<div class=\"px-2\">{$icon} {$label}</div>";
            }
        }

        return <<<HTML
            <div>
                {$rows}
            </div>
        HTML;
    }

    /**
     * @param  array{id: int, status: string, dependencies?: array<int>}  $task
     * @param  array<int, true>  $completedIds
     */
    private function statusIcon(array $task, array $completedIds): string
    {
        if ($task['id'] === 0) {
            // won't happen, but satisfies static analysis
            return '<span class="text-gray">○</span>';
        }

        return match ($task['status']) {
            'completed' => '<span class="text-green-400">●</span>',
            'in_progress' => '<span class="text-cyan-400">●</span>',
            'pending' => $this->isBlocked($task, $completedIds)
                ? '<span class="text-red-400">○</span>'
                : '<span class="text-gray">○</span>',
            default => '<span class="text-gray">○</span>',
        };
    }

    /**
     * @param  array{id: int, status: string, dependencies?: array<int>}  $task
     * @param  array<int, true>  $completedIds
     */
    private function titleColorClass(array $task, array $completedIds): string
    {
        return match ($task['status']) {
            'completed' => 'text-green-400',
            'in_progress' => 'text-cyan-400 font-bold',
            'pending' => $this->isBlocked($task, $completedIds) ? 'text-red-400' : '',
            default => '',
        };
    }

    /**
     * @param  array{id: int, status: string, dependencies?: array<int>}  $task
     * @param  array<int, true>  $completedIds
     */
    private function isBlocked(array $task, array $completedIds): bool
    {
        $dependencies = $task['dependencies'] ?? [];

        if (empty($dependencies)) {
            return false;
        }

        foreach ($dependencies as $depId) {
            if (! isset($completedIds[$depId])) {
                return true;
            }
        }

        return false;
    }
}
