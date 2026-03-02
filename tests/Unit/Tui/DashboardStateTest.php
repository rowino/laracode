<?php

declare(strict_types=1);

use App\Tui\DashboardState;

function makeTasks(array $overrides = []): array
{
    return array_map(fn (array $task) => array_merge([
        'id' => 1,
        'status' => 'pending',
        'title' => 'Task',
        'description' => 'Description',
        'dependencies' => [],
        'priority' => 3,
    ], $task), $overrides);
}

function makeState(array $tasks = [], array $extra = []): DashboardState
{
    $data = array_merge([
        'title' => 'Test Feature',
        'branch' => 'feature/test',
        'tasks' => $tasks,
    ], $extra);

    return DashboardState::fromTasksArray(
        data: $data,
        iteration: $extra['iteration'] ?? 1,
        maxIterations: $extra['maxIterations'] ?? 10,
        elapsed: $extra['elapsed'] ?? 60,
        activeTaskId: $extra['activeTaskId'] ?? null,
        mode: $extra['mode'] ?? 'normal',
        statusMessage: $extra['statusMessage'] ?? 'Running',
    );
}

describe('fromTasksArray', function () {
    it('extracts featureTitle and branch from data', function () {
        $state = makeState();

        expect($state->featureTitle)->toBe('Test Feature')
            ->and($state->branch)->toBe('feature/test');
    });

    it('defaults featureTitle to Untitled when missing', function () {
        $state = DashboardState::fromTasksArray(
            data: ['branch' => 'main'],
            iteration: 1,
            maxIterations: 5,
            elapsed: 0,
            activeTaskId: null,
            mode: 'normal',
            statusMessage: '',
        );

        expect($state->featureTitle)->toBe('Untitled');
    });

    it('defaults branch to unknown when missing', function () {
        $state = DashboardState::fromTasksArray(
            data: ['title' => 'Foo'],
            iteration: 1,
            maxIterations: 5,
            elapsed: 0,
            activeTaskId: null,
            mode: 'normal',
            statusMessage: '',
        );

        expect($state->branch)->toBe('unknown');
    });

    it('defaults tasks to empty array when missing', function () {
        $state = DashboardState::fromTasksArray(
            data: [],
            iteration: 1,
            maxIterations: 5,
            elapsed: 0,
            activeTaskId: null,
            mode: 'normal',
            statusMessage: '',
        );

        expect($state->tasks)->toBe([]);
    });

    it('passes through iteration, timing, mode, and status', function () {
        $state = makeState([], [
            'iteration' => 3,
            'maxIterations' => 8,
            'elapsed' => 120,
            'activeTaskId' => 5,
            'mode' => 'yolo',
            'statusMessage' => 'Building...',
        ]);

        expect($state->currentIteration)->toBe(3)
            ->and($state->maxIterations)->toBe(8)
            ->and($state->elapsedSeconds)->toBe(120)
            ->and($state->activeTaskId)->toBe(5)
            ->and($state->mode)->toBe('yolo')
            ->and($state->statusMessage)->toBe('Building...');
    });
});

describe('completedCount', function () {
    it('counts completed tasks', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'completed'],
            ['id' => 2, 'status' => 'completed'],
            ['id' => 3, 'status' => 'pending'],
            ['id' => 4, 'status' => 'in_progress'],
        ]);

        expect(makeState($tasks)->completedCount())->toBe(2);
    });

    it('returns zero when none completed', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'pending'],
            ['id' => 2, 'status' => 'in_progress'],
        ]);

        expect(makeState($tasks)->completedCount())->toBe(0);
    });
});

describe('pendingCount', function () {
    it('counts pending tasks', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'completed'],
            ['id' => 2, 'status' => 'pending'],
            ['id' => 3, 'status' => 'pending'],
            ['id' => 4, 'status' => 'in_progress'],
        ]);

        expect(makeState($tasks)->pendingCount())->toBe(2);
    });

    it('returns zero when none pending', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'completed'],
            ['id' => 2, 'status' => 'in_progress'],
        ]);

        expect(makeState($tasks)->pendingCount())->toBe(0);
    });
});

describe('blockedCount', function () {
    it('counts tasks blocked by unsatisfied dependencies', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'pending', 'dependencies' => []],
            ['id' => 2, 'status' => 'pending', 'dependencies' => [1]],
            ['id' => 3, 'status' => 'pending', 'dependencies' => [1]],
        ]);

        expect(makeState($tasks)->blockedCount())->toBe(2);
    });

    it('returns zero when all pending tasks have satisfied deps', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'completed', 'dependencies' => []],
            ['id' => 2, 'status' => 'pending', 'dependencies' => [1]],
            ['id' => 3, 'status' => 'pending', 'dependencies' => []],
        ]);

        expect(makeState($tasks)->blockedCount())->toBe(0);
    });

    it('does not count completed or in_progress tasks as blocked', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'completed', 'dependencies' => [99]],
            ['id' => 2, 'status' => 'in_progress', 'dependencies' => [99]],
        ]);

        expect(makeState($tasks)->blockedCount())->toBe(0);
    });

    it('counts task blocked when any dependency unsatisfied', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'completed', 'dependencies' => []],
            ['id' => 2, 'status' => 'pending', 'dependencies' => []],
            ['id' => 3, 'status' => 'pending', 'dependencies' => [1, 2]],
        ]);

        expect(makeState($tasks)->blockedCount())->toBe(1);
    });

    it('treats tasks without dependencies as not blocked', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'pending'],
            ['id' => 2, 'status' => 'pending'],
        ]);

        expect(makeState($tasks)->blockedCount())->toBe(0);
    });
});

describe('totalCount', function () {
    it('returns total number of tasks', function () {
        $tasks = makeTasks([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ]);

        expect(makeState($tasks)->totalCount())->toBe(3);
    });

    it('returns zero for empty task list', function () {
        expect(makeState([])->totalCount())->toBe(0);
    });
});

describe('progressPercent', function () {
    it('calculates percentage of completed tasks', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'completed'],
            ['id' => 2, 'status' => 'completed'],
            ['id' => 3, 'status' => 'pending'],
            ['id' => 4, 'status' => 'pending'],
        ]);

        expect(makeState($tasks)->progressPercent())->toBe(50.0);
    });

    it('returns 0 when no tasks exist', function () {
        expect(makeState([])->progressPercent())->toBe(0.0);
    });

    it('returns 100 when all tasks completed', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'completed'],
            ['id' => 2, 'status' => 'completed'],
        ]);

        expect(makeState($tasks)->progressPercent())->toBe(100.0);
    });

    it('returns 0 when no tasks completed', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'pending'],
            ['id' => 2, 'status' => 'pending'],
        ]);

        expect(makeState($tasks)->progressPercent())->toBe(0.0);
    });

    it('rounds to one decimal', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'completed'],
            ['id' => 2, 'status' => 'pending'],
            ['id' => 3, 'status' => 'pending'],
        ]);

        expect(makeState($tasks)->progressPercent())->toBe(33.3);
    });
});

describe('activeTask', function () {
    it('returns the active task by id', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'completed', 'title' => 'First'],
            ['id' => 2, 'status' => 'in_progress', 'title' => 'Second'],
            ['id' => 3, 'status' => 'pending', 'title' => 'Third'],
        ]);

        $state = makeState($tasks, ['activeTaskId' => 2]);

        expect($state->activeTask())->not->toBeNull()
            ->and($state->activeTask()['id'])->toBe(2)
            ->and($state->activeTask()['title'])->toBe('Second');
    });

    it('returns null when activeTaskId is null', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'pending'],
        ]);

        $state = makeState($tasks, ['activeTaskId' => null]);

        expect($state->activeTask())->toBeNull();
    });

    it('returns null when activeTaskId does not match any task', function () {
        $tasks = makeTasks([
            ['id' => 1, 'status' => 'pending'],
            ['id' => 2, 'status' => 'pending'],
        ]);

        $state = makeState($tasks, ['activeTaskId' => 99]);

        expect($state->activeTask())->toBeNull();
    });
});
