<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Usage: app(CliffNotesParser::class)->extractTaskNotes($content)
 */
class CliffNotesParser
{
    /** @return array<int, string> Task notes keyed by task ID */
    public function extractTaskNotes(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $pattern = '/^## Task #(\d+):/m';

        if (! preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $notes = [];

        for ($i = 0; $i < count($matches[0]); $i++) {
            $taskId = (int) $matches[1][$i][0];
            $startOffset = $matches[0][$i][1];
            $endOffset = isset($matches[0][$i + 1]) ? $matches[0][$i + 1][1] : strlen($content);

            $section = substr($content, $startOffset, $endOffset - $startOffset);
            $notes[$taskId] = trim($section);
        }

        return $notes;
    }

    public function formatTaskNote(int $id, string $title, string $notes): string
    {
        return "---\n## Task #{$id}: {$title}\n{$notes}";
    }
}
