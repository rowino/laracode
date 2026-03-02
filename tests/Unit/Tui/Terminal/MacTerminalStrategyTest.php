<?php

declare(strict_types=1);

use App\Tui\Terminal\MacTerminalStrategy;
use App\Tui\Terminal\TerminalStrategy;
use App\Tui\Terminal\TerminalTabOpener;

describe('MacTerminalStrategy', function () {
    it('implements TerminalStrategy and TerminalTabOpener', function () {
        $strategy = new MacTerminalStrategy([]);

        expect($strategy)
            ->toBeInstanceOf(TerminalStrategy::class)
            ->toBeInstanceOf(TerminalTabOpener::class);
    });

    it('is available when TERM_PROGRAM is Apple_Terminal', function () {
        $strategy = new MacTerminalStrategy(['TERM_PROGRAM' => 'Apple_Terminal']);

        expect($strategy->isAvailable())->toBeTrue();
    });

    it('is not available when TERM_PROGRAM is iTerm.app', function () {
        $strategy = new MacTerminalStrategy(['TERM_PROGRAM' => 'iTerm.app']);

        expect($strategy->isAvailable())->toBeFalse();
    });

    it('is not available when TMUX env is set', function () {
        $strategy = new MacTerminalStrategy(['TMUX' => '/tmp/tmux-501/default,12345,0']);

        expect($strategy->isAvailable())->toBeFalse();
    });

    it('is not available when TERM_PROGRAM is a non-Apple terminal', function () {
        $strategy = new MacTerminalStrategy(['TERM_PROGRAM' => 'WezTerm']);

        expect($strategy->isAvailable())->toBeFalse();
    });
});
