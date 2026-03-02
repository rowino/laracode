<?php

declare(strict_types=1);

use App\Tui\Components\HeaderBar;
use App\Tui\DashboardState;

function makeHeaderState(array $extra = []): DashboardState
{
    return DashboardState::fromTasksArray(
        data: ['title' => 'Test', 'branch' => 'main', 'tasks' => []],
        iteration: $extra['iteration'] ?? 1,
        maxIterations: $extra['maxIterations'] ?? 10,
        elapsed: $extra['elapsed'] ?? 0,
        activeTaskId: null,
        mode: 'normal',
        statusMessage: '',
    );
}

describe('HeaderBar', function () {
    it('renders laracode title with running badge', function () {
        $html = (new HeaderBar)->render(makeHeaderState());

        expect($html)->toContain('laracode')
            ->and($html)->toContain('[Running]');
    });

    it('renders iteration counter', function () {
        $html = (new HeaderBar)->render(makeHeaderState([
            'iteration' => 3,
            'maxIterations' => 8,
        ]));

        expect($html)->toContain('text-cyan')
            ->and($html)->toContain('3/8');
    });

    it('renders time in seconds when under one minute', function () {
        $html = (new HeaderBar)->render(makeHeaderState(['elapsed' => 45]));

        expect($html)->toContain('text-yellow')
            ->and($html)->toContain('45s')
            ->and($html)->not->toContain('m45s');
    });

    it('renders time with minutes and seconds', function () {
        $html = (new HeaderBar)->render(makeHeaderState(['elapsed' => 125]));

        expect($html)->toContain('text-yellow')
            ->and($html)->toContain('2m5s');
    });

    it('renders zero elapsed as 0s', function () {
        $html = (new HeaderBar)->render(makeHeaderState(['elapsed' => 0]));

        expect($html)->toContain('text-yellow')
            ->and($html)->toContain('0s');
    });
});
