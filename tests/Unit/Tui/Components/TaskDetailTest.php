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

        expect($html)->toContain('font-bold text-cyan')
            ->and($html)->toContain('Build the widget');
    });

    it('renders in_progress status badge', function () {
        $state = makeDetailState([
            ['id' => 1, 'status' => 'in_progress', 'title' => 'Task', 'description' => '', 'priority' => 3],
        ], activeTaskId: 1);

        $html = (new TaskDetail)->render($state);

        expect($html)->toContain('text-cyan font-bold')
            ->and($html)->toContain('in_progress')
            ->and($html)->not->toContain('bg-');
    });

    it('renders completed status badge', function () {
        $state = makeDetailState([
            ['id' => 1, 'status' => 'completed', 'title' => 'Task', 'description' => '', 'priority' => 3],
        ], activeTaskId: 1);

        $html = (new TaskDetail)->render($state);

        expect($html)->toContain('text-green font-bold')
            ->and($html)->toContain('completed')
            ->and($html)->not->toContain('bg-');
    });

    it('renders priority value in yellow', function () {
        $state = makeDetailState([
            ['id' => 1, 'status' => 'in_progress', 'title' => 'Task', 'description' => '', 'priority' => 1],
        ], activeTaskId: 1);

        $html = (new TaskDetail)->render($state);

        expect($html)->toContain('Priority:')
            ->and($html)->toMatch('/<span class="text-yellow">1<\/span>/');
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
            ->and($html)->toContain('text-green')
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

describe('TaskDetail::renderTask', function () {
    it('shows fallback for null task', function () {
        $html = (new TaskDetail)->renderTask(null);

        expect($html)->toContain('No task in progress');
    });

    it('renders steps as numbered list', function () {
        $task = [
            'id' => 1,
            'title' => 'Setup project',
            'status' => 'in_progress',
            'priority' => 2,
            'description' => '',
            'steps' => ['Install deps', 'Run migrations', 'Seed database'],
        ];

        $html = (new TaskDetail)->renderTask($task);

        expect($html)->toContain('Steps:')
            ->and($html)->toContain('<span class="text-yellow">1.</span> Install deps')
            ->and($html)->toContain('<span class="text-yellow">2.</span> Run migrations')
            ->and($html)->toContain('<span class="text-yellow">3.</span> Seed database');
    });

    it('omits steps section when empty', function () {
        $task = [
            'id' => 1,
            'title' => 'Task',
            'status' => 'pending',
            'priority' => 3,
            'description' => '',
        ];

        $html = (new TaskDetail)->renderTask($task);

        expect($html)->not->toContain('Steps:');
    });

    it('renders stats for completed task', function () {
        $task = [
            'id' => 1,
            'title' => 'Done task',
            'status' => 'completed',
            'priority' => 2,
            'description' => '',
            'stats' => [
                'durationSeconds' => 96,
                'filesChanged' => 5,
                'linesAdded' => 120,
                'linesRemoved' => 30,
            ],
        ];

        $html = (new TaskDetail)->renderTask($task);

        expect($html)->toContain('Stats:')
            ->and($html)->toContain('Duration:')
            ->and($html)->toContain('1m 36s')
            ->and($html)->toContain('Files changed:')
            ->and($html)->toContain('5')
            ->and($html)->toContain('+120')
            ->and($html)->toContain('-30');
    });

    it('formats duration under 60 seconds', function () {
        $task = [
            'id' => 1,
            'title' => 'Quick task',
            'status' => 'completed',
            'priority' => 3,
            'description' => '',
            'stats' => ['durationSeconds' => 45],
        ];

        $html = (new TaskDetail)->renderTask($task);

        expect($html)->toContain('45s');
    });

    it('formats duration as exact minutes', function () {
        $task = [
            'id' => 1,
            'title' => 'Task',
            'status' => 'completed',
            'priority' => 3,
            'description' => '',
            'stats' => ['durationSeconds' => 120],
        ];

        $html = (new TaskDetail)->renderTask($task);

        expect($html)->toContain('2m')
            ->and($html)->not->toContain('2m ');
    });

    it('omits stats section when not present', function () {
        $task = [
            'id' => 1,
            'title' => 'Task',
            'status' => 'pending',
            'priority' => 3,
            'description' => '',
        ];

        $html = (new TaskDetail)->renderTask($task);

        expect($html)->not->toContain('Stats:');
    });

    it('renders cliff notes when provided', function () {
        $task = [
            'id' => 1,
            'title' => 'Task',
            'status' => 'completed',
            'priority' => 3,
            'description' => '',
        ];

        $notes = "- Created EditorInterface\n- 6 implementations added";

        $html = (new TaskDetail)->renderTask($task, $notes);

        expect($html)->toContain('Notes:')
            ->and($html)->toContain('Created EditorInterface')
            ->and($html)->toContain('6 implementations added');
    });

    it('omits notes section when null', function () {
        $task = [
            'id' => 1,
            'title' => 'Task',
            'status' => 'pending',
            'priority' => 3,
            'description' => '',
        ];

        $html = (new TaskDetail)->renderTask($task);

        expect($html)->not->toContain('Notes:');
    });

    it('omits notes section when empty string', function () {
        $task = [
            'id' => 1,
            'title' => 'Task',
            'status' => 'pending',
            'priority' => 3,
            'description' => '',
        ];

        $html = (new TaskDetail)->renderTask($task, '   ');

        expect($html)->not->toContain('Notes:');
    });

    it('renders all sections together', function () {
        $task = [
            'id' => 1,
            'title' => 'Full task',
            'status' => 'completed',
            'priority' => 1,
            'description' => 'A complete task',
            'steps' => ['Step one', 'Step two'],
            'acceptance' => ['All tests pass'],
            'stats' => [
                'durationSeconds' => 150,
                'filesChanged' => 10,
                'linesAdded' => 500,
                'linesRemoved' => 100,
            ],
        ];

        $html = (new TaskDetail)->renderTask($task, '- Key insight');

        expect($html)->toContain('Full task')
            ->and($html)->toContain('completed')
            ->and($html)->toContain('A complete task')
            ->and($html)->toContain('Steps:')
            ->and($html)->toContain('Step one')
            ->and($html)->toContain('Stats:')
            ->and($html)->toContain('2m 30s')
            ->and($html)->toContain('Acceptance:')
            ->and($html)->toContain('All tests pass')
            ->and($html)->toContain('Notes:')
            ->and($html)->toContain('Key insight');
    });

    it('render delegates to renderTask with activeTask', function () {
        $state = makeDetailState([
            [
                'id' => 1,
                'status' => 'in_progress',
                'title' => 'Active task',
                'description' => 'desc',
                'priority' => 2,
                'steps' => ['Do thing'],
            ],
        ], activeTaskId: 1);

        $detail = new TaskDetail;
        $renderHtml = $detail->render($state);
        $renderTaskHtml = $detail->renderTask($state->activeTask());

        expect($renderHtml)->toBe($renderTaskHtml);
    });
});
