<?php

declare(strict_types=1);

namespace App\Tui;

/**
 * Usage: Escape user text for safe embedding in Termwind HTML.
 * Prevents Symfony Console's OutputFormatter from parsing decoded HTML entities
 * (e.g. "&lt;fg=color&gt;" → "<fg=color>") as style tags.
 */
class Html
{
    public static function escape(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return str_replace('&lt;', '\\&lt;', $escaped);
    }
}
