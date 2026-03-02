<?php

declare(strict_types=1);

namespace App\Tui\Components;

use App\Tui\DashboardState;
use App\Tui\Html;

class StatusBar
{
    public function render(DashboardState $state): string
    {
        $statusMessage = Html::escape($state->statusMessage);
        $mode = Html::escape($state->mode);
        $branch = Html::escape($state->branch);

        $showBranch = $branch !== '' && $branch !== 'unknown';
        $branchHtml = $showBranch
            ? " · <span class=\"text-cyan\">{$branch}</span>"
            : '';

        return <<<HTML
            <div class="flex justify-between text-white px-2 py-0">
                <span>{$statusMessage}</span>
                <span class="text-gray"><span class="text-yellow">{$mode}</span>{$branchHtml}</span>
            </div>
        HTML;
    }
}
