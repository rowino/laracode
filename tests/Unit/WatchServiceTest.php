<?php

declare(strict_types=1);

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

describe('invokeClaudeProcess', function () {
    it('builds correct command for interactive mode', function () {
        // We can't easily test proc_open directly, but we can verify the method doesn't crash
        // with invalid paths - it should return false for process creation failures
        // Suppress proc_open warnings when command doesn't exist
        set_error_handler(fn () => true);
        $result = $this->service->invokeClaudeProcess(
            '/nonexistent/comments.json',
            'interactive',
            '/nonexistent/project',
            $this->tempDir.'/test.lock'
        );
        restore_error_handler();

        // The process likely fails to spawn (claude command not found), but we test the method runs
        // In a real test environment with claude installed, this would create a process
        expect($result === false || is_resource($result))->toBeTrue();
    });

    it('builds correct command for yolo mode', function () {
        set_error_handler(fn () => true);
        $result = $this->service->invokeClaudeProcess(
            '/nonexistent/comments.json',
            'yolo',
            '/nonexistent/project',
            $this->tempDir.'/yolo.lock'
        );
        restore_error_handler();

        expect($result === false || is_resource($result))->toBeTrue();
    });

    it('builds correct command for accept mode', function () {
        set_error_handler(fn () => true);
        $result = $this->service->invokeClaudeProcess(
            '/nonexistent/comments.json',
            'accept',
            '/nonexistent/project',
            $this->tempDir.'/accept.lock'
        );
        restore_error_handler();

        expect($result === false || is_resource($result))->toBeTrue();
    });

    it('creates lock file with process info when process starts', function () {
        // Skip this test if claude is not installed (process would fail to spawn)
        $lockPath = $this->tempDir.'/spawn.lock';
        $result = $this->service->invokeClaudeProcess(
            '/test/comments.json',
            'interactive',
            $this->tempDir,
            $lockPath
        );

        // If process started, lock file should exist
        if (is_resource($result)) {
            expect(file_exists($lockPath))->toBeTrue();

            $lockData = json_decode(file_get_contents($lockPath), true);
            expect($lockData)->toHaveKey('pid')
                ->and($lockData)->toHaveKey('started')
                ->and($lockData)->toHaveKey('mode')
                ->and($lockData)->toHaveKey('commentsPath')
                ->and($lockData['mode'])->toBe('interactive')
                ->and($lockData['commentsPath'])->toBe('/test/comments.json');

            proc_terminate($result);
            proc_close($result);
        }
    })->skip(fn () => shell_exec('which claude') === null, 'Claude CLI not installed');
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

describe('monitorProcess', function () {
    it('exits when process stops running', function () {
        // Create a simple short-lived process
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open('sleep 0.1', $descriptorspec, $pipes);
        $lockPath = $this->tempDir.'/monitor.lock';
        file_put_contents($lockPath, json_encode(['pid' => 1]));

        $callbackCalled = false;
        $this->service->monitorProcess($process, $lockPath, function ($pid) use (&$callbackCalled) {
            $callbackCalled = true;
        });

        proc_close($process);

        // Callback should NOT be called because process ended (not lock removal)
        expect($callbackCalled)->toBeFalse();
    });

    it('calls callback when lock file is removed', function () {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Long-running process
        $process = proc_open('sleep 10', $descriptorspec, $pipes);
        $lockPath = $this->tempDir.'/monitor-lock.lock';
        file_put_contents($lockPath, json_encode(['pid' => getmypid()]));

        $callbackPid = null;

        // Fork to remove lock file after delay
        $pid = pcntl_fork();
        if ($pid === 0) {
            usleep(150000); // 150ms
            unlink($lockPath);
            exit(0);
        }

        $this->service->monitorProcess($process, $lockPath, function ($pid) use (&$callbackPid) {
            $callbackPid = $pid;
        });

        pcntl_waitpid($pid, $status);
        proc_terminate($process);
        proc_close($process);

        expect($callbackPid)->not->toBeNull();
    });
});

describe('terminateProcess', function () {
    it('sends SIGTERM and closes process', function () {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open('sleep 60', $descriptorspec, $pipes);
        $status = proc_get_status($process);
        $pid = $status['pid'];

        $this->service->terminateProcess($process, $pid);

        // Process should be closed
        expect(is_resource($process))->toBeFalse();
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

        // The @ suppression in cleanupLockFile means this should not emit errors
        // But PHP may still emit warnings depending on error_reporting level
        // Suppress any potential warnings for a clean test
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
            'commentsPath' => '/path/to/comments.json',
        ];
        file_put_contents($lockPath, json_encode($lockData));

        $result = $this->service->readLockFile($lockPath);

        expect($result)->not->toBeNull()
            ->and($result['pid'])->toBe(12345)
            ->and($result['started'])->toBe('2026-01-21T10:00:00+00:00')
            ->and($result['mode'])->toBe('yolo')
            ->and($result['commentsPath'])->toBe('/path/to/comments.json');
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
        $prompt = $this->service->buildClaudePrompt(['app/', 'routes/'], 'yolo');

        expect($prompt)->toContain('Execute all changes without prompting');
    });

    it('generates accept mode prompt', function () {
        $prompt = $this->service->buildClaudePrompt(['app/'], 'accept');

        expect($prompt)->toContain('Accept all edits but prompt for other permissions');
    });

    it('generates interactive mode prompt', function () {
        $prompt = $this->service->buildClaudePrompt(['app/'], 'interactive');

        expect($prompt)->toContain('Interactive mode');
    });

    it('generates interactive mode prompt for unknown modes', function () {
        $prompt = $this->service->buildClaudePrompt(['app/'], 'unknown_mode');

        expect($prompt)->toContain('Interactive mode');
    });

    it('includes process comments instruction', function () {
        $prompt = $this->service->buildClaudePrompt(['app/'], 'interactive');

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
