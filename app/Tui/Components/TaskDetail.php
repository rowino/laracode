<?php

declare(strict_types=1);

namespace App\Tui\Components;

use App\Tui\DashboardState;

class TaskDetail
{
    public function render(DashboardState $state): string
    {
        $activeTask = $state->activeTask();

        if ($activeTask === null) {
            return '<div class="px-2 text-gray italic">No task in progress</div>';
        }

        $title = htmlspecialchars($activeTask['title'] ?? $activeTask['description'] ?? 'Untitled');
        $status = $activeTask['status'];
        $priority = $activeTask['priority'] ?? 3;
        $description = htmlspecialchars($activeTask['description'] ?? '');

        $statusBadge = match ($status) {
            'completed' => '<span class="text-green-400 font-bold">completed</span>',
            'in_progress' => '<span class="text-cyan-400 font-bold">in_progress</span>',
            'pending' => '<span class="text-gray font-bold">pending</span>',
            default => '<span class="text-gray font-bold">'.$status.'</span>',
        };

        $acceptanceHtml = '';
        $acceptanceCriteria = $activeTask['acceptance'] ?? [];
        if (! empty($acceptanceCriteria)) {
            $items = '';
            foreach ($acceptanceCriteria as $criterion) {
                $escapedCriterion = htmlspecialchars((string) $criterion);
                $items .= "<li class=\"ml-2\"><span class=\"text-green-400\">✓</span> {$escapedCriterion}</li>";
            }
            $acceptanceHtml = <<<HTML
                <div class="mt-1 px-2">
                    <span class="font-bold text-gray">Acceptance:</span>
                    <ul>{$items}</ul>
                </div>
            HTML;
        }

        $descriptionHtml = $description !== ''
            ? "<div class=\"px-2 text-white\">{$description}</div>"
            : '';

        return <<<HTML
            <div class="my-1">
                <div class="px-2 font-bold text-cyan-400">{$title}</div>
                <div class="px-2">{$statusBadge} <span class="text-gray">Priority: <span class="text-yellow-400">{$priority}</span></span></div>
                {$descriptionHtml}
                {$acceptanceHtml}
            </div>
        HTML;
    }
}
