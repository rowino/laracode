<?php

declare(strict_types=1);

namespace App\Tui\Components;

/**
 * Usage: Renders context-sensitive key hints as a styled bottom bar for the show command.
 */
class KeyHelp
{
    public function render(string $view): string
    {
        $hints = match ($view) {
            'detail' => $this->detailHints(),
            default => $this->listHints(),
        };

        return <<<HTML
            <div class="text-gray px-2 py-0">
                {$hints}
            </div>
        HTML;
    }

    private function listHints(): string
    {
        return '<span class="text-cyan-400">↑↓</span> Navigate'
            .'<span class="mx-8"> </span>'
            .'<span class="text-cyan-400">Enter</span> Details'
            .'<span class="mx-8"> </span>'
            .'<span class="text-cyan-400">q</span> Quit';
    }

    private function detailHints(): string
    {
        return '<span class="text-cyan-400">Esc</span> Back'
            .'<span class="mx-8"> </span>'
            .'<span class="text-cyan-400">q</span> Quit';
    }
}
