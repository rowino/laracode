<?php

declare(strict_types=1);

use App\Tui\Components\TaskList;
use App\Tui\DashboardState;

function makeTaskListState(array $tasks, ?int $activeTaskId = null): DashboardState
{
    $fullTasks = array_map(fn (array $t) => array_merge([
        'id' => 1,
        'status' => 'pending',
        'title' => 'Task',
        'dependencies' => [],
    ], $t), $tasks);

    return DashboardState::fromTasksArray(
        data: ['title' => 'Test', 'branch' => 'main', 'tasks' => $fullTasks],
        iteration: 1,
        maxIterations: 10,
        elapsed: 0,
        activeTaskId: $activeTaskId,
        mode: 'normal',
        statusMessage: '',
    );
}

describe('TaskList', function () {
    it('renders completed task with green filled icon and green title', function () {
        $state = makeTaskListState([
            ['id' => 1, 'status' => 'completed', 'title' => 'Done task'],
        ]);

        $html = (new TaskList)->render($state);

        expect($html)->toContain('text-green')
            ->and($html)->toContain('●')
            ->and($html)->toContain('US-1')
            ->and($html)->toContain('Done task')
            ->and($html)->toMatch('/<span class="text-green">Done task<\/span>/');
    });

    it('renders in_progress task with cyan filled icon and cyan bold title', function () {
        $state = makeTaskListState([
            ['id' => 2, 'status' => 'in_progress', 'title' => 'Active task'],
        ]);

        $html = (new TaskList)->render($state);

        expect($html)->toContain('text-cyan')
            ->and($html)->toContain('●')
            ->and($html)->toMatch('/<span class="text-cyan font-bold">Active task<\/span>/');
    });

    it('renders pending task with gray open icon', function () {
        $state = makeTaskListState([
            ['id' => 1, 'status' => 'pending', 'title' => 'Waiting', 'dependencies' => []],
        ]);

        $html = (new TaskList)->render($state);

        expect($html)->toContain('text-gray')
            ->and($html)->toContain('○');
    });

    it('renders blocked task with red open icon and red title', function () {
        $state = makeTaskListState([
            ['id' => 1, 'status' => 'pending', 'title' => 'Unblocked', 'dependencies' => []],
            ['id' => 2, 'status' => 'pending', 'title' => 'Blocked', 'dependencies' => [1]],
        ]);

        $html = (new TaskList)->render($state);

        expect($html)->toContain('text-red')
            ->and($html)->toContain('Blocked')
            ->and($html)->toMatch('/<span class="text-red">Blocked<\/span>/');
    });

    it('highlights active task row with text-white', function () {
        $state = makeTaskListState([
            ['id' => 1, 'status' => 'in_progress', 'title' => 'Active'],
            ['id' => 2, 'status' => 'pending', 'title' => 'Other'],
        ], activeTaskId: 1);

        $html = (new TaskList)->render($state);

        expect($html)->toContain('text-white')
            ->and($html)->not->toContain('bg-');
    });

    it('renders task id prefix as gray US-N', function () {
        $state = makeTaskListState([
            ['id' => 42, 'status' => 'pending', 'title' => 'My task'],
        ]);

        $html = (new TaskList)->render($state);

        expect($html)->toContain('US-42')
            ->and($html)->toMatch('/<span class="text-gray">US-42<\/span>/');
    });

    it('falls back to description when title is missing', function () {
        $state = makeTaskListState([
            ['id' => 1, 'status' => 'pending', 'description' => 'Fallback desc'],
        ]);

        // Remove 'title' key — the factory sets it, so we build manually
        $tasks = [['id' => 1, 'status' => 'pending', 'description' => 'Fallback desc', 'dependencies' => []]];
        $manualState = DashboardState::fromTasksArray(
            data: ['title' => 'Test', 'branch' => 'main', 'tasks' => $tasks],
            iteration: 1, maxIterations: 10, elapsed: 0,
            activeTaskId: null, mode: 'normal', statusMessage: '',
        );

        $html = (new TaskList)->render($manualState);

        expect($html)->toContain('Fallback desc');
    });

    it('renders selected row with cyan cursor and text-white', function () {
        $state = makeTaskListState([
            ['id' => 1, 'status' => 'pending', 'title' => 'First'],
            ['id' => 2, 'status' => 'pending', 'title' => 'Second'],
        ]);

        $html = (new TaskList)->render($state, selectedTaskIndex: 1);

        expect($html)->toContain('<span class="text-cyan">▸</span>')
            ->and($html)->toMatch('/<div class="text-white px-2">.*▸.*Second/s');
    });

    it('does not show cursor when selectedTaskIndex is null', function () {
        $state = makeTaskListState([
            ['id' => 1, 'status' => 'pending', 'title' => 'Task'],
        ]);

        $html = (new TaskList)->render($state);

        expect($html)->not->toContain('▸');
    });

    it('preserves active task styling independently from selection', function () {
        $state = makeTaskListState([
            ['id' => 1, 'status' => 'in_progress', 'title' => 'Active'],
            ['id' => 2, 'status' => 'pending', 'title' => 'Selected'],
        ], activeTaskId: 1);

        $html = (new TaskList)->render($state, selectedTaskIndex: 1);

        expect($html)->toMatch('/<span class="text-cyan font-bold">Active<\/span>/')
            ->and($html)->toMatch('/<div class="text-white px-2">.*▸.*Selected/s');
    });

    it('shows cursor on active task when selected', function () {
        $state = makeTaskListState([
            ['id' => 1, 'status' => 'in_progress', 'title' => 'Active'],
        ], activeTaskId: 1);

        $html = (new TaskList)->render($state, selectedTaskIndex: 0);

        expect($html)->toContain('▸')
            ->and($html)->toContain('text-white');
    });
});
