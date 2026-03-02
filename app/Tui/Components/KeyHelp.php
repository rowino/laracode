<?php

declare(strict_types=1);

namespace App\Tui\Components;

/**
 * Usage: Renders context-sensitive key hints as a styled bottom bar for the show command.
 */
class KeyHelp
{
    public function render(
        string $view,
        bool $canDismiss = false,
        bool $canFocus = false,
        bool $canOpenTab = false,
        bool $hasEditor = false,
    ): string {
        $hints = match ($view) {
            'detail' => $this->detailHints($canDismiss, $canFocus, $canOpenTab, $hasEditor),
            default => $this->listHints($canDismiss, $canFocus, $canOpenTab, $hasEditor),
        };

        return <<<HTML
            <div class="text-gray px-2 py-0">
                {$hints}
            </div>
        HTML;
    }

    private function listHints(bool $canDismiss, bool $canFocus, bool $canOpenTab, bool $hasEditor): string
    {
        $hints = '<span class="text-cyan">↑↓</span> Navigate'
            .'<span class="mx-8"> </span>'
            .'<span class="text-cyan">Enter</span> Details';

        if ($canDismiss) {
            $hints .= '<span class="mx-8"> </span>'
                .'<span class="text-cyan">d</span> Dismiss';
        }

        if ($canFocus) {
            $hints .= '<span class="mx-8"> </span>'
                .'<span class="text-cyan">f</span> Focus';
        }

        if ($canOpenTab) {
            $hints .= '<span class="mx-8"> </span>'
                .'<span class="text-cyan">t</span> Tab';
        }

        if ($hasEditor) {
            $hints .= '<span class="mx-8"> </span>'
                .'<span class="text-cyan">e</span> Editor';
        }

        $hints .= '<span class="mx-8"> </span>'
            .'<span class="text-cyan">q</span> Quit';

        return $hints;
    }

    private function detailHints(bool $canDismiss, bool $canFocus, bool $canOpenTab, bool $hasEditor): string
    {
        $hints = '<span class="text-cyan">↑↓</span> Navigate'
            .'<span class="mx-8"> </span>'
            .'<span class="text-cyan">Esc</span> Back';

        if ($canDismiss) {
            $hints .= '<span class="mx-8"> </span>'
                .'<span class="text-cyan">d</span> Dismiss';
        }

        if ($canFocus) {
            $hints .= '<span class="mx-8"> </span>'
                .'<span class="text-cyan">f</span> Focus';
        }

        if ($canOpenTab) {
            $hints .= '<span class="mx-8"> </span>'
                .'<span class="text-cyan">t</span> Tab';
        }

        if ($hasEditor) {
            $hints .= '<span class="mx-8"> </span>'
                .'<span class="text-cyan">e</span> Editor';
        }

        $hints .= '<span class="mx-8"> </span>'
            .'<span class="text-cyan">q</span> Quit';

        return $hints;
    }
}
