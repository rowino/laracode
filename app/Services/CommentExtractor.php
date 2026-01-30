<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Usage: Extract @claude comments from source files for processing by the watch command.
 */
class CommentExtractor
{
    private const MAX_FILE_SIZE = 1024 * 1024; // 1MB

    /**
     * @param  array<string>  $filePaths
     * @return array{comments: array<array{file: string, line: int, text: string}>, metadata: array{stopWordFound: bool, stopWordFile: string|null, filesScanned: int, timestamp: string}}
     */
    public function scanFiles(array $filePaths, string $stopWord = 'claude!', string $searchWord = '@claude'): array
    {
        $comments = [];
        $stopWordFound = false;
        $stopWordFile = null;

        foreach ($filePaths as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            $fileComments = $this->extractFromFile($path, $searchWord);
            foreach ($fileComments as $comment) {
                $comments[] = $comment;
                if (! $stopWordFound && $this->hasStopWord($comment['text'], $stopWord)) {
                    $stopWordFound = true;
                    $stopWordFile = $path;
                }
            }
        }

        return [
            'comments' => $comments,
            'metadata' => [
                'stopWordFound' => $stopWordFound,
                'stopWordFile' => $stopWordFile,
                'filesScanned' => count($filePaths),
                'timestamp' => date('c'),
            ],
        ];
    }

    /**
     * @return array<array{file: string, line: int, text: string}>
     */
    public function extractFromFile(string $path, string $searchWord = '@claude'): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $fileSize = filesize($path);
        if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $extension = $this->getEffectiveExtension($path);
        $patterns = $this->detectCommentStyle($extension, $searchWord);
        $comments = [];

        foreach ($patterns as $pattern) {
            $matches = $this->matchCommentsWithLineNumbers($content, $pattern, $path, $searchWord);
            foreach ($matches as $match) {
                $comments[] = $match;
            }
        }

        usort($comments, fn ($a, $b) => $a['line'] <=> $b['line']);

        return $comments;
    }

    public function hasStopWord(string $content, string $stopWord): bool
    {
        return str_contains(strtolower($content), strtolower($stopWord));
    }

    /**
     * @return array<string>
     */
    public function detectCommentStyle(string $extension, string $searchWord = '@claude'): array
    {
        $escaped = preg_quote($searchWord, '/');

        return match (strtolower($extension)) {
            'php' => [
                '/\/\/\s*'.$escaped.'\b(.*)$/m',                    // PHP single-line //
                '/\/\*\s*'.$escaped.'\b([\s\S]*?)\*\//m',           // PHP multi-line /* */
                '/#\s*'.$escaped.'\b(.*)$/m',                        // PHP single-line # (alternative)
            ],
            'js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs' => [
                '/\/\/\s*'.$escaped.'\b(.*)$/m',                    // JS single-line
                '/\/\*\s*'.$escaped.'\b([\s\S]*?)\*\//m',           // JS multi-line
            ],
            'py' => [
                '/#\s*'.$escaped.'\b(.*)$/m',                        // Python single-line
                '/"""\s*'.$escaped.'\b([\s\S]*?)"""/m',             // Python docstring
                "/'''\s*".$escaped."\b([\s\S]*?)'''/m",             // Python single-quote docstring
            ],
            'rb' => [
                '/#\s*'.$escaped.'\b(.*)$/m',                        // Ruby single-line
                '/=begin\s*'.$escaped.'\b([\s\S]*?)=end/m',         // Ruby multi-line
            ],
            'html', 'htm', 'vue', 'svelte' => [
                '/<!--\s*'.$escaped.'\b([\s\S]*?)-->/m',            // HTML comment
            ],
            'blade.php' => [
                '/\{\{--\s*'.$escaped.'\b([\s\S]*?)--\}\}/m',       // Blade comment {{-- --}}
                '/<!--\s*'.$escaped.'\b([\s\S]*?)-->/m',            // HTML comment
                '/\/\/\s*'.$escaped.'\b(.*)$/m',                    // PHP single-line //
                '/\/\*\s*'.$escaped.'\b([\s\S]*?)\*\//m',           // PHP multi-line /* */
                '/#\s*'.$escaped.'\b(.*)$/m',                        // PHP single-line #
            ],
            'css', 'scss', 'sass', 'less' => [
                '/\/\*\s*'.$escaped.'\b([\s\S]*?)\*\//m',           // CSS multi-line
                '/\/\/\s*'.$escaped.'\b(.*)$/m',                    // SCSS/SASS single-line
            ],
            'sql' => [
                '/--\s*'.$escaped.'\b(.*)$/m',                       // SQL single-line
                '/\/\*\s*'.$escaped.'\b([\s\S]*?)\*\//m',           // SQL multi-line
            ],
            'sh', 'bash', 'zsh' => [
                '/#\s*'.$escaped.'\b(.*)$/m',                        // Shell single-line
            ],
            'yaml', 'yml' => [
                '/#\s*'.$escaped.'\b(.*)$/m',                        // YAML comment
            ],
            'go' => [
                '/\/\/\s*'.$escaped.'\b(.*)$/m',                    // Go single-line
                '/\/\*\s*'.$escaped.'\b([\s\S]*?)\*\//m',           // Go multi-line
            ],
            'rs' => [
                '/\/\/\s*'.$escaped.'\b(.*)$/m',                    // Rust single-line
                '/\/\*\s*'.$escaped.'\b([\s\S]*?)\*\//m',           // Rust multi-line
            ],
            'java', 'kt', 'scala' => [
                '/\/\/\s*'.$escaped.'\b(.*)$/m',                    // Java single-line
                '/\/\*\s*'.$escaped.'\b([\s\S]*?)\*\//m',           // Java multi-line
            ],
            'c', 'cpp', 'cc', 'h', 'hpp' => [
                '/\/\/\s*'.$escaped.'\b(.*)$/m',                    // C++ single-line
                '/\/\*\s*'.$escaped.'\b([\s\S]*?)\*\//m',           // C multi-line
            ],
            default => [
                '/\/\/\s*'.$escaped.'\b(.*)$/m',                    // Default: C-style single-line
                '/#\s*'.$escaped.'\b(.*)$/m',                        // Default: Hash single-line
                '/\/\*\s*'.$escaped.'\b([\s\S]*?)\*\//m',           // Default: C-style multi-line
                '/<!--\s*'.$escaped.'\b([\s\S]*?)-->/m',            // Default: HTML comment
            ],
        };
    }

    /**
     * @return array<array{file: string, line: int, text: string}>
     */
    private function matchCommentsWithLineNumbers(string $content, string $pattern, string $filePath, string $searchWord = '@claude'): array
    {
        $results = [];

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $match) {
                $offset = $match[1];
                $lineNumber = $this->getLineNumberAtOffset($content, $offset);
                $commentText = isset($matches[1][$index]) ? trim($matches[1][$index][0]) : '';

                $results[] = [
                    'file' => $filePath,
                    'line' => $lineNumber,
                    'text' => $searchWord.' '.$commentText,
                ];
            }
        }

        return $results;
    }

    private function getLineNumberAtOffset(string $content, int $offset): int
    {
        $beforeOffset = substr($content, 0, $offset);

        return substr_count($beforeOffset, "\n") + 1;
    }

    private function getEffectiveExtension(string $path): string
    {
        $basename = basename($path);

        if (str_ends_with($basename, '.blade.php')) {
            return 'blade.php';
        }

        return pathinfo($path, PATHINFO_EXTENSION);
    }
}
