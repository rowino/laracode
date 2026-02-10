#!/usr/bin/env node

/**
 * File watcher script using chokidar
 * Monitors file changes and emits JSON events to STDOUT
 *
 * Usage: node watcher.js [paths...] [--exclude=pattern1,pattern2]
 */

const chokidar = require('chokidar');
const path = require('path');
const fs = require('fs');

const FALLBACK_PATHS = ['app/', 'routes/', 'resources/'];
const FALLBACK_IGNORED = [
    '**/vendor/**',
    '**/node_modules/**',
    '**/storage/**',
    '**/.git/**',
    '**/.idea/**',
    '**/.vscode/**',
    '**/bootstrap/cache/**',
    '**/*.log',
    '**/.DS_Store',
];

function loadConfig() {
    const candidates = [
        path.resolve('.laracode/settings.local.json'),
        path.resolve('.laracode/settings.json'),
    ];

    for (const file of candidates) {
        try {
            const raw = fs.readFileSync(file, 'utf8');
            const json = JSON.parse(raw);
            if (json && typeof json === 'object' && json.watch) {
                return json.watch;
            }
        } catch {
            // file missing or invalid JSON — try next
        }
    }

    return {};
}

const config = loadConfig();

// Parse CLI arguments
const args = process.argv.slice(2);
let pathsToWatch = [];
let excludePatterns = [];

for (const arg of args) {
    if (arg.startsWith('--exclude=')) {
        excludePatterns = arg.replace('--exclude=', '').split(',').map(p => p.trim());
    } else if (!arg.startsWith('--')) {
        pathsToWatch.push(arg);
    }
}

// Use CLI paths, then config paths, then fallback
if (pathsToWatch.length === 0) {
    pathsToWatch = Array.isArray(config.paths) && config.paths.length > 0
        ? config.paths
        : FALLBACK_PATHS;
}

// Merge config exclude patterns with fallback, then CLI overrides
const defaultIgnored = Array.isArray(config.excludePatterns) && config.excludePatterns.length > 0
    ? config.excludePatterns
    : FALLBACK_IGNORED;

const ignored = [...defaultIgnored, ...excludePatterns];

/**
 * Emit a JSON event to STDOUT
 */
function emitEvent(event, filePath) {
    const output = {
        event: event,
        path: path.resolve(filePath),
        timestamp: new Date().toISOString(),
    };

    // Write JSON to STDOUT followed by newline
    process.stdout.write(JSON.stringify(output) + '\n');
}

/**
 * Handle errors gracefully
 */
function handleError(error) {
    const output = {
        event: 'error',
        error: error.message || String(error),
        timestamp: new Date().toISOString(),
    };

    process.stderr.write(JSON.stringify(output) + '\n');
}

// Initialize watcher
const watcher = chokidar.watch(pathsToWatch, {
    ignored: ignored,
    persistent: true,
    ignoreInitial: true,
    // Debouncing configuration
    awaitWriteFinish: {
        stabilityThreshold: 500,
        pollInterval: 100,
    },
    // Don't follow symlinks to avoid infinite loops
    followSymlinks: false,
    // Use polling for better cross-platform compatibility if needed
    usePolling: false,
    // Debounce with atomic writes
    atomic: true,
});

// Emit ready event when watcher is initialized
watcher.on('ready', () => {
    const output = {
        event: 'ready',
        paths: pathsToWatch,
        ignored: ignored,
        timestamp: new Date().toISOString(),
    };
    process.stdout.write(JSON.stringify(output) + '\n');
});

// Watch for file events
watcher.on('add', (filePath) => emitEvent('add', filePath));
watcher.on('change', (filePath) => emitEvent('change', filePath));
watcher.on('unlink', (filePath) => emitEvent('unlink', filePath));
watcher.on('addDir', (filePath) => emitEvent('addDir', filePath));
watcher.on('unlinkDir', (filePath) => emitEvent('unlinkDir', filePath));

// Handle errors
watcher.on('error', handleError);

/**
 * Graceful shutdown handler
 */
async function shutdown(signal) {
    const output = {
        event: 'shutdown',
        signal: signal,
        timestamp: new Date().toISOString(),
    };
    process.stdout.write(JSON.stringify(output) + '\n');

    await watcher.close();
    process.exit(0);
}

// Handle process signals for cleanup
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGHUP', () => shutdown('SIGHUP'));

// Handle uncaught errors
process.on('uncaughtException', (error) => {
    handleError(error);
    process.exit(1);
});

process.on('unhandledRejection', (reason) => {
    handleError(reason instanceof Error ? reason : new Error(String(reason)));
    process.exit(1);
});
