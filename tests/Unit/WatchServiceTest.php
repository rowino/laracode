<?php

declare(strict_types=1);

use App\Enums\BuildMode;
use App\Services\WatchService;

function deleteDirectoryRecursively(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir.'/'.$item;
        is_dir($path) ? deleteDirectoryRecursively($path) : @unlink($path);
    }
    @rmdir($dir);
}

beforeEach(function () {
    $this->service = new WatchService;
    $this->tempDir = sys_get_temp_dir().'/watch_service_test_'.uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    deleteDirectoryRecursively($this->tempDir);
});

describe('createCommentsJson', function () {
    it('generates valid JSON with correct structure', function () {
        $comments = [
            'comments' => [
                ['file' => '/path/to/file.php', 'line' => 10, 'text' => '@claude Test comment'],
                ['file' => '/path/to/file.php', 'line' => 20, 'text' => '@claude Second comment'],
            ],
            'metadata' => [
                'stopWordFound' => true,
                'stopWordFile' => '/path/to/file.php',
                'filesScanned' => 1,
                'timestamp' => '2026-01-21T10:00:00+00:00',
            ],
        ];

        $outputPath = $this->tempDir.'/comments.json';
        $this->service->createCommentsJson($comments, $outputPath);

        expect(file_exists($outputPath))->toBeTrue();

        $written = json_decode(file_get_contents($outputPath), true);
        expect($written)->toBeArray()
            ->and($written['comments'])->toHaveCount(2)
            ->and($written['metadata']['stopWordFound'])->toBeTrue()
            ->and($written['metadata']['filesScanned'])->toBe(1);
    });

    it('creates JSON with pretty print formatting', function () {
        $comments = [
            'comments' => [
                ['file' => '/path/file.php', 'line' => 5, 'text' => '@claude test'],
            ],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 1,
                'timestamp' => date('c'),
            ],
        ];

        $outputPath = $this->tempDir.'/comments.json';
        $this->service->createCommentsJson($comments, $outputPath);

        $content = file_get_contents($outputPath);
        expect($content)->toContain("\n"); // Pretty printed JSON has newlines
    });

    it('creates parent directories if they do not exist', function () {
        $comments = [
            'comments' => [],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 0,
                'timestamp' => date('c'),
            ],
        ];

        $outputPath = $this->tempDir.'/deep/nested/dir/comments.json';
        $this->service->createCommentsJson($comments, $outputPath);

        expect(file_exists($outputPath))->toBeTrue()
            ->and(is_dir($this->tempDir.'/deep/nested/dir'))->toBeTrue();
    });

    it('handles empty comments array', function () {
        $comments = [
            'comments' => [],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 0,
                'timestamp' => date('c'),
            ],
        ];

        $outputPath = $this->tempDir.'/empty.json';
        $this->service->createCommentsJson($comments, $outputPath);

        $written = json_decode(file_get_contents($outputPath), true);
        expect($written['comments'])->toBeEmpty();
    });

    it('preserves slashes in paths (JSON_UNESCAPED_SLASHES)', function () {
        $comments = [
            'comments' => [
                ['file' => '/path/to/file.php', 'line' => 1, 'text' => '@claude test'],
            ],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 1,
                'timestamp' => date('c'),
            ],
        ];

        $outputPath = $this->tempDir.'/slashes.json';
        $this->service->createCommentsJson($comments, $outputPath);

        $content = file_get_contents($outputPath);
        expect($content)->toContain('/path/to/file.php')
            ->and($content)->not->toContain('\\/');
    });

    it('throws exception when unable to write file', function () {
        $comments = [
            'comments' => [],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 0,
                'timestamp' => date('c'),
            ],
        ];

        // Create a directory and make it non-writable
        $nonWritableDir = $this->tempDir.'/readonly';
        mkdir($nonWritableDir, 0555, true);

        // PHP emits a warning before RuntimeException can be thrown,
        // so we need to suppress errors and check the result
        set_error_handler(fn () => true); // Suppress errors temporarily
        $exceptionThrown = false;
        try {
            $this->service->createCommentsJson($comments, $nonWritableDir.'/comments.json');
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
        } finally {
            restore_error_handler();
            // Make directory writable again for cleanup
            chmod($nonWritableDir, 0755);
        }

        expect($exceptionThrown)->toBeTrue();
    });
});

describe('waitForNotification', function () {
    it('reads notification file and returns parsed content', function () {
        $notificationFile = $this->tempDir.'/notification.json';
        file_put_contents($notificationFile, json_encode([
            'status' => 'completed',
            'action' => 'tasks-ready',
            'data' => ['tasksFile' => '/path/to/tasks.json'],
        ]));

        $result = $this->service->waitForNotification($notificationFile, 1);

        expect($result['status'])->toBe('completed')
            ->and($result['action'])->toBe('tasks-ready')
            ->and($result['data']['tasksFile'])->toBe('/path/to/tasks.json');
    });

    it('deletes notification file after reading', function () {
        $notificationFile = $this->tempDir.'/to-delete.json';
        file_put_contents($notificationFile, json_encode(['status' => 'done']));

        $this->service->waitForNotification($notificationFile, 1);

        expect(file_exists($notificationFile))->toBeFalse();
    });

    it('returns timeout status when file not found within timeout', function () {
        $result = $this->service->waitForNotification(
            $this->tempDir.'/nonexistent.json',
            1 // 1 second timeout
        );

        expect($result['status'])->toBe('timeout');
    });

    it('handles invalid JSON in notification file', function () {
        $notificationFile = $this->tempDir.'/invalid.json';
        file_put_contents($notificationFile, 'not valid json {{{');

        $result = $this->service->waitForNotification($notificationFile, 1);

        // Should timeout because JSON parsing fails and it keeps waiting
        expect($result['status'])->toBe('timeout');
    });

    it('handles notification file without status field', function () {
        $notificationFile = $this->tempDir.'/no-status.json';
        file_put_contents($notificationFile, json_encode(['action' => 'test']));

        $result = $this->service->waitForNotification($notificationFile, 1);

        // Should timeout because status is required
        expect($result['status'])->toBe('timeout');
    });

    it('waits for file creation in a loop', function () {
        $notificationFile = $this->tempDir.'/delayed.json';

        // Create file after a short delay in background
        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child process
            usleep(200000); // 200ms delay
            file_put_contents($notificationFile, json_encode(['status' => 'delayed-success']));
            exit(0);
        }

        $result = $this->service->waitForNotification($notificationFile, 2);

        pcntl_waitpid($pid, $status);

        expect($result['status'])->toBe('delayed-success');
    });
});

describe('cleanupLockFile', function () {
    it('removes existing lock file', function () {
        $lockPath = $this->tempDir.'/cleanup.lock';
        file_put_contents($lockPath, '{}');

        expect(file_exists($lockPath))->toBeTrue();

        $this->service->cleanupLockFile($lockPath);

        expect(file_exists($lockPath))->toBeFalse();
    });

    it('does not throw when lock file does not exist', function () {
        $lockPath = $this->tempDir.'/nonexistent.lock';

        set_error_handler(fn () => true);
        $this->service->cleanupLockFile($lockPath);
        restore_error_handler();

        expect(file_exists($lockPath))->toBeFalse();
    });
});

describe('readLockFile', function () {
    it('returns parsed lock file data', function () {
        $lockPath = $this->tempDir.'/read.lock';
        $lockData = [
            'pid' => 12345,
            'started' => '2026-01-21T10:00:00+00:00',
            'mode' => 'yolo',
        ];
        file_put_contents($lockPath, json_encode($lockData));

        $result = $this->service->readLockFile($lockPath);

        expect($result)->not->toBeNull()
            ->and($result['pid'])->toBe(12345)
            ->and($result['started'])->toBe('2026-01-21T10:00:00+00:00')
            ->and($result['mode'])->toBe('yolo');
    });

    it('returns null for missing lock file', function () {
        $result = $this->service->readLockFile($this->tempDir.'/missing.lock');

        expect($result)->toBeNull();
    });

    it('returns null for invalid JSON', function () {
        $lockPath = $this->tempDir.'/invalid.lock';
        file_put_contents($lockPath, 'not json');

        $result = $this->service->readLockFile($lockPath);

        expect($result)->toBeNull();
    });

    it('returns null when required fields are missing', function () {
        $lockPath = $this->tempDir.'/incomplete.lock';
        file_put_contents($lockPath, json_encode(['mode' => 'interactive'])); // Missing pid and started

        $result = $this->service->readLockFile($lockPath);

        expect($result)->toBeNull();
    });

    it('returns data when pid and started are present', function () {
        $lockPath = $this->tempDir.'/minimal.lock';
        file_put_contents($lockPath, json_encode([
            'pid' => 99999,
            'started' => '2026-01-21T12:00:00+00:00',
        ])); // Minimal required fields

        $result = $this->service->readLockFile($lockPath);

        expect($result)->not->toBeNull()
            ->and($result['pid'])->toBe(99999)
            ->and($result['started'])->toBe('2026-01-21T12:00:00+00:00');
    });
});

describe('buildClaudePrompt', function () {
    it('generates yolo mode prompt', function () {
        $prompt = $this->service->buildClaudePrompt(BuildMode::Yolo);

        expect($prompt)->toContain('Yolo - Executes without confirmation');
    });

    it('generates accept mode prompt', function () {
        $prompt = $this->service->buildClaudePrompt(BuildMode::Accept);

        expect($prompt)->toContain('Accept - Auto-accepts all prompts');
    });

    it('generates interactive mode prompt', function () {
        $prompt = $this->service->buildClaudePrompt(BuildMode::Interactive);

        expect($prompt)->toContain('Interactive - Asks before making changes');
    });

    it('includes process comments instruction', function () {
        $prompt = $this->service->buildClaudePrompt(BuildMode::Interactive);

        expect($prompt)->toContain('Process @claude comments');
    });
});

describe('groupCommentsByFile', function () {
    it('groups comments from same file together', function () {
        $comments = [
            'comments' => [
                ['file' => '/path/file1.php', 'line' => 10, 'text' => '@claude Comment 1'],
                ['file' => '/path/file1.php', 'line' => 20, 'text' => '@claude Comment 2'],
                ['file' => '/path/file1.php', 'line' => 30, 'text' => '@claude Comment 3'],
            ],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 1,
                'timestamp' => date('c'),
            ],
        ];

        $grouped = $this->service->groupCommentsByFile($comments);

        expect($grouped)->toHaveCount(1)
            ->and($grouped['/path/file1.php'])->toHaveCount(3);
    });

    it('creates separate groups for different files', function () {
        $comments = [
            'comments' => [
                ['file' => '/path/file1.php', 'line' => 10, 'text' => '@claude Comment 1'],
                ['file' => '/path/file2.php', 'line' => 5, 'text' => '@claude Comment 2'],
                ['file' => '/path/file3.php', 'line' => 15, 'text' => '@claude Comment 3'],
            ],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 3,
                'timestamp' => date('c'),
            ],
        ];

        $grouped = $this->service->groupCommentsByFile($comments);

        expect($grouped)->toHaveCount(3)
            ->and($grouped)->toHaveKey('/path/file1.php')
            ->and($grouped)->toHaveKey('/path/file2.php')
            ->and($grouped)->toHaveKey('/path/file3.php');
    });

    it('preserves line number and text in grouped comments', function () {
        $comments = [
            'comments' => [
                ['file' => '/path/file.php', 'line' => 42, 'text' => '@claude Fix this bug'],
            ],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 1,
                'timestamp' => date('c'),
            ],
        ];

        $grouped = $this->service->groupCommentsByFile($comments);

        expect($grouped['/path/file.php'][0]['line'])->toBe(42)
            ->and($grouped['/path/file.php'][0]['text'])->toBe('@claude Fix this bug');
    });

    it('handles empty comments array', function () {
        $comments = [
            'comments' => [],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 0,
                'timestamp' => date('c'),
            ],
        ];

        $grouped = $this->service->groupCommentsByFile($comments);

        expect($grouped)->toBeEmpty();
    });

    it('does not include file key in grouped array values', function () {
        $comments = [
            'comments' => [
                ['file' => '/path/file.php', 'line' => 10, 'text' => '@claude test'],
            ],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 1,
                'timestamp' => date('c'),
            ],
        ];

        $grouped = $this->service->groupCommentsByFile($comments);

        expect($grouped['/path/file.php'][0])->not->toHaveKey('file')
            ->and($grouped['/path/file.php'][0])->toHaveKey('line')
            ->and($grouped['/path/file.php'][0])->toHaveKey('text');
    });
});
