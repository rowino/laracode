<?php

declare(strict_types=1);

use App\Tui\Terminal\FocusResult;
use App\Tui\Terminal\TerminalFocuser;
use App\Tui\Terminal\TerminalStrategy;

describe('TerminalFocuser', function () {
    it('returns unsupported when no strategies available', function () {
        $focuser = new TerminalFocuser([]);

        $result = $focuser->focus(1234);

        expect($result->success)->toBeFalse()
            ->and($result->message)->toBe('No supported terminal multiplexer detected');
    });

    it('delegates to first available strategy', function () {
        $strategy = Mockery::mock(TerminalStrategy::class);
        $strategy->shouldReceive('isAvailable')->andReturn(true);
        $strategy->shouldReceive('focus')->with(1234)->andReturn(FocusResult::success());

        $focuser = new TerminalFocuser([$strategy]);

        $result = $focuser->focus(1234);

        expect($result->success)->toBeTrue();
    });

    it('skips unavailable strategies and uses next available', function () {
        $unavailable = Mockery::mock(TerminalStrategy::class);
        $unavailable->shouldReceive('isAvailable')->andReturn(false);
        $unavailable->shouldNotReceive('focus');

        $available = Mockery::mock(TerminalStrategy::class);
        $available->shouldReceive('isAvailable')->andReturn(true);
        $available->shouldReceive('focus')->with(5678)->andReturn(FocusResult::success());

        $focuser = new TerminalFocuser([$unavailable, $available]);

        $result = $focuser->focus(5678);

        expect($result->success)->toBeTrue();
    });

    it('returns unsupported when all strategies are unavailable', function () {
        $strategy1 = Mockery::mock(TerminalStrategy::class);
        $strategy1->shouldReceive('isAvailable')->andReturn(false);

        $strategy2 = Mockery::mock(TerminalStrategy::class);
        $strategy2->shouldReceive('isAvailable')->andReturn(false);

        $focuser = new TerminalFocuser([$strategy1, $strategy2]);

        $result = $focuser->focus(1234);

        expect($result->success)->toBeFalse()
            ->and($result->message)->toBe('No supported terminal multiplexer detected');
    });

    it('reports supported when at least one strategy is available', function () {
        $unavailable = Mockery::mock(TerminalStrategy::class);
        $unavailable->shouldReceive('isAvailable')->andReturn(false);

        $available = Mockery::mock(TerminalStrategy::class);
        $available->shouldReceive('isAvailable')->andReturn(true);

        $focuser = new TerminalFocuser([$unavailable, $available]);

        expect($focuser->isSupported())->toBeTrue();
    });

    it('reports unsupported when no strategies are available', function () {
        $strategy = Mockery::mock(TerminalStrategy::class);
        $strategy->shouldReceive('isAvailable')->andReturn(false);

        $focuser = new TerminalFocuser([$strategy]);

        expect($focuser->isSupported())->toBeFalse();
    });

    it('reports unsupported with empty strategies', function () {
        $focuser = new TerminalFocuser([]);

        expect($focuser->isSupported())->toBeFalse();
    });
});
