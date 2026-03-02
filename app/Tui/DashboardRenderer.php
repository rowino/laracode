<?php

declare(strict_types=1);

namespace App\Tui;

use App\Tui\Components\HeaderBar;
use App\Tui\Components\ProgressBar;
use App\Tui\Components\StatusBar;
use App\Tui\Components\TaskDetail;
use App\Tui\Components\TaskList;

use function Termwind\render;

class DashboardRenderer
{
    public function __construct(
        private HeaderBar $headerBar,
        private TaskList $taskList,
        private TaskDetail $taskDetail,
        private ProgressBar $progressBar,
        private StatusBar $statusBar,
    ) {}

    public function render(DashboardState $state): void
    {
        if (defined('STDOUT') && function_exists('posix_isatty') && posix_isatty(STDOUT)) {
            echo "\033[2J\033[H";
        }

        $featureTitle = Html::escape($state->featureTitle);

        $html = '<div>'
            .implode("\n", [
                $this->headerBar->render($state),
                "<div class=\"px-2 font-bold\">{$featureTitle}</div>",
                $this->taskList->render($state),
                $this->progressBar->render($state),
                '<hr>',
                $this->taskDetail->render($state),
                $this->statusBar->render($state),
            ])
            .'</div>';

        render($html);
    }

    /**
     * @param  array{tasks: array<array{stats?: array{durationSeconds?: int}}>, stats?: array{filesChanged?: int, linesAdded?: int, linesRemoved?: int}}  $tasks
     */
    public function renderFinalStats(array $tasks): void
    {
        $totalSeconds = 0;
        foreach ($tasks['tasks'] as $task) {
            $totalSeconds += $task['stats']['durationSeconds'] ?? 0;
        }

        $minutes = intdiv($totalSeconds, 60);
        $seconds = $totalSeconds % 60;
        $duration = $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s";

        $filesChanged = $tasks['stats']['filesChanged'] ?? 0;
        $linesAdded = $tasks['stats']['linesAdded'] ?? 0;
        $linesRemoved = $tasks['stats']['linesRemoved'] ?? 0;

        $html = <<<HTML
            <div>
                <div class="text-green px-2 py-0 font-bold">Build Complete</div>
                <div class="my-1 px-2">
                    <div>Duration:      <span class="text-yellow">{$duration}</span></div>
                    <div>Files Changed: <span class="text-yellow">{$filesChanged}</span></div>
                    <div>Lines Added:   <span class="text-green">+{$linesAdded}</span></div>
                    <div>Lines Removed: <span class="text-red">-{$linesRemoved}</span></div>
                </div>
            </div>
        HTML;

        render($html);
    }
}
