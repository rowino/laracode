<?php

declare(strict_types=1);

namespace App\Tui\Components;

use App\Tui\DashboardState;

class StatusBar
{
    public function render(DashboardState $state): string
    {
        $statusMessage = htmlspecialchars($state->statusMessage);
        $mode = htmlspecialchars($state->mode);
        $branch = htmlspecialchars($state->branch);

        $showBranch = $branch !== '' && $branch !== 'unknown';
        $branchHtml = $showBranch
            ? " · <span class=\"text-cyan-400\">{$branch}</span>"
            : '';

        return <<<HTML
            <div class="flex justify-between text-white px-2 py-0">
                <span>{$statusMessage}</span>
                <span class="text-gray"><span class="text-yellow-400">{$mode}</span>{$branchHtml}</span>
            </div>
        HTML;
    }
}
