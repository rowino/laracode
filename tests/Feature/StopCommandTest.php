<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-stop-test-'.uniqid();
    mkdir($this->testPath, 0755, true);
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

it('fails when lock file does not exist', function () {
    $lockPath = $this->testPath.'/nonexistent.lock';

    $this->artisan('stop', ['path' => $lockPath])
        ->assertFailed()
        ->expectsOutputToContain('Lock file not found');
});

it('fails when lock file contains invalid json', function () {
    $lockPath = $this->testPath.'/index.lock';
    file_put_contents($lockPath, 'not valid json {{{');

    $this->artisan('stop', ['path' => $lockPath])
        ->assertFailed()
        ->expectsOutputToContain('Invalid JSON in lock file');
});

it('fails when lock file is missing pid field', function () {
    $lockPath = $this->testPath.'/index.lock';
    file_put_contents($lockPath, json_encode(['started' => time()]));

    $this->artisan('stop', ['path' => $lockPath])
        ->assertFailed()
        ->expectsOutputToContain("missing 'pid' field");
});

it('fails when pid is not an integer', function () {
    $lockPath = $this->testPath.'/index.lock';
    file_put_contents($lockPath, json_encode(['pid' => 'not-a-number']));

    $this->artisan('stop', ['path' => $lockPath])
        ->assertFailed()
        ->expectsOutputToContain("missing 'pid' field");
});

it('succeeds and removes lock file when process is not running', function () {
    $lockPath = $this->testPath.'/index.lock';
    // Use a PID that definitely doesn't exist (max PID is usually around 32768 or 4194304)
    file_put_contents($lockPath, json_encode(['pid' => 999999999]));

    $this->artisan('stop', ['path' => $lockPath])
        ->assertSuccessful()
        ->expectsOutputToContain('is not running')
        ->expectsOutputToContain('Stopped build and cleaned up lock file');

    expect(file_exists($lockPath))->toBeFalse();
});

it('deletes lock file after handling non-running process', function () {
    $lockPath = $this->testPath.'/index.lock';
    file_put_contents($lockPath, json_encode(['pid' => 999999999]));

    expect(file_exists($lockPath))->toBeTrue();

    $this->artisan('stop', ['path' => $lockPath])
        ->assertSuccessful();

    expect(file_exists($lockPath))->toBeFalse();
});

it('terminates running process and deletes lock file', function () {
    // Use exec to replace shell with sleep (no shell wrapper)
    $proc = proc_open('exec sleep 60', [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    expect($proc)->not->toBeFalse();

    $status = proc_get_status($proc);
    $pid = $status['pid'];

    // Close pipes so process isn't blocked on I/O
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    // Verify the process is running
    expect(posix_kill($pid, 0))->toBeTrue();

    $lockPath = $this->testPath.'/index.lock';
    file_put_contents($lockPath, json_encode(['pid' => $pid]));

    $this->artisan('stop', ['path' => $lockPath])
        ->assertSuccessful()
        ->expectsOutputToContain("Sending SIGTERM to process {$pid}")
        ->expectsOutputToContain('Stopped build and cleaned up lock file');

    // Give time for process cleanup and reap zombie
    usleep(100000);
    proc_close($proc);

    // Process should be terminated
    expect(posix_kill($pid, 0))->toBeFalse();
    expect(file_exists($lockPath))->toBeFalse();
});

it('escalates to SIGKILL when SIGTERM does not stop process', function () {
    // This test is tricky because we need a process that ignores SIGTERM
    // Use exec to replace shell so PID is correct, then trap in a subshell
    // Using a perl script as it handles signals more predictably
    $scriptPath = $this->testPath.'/trap.pl';
    file_put_contents($scriptPath, <<<'PERL'
#!/usr/bin/env perl
$SIG{TERM} = 'IGNORE';
sleep(60);
PERL);
    chmod($scriptPath, 0755);

    // Use exec to make the script's PID match the proc_open PID
    $proc = proc_open("exec {$scriptPath}", [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    expect($proc)->not->toBeFalse();

    $status = proc_get_status($proc);
    $pid = $status['pid'];

    // Close pipes so process isn't blocked on I/O
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    // Allow time for signal handler to be set up
    usleep(100000);

    // Verify the process is running
    expect(posix_kill($pid, 0))->toBeTrue();

    $lockPath = $this->testPath.'/index.lock';
    file_put_contents($lockPath, json_encode(['pid' => $pid]));

    $this->artisan('stop', ['path' => $lockPath])
        ->assertSuccessful()
        ->expectsOutputToContain("Sending SIGTERM to process {$pid}")
        ->expectsOutputToContain('sending SIGKILL')
        ->expectsOutputToContain('Stopped build and cleaned up lock file');

    // Give time for process cleanup and reap zombie
    usleep(200000);
    proc_close($proc);

    // Process should be terminated
    expect(posix_kill($pid, 0))->toBeFalse();
    expect(file_exists($lockPath))->toBeFalse();
});
