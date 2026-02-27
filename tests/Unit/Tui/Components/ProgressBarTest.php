<?php

declare(strict_types=1);

use App\Tui\Components\ProgressBar;
use App\Tui\DashboardState;

function makeProgressState(array $tasks): DashboardState
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
        activeTaskId: null,
        mode: 'normal',
        statusMessage: '',
    );
}

describe('ProgressBar', function () {
    it('renders 0% for no completed tasks', function () {
        $state = makeProgressState([
            ['id' => 1, 'status' => 'pending'],
            ['id' => 2, 'status' => 'pending'],
        ]);

        $html = (new ProgressBar)->render($state);

        expect($html)->toContain('0%')
            ->and($html)->toContain('0/2')
            ->and($html)->toContain('░');
    });

    it('renders 100% for all completed tasks', function () {
        $state = makeProgressState([
            ['id' => 1, 'status' => 'completed'],
            ['id' => 2, 'status' => 'completed'],
        ]);

        $html = (new ProgressBar)->render($state);

        expect($html)->toContain('100%')
            ->and($html)->toContain('2/2')
            ->and($html)->toContain('█');
    });

    it('renders partial progress', function () {
        $state = makeProgressState([
            ['id' => 1, 'status' => 'completed'],
            ['id' => 2, 'status' => 'pending'],
        ]);

        $html = (new ProgressBar)->render($state);

        expect($html)->toContain('50%')
            ->and($html)->toContain('1/2')
            ->and($html)->toContain('█')
            ->and($html)->toContain('░');
    });

    it('renders empty bar for zero tasks', function () {
        $state = makeProgressState([]);

        $html = (new ProgressBar)->render($state);

        expect($html)->toContain('0%')
            ->and($html)->toContain('0/0');
    });

    it('uses green color for filled portion', function () {
        $state = makeProgressState([
            ['id' => 1, 'status' => 'completed'],
        ]);

        $html = (new ProgressBar)->render($state);

        expect($html)->toContain('text-green-400');
    });

    it('uses gray color for empty portion', function () {
        $state = makeProgressState([
            ['id' => 1, 'status' => 'pending'],
        ]);

        $html = (new ProgressBar)->render($state);

        expect($html)->toContain('text-gray');
    });
});
