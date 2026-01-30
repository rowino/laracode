<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-notify-test-'.uniqid();
    mkdir($this->testPath, 0755, true);
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

describe('build action', function () {
    it('fails when lock path is not provided', function () {
        $this->artisan('notify', ['action' => 'build'])
            ->assertFailed()
            ->expectsOutputToContain('Lock path is required');
    });

    it('fails when lock file does not exist', function () {
        $lockPath = $this->testPath.'/nonexistent.lock';

        $this->artisan('notify', ['action' => 'build', 'data' => $lockPath])
            ->assertFailed()
            ->expectsOutputToContain('Lock file not found');
    });

    it('fails when lock file contains invalid json', function () {
        $lockPath = $this->testPath.'/index.lock';
        file_put_contents($lockPath, 'not valid json {{{');

        $this->artisan('notify', ['action' => 'build', 'data' => $lockPath])
            ->assertFailed()
            ->expectsOutputToContain('Invalid JSON in lock file');
    });

    it('fails when lock file is missing pid field', function () {
        $lockPath = $this->testPath.'/index.lock';
        file_put_contents($lockPath, json_encode(['started' => time()]));

        $this->artisan('notify', ['action' => 'build', 'data' => $lockPath])
            ->assertFailed()
            ->expectsOutputToContain("missing 'pid' field");
    });

    it('fails when pid is not an integer', function () {
        $lockPath = $this->testPath.'/index.lock';
        file_put_contents($lockPath, json_encode(['pid' => 'not-a-number']));

        $this->artisan('notify', ['action' => 'build', 'data' => $lockPath])
            ->assertFailed()
            ->expectsOutputToContain("missing 'pid' field");
    });

    it('succeeds and removes lock file when process is not running', function () {
        $lockPath = $this->testPath.'/index.lock';
        file_put_contents($lockPath, json_encode(['pid' => 999999999]));

        $this->artisan('notify', ['action' => 'build', 'data' => $lockPath])
            ->assertSuccessful()
            ->expectsOutputToContain('is not running')
            ->expectsOutputToContain('Build signal processed and lock file cleaned up');

        expect(file_exists($lockPath))->toBeFalse();
    });

    it('terminates running process and deletes lock file', function () {
        $proc = proc_open('exec sleep 60', [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        expect($proc)->not->toBeFalse();

        $status = proc_get_status($proc);
        $pid = $status['pid'];

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(posix_kill($pid, 0))->toBeTrue();

        $lockPath = $this->testPath.'/index.lock';
        file_put_contents($lockPath, json_encode(['pid' => $pid]));

        $this->artisan('notify', ['action' => 'build', 'data' => $lockPath])
            ->assertSuccessful()
            ->expectsOutputToContain("Sending SIGTERM to process {$pid}")
            ->expectsOutputToContain('Build signal processed and lock file cleaned up');

        usleep(100000);
        proc_close($proc);

        expect(posix_kill($pid, 0))->toBeFalse();
        expect(file_exists($lockPath))->toBeFalse();
    });

    it('writes completed.json when task option is provided', function () {
        $lockPath = $this->testPath.'/index.lock';
        $started = '2026-01-14T10:00:00+00:00';
        file_put_contents($lockPath, json_encode(['pid' => 999999999, 'started' => $started]));

        $this->artisan('notify', ['action' => 'build', 'data' => $lockPath, '--task' => '5'])
            ->assertSuccessful();

        $completedPath = $this->testPath.'/completed.json';
        expect(file_exists($completedPath))->toBeTrue();

        $content = json_decode(file_get_contents($completedPath), true);
        expect($content)->toHaveKey('taskId')
            ->and($content['taskId'])->toBe(5)
            ->and($content)->toHaveKey('startedAt')
            ->and($content['startedAt'])->toBe($started)
            ->and($content)->toHaveKey('completedAt');
    });

    it('does not write completed.json when task option is not provided', function () {
        $lockPath = $this->testPath.'/index.lock';
        file_put_contents($lockPath, json_encode(['pid' => 999999999]));

        $this->artisan('notify', ['action' => 'build', 'data' => $lockPath])
            ->assertSuccessful();

        $completedPath = $this->testPath.'/completed.json';
        expect(file_exists($completedPath))->toBeFalse();
    });

    it('escalates to SIGKILL when SIGTERM does not stop process', function () {
        $scriptPath = $this->testPath.'/trap.pl';
        file_put_contents($scriptPath, <<<'PERL'
#!/usr/bin/env perl
$SIG{TERM} = 'IGNORE';
sleep(60);
PERL);
        chmod($scriptPath, 0755);

        $proc = proc_open("exec {$scriptPath}", [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        expect($proc)->not->toBeFalse();

        $status = proc_get_status($proc);
        $pid = $status['pid'];

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        usleep(100000);

        expect(posix_kill($pid, 0))->toBeTrue();

        $lockPath = $this->testPath.'/index.lock';
        file_put_contents($lockPath, json_encode(['pid' => $pid]));

        $this->artisan('notify', ['action' => 'build', 'data' => $lockPath])
            ->assertSuccessful()
            ->expectsOutputToContain("Sending SIGTERM to process {$pid}")
            ->expectsOutputToContain('sending SIGKILL')
            ->expectsOutputToContain('Build signal processed and lock file cleaned up');

        usleep(200000);
        proc_close($proc);

        expect(posix_kill($pid, 0))->toBeFalse();
        expect(file_exists($lockPath))->toBeFalse();
    });
});

describe('plan-ready action', function () {
    it('auto-continues in yolo mode without prompting', function () {
        $this->artisan('notify', [
            'action' => 'plan-ready',
            '--mode' => 'yolo',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('auto-continuing in yolo mode');
    });

    it('displays custom message when provided', function () {
        $this->artisan('notify', [
            'action' => 'plan-ready',
            '--mode' => 'yolo',
            '--message' => 'Custom plan message',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Custom plan message');
    });

    it('displays plan path when provided in data', function () {
        $data = json_encode(['planPath' => '/path/to/plan.md']);

        $this->artisan('notify', [
            'action' => 'plan-ready',
            'data' => $data,
            '--mode' => 'yolo',
        ])
            ->assertSuccessful();
    });

    it('prompts for approval in interactive mode', function () {
        $this->artisan('notify', [
            'action' => 'plan-ready',
            '--mode' => 'interactive',
        ])
            ->expectsConfirmation('Approve this plan and continue?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Plan approved');
    });

    it('fails when plan rejected in interactive mode', function () {
        $this->artisan('notify', [
            'action' => 'plan-ready',
            '--mode' => 'interactive',
        ])
            ->expectsConfirmation('Approve this plan and continue?', 'no')
            ->assertFailed()
            ->expectsOutputToContain('Plan rejected');
    });
});

describe('tasks-ready action', function () {
    it('auto-starts build in yolo mode without prompting', function () {
        $this->artisan('notify', [
            'action' => 'tasks-ready',
            '--mode' => 'yolo',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('auto-starting build in yolo mode');
    });

    it('displays custom message when provided', function () {
        $this->artisan('notify', [
            'action' => 'tasks-ready',
            '--mode' => 'yolo',
            '--message' => 'Tasks are ready!',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Tasks are ready!');
    });

    it('displays task info when provided in data', function () {
        $data = json_encode([
            'tasksPath' => '/path/to/tasks.json',
            'taskCount' => 10,
        ]);

        $this->artisan('notify', [
            'action' => 'tasks-ready',
            'data' => $data,
            '--mode' => 'yolo',
        ])
            ->assertSuccessful();
    });

    it('prompts to start build in interactive mode', function () {
        $this->artisan('notify', [
            'action' => 'tasks-ready',
            '--mode' => 'interactive',
        ])
            ->expectsConfirmation('Start the build loop now?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Starting build loop');
    });

    it('fails when build skipped in interactive mode', function () {
        $this->artisan('notify', [
            'action' => 'tasks-ready',
            '--mode' => 'interactive',
        ])
            ->expectsConfirmation('Start the build loop now?', 'no')
            ->assertFailed()
            ->expectsOutputToContain('Build skipped');
    });
});

describe('watch-complete action', function () {
    it('logs completion message', function () {
        $this->artisan('notify', ['action' => 'watch-complete'])
            ->assertSuccessful()
            ->expectsOutputToContain('Watch Cycle Complete')
            ->expectsOutputToContain('Continuing to watch');
    });

    it('displays custom message when provided', function () {
        $this->artisan('notify', [
            'action' => 'watch-complete',
            '--message' => 'All done!',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('All done!');
    });

    it('displays completion stats when provided in data', function () {
        $data = json_encode([
            'filesProcessed' => 5,
            'commentsFound' => 3,
            'tasksCompleted' => 2,
        ]);

        $this->artisan('notify', [
            'action' => 'watch-complete',
            'data' => $data,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Files processed:')
            ->expectsOutputToContain('Comments found:')
            ->expectsOutputToContain('Tasks completed:');
    });
});

describe('error action', function () {
    it('displays error and returns failure', function () {
        $this->artisan('notify', ['action' => 'error'])
            ->assertFailed()
            ->expectsOutputToContain('Error During Watch/Build');
    });

    it('displays custom error message', function () {
        $this->artisan('notify', [
            'action' => 'error',
            '--message' => 'Something went wrong!',
        ])
            ->assertFailed()
            ->expectsOutputToContain('Something went wrong!');
    });

    it('displays error details from data', function () {
        $data = json_encode([
            'error' => 'File not found',
            'file' => '/path/to/missing.php',
            'line' => 42,
        ]);

        $this->artisan('notify', [
            'action' => 'error',
            'data' => $data,
        ])
            ->assertFailed()
            ->expectsOutputToContain('File not found')
            ->expectsOutputToContain('/path/to/missing.php')
            ->expectsOutputToContain('42');
    });

    it('displays raw string data when not valid json', function () {
        $this->artisan('notify', [
            'action' => 'error',
            'data' => 'Raw error message',
        ])
            ->assertFailed()
            ->expectsOutputToContain('Raw error message');
    });
});

describe('unknown action', function () {
    it('returns error for invalid action', function () {
        $this->artisan('notify', ['action' => 'invalid-action'])
            ->assertFailed()
            ->expectsOutputToContain('Unknown action: invalid-action')
            ->expectsOutputToContain('Valid actions');
    });
});
