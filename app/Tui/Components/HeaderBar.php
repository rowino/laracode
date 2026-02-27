<?php

declare(strict_types=1);

namespace App\Tui\Components;

use App\Tui\DashboardState;

class HeaderBar
{
    public function render(DashboardState $state): string
    {
        $minutes = intdiv($state->elapsedSeconds, 60);
        $seconds = $state->elapsedSeconds % 60;
        $time = $minutes > 0 ? "{$minutes}m{$seconds}s" : "{$seconds}s";

        return <<<HTML
            <div class="flex justify-between text-white px-2 py-0">
                <span class="font-bold">laracode <span class="text-green-400">[Running]</span></span>
                <span class="text-gray">Iteration: <span class="text-cyan-400">{$state->currentIteration}/{$state->maxIterations}</span>  Time: <span class="text-yellow-400">{$time}</span></span>
            </div>
        HTML;
    }
}
