<?php

declare(strict_types=1);

use App\Tui\Components\StatusBar;
use App\Tui\DashboardState;

function makeStatusBarState(string $statusMessage = '', string $mode = 'normal', string $branch = 'main'): DashboardState
{
    return DashboardState::fromTasksArray(
        data: ['title' => 'Test', 'branch' => $branch, 'tasks' => []],
        iteration: 1,
        maxIterations: 10,
        elapsed: 0,
        activeTaskId: null,
        mode: $mode,
        statusMessage: $statusMessage,
    );
}

describe('StatusBar', function () {
    it('renders status message', function () {
        $html = (new StatusBar)->render(makeStatusBarState('Building task 3...'));

        expect($html)->toContain('Building task 3...');
    });

    it('renders mode and branch', function () {
        $html = (new StatusBar)->render(makeStatusBarState(mode: 'yolo', branch: 'feature/tui'));

        expect($html)->toContain('text-yellow-400')
            ->and($html)->toContain('yolo')
            ->and($html)->toContain('text-cyan-400')
            ->and($html)->toContain('feature/tui');
    });

    it('separates mode and branch with dot separator', function () {
        $html = (new StatusBar)->render(makeStatusBarState(mode: 'accept', branch: 'main'));

        expect($html)->toContain('text-yellow-400')
            ->and($html)->toContain('accept')
            ->and($html)->toContain('·')
            ->and($html)->toContain('text-cyan-400')
            ->and($html)->toContain('main');
    });

    it('renders empty status message', function () {
        $html = (new StatusBar)->render(makeStatusBarState(''));

        expect($html)->toContain('<span></span>');
    });
});
