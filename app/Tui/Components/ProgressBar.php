<?php

declare(strict_types=1);

namespace App\Tui\Components;

use App\Tui\DashboardState;

class ProgressBar
{
    private const BAR_WIDTH = 30;

    public function render(DashboardState $state): string
    {
        $percent = $state->progressPercent();
        $completed = $state->completedCount();
        $total = $state->totalCount();

        $filledWidth = $total > 0
            ? (int) round(($completed / $total) * self::BAR_WIDTH)
            : 0;
        $emptyWidth = self::BAR_WIDTH - $filledWidth;

        $filled = str_repeat('█', $filledWidth);
        $empty = str_repeat('░', $emptyWidth);

        return <<<HTML
            <div class="px-2">
                <span class="text-green">{$filled}</span><span class="text-gray">{$empty}</span> <span class="font-bold">{$percent}%</span> <span class="text-gray">{$completed}/{$total}</span>
            </div>
        HTML;
    }
}
