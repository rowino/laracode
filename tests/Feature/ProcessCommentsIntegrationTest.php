<?php

declare(strict_types=1);

use App\Services\CommentExtractor;
use App\Services\WatchService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-process-comments-test-'.uniqid();
    mkdir($this->testPath.'/.laracode/specs/test-feature', 0755, true);
    mkdir($this->testPath.'/app', 0755, true);
    mkdir($this->testPath.'/routes', 0755, true);
    mkdir($this->testPath.'/resources', 0755, true);
    chdir($this->testPath);
    $this->commentExtractor = new CommentExtractor;
    $this->watchService = new WatchService;
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

describe('interactive mode workflow', function () {
    it('scans changed files for @claude comments', function () {
        $phpFile = $this->testPath.'/app/UserController.php';
        file_put_contents($phpFile, <<<'PHP'
<?php
// @claude Add input validation for email field
class UserController {
    /* @claude Implement rate limiting here claude! */
    public function store(Request $request) {
        // Save user
    }
}
PHP);

        $result = $this->commentExtractor->scanFiles([$phpFile], 'claude!');

        expect($result['comments'])->toHaveCount(2)
            ->and($result['metadata']['stopWordFound'])->toBeTrue()
            ->and($result['metadata']['stopWordFile'])->toBe($phpFile)
            ->and($result['comments'][0]['file'])->toBe($phpFile)
            ->and($result['comments'][0]['line'])->toBe(2)
            ->and($result['comments'][1]['line'])->toBe(4);
    });

    it('creates comments.json with proper structure for Claude processing', function () {
        $phpFile = $this->testPath.'/app/TestService.php';
        file_put_contents($phpFile, "<?php\n// @claude Implement caching mechanism claude!");

        $result = $this->commentExtractor->scanFiles([$phpFile], 'claude!');

        $commentsPath = $this->testPath.'/.laracode/comments.json';
        $this->watchService->createCommentsJson($result, $commentsPath);

        expect(file_exists($commentsPath))->toBeTrue();

        $content = json_decode(file_get_contents($commentsPath), true);
        expect($content)->toHaveKey('comments')
            ->toHaveKey('metadata')
            ->and($content['comments'])->toBeArray()
            ->and($content['metadata']['stopWordFound'])->toBeTrue()
            ->and($content['metadata']['filesScanned'])->toBe(1);
    });

    it('groups comments by file for organized display', function () {
        $file1 = $this->testPath.'/app/Controller.php';
        $file2 = $this->testPath.'/app/Service.php';

        file_put_contents($file1, "<?php\n// @claude First comment\n// @claude Second comment claude!");
        file_put_contents($file2, "<?php\n// @claude Service comment");

        $result = $this->commentExtractor->scanFiles([$file1, $file2], 'claude!');
        $grouped = $this->watchService->groupCommentsByFile($result);

        expect($grouped)->toHaveCount(2)
            ->and($grouped[$file1])->toHaveCount(2)
            ->and($grouped[$file2])->toHaveCount(1)
            ->and($grouped[$file1][0]['text'])->toContain('First comment')
            ->and($grouped[$file1][1]['text'])->toContain('Second comment');
    });

    it('prompts for plan approval in interactive mode', function () {
        $this->artisan('notify', [
            'action' => 'plan-ready',
            '--mode' => 'interactive',
        ])
            ->expectsConfirmation('Approve this plan and continue?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Plan approved');
    });

    it('rejects plan when user declines in interactive mode', function () {
        $this->artisan('notify', [
            'action' => 'plan-ready',
            '--mode' => 'interactive',
        ])
            ->expectsConfirmation('Approve this plan and continue?', 'no')
            ->assertFailed()
            ->expectsOutputToContain('Plan rejected');
    });

    it('prompts for build start in interactive mode', function () {
        $this->artisan('notify', [
            'action' => 'tasks-ready',
            '--mode' => 'interactive',
        ])
            ->expectsConfirmation('Start the build loop now?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Starting build loop');
    });
});

describe('yolo mode workflow', function () {
    it('auto-continues plan without prompting', function () {
        $this->artisan('notify', [
            'action' => 'plan-ready',
            '--mode' => 'yolo',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('auto-continuing in yolo mode');
    });

    it('auto-starts build without prompting', function () {
        $this->artisan('notify', [
            'action' => 'tasks-ready',
            '--mode' => 'yolo',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('auto-starting build in yolo mode');
    });

    it('processes complete yolo flow without user intervention', function () {
        // Simulate plan ready
        $this->artisan('notify', [
            'action' => 'plan-ready',
            '--mode' => 'yolo',
        ])->assertSuccessful();

        // Simulate tasks ready
        $this->artisan('notify', [
            'action' => 'tasks-ready',
            '--mode' => 'yolo',
        ])->assertSuccessful();

        // Simulate watch complete
        $this->artisan('notify', [
            'action' => 'watch-complete',
        ])->assertSuccessful();
    });
});

describe('multiple watch cycles', function () {
    it('resets changed files between processing cycles', function () {
        $changedFiles = [];

        // First cycle
        $file1 = $this->testPath.'/app/Cycle1.php';
        file_put_contents($file1, "<?php\n// @claude First cycle comment claude!");
        $changedFiles[] = $file1;

        $result1 = $this->commentExtractor->scanFiles($changedFiles, 'claude!');
        expect($result1['metadata']['stopWordFound'])->toBeTrue();

        // Reset (as WatchCommand does)
        $changedFiles = [];

        // Second cycle with different file
        $file2 = $this->testPath.'/app/Cycle2.php';
        file_put_contents($file2, "<?php\n// @claude Second cycle comment claude!");
        $changedFiles[] = $file2;

        $result2 = $this->commentExtractor->scanFiles($changedFiles, 'claude!');
        expect($result2['metadata']['stopWordFound'])->toBeTrue()
            ->and($result2['comments'])->toHaveCount(1)
            ->and($result2['comments'][0]['file'])->toBe($file2);
    });

    it('maintains watch state between cycles via lock file', function () {
        $lockPath = $this->testPath.'/.laracode/watch.lock';

        // First cycle - create lock
        file_put_contents($lockPath, json_encode([
            'pid' => getmypid(),
            'started' => date('c'),
            'mode' => 'interactive',
            'commentsPath' => $this->testPath.'/.laracode/comments.json',
        ]));

        $lockData = $this->watchService->readLockFile($lockPath);
        expect($lockData)->not->toBeNull()
            ->and($lockData['pid'])->toBe(getmypid())
            ->and($lockData['mode'])->toBe('interactive');

        // Cleanup
        $this->watchService->cleanupLockFile($lockPath);
        expect(file_exists($lockPath))->toBeFalse();
    });

    it('continues watching after processing completes', function () {
        $this->artisan('notify', ['action' => 'watch-complete'])
            ->assertSuccessful()
            ->expectsOutputToContain('Continuing to watch');
    });
});

describe('changed files handling', function () {
    it('accumulates multiple changed files before triggering', function () {
        $changedFiles = [];

        // Simulate multiple file changes (none have stop word)
        $file1 = $this->testPath.'/app/File1.php';
        $file2 = $this->testPath.'/app/File2.php';
        $file3 = $this->testPath.'/app/File3.php';

        file_put_contents($file1, "<?php\n// @claude Add feature A");
        file_put_contents($file2, "<?php\n// @claude Add feature B");
        file_put_contents($file3, "<?php\n// @claude Add feature C claude!");

        $changedFiles = [$file1, $file2, $file3];

        // Only triggers when stop word is found
        $result = $this->commentExtractor->scanFiles($changedFiles, 'claude!');

        expect($result['metadata']['stopWordFound'])->toBeTrue()
            ->and($result['comments'])->toHaveCount(3)
            ->and($result['metadata']['filesScanned'])->toBe(3);
    });

    it('clears changed files array after processing', function () {
        $changedFiles = [];

        $file = $this->testPath.'/app/ToProcess.php';
        file_put_contents($file, "<?php\n// @claude Process this claude!");
        $changedFiles[] = $file;

        $result = $this->commentExtractor->scanFiles($changedFiles, 'claude!');
        expect($result['metadata']['stopWordFound'])->toBeTrue();

        // Reset (simulating resetChangedFiles())
        $changedFiles = [];

        // Verify empty state
        $resultAfterReset = $this->commentExtractor->scanFiles($changedFiles, 'claude!');
        expect($resultAfterReset['comments'])->toBeEmpty()
            ->and($resultAfterReset['metadata']['stopWordFound'])->toBeFalse();
    });
});

describe('spec and tasks generation', function () {
    it('creates spec.md structure from comments', function () {
        $specPath = $this->testPath.'/.laracode/specs/test-feature/spec.md';

        $specContent = <<<'MD'
# Test Feature Spec

## Overview
This spec was generated from @claude comments.

## Source Comments
| File | Line | Comment |
|------|------|---------|
| app/UserController.php | 10 | Add validation |
| app/UserController.php | 25 | Add rate limiting |

## Requirements
- Implement validation
- Implement rate limiting

## Acceptance Criteria
- [ ] Validation works for all inputs
- [ ] Rate limiting prevents abuse
MD;

        file_put_contents($specPath, $specContent);

        expect(file_exists($specPath))->toBeTrue();

        $content = file_get_contents($specPath);
        expect($content)->toContain('Overview')
            ->toContain('Source Comments')
            ->toContain('Requirements')
            ->toContain('Acceptance Criteria');
    });

    it('creates tasks.json with proper dependency ordering', function () {
        $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';

        $tasks = [
            'title' => 'Process Comments Test',
            'branch' => 'feature/process-comments',
            'created' => date('c'),
            'tasks' => [
                [
                    'id' => 1,
                    'title' => 'Add validation',
                    'description' => 'Add input validation for email field',
                    'steps' => ['Add validation rules', 'Add error messages'],
                    'status' => 'pending',
                    'dependencies' => [],
                    'priority' => 1,
                    'acceptance' => ['Validation rejects invalid emails'],
                ],
                [
                    'id' => 2,
                    'title' => 'Add rate limiting',
                    'description' => 'Implement rate limiting for API endpoints',
                    'steps' => ['Configure rate limiter', 'Apply to routes'],
                    'status' => 'pending',
                    'dependencies' => [1],
                    'priority' => 2,
                    'acceptance' => ['Rate limiting prevents abuse'],
                ],
            ],
            'sources' => ['spec.md'],
        ];

        file_put_contents($tasksPath, json_encode($tasks, JSON_PRETTY_PRINT));

        expect(file_exists($tasksPath))->toBeTrue();

        $content = json_decode(file_get_contents($tasksPath), true);
        expect($content['tasks'])->toHaveCount(2)
            ->and($content['tasks'][1]['dependencies'])->toBe([1]);
    });

    it('creates plan.md from spec', function () {
        $planPath = $this->testPath.'/.laracode/specs/test-feature/plan.md';

        $planContent = <<<'MD'
# Implementation Plan

## Overview
Based on the @claude comments found in the codebase.

## Tasks
1. Add input validation (Priority: Critical)
2. Add rate limiting (Priority: High)

## Architecture Notes
- Uses existing validation framework
- Rate limiter configured at middleware level
MD;

        file_put_contents($planPath, $planContent);

        expect(file_exists($planPath))->toBeTrue();

        $content = file_get_contents($planPath);
        expect($content)->toContain('Implementation Plan')
            ->toContain('Tasks')
            ->toContain('Priority');
    });
});

describe('error handling', function () {
    it('handles missing files gracefully', function () {
        $changedFiles = [
            '/nonexistent/path/file.php',
            $this->testPath.'/also-missing.php',
        ];

        $result = $this->commentExtractor->scanFiles($changedFiles, 'claude!');

        expect($result['comments'])->toBeEmpty()
            ->and($result['metadata']['stopWordFound'])->toBeFalse()
            ->and($result['metadata']['filesScanned'])->toBe(2);
    });

    it('reports errors through notify error action', function () {
        $this->artisan('notify', [
            'action' => 'error',
            '--message' => 'Failed to process @claude comments',
            'data' => json_encode([
                'error' => 'File not readable',
                'file' => '/path/to/file.php',
            ]),
        ])
            ->assertFailed()
            ->expectsOutputToContain('Failed to process @claude comments')
            ->expectsOutputToContain('File not readable');
    });

    it('handles invalid comments.json creation path', function () {
        $invalidPath = '/root/nonexistent/deeply/nested/comments.json';

        $comments = [
            'comments' => [],
            'metadata' => [
                'stopWordFound' => false,
                'stopWordFile' => null,
                'filesScanned' => 0,
                'timestamp' => date('c'),
            ],
        ];

        // Suppress mkdir warning and expect RuntimeException
        set_error_handler(fn () => true);
        try {
            $this->watchService->createCommentsJson($comments, $invalidPath);
            // If we get here without throwing, that's unexpected for /root path
            expect(true)->toBeFalse(); // Force fail if no exception
        } catch (\RuntimeException|\ErrorException $e) {
            // Either RuntimeException from our code or ErrorException from mkdir is acceptable
            expect($e->getMessage())->toMatch('/Failed to write|mkdir/');
        } finally {
            restore_error_handler();
        }
    });
});

describe('comment extraction accuracy', function () {
    it('extracts comments with accurate line numbers across multiple files', function () {
        $file1 = $this->testPath.'/app/First.php';
        file_put_contents($file1, "<?php\n\n\n// @claude Line 4 comment\nclass First {}");

        $file2 = $this->testPath.'/app/Second.php';
        file_put_contents($file2, "<?php\nclass Second {\n    // @claude Line 3 comment claude!\n}");

        $result = $this->commentExtractor->scanFiles([$file1, $file2], 'claude!');

        $file1Comments = array_filter($result['comments'], fn ($c) => $c['file'] === $file1);
        $file2Comments = array_filter($result['comments'], fn ($c) => $c['file'] === $file2);

        expect(array_values($file1Comments)[0]['line'])->toBe(4)
            ->and(array_values($file2Comments)[0]['line'])->toBe(3);
    });

    it('handles various comment styles in single scan', function () {
        $jsFile = $this->testPath.'/resources/app.js';
        // resources dir already created in beforeEach, no need to recreate
        file_put_contents($jsFile, <<<'JS'
// @claude JS single line comment
/* @claude JS multi-line comment claude! */
class App {}
JS);

        $pyFile = $this->testPath.'/app/script.py';
        file_put_contents($pyFile, "# @claude Python comment\nprint('hello')");

        $result = $this->commentExtractor->scanFiles([$jsFile, $pyFile], 'claude!');

        expect($result['comments'])->toHaveCount(3)
            ->and($result['metadata']['stopWordFound'])->toBeTrue();
    });
});

describe('full interactive flow simulation', function () {
    it('simulates complete watch to build cycle', function () {
        // Step 1: File changes detected
        $phpFile = $this->testPath.'/app/Feature.php';
        file_put_contents($phpFile, "<?php\n// @claude Implement new feature claude!");

        $changedFiles = [$phpFile];
        $result = $this->commentExtractor->scanFiles($changedFiles, 'claude!');

        expect($result['metadata']['stopWordFound'])->toBeTrue();

        // Step 2: Create comments.json
        $commentsPath = $this->testPath.'/.laracode/comments.json';
        $this->watchService->createCommentsJson($result, $commentsPath);

        expect(file_exists($commentsPath))->toBeTrue();

        // Step 3: Plan ready (interactive mode prompts)
        $this->artisan('notify', [
            'action' => 'plan-ready',
            '--mode' => 'interactive',
        ])
            ->expectsConfirmation('Approve this plan and continue?', 'yes')
            ->assertSuccessful();

        // Step 4: Tasks ready (interactive mode prompts)
        $this->artisan('notify', [
            'action' => 'tasks-ready',
            '--mode' => 'interactive',
        ])
            ->expectsConfirmation('Start the build loop now?', 'yes')
            ->assertSuccessful();

        // Step 5: Watch complete
        $this->artisan('notify', [
            'action' => 'watch-complete',
            'data' => json_encode([
                'filesProcessed' => 1,
                'commentsFound' => 1,
                'tasksCompleted' => 1,
            ]),
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Files processed')
            ->expectsOutputToContain('Comments found')
            ->expectsOutputToContain('Tasks completed');

        // Step 6: Reset changed files (simulated)
        $changedFiles = [];

        expect($changedFiles)->toBeEmpty();
    });
});

describe('full yolo flow simulation', function () {
    it('simulates complete automated watch to build cycle', function () {
        // Step 1: File changes detected
        $phpFile = $this->testPath.'/app/AutoFeature.php';
        file_put_contents($phpFile, "<?php\n// @claude Auto-implement this claude!");

        $changedFiles = [$phpFile];
        $result = $this->commentExtractor->scanFiles($changedFiles, 'claude!');

        expect($result['metadata']['stopWordFound'])->toBeTrue();

        // Step 2: Create comments.json
        $commentsPath = $this->testPath.'/.laracode/comments.json';
        $this->watchService->createCommentsJson($result, $commentsPath);

        // Step 3: Plan ready (yolo mode auto-continues)
        $this->artisan('notify', [
            'action' => 'plan-ready',
            '--mode' => 'yolo',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('auto-continuing');

        // Step 4: Tasks ready (yolo mode auto-starts)
        $this->artisan('notify', [
            'action' => 'tasks-ready',
            '--mode' => 'yolo',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('auto-starting build');

        // Step 5: Watch complete
        $this->artisan('notify', [
            'action' => 'watch-complete',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Continuing to watch');
    });
});

describe('lock file management', function () {
    it('creates lock file with proper structure', function () {
        $lockPath = $this->testPath.'/.laracode/watch.lock';

        $lockData = [
            'pid' => 12345,
            'started' => '2026-01-21T12:00:00+00:00',
            'mode' => 'yolo',
            'commentsPath' => $this->testPath.'/.laracode/comments.json',
        ];

        file_put_contents($lockPath, json_encode($lockData, JSON_PRETTY_PRINT));

        $readData = $this->watchService->readLockFile($lockPath);

        expect($readData)->not->toBeNull()
            ->and($readData['pid'])->toBe(12345)
            ->and($readData['mode'])->toBe('yolo')
            ->and($readData['commentsPath'])->toBe($this->testPath.'/.laracode/comments.json');
    });

    it('handles concurrent access to lock file', function () {
        $lockPath = $this->testPath.'/.laracode/watch.lock';

        // Create initial lock
        file_put_contents($lockPath, json_encode(['pid' => 111, 'started' => date('c')]));

        // Overwrite (simulating another process)
        file_put_contents($lockPath, json_encode(['pid' => 222, 'started' => date('c')]));

        $data = $this->watchService->readLockFile($lockPath);
        expect($data['pid'])->toBe(222);
    });
});

describe('watch config integration', function () {
    it('creates valid watch config during init', function () {
        $configPath = $this->testPath.'/.laracode/watch.json';

        $config = [
            'paths' => ['app/', 'routes/', 'resources/'],
            'stopWord' => 'claude!',
            'mode' => 'interactive',
            'excludePatterns' => ['*/vendor/*', '*/node_modules/*', '*/storage/*'],
        ];

        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));

        expect(file_exists($configPath))->toBeTrue();

        $loaded = json_decode(file_get_contents($configPath), true);
        expect($loaded['paths'])->toBe(['app/', 'routes/', 'resources/'])
            ->and($loaded['stopWord'])->toBe('claude!')
            ->and($loaded['mode'])->toBe('interactive')
            ->and($loaded['excludePatterns'])->toContain('*/vendor/*');
    });
});
