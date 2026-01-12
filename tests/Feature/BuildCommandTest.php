<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-build-test-'.uniqid();
    mkdir($this->testPath.'/.laracode/specs/test-feature', 0755, true);
    mkdir($this->testPath.'/.claude', 0755, true);
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

it('fails when tasks file does not exist', function () {
    $this->artisan('build', ['path' => $this->testPath.'/nonexistent.json'])
        ->assertFailed()
        ->expectsOutputToContain('Tasks file not found');
});

it('fails when tasks file has invalid json', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, 'invalid json {{{');

    $this->artisan('build', ['path' => $tasksPath])
        ->assertFailed()
        ->expectsOutputToContain('Invalid JSON');
});

it('fails when tasks array is missing', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode(['feature' => 'Test']));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertFailed()
        ->expectsOutputToContain("must contain a 'tasks' array");
});

it('exits successfully when all tasks are completed', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'description' => 'Task 1', 'status' => 'completed'],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('All tasks completed');
});

it('displays progress stats correctly', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'description' => 'Task 1', 'status' => 'completed'],
            ['id' => 2, 'description' => 'Task 2', 'status' => 'completed'],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('Test Feature')
        ->expectsOutputToContain('100%')
        ->expectsOutputToContain('2/2 completed');
});

it('respects max iterations option', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'description' => 'Task 1', 'status' => 'pending'],
            ['id' => 2, 'description' => 'Task 2', 'status' => 'pending'],
            ['id' => 3, 'description' => 'Task 3', 'status' => 'pending'],
        ],
    ]));

    Process::fake([
        '*' => Process::result(output: 'Task completed'),
    ]);

    $this->artisan('build', [
        'path' => $tasksPath,
        '--iterations' => 1,
        '--delay' => 0,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Reached max iterations (1)');
});

it('invokes claude subprocess for pending tasks', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'description' => 'Task 1', 'status' => 'pending'],
        ],
    ]));

    Process::fake([
        '*' => Process::result(output: 'Task completed successfully'),
    ]);

    $this->artisan('build', [
        'path' => $tasksPath,
        '--iterations' => 1,
        '--delay' => 0,
    ])->assertSuccessful();

    Process::assertRan(function (PendingProcess $process): bool {
        return in_array('claude', $process->command, true)
            && in_array('/build-next', $process->command, true);
    });
});

it('handles claude subprocess errors gracefully', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'description' => 'Task 1', 'status' => 'pending'],
        ],
    ]));

    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: 'Something went wrong',
            exitCode: 1
        ),
    ]);

    $this->artisan('build', [
        'path' => $tasksPath,
        '--iterations' => 1,
        '--delay' => 0,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Claude exited with error');
});
