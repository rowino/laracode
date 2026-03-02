<?php

declare(strict_types=1);

namespace App\Tui\Components;

use App\Tui\DashboardState;
use App\Tui\Html;
use App\Tui\MarkdownRenderer;

class TaskDetail
{
    public function render(DashboardState $state): string
    {
        return $this->renderTask($state->activeTask());
    }

    /** @param array<string, mixed>|null $task */
    public function renderTask(?array $task, ?string $cliffNotes = null): string
    {
        if ($task === null) {
            return '<div class="px-2 text-gray italic">No task in progress</div>';
        }

        $title = Html::escape($task['title'] ?? $task['description'] ?? 'Untitled');
        $status = $task['status'];
        $priority = $task['priority'] ?? 3;
        $description = Html::escape($task['description'] ?? '');

        $statusBadge = match ($status) {
            'completed' => '<span class="text-green font-bold">completed</span>',
            'in_progress' => '<span class="text-cyan font-bold">in_progress</span>',
            'pending' => '<span class="text-gray font-bold">pending</span>',
            default => '<span class="text-gray font-bold">'.$status.'</span>',
        };

        $descriptionHtml = $description !== ''
            ? "<div class=\"px-2 text-white\">{$description}</div>"
            : '';

        $stepsHtml = $this->renderSteps($task['steps'] ?? []);
        $statsHtml = $this->renderStats($task['stats'] ?? null);
        $acceptanceHtml = $this->renderAcceptance($task['acceptance'] ?? []);
        $notesHtml = $this->renderNotes($cliffNotes);

        return <<<HTML
            <div class="my-1">
                <div class="px-2 font-bold text-cyan">{$title}</div>
                <div class="px-2">{$statusBadge} <span class="text-gray">Priority: <span class="text-yellow">{$priority}</span></span></div>
                {$descriptionHtml}
                {$stepsHtml}
                {$statsHtml}
                {$acceptanceHtml}
                {$notesHtml}
            </div>
        HTML;
    }

    /** @param list<string> $steps */
    private function renderSteps(array $steps): string
    {
        if (empty($steps)) {
            return '';
        }

        $items = '';
        foreach ($steps as $i => $step) {
            $num = $i + 1;
            $escapedStep = Html::escape((string) $step);
            $items .= "<li class=\"ml-2\"><span class=\"text-yellow\">{$num}.</span> {$escapedStep}</li>";
        }

        return <<<HTML
            <div class="mt-1 px-2">
                <span class="font-bold text-gray">Steps:</span>
                <ul>{$items}</ul>
            </div>
        HTML;
    }

    /** @param array<string, mixed>|null $stats */
    private function renderStats(?array $stats): string
    {
        if ($stats === null || empty($stats)) {
            return '';
        }

        $items = '';

        if (isset($stats['durationSeconds'])) {
            $duration = $this->formatDuration((int) $stats['durationSeconds']);
            $items .= "<li class=\"ml-2\"><span class=\"text-gray\">Duration:</span> <span class=\"text-white\">{$duration}</span></li>";
        }

        if (isset($stats['filesChanged'])) {
            $items .= "<li class=\"ml-2\"><span class=\"text-gray\">Files changed:</span> <span class=\"text-white\">{$stats['filesChanged']}</span></li>";
        }

        if (isset($stats['linesAdded'])) {
            $items .= "<li class=\"ml-2\"><span class=\"text-green\">+{$stats['linesAdded']}</span>";
            if (isset($stats['linesRemoved'])) {
                $items .= " <span class=\"text-red\">-{$stats['linesRemoved']}</span>";
            }
            $items .= '</li>';
        }

        if ($items === '') {
            return '';
        }

        return <<<HTML
            <div class="mt-1 px-2">
                <span class="font-bold text-gray">Stats:</span>
                <ul>{$items}</ul>
            </div>
        HTML;
    }

    /** @param list<string> $criteria */
    private function renderAcceptance(array $criteria): string
    {
        if (empty($criteria)) {
            return '';
        }

        $items = '';
        foreach ($criteria as $criterion) {
            $escapedCriterion = Html::escape((string) $criterion);
            $items .= "<li class=\"ml-2\"><span class=\"text-green\">✓</span> {$escapedCriterion}</li>";
        }

        return <<<HTML
            <div class="mt-1 px-2">
                <span class="font-bold text-gray">Acceptance:</span>
                <ul>{$items}</ul>
            </div>
        HTML;
    }

    private function renderNotes(?string $cliffNotes): string
    {
        if ($cliffNotes === null || trim($cliffNotes) === '') {
            return '';
        }

        $rendered = (new MarkdownRenderer)->toTermwind(trim($cliffNotes));

        return <<<HTML
            <div class="mt-1 px-2">
                <span class="font-bold text-gray">Notes:</span>
                <div class="ml-2 text-white">{$rendered}</div>
            </div>
        HTML;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        return $remaining > 0 ? "{$minutes}m {$remaining}s" : "{$minutes}m";
    }
}
