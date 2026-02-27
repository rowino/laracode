<?php

declare(strict_types=1);

namespace App\Tui\Components;

/**
 * Usage: Renders context-sensitive key hints as a styled bottom bar for the show command.
 */
class KeyHelp
{
    public function render(string $view, bool $canDismiss = false, bool $canFocus = false): string
    {
        $hints = match ($view) {
            'detail' => $this->detailHints($canDismiss),
            default => $this->listHints($canDismiss, $canFocus),
        };

        return <<<HTML
            <div class="text-gray px-2 py-0">
                {$hints}
            </div>
        HTML;
    }

    private function listHints(bool $canDismiss, bool $canFocus): string
    {
        $hints = '<span class="text-cyan-400">↑↓</span> Navigate'
            .'<span class="mx-8"> </span>'
            .'<span class="text-cyan-400">Enter</span> Details';

        if ($canDismiss) {
            $hints .= '<span class="mx-8"> </span>'
                .'<span class="text-cyan-400">d</span> Dismiss';
        }

        if ($canFocus) {
            $hints .= '<span class="mx-8"> </span>'
                .'<span class="text-cyan-400">f</span> Focus';
        }

        $hints .= '<span class="mx-8"> </span>'
            .'<span class="text-cyan-400">q</span> Quit';

        return $hints;
    }

    private function detailHints(bool $canDismiss): string
    {
        $hints = '<span class="text-cyan-400">Esc</span> Back';

        if ($canDismiss) {
            $hints .= '<span class="mx-8"> </span>'
                .'<span class="text-cyan-400">d</span> Dismiss';
        }

        $hints .= '<span class="mx-8"> </span>'
            .'<span class="text-cyan-400">q</span> Quit';

        return $hints;
    }
}
