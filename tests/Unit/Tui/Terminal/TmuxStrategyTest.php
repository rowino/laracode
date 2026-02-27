<?php

declare(strict_types=1);

use App\Tui\Terminal\TmuxStrategy;

describe('TmuxStrategy', function () {
    it('is available when TMUX env var is set', function () {
        $strategy = new TmuxStrategy(['TMUX' => '/tmp/tmux-501/default,12345,0']);

        expect($strategy->isAvailable())->toBeTrue();
    });

    it('is not available when TMUX env var is empty', function () {
        $strategy = new TmuxStrategy(['TMUX' => '']);

        expect($strategy->isAvailable())->toBeFalse();
    });

    it('is not available when TMUX env var is missing', function () {
        $strategy = new TmuxStrategy([]);

        expect($strategy->isAvailable())->toBeFalse();
    });
});
