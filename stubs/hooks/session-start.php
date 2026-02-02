#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SessionStart Hook
 *
 * Updates the lock file with Claude's session_id for statusline matching.
 * Reads session_id from stdin JSON and writes to lock file specified in LARACODE_LOCK_FILE env var.
 */
$input = file_get_contents('php://stdin');
$data = $input ? json_decode($input, true) : [];

$sessionId = $data['session_id'] ?? null;
$lockFile = getenv('LARACODE_LOCK_FILE');

if (! $sessionId || ! $lockFile || ! file_exists($lockFile)) {
    exit(0);
}

$lockContent = file_get_contents($lockFile);
if ($lockContent === false) {
    exit(0);
}

$lockData = json_decode($lockContent, true);
if (! is_array($lockData)) {
    exit(0);
}

$lockData['session_id'] = $sessionId;

$result = file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($result === false) {
    fwrite(STDERR, "Failed to write session_id to lock file: {$lockFile}\n");
    exit(1);
}
