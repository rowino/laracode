#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * LaraCode Statusline Script
 *
 * Displays build task info alongside Claude Code's status.
 * Reads JSON from stdin (Claude's status data) and enriches it with build context.
 *
 * Usage: Configured in .claude/settings.local.json as:
 *   { "statusLine": { "type": "command", "command": "php .claude/scripts/statusline.php" } }
 *
 * Test: echo '{"model":{"display_name":"Opus"},"context_window":{"used_percentage":42}}' | php .claude/scripts/statusline.php
 */

// Read stdin with timeout to prevent blocking
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);

// Give it a moment to receive data, then read
usleep(10000); // 10ms
$input = '';
while (($chunk = fread($stdin, 8192)) !== false && $chunk !== '') {
    $input .= $chunk;
}
fclose($stdin);

$status = $input ? json_decode($input, true) : [];

// Extract session_id for lock file matching
$sessionId = $status['session_id'] ?? null;

// Extract model - handle both nested format and simple string
$model = 'Unknown';
if (isset($status['model'])) {
    if (is_array($status['model'])) {
        $model = $status['model']['display_name'] ?? $status['model']['id'] ?? 'Unknown';
    } else {
        $model = $status['model'];
    }
}

// Extract context percentage - handle nested format
$contextPercent = 0;
if (isset($status['context_window'])) {
    if (is_array($status['context_window'])) {
        $contextPercent = (int) round($status['context_window']['used_percentage'] ?? 0);
    } else {
        // Legacy format: context_window is the size, context_used is the usage
        $contextWindow = (int) $status['context_window'];
        $contextUsed = (int) ($status['context_used'] ?? 0);
        $contextPercent = $contextWindow > 0 ? (int) round(($contextUsed / $contextWindow) * 100) : 0;
    }
}

// Get current git branch
$gitBranch = getCurrentBranch();

// Find active build lock file (matching session_id if available)
$lockFile = findActiveLock($sessionId);
$taskInfo = '';

if ($lockFile && file_exists($lockFile)) {
    $lockContent = file_get_contents($lockFile);
    if ($lockContent !== false) {
        $lockData = json_decode($lockContent, true);
        if (isset($lockData['currentTask'])) {
            $taskId = $lockData['currentTask']['id'] ?? '?';
            $taskTitle = $lockData['currentTask']['title'] ?? 'Untitled';
            // Truncate title if too long
            if (strlen($taskTitle) > 40) {
                $taskTitle = substr($taskTitle, 0, 37).'...';
            }
            $taskInfo = "#{$taskId}: {$taskTitle} | ";
        }
    }
}

// Format model name (shorten if needed)
$modelShort = formatModelName($model);

// Build the status line with ANSI colors
$statusLine = '';

if ($gitBranch) {
    $statusLine .= "\033[35m[{$gitBranch}]\033[0m "; // Magenta for branch
}

if ($taskInfo) {
    $statusLine .= "\033[36m{$taskInfo}\033[0m"; // Cyan for task info
}

$statusLine .= "\033[33m[{$modelShort}]\033[0m "; // Yellow for model

// Color context based on usage
$contextColor = getContextColor($contextPercent);
$statusLine .= "{$contextColor}{$contextPercent}%\033[0m";

echo $statusLine;

/**
 * Get current git branch name
 */
function getCurrentBranch(): ?string
{
    $result = exec('git rev-parse --abbrev-ref HEAD 2>/dev/null', $output, $returnCode);

    return $returnCode === 0 && $result ? trim($result) : null;
}

/**
 * Find the active lock file in .laracode/specs/ matching the session_id
 */
function findActiveLock(?string $sessionId): ?string
{
    $cwd = getcwd();
    if ($cwd === false) {
        return null;
    }

    $specsDir = $cwd.'/.laracode/specs';
    if (! is_dir($specsDir)) {
        return null;
    }

    $dirs = glob($specsDir.'/*', GLOB_ONLYDIR);
    if (! $dirs) {
        return null;
    }

    // First pass: try to match by session_id
    if ($sessionId !== null) {
        foreach ($dirs as $dir) {
            $lockPath = $dir.'/index.lock';
            if (file_exists($lockPath)) {
                $content = file_get_contents($lockPath);
                if ($content !== false) {
                    $data = json_decode($content, true);
                    if (isset($data['session_id']) && $data['session_id'] === $sessionId) {
                        return $lockPath;
                    }
                }
            }
        }
    }

    // Fallback: return first lock file (backward compatibility)
    foreach ($dirs as $dir) {
        $lockPath = $dir.'/index.lock';
        if (file_exists($lockPath)) {
            return $lockPath;
        }
    }

    return null;
}

/**
 * Format model name to a shorter display version
 */
function formatModelName(string $model): string
{
    $model = strtolower($model);

    if (str_contains($model, 'opus')) {
        return 'Opus';
    }
    if (str_contains($model, 'sonnet')) {
        return 'Sonnet';
    }
    if (str_contains($model, 'haiku')) {
        return 'Haiku';
    }

    // Return first word if unknown
    $parts = explode('-', $model);

    return ucfirst($parts[0]);
}

/**
 * Get ANSI color based on context usage percentage
 */
function getContextColor(int $percent): string
{
    if ($percent >= 80) {
        return "\033[31m"; // Red - critical
    }
    if ($percent >= 60) {
        return "\033[33m"; // Yellow - warning
    }

    return "\033[32m"; // Green - ok
}
