<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\CommentExtractor;
use App\Services\WatchService;
use LaravelZero\Framework\Commands\Command;

/**
 * Usage: Long-running daemon that monitors files for @claude comments and triggers processing.
 */
class WatchCommand extends Command
{
    protected $signature = 'watch
        {--paths=* : Paths to watch (default: app/, routes/, resources/)}
        {--stop-word=ai! : Stop word to trigger processing}
        {--search-word=@ai : Comment marker to search for (default: @ai)}
        {--mode=interactive : Permission mode: yolo, accept, interactive}
        {--config= : Path to watch.json config file}
        {--exclude=* : Additional patterns to exclude}';

    protected $description = 'Watch files for @claude comments and process them when stop word is detected';

    /** @var array<string> */
    private array $changedFiles = [];

    /** @var resource|null */
    private mixed $watcherProcess = null;

    private CommentExtractor $commentExtractor;

    private WatchService $watchService;

    /** @var array<string> */
    private array $watchPaths = [];

    private string $watchStopWord = '';

    private string $watchSearchWord = '';

    public function __construct(CommentExtractor $commentExtractor, WatchService $watchService)
    {
        parent::__construct();
        $this->commentExtractor = $commentExtractor;
        $this->watchService = $watchService;
    }

    public function handle(): int
    {
        if (! $this->checkChokidarInstalled()) {
            return self::FAILURE;
        }

        $config = $this->loadConfig();
        $paths = $this->resolvePaths($config);
        $stopWord = $this->resolveStopWord($config);
        $searchWord = $this->resolveSearchWord($config);
        $mode = $this->resolveMode($config);
        $excludePatterns = $this->resolveExcludePatterns($config);

        $this->watchPaths = $paths;
        $this->watchStopWord = $stopWord;
        $this->watchSearchWord = $searchWord;

        $this->displayStartup($paths, $stopWord, $searchWord, $mode);

        $projectPath = $this->resolveProjectPath();
        $lockPath = $projectPath.'/.laracode/watch.lock';

        $this->line('<fg=gray>Scanning for existing stop words...</>');
        $scanResult = $this->scanAllPathsForStopWord($paths, $stopWord, $searchWord);

        if ($scanResult['found']) {
            $this->info('Stop word found on startup! Processing immediately...');
            $this->changedFiles = array_unique($scanResult['files']);

            $this->triggerProcessing($searchWord, $mode, $projectPath, $lockPath);

            $this->changedFiles = [];
            $this->newLine();
            $this->line('Resuming watch...');
        } else {
            $this->line('<fg=gray>No stop words found. Starting watch...</>');
        }

        $watcherProcess = $this->spawnWatcher($paths, $excludePatterns);
        if (! $watcherProcess) {
            $this->error('Failed to spawn watcher process');

            return self::FAILURE;
        }

        $this->watcherProcess = $watcherProcess;
        $this->registerShutdownHandlers();

        return $this->runWatchLoop($watcherProcess, $stopWord, $searchWord, $mode, $projectPath, $lockPath);
    }

    private function checkChokidarInstalled(): bool
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            ['npm', 'list', 'chokidar'],
            $descriptorspec,
            $pipes,
            $this->resolveProjectPath()
        );

        if (! is_resource($process)) {
            $this->error('Failed to check for chokidar installation');

            return false;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($output === false || str_contains($output, 'empty') || str_contains($output, 'MISSING')) {
            $this->error('Chokidar is not installed. Please install it first:');
            $this->newLine();
            $this->line('  <comment>npm install chokidar</comment>');
            $this->newLine();
            $this->line('Or globally:');
            $this->line('  <comment>npm install -g chokidar</comment>');

            return false;
        }

        return true;
    }

    /**
     * @return array{paths?: array<string>, stopWord?: string, searchWord?: string, mode?: string, excludePatterns?: array<string>}
     */
    private function loadConfig(): array
    {
        /** @var string|null $configPath */
        $configPath = $this->option('config');

        if ($configPath === null) {
            $defaultConfig = $this->resolveProjectPath().'/.laracode/watch.json';
            if (file_exists($defaultConfig)) {
                $configPath = $defaultConfig;
            }
        }

        if ($configPath === null || ! file_exists($configPath)) {
            return [];
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array{paths?: array<string>, stopWord?: string, searchWord?: string, mode?: string, excludePatterns?: array<string>} */
        return $decoded;
    }

    /**
     * @param  array{paths?: array<string>, stopWord?: string, searchWord?: string, mode?: string, excludePatterns?: array<string>}  $config
     * @return array<string>
     */
    private function resolvePaths(array $config): array
    {
        /** @var array<string> $optionPaths */
        $optionPaths = $this->option('paths');

        if (! empty($optionPaths)) {
            return $optionPaths;
        }

        if (! empty($config['paths'])) {
            return $config['paths'];
        }

        return ['app/', 'routes/', 'resources/'];
    }

    /**
     * @param  array{paths?: array<string>, stopWord?: string, searchWord?: string, mode?: string, excludePatterns?: array<string>}  $config
     */
    private function resolveStopWord(array $config): string
    {
        /** @var string $optionStopWord */
        $optionStopWord = $this->option('stop-word');

        if ($optionStopWord !== 'ai!') {
            return $optionStopWord;
        }

        return $config['stopWord'] ?? 'ai!';
    }

    /**
     * @param  array{paths?: array<string>, stopWord?: string, searchWord?: string, mode?: string, excludePatterns?: array<string>}  $config
     */
    private function resolveSearchWord(array $config): string
    {
        /** @var string $optionSearchWord */
        $optionSearchWord = $this->option('search-word');

        if ($optionSearchWord !== '@ai') {
            return $optionSearchWord;
        }

        return $config['searchWord'] ?? '@ai';
    }

    /**
     * @param  array{paths?: array<string>, stopWord?: string, searchWord?: string, mode?: string, excludePatterns?: array<string>}  $config
     */
    private function resolveMode(array $config): string
    {
        /** @var string $optionMode */
        $optionMode = $this->option('mode');

        if ($optionMode !== 'interactive') {
            return $optionMode;
        }

        return $config['mode'] ?? 'interactive';
    }

    /**
     * @param  array{paths?: array<string>, stopWord?: string, searchWord?: string, mode?: string, excludePatterns?: array<string>}  $config
     * @return array<string>
     */
    private function resolveExcludePatterns(array $config): array
    {
        /** @var array<string> $optionExclude */
        $optionExclude = $this->option('exclude');

        $patterns = [];

        if (! empty($optionExclude)) {
            $patterns = array_merge($patterns, $optionExclude);
        }

        if (! empty($config['excludePatterns'])) {
            $patterns = array_merge($patterns, $config['excludePatterns']);
        }

        return array_unique($patterns);
    }

    /**
     * @param  array<string>  $paths
     */
    private function displayStartup(array $paths, string $stopWord, string $searchWord, string $mode): void
    {
        $this->newLine();
        $this->info('LaraCode Watch');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('<comment>Watching:</comment> '.implode(', ', $paths));
        $this->line('<comment>Search word:</comment> '.$searchWord);
        $this->line('<comment>Stop word:</comment> '.$stopWord);
        $this->line('<comment>Mode:</comment> '.$mode);
        $this->newLine();
        $this->line('Waiting for file changes...');
        $this->line('<fg=gray>Add '.$searchWord.' comments to your files, then include "'.$stopWord.'" to trigger processing</>');
        $this->newLine();
    }

    private function resolveProjectPath(): string
    {
        $cwd = getcwd();

        return $cwd !== false ? $cwd : '.';
    }

    /**
     * @param  array<string>  $paths
     * @param  array<string>  $excludePatterns
     * @return resource|false
     */
    private function spawnWatcher(array $paths, array $excludePatterns): mixed
    {
        $watcherScript = $this->getWatcherScriptPath();

        if (! file_exists($watcherScript)) {
            $this->error("Watcher script not found: {$watcherScript}");

            return false;
        }

        /** @var list<string> $command */
        $command = ['node', $watcherScript, ...$paths];

        if (! empty($excludePatterns)) {
            $command[] = '--exclude='.implode(',', $excludePatterns);
        }

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptorspec,
            $pipes,
            $this->resolveProjectPath(),
            ['NODE_PATH' => $this->resolveProjectPath().'/node_modules']
        );

        if (! is_resource($process)) {
            return false;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->watcherPipes = $pipes;

        return $process;
    }

    /** @var array<int, resource>|null */
    private ?array $watcherPipes = null;

    private function getWatcherScriptPath(): string
    {
        $pharPath = \Phar::running(false);

        if ($pharPath !== '') {
            $tempDir = sys_get_temp_dir().'/laracode-watcher';
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $watcherPath = $tempDir.'/watcher.js';
            if (! file_exists($watcherPath)) {
                $content = file_get_contents('phar://'.$pharPath.'/resources/watcher.js');
                if ($content !== false) {
                    file_put_contents($watcherPath, $content);
                }
            }

            return $watcherPath;
        }

        return dirname(__DIR__, 2).'/resources/watcher.js';
    }

    /**
     * @param  resource  $process
     */
    private function runWatchLoop(mixed $process, string $stopWord, string $searchWord, string $mode, string $projectPath, string $lockPath): int
    {
        $pipes = $this->watcherPipes;
        if ($pipes === null) {
            return self::FAILURE;
        }

        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                $this->readAndDisplayStderr($pipes[2]);
                $this->warn('Watcher process exited unexpectedly');

                return self::FAILURE;
            }

            $this->readAndDisplayStderr($pipes[2]);

            $line = fgets($pipes[1]);

            if ($line !== false) {
                $event = json_decode(trim($line), true);

                if (is_array($event) && isset($event['event']) && is_string($event['event']) && isset($event['timestamp']) && is_string($event['timestamp'])) {
                    /** @var array{event: string, path?: string, paths?: array<string>, error?: string, signal?: string, timestamp: string} $event */
                    $this->processWatcherEvent($event);

                    if ($this->shouldTriggerProcessing($stopWord, $searchWord)) {
                        $this->triggerProcessing($searchWord, $mode, $projectPath, $lockPath);
                        $this->changedFiles = [];
                    }
                }
            }

            usleep(50000); // 50ms
        }
    }

    /**
     * @param  resource  $stderrPipe
     */
    private function readAndDisplayStderr(mixed $stderrPipe): void
    {
        while (($line = fgets($stderrPipe)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);
            if (is_array($event) && isset($event['event']) && $event['event'] === 'error') {
                $error = $event['error'] ?? 'Unknown error';
                $this->error("Node error: {$error}");
            } else {
                $this->error("Node: {$line}");
            }
        }
    }

    /**
     * @param  array{event: string, path?: string, paths?: array<string>, error?: string, signal?: string, timestamp: string}  $event
     */
    private function processWatcherEvent(array $event): void
    {
        $eventType = $event['event'];

        switch ($eventType) {
            case 'ready':
                $this->line('<fg=green>✓</> Watcher ready');
                break;

            case 'add':
            case 'change':
                if (isset($event['path'])) {
                    $relativePath = $this->getRelativePath($event['path']);
                    $this->line("<fg=blue>•</> {$eventType}: {$relativePath}");
                    if (! in_array($event['path'], $this->changedFiles, true)) {
                        $this->changedFiles[] = $event['path'];
                    }
                }
                break;

            case 'unlink':
                if (isset($event['path'])) {
                    $relativePath = $this->getRelativePath($event['path']);
                    $this->line("<fg=red>•</> deleted: {$relativePath}");
                }
                break;

            case 'error':
                $error = $event['error'] ?? 'Unknown error';
                $this->error("Watcher error: {$error}");
                break;

            case 'shutdown':
                $signal = $event['signal'] ?? 'unknown';
                $this->line("<comment>Watcher received {$signal}</comment>");
                break;
        }
    }

    private function getRelativePath(string $absolutePath): string
    {
        $projectPath = $this->resolveProjectPath();

        if (str_starts_with($absolutePath, $projectPath)) {
            return ltrim(substr($absolutePath, strlen($projectPath)), '/');
        }

        return $absolutePath;
    }

    private function shouldTriggerProcessing(string $stopWord, string $searchWord): bool
    {
        if (empty($this->changedFiles)) {
            return false;
        }

        $filesToScan = $this->changedFiles;
        $this->changedFiles = [];

        $this->line('<fg=gray>Scanning '.count($filesToScan).' file(s) for '.$searchWord.'...</>');

        $result = $this->commentExtractor->scanFiles($filesToScan, $stopWord, $searchWord);

        if (empty($result['comments'])) {
            $this->line('<fg=gray>No '.$searchWord.' comments found</>');

            return false;
        }

        $this->line('<fg=gray>Found '.count($result['comments']).' comment(s):</>');
        foreach ($result['comments'] as $comment) {
            $relativePath = $this->getRelativePath($comment['file']);
            $hasStop = $this->commentExtractor->hasStopWord($comment['text'], $stopWord);
            $indicator = $hasStop ? '<fg=green>✓ STOP</>' : '<fg=gray>-</>';
            $this->line("  {$indicator} {$relativePath}:{$comment['line']} - {$comment['text']}");
        }

        if (! $result['metadata']['stopWordFound']) {
            $this->line('<fg=yellow>Stop word "'.$stopWord.'" not found. Add it to trigger processing.</>');

            return false;
        }

        $this->changedFiles = $filesToScan;

        return true;
    }

    private function triggerProcessing(string $searchWord, string $mode, string $projectPath, string $lockPath): void
    {
        $this->newLine();
        $this->info('Stop word detected! Processing '.$searchWord.' comments...');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $result = $this->commentExtractor->scanFiles($this->changedFiles, 'ai!', $searchWord);

        if (empty($result['comments'])) {
            $this->warn('No '.$searchWord.' comments found in changed files');
            $this->newLine();
            $this->line('Resuming watch...');

            return;
        }

        $this->displayComments($result, $searchWord);

        $commentsPath = $projectPath.'/.laracode/comments.json';
        $this->watchService->createCommentsJson($result, $commentsPath);

        $this->line("<comment>Invoking Claude with mode:</comment> {$mode}");
        $this->newLine();

        $claudeProcess = $this->watchService->invokeClaudeProcess(
            $commentsPath,
            $mode,
            $projectPath,
            $lockPath
        );

        if ($claudeProcess === false) {
            $this->error('Failed to invoke Claude process');

            return;
        }

        $this->watchService->monitorProcess($claudeProcess, $lockPath, function (int $pid) use ($claudeProcess): void {
            $this->line('<comment>Lock file removed, terminating Claude...</comment>');
            $this->watchService->terminateProcess($claudeProcess, $pid);
        });

        $this->watchService->cleanupLockFile($lockPath);

        $this->newLine();
        $this->info('Processing complete');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('Resuming watch...');
        $this->newLine();

        $this->line('<fg=gray>Checking for new stop words...</>');
        if ($this->scanForNewStopWords()) {
            $this->info('New stop word detected! Processing again...');
            $this->triggerProcessing($searchWord, $mode, $projectPath, $lockPath);
        } else {
            $this->line('<fg=gray>No new stop words found. Continuing watch...</>');
        }
    }

    /**
     * @param  array{comments: array<array{file: string, line: int, text: string}>, metadata: array{stopWordFound: bool, stopWordFile: string|null, filesScanned: int, timestamp: string}}  $result
     */
    private function displayComments(array $result, string $searchWord): void
    {
        $this->line('<comment>Found '.count($result['comments']).' '.$searchWord.' comment(s):</comment>');
        $this->newLine();

        $grouped = $this->watchService->groupCommentsByFile($result);

        foreach ($grouped as $file => $comments) {
            $relativePath = $this->getRelativePath($file);
            $this->line("<info>{$relativePath}</info>");

            foreach ($comments as $comment) {
                $this->line("  <fg=gray>L{$comment['line']}:</> {$comment['text']}");
            }

            $this->newLine();
        }
    }

    /**
     * @param  array<string>  $paths
     * @return array{found: bool, files: array<string>}
     */
    private function scanAllPathsForStopWord(array $paths, string $stopWord, string $searchWord): array
    {
        $projectPath = $this->resolveProjectPath();
        $filesToScan = [];

        foreach ($paths as $path) {
            $fullPath = $projectPath.'/'.$path;

            if (! is_dir($fullPath)) {
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file) {
                    if ($file instanceof \SplFileInfo && $file->isFile() && $this->isWatchableFile($file->getPathname())) {
                        $filesToScan[] = $file->getPathname();
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        if (empty($filesToScan)) {
            return ['found' => false, 'files' => []];
        }

        $result = $this->commentExtractor->scanFiles($filesToScan, $stopWord, $searchWord);

        return [
            'found' => $result['metadata']['stopWordFound'],
            'files' => $result['metadata']['stopWordFound']
                ? array_unique(array_column($result['comments'], 'file'))
                : [],
        ];
    }

    private function isWatchableFile(string $path): bool
    {
        $basename = basename($path);

        if (str_ends_with($basename, '.blade.php')) {
            return true;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        $watchableExtensions = [
            'php', 'js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs',
            'py', 'rb', 'html', 'htm', 'vue', 'svelte',
            'css', 'scss', 'sass', 'less', 'sql',
            'sh', 'bash', 'zsh', 'yaml', 'yml',
            'go', 'rs', 'java', 'kt', 'scala',
            'c', 'cpp', 'cc', 'h', 'hpp',
        ];

        return in_array(strtolower($extension), $watchableExtensions, true);
    }

    private function scanForNewStopWords(): bool
    {
        $scanResult = $this->scanAllPathsForStopWord(
            $this->watchPaths,
            $this->watchStopWord,
            $this->watchSearchWord
        );

        if ($scanResult['found']) {
            $this->changedFiles = $scanResult['files'];

            return true;
        }

        return false;
    }

    private function registerShutdownHandlers(): void
    {
        pcntl_async_signals(true);

        $shutdown = function (int $signal): void {
            $this->newLine();
            $this->line('<comment>Shutting down watcher...</comment>');

            if ($this->watcherProcess !== null && is_resource($this->watcherProcess)) {
                $status = proc_get_status($this->watcherProcess);
                if ($status['running']) {
                    posix_kill($status['pid'], SIGTERM);
                    usleep(500000);
                }
                proc_close($this->watcherProcess);
            }

            if ($this->watcherPipes !== null) {
                foreach ($this->watcherPipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }

            exit(0);
        };

        pcntl_signal(SIGTERM, $shutdown);
        pcntl_signal(SIGINT, $shutdown);
    }
}
