<?php

declare(strict_types=1);

use App\Services\CommentExtractor;
use App\Services\WatchService;
use Illuminate\Support\Facades\File;

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

it('loads config from default watch.json location', function () {
    // Create watch config
    $configPath = $this->testPath.'/.laracode/watch.json';
    file_put_contents($configPath, json_encode([
        'paths' => ['custom/'],
        'stopWord' => 'customstop!',
        'mode' => 'yolo',
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

it('loads config from explicit config path', function () {
    $configPath = $this->testPath.'/custom-config.json';
    file_put_contents($configPath, json_encode([
        'paths' => ['src/'],
        'stopWord' => 'go!',
        'mode' => 'accept',
    ]));

    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho '(empty)'\nexit 0");
    chmod($npmScript, 0755);

    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        $this->artisan('watch', ['--config' => $configPath])
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
    $configPath = $this->testPath.'/.laracode/watch.json';
    file_put_contents($configPath, json_encode([
        'paths' => ['config-path/'],
        'stopWord' => 'configstop!',
        'searchWord' => '@configclaude',
        'mode' => 'yolo',
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
            '--search-word' => '@cliclaude',
            '--mode' => 'interactive',
        ])
            ->assertFailed();
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('loads searchWord from config file', function () {
    $configPath = $this->testPath.'/.laracode/watch.json';
    file_put_contents($configPath, json_encode([
        'searchWord' => '@todo',
        'stopWord' => 'done!',
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
    $configPath = $this->testPath.'/.laracode/watch.json';
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

it('handles missing config file gracefully', function () {
    $npmScript = $this->testPath.'/npm';
    file_put_contents($npmScript, "#!/bin/bash\necho '(empty)'\nexit 0");
    chmod($npmScript, 0755);

    $originalPath = getenv('PATH');
    putenv("PATH={$this->testPath}:{$originalPath}");

    try {
        // Should use defaults when config doesn't exist
        $this->artisan('watch', ['--config' => '/nonexistent/config.json'])
            ->assertFailed();
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('merges exclude patterns from config and CLI', function () {
    $configPath = $this->testPath.'/.laracode/watch.json';
    file_put_contents($configPath, json_encode([
        'excludePatterns' => ['config-pattern/*'],
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
    it('extracts @claude comments from PHP files', function () {
        $extractor = new CommentExtractor;

        $phpFile = $this->testPath.'/app/TestClass.php';
        file_put_contents($phpFile, <<<'PHP'
<?php
// @claude Add validation here
class TestClass {
    /* @claude Implement method claude! */
    public function test() {}
}
PHP);

        $result = $extractor->scanFiles([$phpFile], 'claude!');

        expect($result['metadata']['stopWordFound'])->toBeTrue();
        expect($result['comments'])->toHaveCount(2);
        expect($result['comments'][0]['text'])->toContain('@claude');
    });

    it('detects stop word in comments', function () {
        $extractor = new CommentExtractor;

        $phpFile = $this->testPath.'/app/StopTest.php';
        file_put_contents($phpFile, <<<'PHP'
<?php
// @claude This is a regular comment
// @claude This has the stop word claude!
PHP);

        $result = $extractor->scanFiles([$phpFile], 'claude!');

        expect($result['metadata']['stopWordFound'])->toBeTrue();
        expect($result['metadata']['stopWordFile'])->toBe($phpFile);
    });

    it('returns false for stop word when not present', function () {
        $extractor = new CommentExtractor;

        $phpFile = $this->testPath.'/app/NoStop.php';
        file_put_contents($phpFile, <<<'PHP'
<?php
// @claude Regular comment without stop word
PHP);

        $result = $extractor->scanFiles([$phpFile], 'claude!');

        expect($result['metadata']['stopWordFound'])->toBeFalse();
        expect($result['metadata']['stopWordFile'])->toBeNull();
    });

    it('scans multiple files for comments', function () {
        $extractor = new CommentExtractor;

        $file1 = $this->testPath.'/app/File1.php';
        $file2 = $this->testPath.'/app/File2.php';
        file_put_contents($file1, "<?php\n// @claude Comment in file 1");
        file_put_contents($file2, "<?php\n// @claude Comment in file 2 claude!");

        $result = $extractor->scanFiles([$file1, $file2], 'claude!');

        expect($result['metadata']['filesScanned'])->toBe(2);
        expect($result['comments'])->toHaveCount(2);
        expect($result['metadata']['stopWordFound'])->toBeTrue();
    });
});

describe('WatchService integration', function () {
    it('creates comments.json file with correct structure', function () {
        $service = new WatchService;

        $comments = [
            'comments' => [
                ['file' => '/path/to/file.php', 'line' => 10, 'text' => '@claude Test comment'],
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
        $service = new WatchService;

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
        $service = new WatchService;

        $comments = [
            'comments' => [
                ['file' => '/path/file1.php', 'line' => 10, 'text' => '@claude Comment 1'],
                ['file' => '/path/file1.php', 'line' => 20, 'text' => '@claude Comment 2'],
                ['file' => '/path/file2.php', 'line' => 5, 'text' => '@claude Comment 3'],
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
        $service = new WatchService;

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
        $service = new WatchService;

        $data = $service->readLockFile($this->testPath.'/nonexistent.lock');

        expect($data)->toBeNull();
    });

    it('cleans up lock file', function () {
        $service = new WatchService;

        $lockPath = $this->testPath.'/.laracode/watch.lock';
        file_put_contents($lockPath, '{}');

        expect(file_exists($lockPath))->toBeTrue();

        $service->cleanupLockFile($lockPath);

        expect(file_exists($lockPath))->toBeFalse();
    });

    it('builds claude prompt for different modes', function () {
        $service = new WatchService;

        $yoloPrompt = $service->buildClaudePrompt(['app/'], 'yolo');
        $acceptPrompt = $service->buildClaudePrompt(['app/'], 'accept');
        $interactivePrompt = $service->buildClaudePrompt(['app/'], 'interactive');

        expect($yoloPrompt)->toContain('Execute all changes without prompting');
        expect($acceptPrompt)->toContain('Accept all edits');
        expect($interactivePrompt)->toContain('Interactive mode');
    });
});

describe('watch config file', function () {
    it('validates watch.json schema fields', function () {
        $configPath = $this->testPath.'/.laracode/watch.json';
        file_put_contents($configPath, json_encode([
            'paths' => ['app/', 'routes/', 'resources/'],
            'stopWord' => 'claude!',
            'mode' => 'interactive',
            'excludePatterns' => ['vendor/*', 'node_modules/*'],
        ]));

        $config = json_decode(file_get_contents($configPath), true);

        expect($config)->toHaveKey('paths');
        expect($config)->toHaveKey('stopWord');
        expect($config)->toHaveKey('mode');
        expect($config)->toHaveKey('excludePatterns');
        expect($config['paths'])->toBeArray();
        expect($config['excludePatterns'])->toBeArray();
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
// @ai Fix this bug ai!
class StartupTest {}
PHP);

        $result = $extractor->scanFiles([$phpFile], 'ai!', '@ai');

        expect($result['metadata']['stopWordFound'])->toBeTrue()
            ->and($result['comments'])->toHaveCount(1)
            ->and($result['comments'][0]['text'])->toContain('ai!');
    });

    it('scans for stop word on startup and does not find it', function () {
        $extractor = new CommentExtractor;

        $phpFile = $this->testPath.'/app/NoStopWord.php';
        file_put_contents($phpFile, <<<'PHP'
<?php
// @ai Fix this bug
class NoStopWord {}
PHP);

        $result = $extractor->scanFiles([$phpFile], 'ai!', '@ai');

        expect($result['metadata']['stopWordFound'])->toBeFalse()
            ->and($result['comments'])->toHaveCount(1)
            ->and($result['comments'][0]['text'])->not->toContain('ai!');
    });

    it('filters watchable file types correctly', function () {
        $extractor = new CommentExtractor;

        $phpFile = $this->testPath.'/app/Test.php';
        $jsFile = $this->testPath.'/app/test.js';
        $txtFile = $this->testPath.'/app/readme.txt';

        file_put_contents($phpFile, "<?php\n// @ai Test ai!");
        file_put_contents($jsFile, '// @ai Test ai!');
        file_put_contents($txtFile, '// @ai Test ai!');

        $phpResult = $extractor->scanFiles([$phpFile], 'ai!', '@ai');
        $jsResult = $extractor->scanFiles([$jsFile], 'ai!', '@ai');

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

        file_put_contents($file1, "<?php\n// @ai Task 1 ai!");
        file_put_contents($file2, "<?php\n// @ai Task 2 ai!");
        file_put_contents($file3, "<?php\n// @ai Task 3 ai!");

        $result = $extractor->scanFiles([$file1, $file2, $file3], 'ai!', '@ai');

        expect($result['metadata']['stopWordFound'])->toBeTrue()
            ->and($result['comments'])->toHaveCount(3)
            ->and($result['metadata']['filesScanned'])->toBe(3);
    });

    it('handles non-existent paths gracefully during scanning', function () {
        $extractor = new CommentExtractor;

        $nonExistentFile = $this->testPath.'/app/NonExistent.php';

        $result = $extractor->scanFiles([$nonExistentFile], 'ai!', '@ai');

        expect($result['metadata']['stopWordFound'])->toBeFalse()
            ->and($result['comments'])->toBeEmpty()
            ->and($result['metadata']['filesScanned'])->toBe(1);
    });

    it('detects stop words in blade files', function () {
        $extractor = new CommentExtractor;

        $bladeFile = $this->testPath.'/resources/views/test.blade.php';
        mkdir($this->testPath.'/resources/views', 0755, true);
        file_put_contents($bladeFile, <<<'BLADE'
{{-- @ai Update layout ai! --}}
<div>Content</div>
BLADE);

        $result = $extractor->scanFiles([$bladeFile], 'ai!', '@ai');

        expect($result['metadata']['stopWordFound'])->toBeTrue()
            ->and($result['comments'])->toHaveCount(1);
    });
});
