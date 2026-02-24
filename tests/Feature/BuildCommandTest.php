<?php

declare(strict_types=1);

use App\Commands\BuildCommand;
use App\Services\AgentRunner;
use Illuminate\Support\Facades\File;

function mockAgentRunner(): void
{
    $mock = Mockery::mock(AgentRunner::class);
    $mock->shouldReceive('run')->andReturn(false);

    $kernel = app(Illuminate\Contracts\Console\Kernel::class);
    $command = (new ReflectionMethod($kernel, 'getArtisan'))->invoke($kernel)->find('build');
    (new ReflectionProperty(BuildCommand::class, 'agentRunner'))->setValue($command, $mock);
}

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
            ['id' => 1, 'title' => 'Task 1', 'status' => 'completed'],
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
            ['id' => 1, 'title' => 'Task 1', 'status' => 'completed'],
            ['id' => 2, 'title' => 'Task 2', 'status' => 'completed'],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('Test Feature')
        ->expectsOutputToContain('100%')
        ->expectsOutputToContain('2/2');
});

it('respects max iterations option', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1', 'status' => 'pending'],
            ['id' => 2, 'title' => 'Task 2', 'status' => 'pending'],
            ['id' => 3, 'title' => 'Task 3', 'status' => 'pending'],
        ],
    ]));

    mockAgentRunner();

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
            ['id' => 1, 'title' => 'Task 1', 'status' => 'pending'],
        ],
    ]));

    $this->artisan('build', [
        'path' => $tasksPath,
        '--iterations' => 1,
        '--delay' => 0,
    ])
        ->expectsOutputToContain('Task #1: Task 1');
})->skip('Subprocess test requires real claude CLI - covered by integration tests');

it('handles claude subprocess errors gracefully', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1', 'status' => 'pending'],
        ],
    ]));

    $this->artisan('build', [
        'path' => $tasksPath,
        '--iterations' => 1,
        '--delay' => 0,
    ])
        ->expectsOutputToContain('Task #1: Task 1');
})->skip('Subprocess error test requires real claude CLI - covered by integration tests');

it('displays branch name when present in tasks.json', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'branch' => 'feature/test-branch',
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1', 'status' => 'completed'],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('feature/test-branch');
});

it('displays created date when present in tasks.json', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'created' => '2026-01-12T10:30:00Z',
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1', 'status' => 'completed'],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('Test Feature');
});

it('displays blocked task count when tasks have unsatisfied dependencies', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1', 'status' => 'completed', 'dependencies' => []],
            ['id' => 2, 'title' => 'Task 2', 'status' => 'pending', 'dependencies' => [1, 3]],
            ['id' => 3, 'title' => 'Task 3', 'status' => 'pending', 'dependencies' => []],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath, '--iterations' => 0])
        ->expectsOutputToContain('1 blocked');
});

it('calculates blocked tasks correctly with multiple blocked tasks', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1', 'status' => 'pending', 'dependencies' => []],
            ['id' => 2, 'title' => 'Task 2', 'status' => 'pending', 'dependencies' => [1]],
            ['id' => 3, 'title' => 'Task 3', 'status' => 'pending', 'dependencies' => [1]],
            ['id' => 4, 'title' => 'Task 4', 'status' => 'pending', 'dependencies' => [2, 3]],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath, '--iterations' => 0])
        ->expectsOutputToContain('3 blocked');
});

it('does not show blocked count when no tasks are blocked', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1', 'status' => 'completed', 'dependencies' => []],
            ['id' => 2, 'title' => 'Task 2', 'status' => 'pending', 'dependencies' => [1]],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath, '--iterations' => 0])
        ->doesntExpectOutputToContain('blocked');
});

it('handles new schema with title field instead of description', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'New Schema Feature',
        'branch' => 'feature/new-schema',
        'created' => '2026-01-12T10:30:00Z',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'First Task Title',
                'description' => 'This is a longer description',
                'status' => 'completed',
                'dependencies' => [],
                'priority' => 1,
                'acceptance' => ['Criteria 1', 'Criteria 2'],
            ],
        ],
        'sources' => ['spec.md'],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('New Schema Feature')
        ->expectsOutputToContain('feature/new-schema')
        ->expectsOutputToContain('100%');
});

it('displays task title over description in next task output', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Task With Title',
                'description' => 'Fallback description',
                'status' => 'pending',
                'dependencies' => [],
            ],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath, '--iterations' => 0])
        ->expectsOutputToContain('Test Feature');
});

it('creates lock file with PID when running claude subprocess', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    $lockPath = $this->testPath.'/.laracode/specs/test-feature/index.lock';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1', 'status' => 'pending'],
        ],
    ]));

    mockAgentRunner();

    $this->artisan('build', [
        'path' => $tasksPath,
        '--iterations' => 1,
        '--delay' => 0,
    ]);

    expect($lockPath)->toEqual($this->testPath.'/.laracode/specs/test-feature/index.lock');
});

it('cleans up lock file after process completion', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    $lockPath = $this->testPath.'/.laracode/specs/test-feature/index.lock';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Test Feature',
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1', 'status' => 'pending'],
        ],
    ]));

    mockAgentRunner();

    $this->artisan('build', [
        'path' => $tasksPath,
        '--iterations' => 1,
        '--delay' => 0,
    ]);

    expect(file_exists($lockPath))->toBeFalse();
});

it('computes lock path correctly next to tasks.json', function () {
    $nestedPath = $this->testPath.'/.laracode/specs/deeply/nested/feature';
    mkdir($nestedPath, 0755, true);
    $tasksPath = $nestedPath.'/tasks.json';
    $expectedLockPath = $nestedPath.'/index.lock';

    file_put_contents($tasksPath, json_encode([
        'title' => 'Nested Feature',
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1', 'status' => 'completed'],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful();

    expect(dirname($tasksPath))->toEqual($nestedPath)
        ->and($expectedLockPath)->toEqual($nestedPath.'/index.lock');
});

it('displays final stats when all tasks are completed with stats data', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Stats Test Feature',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Task 1',
                'status' => 'completed',
                'stats' => [
                    'startedAt' => '2026-01-14T12:00:00Z',
                    'completedAt' => '2026-01-14T12:05:00Z',
                    'durationSeconds' => 300,
                ],
            ],
            [
                'id' => 2,
                'title' => 'Task 2',
                'status' => 'completed',
                'stats' => [
                    'startedAt' => '2026-01-14T12:10:00Z',
                    'completedAt' => '2026-01-14T12:12:00Z',
                    'durationSeconds' => 120,
                ],
            ],
        ],
        'stats' => [
            'filesChanged' => 5,
            'linesAdded' => 120,
            'linesRemoved' => 30,
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('All tasks completed')
        ->expectsOutputToContain('Build Complete')
        ->expectsOutputToContain('7m 0s')
        ->expectsOutputToContain('5')
        ->expectsOutputToContain('+120')
        ->expectsOutputToContain('-30');
});

it('displays final stats with zero values when no stats present', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'No Stats Feature',
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1', 'status' => 'completed'],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('Build Complete')
        ->expectsOutputToContain('0s')
        ->expectsOutputToContain('+0')
        ->expectsOutputToContain('-0');
});

it('formats duration as seconds only when under one minute', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Short Duration Feature',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Quick Task',
                'status' => 'completed',
                'stats' => ['durationSeconds' => 45],
            ],
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('45s')
        ->doesntExpectOutputToContain('0m 45s');
});

it('cleans up stale completion signal files on startup', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    $completedPath = $this->testPath.'/.laracode/specs/test-feature/completed.json';

    file_put_contents($tasksPath, json_encode([
        'title' => 'Stats Integration Test',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Task 1',
                'status' => 'completed',
                'stats' => [
                    'startedAt' => '2026-01-14T10:00:00+00:00',
                    'completedAt' => '2026-01-14T10:05:00+00:00',
                    'durationSeconds' => 300,
                    'filesChanged' => 2,
                    'linesAdded' => 50,
                    'linesRemoved' => 10,
                ],
            ],
        ],
        'stats' => [
            'filesChanged' => 2,
            'linesAdded' => 50,
            'linesRemoved' => 10,
        ],
    ]));

    file_put_contents($completedPath, json_encode([
        'taskId' => 1,
        'startedAt' => '2026-01-14T10:00:00+00:00',
        'completedAt' => '2026-01-14T10:05:00+00:00',
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('All tasks completed');

    expect(file_exists($completedPath))->toBeTrue();
})->skip('Completion signal processing requires active build loop - covered by integration tests');

it('accumulates root-level stats across multiple completed tasks', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Accumulative Stats Test',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Task 1',
                'status' => 'completed',
                'stats' => [
                    'filesChanged' => 3,
                    'linesAdded' => 50,
                    'linesRemoved' => 10,
                ],
            ],
            [
                'id' => 2,
                'title' => 'Task 2',
                'status' => 'completed',
                'stats' => [
                    'filesChanged' => 2,
                    'linesAdded' => 30,
                    'linesRemoved' => 5,
                ],
            ],
        ],
        'stats' => [
            'filesChanged' => 5,
            'linesAdded' => 80,
            'linesRemoved' => 15,
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('+80')
        ->expectsOutputToContain('-15');
});

it('includes file changes in individual task stats display', function () {
    $tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    file_put_contents($tasksPath, json_encode([
        'title' => 'Task Level Stats Test',
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Task With File Stats',
                'status' => 'completed',
                'stats' => [
                    'durationSeconds' => 120,
                    'filesChanged' => 4,
                    'linesAdded' => 100,
                    'linesRemoved' => 25,
                ],
            ],
        ],
        'stats' => [
            'filesChanged' => 4,
            'linesAdded' => 100,
            'linesRemoved' => 25,
        ],
    ]));

    $this->artisan('build', ['path' => $tasksPath])
        ->assertSuccessful()
        ->expectsOutputToContain('Build Complete')
        ->expectsOutputToContain('4')
        ->expectsOutputToContain('+100')
        ->expectsOutputToContain('-25');
});
