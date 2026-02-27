<?php

declare(strict_types=1);

use App\Tui\Terminal\ItermStrategy;

describe('ItermStrategy', function () {
    it('is available when TERM_PROGRAM is iTerm.app', function () {
        $strategy = new ItermStrategy(['TERM_PROGRAM' => 'iTerm.app']);

        expect($strategy->isAvailable())->toBeTrue();
    });

    it('is not available when TERM_PROGRAM is different', function () {
        $strategy = new ItermStrategy(['TERM_PROGRAM' => 'Apple_Terminal']);

        expect($strategy->isAvailable())->toBeFalse();
    });

    it('is not available when TERM_PROGRAM is missing', function () {
        $strategy = new ItermStrategy([]);

        expect($strategy->isAvailable())->toBeFalse();
    });
});
