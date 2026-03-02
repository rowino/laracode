<?php

declare(strict_types=1);

namespace App\Tui;

/**
 * Usage: Convert markdown cliff notes into Termwind-compatible HTML for terminal rendering.
 */
class MarkdownRenderer
{
    public function toTermwind(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $output = [];

        foreach ($lines as $line) {
            $output[] = $this->processLine($line);
        }

        return implode("\n", $output);
    }

    private function processLine(string $line): string
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return '<div></div>';
        }

        if (preg_match('/^#{2,3}\s+(.+)$/', $trimmed, $matches)) {
            $content = $this->inlineTransforms(Html::escape($matches[1]));

            return '<span class="font-bold text-cyan">'.$content.'</span>';
        }

        if (preg_match('/^>\s*(.*)$/', $trimmed, $matches)) {
            $content = $this->inlineTransforms(Html::escape($matches[1]));

            return '<div class="ml-2"><span class="text-gray">│</span> <span class="text-gray italic">'.$content.'</span></div>';
        }

        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $matches)) {
            $content = $this->inlineTransforms(Html::escape($matches[1]));

            return '<div class="ml-2"><span class="text-green">✓</span> '.$content.'</div>';
        }

        if (preg_match('/^(\d+)\.\s+(.+)$/', $trimmed, $matches)) {
            $content = $this->inlineTransforms(Html::escape($matches[2]));

            return '<div class="ml-2"><span class="text-yellow">'.$matches[1].'.</span> '.$content.'</div>';
        }

        $content = $this->inlineTransforms(Html::escape($trimmed));

        return '<div>'.$content.'</div>';
    }

    private function inlineTransforms(string $text): string
    {
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<span class="font-bold">$1</span>', $text);

        return (string) preg_replace('/`(.+?)`/', '<span class="text-yellow">$1</span>', $text);
    }
}
