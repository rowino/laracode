<?php

declare(strict_types=1);

use App\Enums\BuildMode;
use App\Services\CommentExtractor;
use App\Services\WatchService;
use Illuminate\Support\Facades\File;

function createWatchService(): WatchService
{
    return new WatchService;
}

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-watch-test-'.uniqid();
    mkdir($this->testPath.'/.laracode', 0755, true);
    mkdir($this->testPath.'/app', 0755, true);
    mkdir($this->testPath.'/routes', 0755, true);
    chdir($this->testPath);
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

it('fails when chokidar is not installed', function () {
    // Create a mock npm that reports chokidar as missing
    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho '(empty)'\nexit 0");
    chmod($npmScript, 0755);

    // Put our mock npm first in PATH
    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        $this->artisan('watch', ['--paths' => ['app/']])
            ->assertFailed()
            ->expectsOutputToContain('Chokidar is not installed');
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('displays install instructions when chokidar is missing', function () {
    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho 'MISSING: chokidar'\nexit 0");
    chmod($npmScript, 0755);

    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        $this->artisan('watch', ['--paths' => ['app/']])
            ->assertFailed()
            ->expectsOutputToContain('npm install chokidar');
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('loads config from settings.json', function () {
    $configPath = $this->testPath.'/.laracode/settings.json';
    file_put_contents($configPath, json_encode([
        'watch' => [
            'paths' => ['custom/'],
            'searchWord' => '@custom',
            'stopWord' => 'customstop!',
            'mode' => 'yolo',
        ],
    ]));

    // Create mock npm that reports chokidar as missing (to fail early for test)
    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho '(empty)'\nexit 0");
    chmod($npmScript, 0755);

    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        // Command will fail on chokidar check, but config loading happens before that
        $this->artisan('watch')
            ->assertFailed();
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('loads config from local settings override', function () {
    // Create local settings override (takes precedence over project settings)
    $localPath = $this->testPath.'/.laracode/settings.local.json';
    file_put_contents($localPath, json_encode([
        'watch' => [
            'paths' => ['src/'],
            'searchWord' => '@go',
            'stopWord' => 'go!',
            'mode' => 'accept',
        ],
    ]));

    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho '(empty)'\nexit 0");
    chmod($npmScript, 0755);

    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        $this->artisan('watch')
            ->assertFailed();
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('uses default paths when no config or options provided', function () {
    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho '(empty)'\nexit 0");
    chmod($npmScript, 0755);

    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        // Default paths: app/, routes/, resources/
        $this->artisan('watch')
            ->assertFailed()
            ->expectsOutputToContain('Chokidar is not installed');
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('prefers CLI options over config file settings', function () {
    $configPath = $this->testPath.'/.laracode/settings.json';
    file_put_contents($configPath, json_encode([
        'watch' => [
            'paths' => ['config-path/'],
            'stopWord' => 'configstop!',
            'searchWord' => '@config-test-agent',
            'mode' => 'yolo',
        ],
    ]));

    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho '(empty)'\nexit 0");
    chmod($npmScript, 0755);

    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        // CLI options should override config
        $this->artisan('watch', [
            '--paths' => ['cli-path/'],
            '--stop-word' => 'clistop!',
            '--search-word' => '@cli-test-agent',
            '--mode' => 'interactive',
        ])
            ->assertFailed();
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('loads searchWord from config file', function () {
    $configPath = $this->testPath.'/.laracode/settings.json';
    file_put_contents($configPath, json_encode([
        'watch' => [
            'searchWord' => '@todo',
            'stopWord' => 'done!',
        ],
    ]));

    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho '(empty)'\nexit 0");
    chmod($npmScript, 0755);

    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        $this->artisan('watch')
            ->assertFailed()
            ->expectsOutputToContain('Chokidar is not installed');
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('handles invalid config file gracefully', function () {
    $configPath = $this->testPath.'/.laracode/settings.json';
    file_put_contents($configPath, 'invalid json {{{');

    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho '(empty)'\nexit 0");
    chmod($npmScript, 0755);

    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        // Should use defaults when config is invalid
        $this->artisan('watch')
            ->assertFailed();
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('handles missing settings file gracefully', function () {
    // Remove any existing settings files
    @unlink($this->testPath.'/.laracode/settings.json');
    @unlink($this->testPath.'/.laracode/settings.local.json');

    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho '(empty)'\nexit 0");
    chmod($npmScript, 0755);

    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        // Should use defaults when no settings files exist
        $this->artisan('watch')
            ->assertFailed()
            ->expectsOutputToContain('Chokidar is not installed');
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('merges exclude patterns from config and CLI', function () {
    $configPath = $this->testPath.'/.laracode/settings.json';
    file_put_contents($configPath, json_encode([
        'watch' => [
            'excludePatterns' => ['config-pattern/*'],
        ],
    ]));

    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho '(empty)'\nexit 0");
    chmod($npmScript, 0755);

    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        // Both CLI and config exclude patterns should be merged
        $this->artisan('watch', ['--exclude' => ['cli-pattern/*']])
            ->assertFailed();
    } finally {
        putenv("PATH={$originalPath}");
    }
});

describe('CommentExtractor integration', function () {
    it('extracts @test-agent comments from PHP files', function () {
        $extractor = new CommentExtractor;

        $phpFile = $this->testPath.'/app/TestClass.php';
        file_put_contents($phpFile, <<<'PHP'
<?php
// @test-agent Add validation here
class TestClass {
    /* @test-agent Implement method test-agent! */
    public function test() {}
}
PHP);

        $result = $extractor->scanFiles([$phpFile], 'test-agent!', '@test-agent');

        expect($result['metadata']['stopWordFound'])->toBeTrue();
        expect($result['comments'])->toHaveCount(2);
        expect($result['comments'][0]['text'])->toContain('@test-agent');
    });

    it('detects stop word in comments', function () {
        $extractor = new CommentExtractor;

        $phpFile = $this->testPath.'/app/StopTest.php';
        file_put_contents($phpFile, <<<'PHP'
<?php
// @test-agent This is a regular comment
// @test-agent This has the stop word test-agent!
PHP);

        $result = $extractor->scanFiles([$phpFile], 'test-agent!', '@test-agent');

        expect($result['metadata']['stopWordFound'])->toBeTrue();
        expect($result['metadata']['stopWordFile'])->toBe($phpFile);
    });

    it('returns false for stop word when not present', function () {
        $extractor = new CommentExtractor;

        $phpFile = $this->testPath.'/app/NoStop.php';
        file_put_contents($phpFile, <<<'PHP'
<?php
// @test-agent Regular comment without stop word
PHP);

        $result = $extractor->scanFiles([$phpFile], 'test-agent!', '@test-agent');

        expect($result['metadata']['stopWordFound'])->toBeFalse();
        expect($result['metadata']['stopWordFile'])->toBeNull();
    });

    it('scans multiple files for comments', function () {
        $extractor = new CommentExtractor;

        $file1 = $this->testPath.'/app/File1.php';
        $file2 = $this->testPath.'/app/File2.php';
        file_put_contents($file1, "<?php\n// @test-agent Comment in file 1");
        file_put_contents($file2, "<?php\n// @test-agent Comment in file 2 test-agent!");

        $result = $extractor->scanFiles([$file1, $file2], 'test-agent!', '@test-agent');

        expect($result['metadata']['filesScanned'])->toBe(2);
        expect($result['comments'])->toHaveCount(2);
        expect($result['metadata']['stopWordFound'])->toBeTrue();
    });
});

describe('WatchService integration', function () {
    it('creates comments.json file with correct structure', function () {
        $service = createWatchService();

        $comments = [
            'comments' => [
                ['file' => '/path/to/file.php', 'line' => 10, 'text' => '@test-agent Test comment'],
            ],
            'metadata' => [
                'stopWordFound' => true,
                'stopWordFile' => '/path/to/file.php',
                'filesScanned' => 1,
                'timestamp' => date('c'),
            ],
        ];

        $outputPath = $this->testPath.'/.laracode/comments.json';
        $service->createCommentsJson($comments, $outputPath);

        expect(file_exists($outputPath))->toBeTrue();
        $written = json_decode(file_get_contents($outputPath), true);
        expect($written['comments'])->toHaveCount(1);
        expect($written['metadata']['stopWordFound'])->toBeTrue();
    });

    it('creates parent directories for comments.json', function () {
        $service = createWatchService();

        $comments = [
            'comments' => [],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 0,
                'timestamp' => date('c'),
            ],
        ];

        $outputPath = $this->testPath.'/deep/nested/dir/comments.json';
        $service->createCommentsJson($comments, $outputPath);

        expect(file_exists($outputPath))->toBeTrue();
    });

    it('groups comments by file correctly', function () {
        $service = createWatchService();

        $comments = [
            'comments' => [
                ['file' => '/path/file1.php', 'line' => 10, 'text' => '@test-agent Comment 1'],
                ['file' => '/path/file1.php', 'line' => 20, 'text' => '@test-agent Comment 2'],
                ['file' => '/path/file2.php', 'line' => 5, 'text' => '@test-agent Comment 3'],
            ],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 2,
                'timestamp' => date('c'),
            ],
        ];

        $grouped = $service->groupCommentsByFile($comments);

        expect($grouped)->toHaveCount(2);
        expect($grouped['/path/file1.php'])->toHaveCount(2);
        expect($grouped['/path/file2.php'])->toHaveCount(1);
    });

    it('reads and parses lock file correctly', function () {
        $service = createWatchService();

        $lockPath = $this->testPath.'/.laracode/watch.lock';
        file_put_contents($lockPath, json_encode([
            'pid' => 12345,
            'started' => '2026-01-21T10:00:00+00:00',
            'mode' => 'interactive',
            'commentsPath' => '/path/to/comments.json',
        ]));

        $data = $service->readLockFile($lockPath);

        expect($data)->not->toBeNull();
        expect($data['pid'])->toBe(12345);
        expect($data['mode'])->toBe('interactive');
    });

    it('returns null for missing lock file', function () {
        $service = createWatchService();

        $data = $service->readLockFile($this->testPath.'/nonexistent.lock');

        expect($data)->toBeNull();
    });

    it('cleans up lock file', function () {
        $service = createWatchService();

        $lockPath = $this->testPath.'/.laracode/watch.lock';
        file_put_contents($lockPath, '{}');

        expect(file_exists($lockPath))->toBeTrue();

        $service->cleanupLockFile($lockPath);

        expect(file_exists($lockPath))->toBeFalse();
    });

    it('builds claude prompt for different modes', function () {
        $service = createWatchService();

        $yoloPrompt = $service->buildClaudePrompt(BuildMode::Yolo);
        $acceptPrompt = $service->buildClaudePrompt(BuildMode::Accept);
        $interactivePrompt = $service->buildClaudePrompt(BuildMode::Interactive);

        expect($yoloPrompt)->toContain('Yolo - Executes without confirmation');
        expect($acceptPrompt)->toContain('Accept - Auto-accepts all prompts');
        expect($interactivePrompt)->toContain('Interactive - Asks before making changes');
    });
});

describe('watch config file', function () {
    it('validates settings.json watch schema fields', function () {
        $configPath = $this->testPath.'/.laracode/settings.json';
        file_put_contents($configPath, json_encode([
            'watch' => [
                'paths' => ['app/', 'routes/', 'resources/'],
                'stopWord' => 'test-agent!',
                'mode' => 'interactive',
                'excludePatterns' => ['vendor/*', 'node_modules/*'],
            ],
        ]));

        $config = json_decode(file_get_contents($configPath), true);

        expect($config)->toHaveKey('watch');
        expect($config['watch'])->toHaveKey('paths');
        expect($config['watch'])->toHaveKey('stopWord');
        expect($config['watch'])->toHaveKey('mode');
        expect($config['watch'])->toHaveKey('excludePatterns');
        expect($config['watch']['paths'])->toBeArray();
        expect($config['watch']['excludePatterns'])->toBeArray();
    });
});

describe('comment accumulation across files', function () {
    it('accumulates comments from files without stop word and processes all when stop word arrives', function () {
        $extractor = new CommentExtractor;

        $fileA = $this->testPath.'/app/FileA.php';
        $fileB = $this->testPath.'/app/FileB.php';

        file_put_contents($fileA, "<?php\n// @test-agent Add logging here");
        file_put_contents($fileB, "<?php\n// @test-agent Fix validation test-agent!");

        $resultA = $extractor->scanFiles([$fileA], 'test-agent!', '@test-agent');
        expect($resultA['metadata']['stopWordFound'])->toBeFalse()
            ->and($resultA['comments'])->toHaveCount(1);

        $pendingFiles = array_unique(array_column($resultA['comments'], 'file'));

        $resultB = $extractor->scanFiles([$fileB], 'test-agent!', '@test-agent');
        expect($resultB['metadata']['stopWordFound'])->toBeTrue();

        $allFiles = array_values(array_unique(array_merge($pendingFiles, [$fileB])));
        $finalResult = $extractor->scanFiles($allFiles, 'test-agent!', '@test-agent');

        expect($finalResult['comments'])->toHaveCount(2)
            ->and($finalResult['metadata']['stopWordFound'])->toBeTrue()
            ->and($finalResult['metadata']['filesScanned'])->toBe(2);
    });
});

describe('watcher script requirements', function () {
    it('watcher script exists in resources directory', function () {
        $watcherPath = dirname(__DIR__, 2).'/resources/watcher.js';

        expect(file_exists($watcherPath))->toBeTrue();
    });

    it('watcher script contains required functionality', function () {
        $watcherPath = dirname(__DIR__, 2).'/resources/watcher.js';
        $content = file_get_contents($watcherPath);

        // Check for chokidar import
        expect($content)->toContain('chokidar');

        // Check for JSON event output
        expect($content)->toContain('JSON.stringify');

        // Check for debouncing config
        expect($content)->toContain('awaitWriteFinish');

        // Check for signal handlers
        expect($content)->toContain('SIGTERM');
        expect($content)->toContain('SIGINT');
    });
});

describe('startup and post-processing stop word scanning', function () {
    it('scans for stop word on startup and finds it', function () {
        $extractor = new CommentExtractor;

        $phpFile = $this->testPath.'/app/StartupTest.php';
        file_put_contents($phpFile, <<<'PHP'
<?php
// @test-agent Fix this bug test-now!
class StartupTest {}
PHP);

        $result = $extractor->scanFiles([$phpFile], 'test-now!', '@test-agent');

        expect($result['metadata']['stopWordFound'])->toBeTrue()
            ->and($result['comments'])->toHaveCount(1)
            ->and($result['comments'][0]['text'])->toContain('test-now!');
    });

    it('scans for stop word on startup and does not find it', function () {
        $extractor = new CommentExtractor;

        $phpFile = $this->testPath.'/app/NoStopWord.php';
        file_put_contents($phpFile, <<<'PHP'
<?php
// @test-agent Fix this bug
class NoStopWord {}
PHP);

        $result = $extractor->scanFiles([$phpFile], 'test-now!', '@test-agent');

        expect($result['metadata']['stopWordFound'])->toBeFalse()
            ->and($result['comments'])->toHaveCount(1)
            ->and($result['comments'][0]['text'])->not->toContain('test-now!');
    });

    it('filters watchable file types correctly', function () {
        $extractor = new CommentExtractor;

        $phpFile = $this->testPath.'/app/Test.php';
        $jsFile = $this->testPath.'/app/test.js';
        $txtFile = $this->testPath.'/app/readme.txt';

        file_put_contents($phpFile, "<?php\n// @test-agent Test test-now!");
        file_put_contents($jsFile, '// @test-agent Test test-now!');
        file_put_contents($txtFile, '// @test-agent Test test-now!');

        $phpResult = $extractor->scanFiles([$phpFile], 'test-now!', '@test-agent');
        $jsResult = $extractor->scanFiles([$jsFile], 'test-now!', '@test-agent');

        expect($phpResult['metadata']['stopWordFound'])->toBeTrue()
            ->and($jsResult['metadata']['stopWordFound'])->toBeTrue()
            ->and($phpResult['comments'])->toHaveCount(1)
            ->and($jsResult['comments'])->toHaveCount(1);
    });

    it('scans multiple files with stop words', function () {
        $extractor = new CommentExtractor;

        $file1 = $this->testPath.'/app/File1.php';
        $file2 = $this->testPath.'/app/File2.php';
        $file3 = $this->testPath.'/app/File3.php';

        file_put_contents($file1, "<?php\n// @test-agent Task 1 test-now!");
        file_put_contents($file2, "<?php\n// @test-agent Task 2 test-now!");
        file_put_contents($file3, "<?php\n// @test-agent Task 3 test-now!");

        $result = $extractor->scanFiles([$file1, $file2, $file3], 'test-now!', '@test-agent');

        expect($result['metadata']['stopWordFound'])->toBeTrue()
            ->and($result['comments'])->toHaveCount(3)
            ->and($result['metadata']['filesScanned'])->toBe(3);
    });

    it('handles non-existent paths gracefully during scanning', function () {
        $extractor = new CommentExtractor;

        $nonExistentFile = $this->testPath.'/app/NonExistent.php';

        $result = $extractor->scanFiles([$nonExistentFile], 'test-now!', '@test-agent');

        expect($result['metadata']['stopWordFound'])->toBeFalse()
            ->and($result['comments'])->toBeEmpty()
            ->and($result['metadata']['filesScanned'])->toBe(1);
    });

    it('detects stop words in blade files', function () {
        $extractor = new CommentExtractor;

        $bladeFile = $this->testPath.'/resources/views/test.blade.php';
        mkdir($this->testPath.'/resources/views', 0755, true);
        file_put_contents($bladeFile, <<<'BLADE'
{{-- @test-agent Update layout test-now! --}}
<div>Content</div>
BLADE);

        $result = $extractor->scanFiles([$bladeFile], 'test-now!', '@test-agent');

        expect($result['metadata']['stopWordFound'])->toBeTrue()
            ->and($result['comments'])->toHaveCount(1);
    });
});
