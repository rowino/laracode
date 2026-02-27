<?php

declare(strict_types=1);

use App\Tui\Components\TaskDetail;
use App\Tui\DashboardState;

function makeDetailState(array $tasks, ?int $activeTaskId = null): DashboardState
{
    return DashboardState::fromTasksArray(
        data: ['title' => 'Test', 'branch' => 'main', 'tasks' => $tasks],
        iteration: 1,
        maxIterations: 10,
        elapsed: 0,
        activeTaskId: $activeTaskId,
        mode: 'normal',
        statusMessage: '',
    );
}

describe('TaskDetail', function () {
    it('shows fallback when no active task', function () {
        $state = makeDetailState([
            ['id' => 1, 'status' => 'pending', 'title' => 'Task'],
        ]);

        $html = (new TaskDetail)->render($state);

        expect($html)->toContain('No task in progress');
    });

    it('renders active task title in bold cyan', function () {
        $state = makeDetailState([
            ['id' => 1, 'status' => 'in_progress', 'title' => 'Build the widget', 'description' => 'desc', 'priority' => 2],
        ], activeTaskId: 1);

        $html = (new TaskDetail)->render($state);

        expect($html)->toContain('font-bold text-cyan-400')
            ->and($html)->toContain('Build the widget');
    });

    it('renders in_progress status badge', function () {
        $state = makeDetailState([
            ['id' => 1, 'status' => 'in_progress', 'title' => 'Task', 'description' => '', 'priority' => 3],
        ], activeTaskId: 1);

        $html = (new TaskDetail)->render($state);

        expect($html)->toContain('text-cyan-400 font-bold')
            ->and($html)->toContain('in_progress')
            ->and($html)->not->toContain('bg-');
    });

    it('renders completed status badge', function () {
        $state = makeDetailState([
            ['id' => 1, 'status' => 'completed', 'title' => 'Task', 'description' => '', 'priority' => 3],
        ], activeTaskId: 1);

        $html = (new TaskDetail)->render($state);

        expect($html)->toContain('text-green-400 font-bold')
            ->and($html)->toContain('completed')
            ->and($html)->not->toContain('bg-');
    });

    it('renders priority value in yellow', function () {
        $state = makeDetailState([
            ['id' => 1, 'status' => 'in_progress', 'title' => 'Task', 'description' => '', 'priority' => 1],
        ], activeTaskId: 1);

        $html = (new TaskDetail)->render($state);

        expect($html)->toContain('Priority:')
            ->and($html)->toMatch('/<span class="text-yellow-400">1<\/span>/');
    });

    it('renders description text in white', function () {
        $state = makeDetailState([
            ['id' => 1, 'status' => 'in_progress', 'title' => 'Task', 'description' => 'Some detailed desc', 'priority' => 3],
        ], activeTaskId: 1);

        $html = (new TaskDetail)->render($state);

        expect($html)->toContain('Some detailed desc')
            ->and($html)->toContain('text-white');
    });

    it('renders acceptance criteria with green checkmarks', function () {
        $state = makeDetailState([
            [
                'id' => 1,
                'status' => 'in_progress',
                'title' => 'Task',
                'description' => '',
                'priority' => 3,
                'acceptance' => ['Tests pass', 'No regressions'],
            ],
        ], activeTaskId: 1);

        $html = (new TaskDetail)->render($state);

        expect($html)->toContain('Acceptance:')
            ->and($html)->toContain('Tests pass')
            ->and($html)->toContain('No regressions')
            ->and($html)->toContain('text-green-400')
            ->and($html)->toContain('✓');
    });

    it('omits acceptance section when empty', function () {
        $state = makeDetailState([
            ['id' => 1, 'status' => 'in_progress', 'title' => 'Task', 'description' => '', 'priority' => 3],
        ], activeTaskId: 1);

        $html = (new TaskDetail)->render($state);

        expect($html)->not->toContain('Acceptance:');
    });

    it('falls back to description when title missing', function () {
        $state = makeDetailState([
            ['id' => 1, 'status' => 'in_progress', 'description' => 'Fallback desc', 'priority' => 3],
        ], activeTaskId: 1);

        $html = (new TaskDetail)->render($state);

        expect($html)->toContain('Fallback desc');
    });
});
