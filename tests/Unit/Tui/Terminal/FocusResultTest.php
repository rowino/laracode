<?php

declare(strict_types=1);

use App\Tui\Terminal\FocusResult;

describe('FocusResult factories', function () {
    it('creates success result', function () {
        $result = FocusResult::success();

        expect($result->success)->toBeTrue()
            ->and($result->message)->toBe('Focused terminal pane');
    });

    it('creates notFound result', function () {
        $result = FocusResult::notFound();

        expect($result->success)->toBeFalse()
            ->and($result->message)->toBe('Could not find terminal pane for this session');
    });

    it('creates unsupported result', function () {
        $result = FocusResult::unsupported();

        expect($result->success)->toBeFalse()
            ->and($result->message)->toBe('No supported terminal multiplexer detected');
    });

    it('creates error result with custom message', function () {
        $result = FocusResult::error('Something went wrong');

        expect($result->success)->toBeFalse()
            ->and($result->message)->toBe('Something went wrong');
    });
});
