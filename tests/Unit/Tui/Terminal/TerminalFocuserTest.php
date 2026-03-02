<?php

declare(strict_types=1);

use App\Tui\Terminal\FocusResult;
use App\Tui\Terminal\MacTerminalStrategy;
use App\Tui\Terminal\TerminalFocuser;
use App\Tui\Terminal\TerminalStrategy;
use App\Tui\Terminal\TerminalTabOpener;

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

    it('includes MacTerminalStrategy in default strategies', function () {
        $focuser = new TerminalFocuser;

        $reflection = new ReflectionProperty(TerminalFocuser::class, 'strategies');
        $strategies = $reflection->getValue($focuser);

        $hasMac = false;
        foreach ($strategies as $strategy) {
            if ($strategy instanceof MacTerminalStrategy) {
                $hasMac = true;
                break;
            }
        }

        expect($hasMac)->toBeTrue();
    });
});

describe('TerminalFocuser openTab', function () {
    it('delegates openTab to first available TerminalTabOpener strategy', function () {
        $tabOpener = Mockery::mock(TerminalStrategy::class, TerminalTabOpener::class);
        $tabOpener->shouldReceive('isAvailable')->andReturn(true);
        $tabOpener->shouldReceive('openTab')->with('/project')->andReturn(new FocusResult(true, 'Opened tab'));

        $focuser = new TerminalFocuser([$tabOpener]);

        $result = $focuser->openTab('/project');

        expect($result->success)->toBeTrue()
            ->and($result->message)->toBe('Opened tab');
    });

    it('skips strategies that do not implement TerminalTabOpener', function () {
        $nonOpener = Mockery::mock(TerminalStrategy::class);
        $nonOpener->shouldReceive('isAvailable')->andReturn(true);

        $tabOpener = Mockery::mock(TerminalStrategy::class, TerminalTabOpener::class);
        $tabOpener->shouldReceive('isAvailable')->andReturn(true);
        $tabOpener->shouldReceive('openTab')->with('/project')->andReturn(new FocusResult(true, 'Opened tab'));

        $focuser = new TerminalFocuser([$nonOpener, $tabOpener]);

        $result = $focuser->openTab('/project');

        expect($result->success)->toBeTrue();
    });

    it('skips unavailable TerminalTabOpener strategies', function () {
        $unavailable = Mockery::mock(TerminalStrategy::class, TerminalTabOpener::class);
        $unavailable->shouldReceive('isAvailable')->andReturn(false);
        $unavailable->shouldNotReceive('openTab');

        $available = Mockery::mock(TerminalStrategy::class, TerminalTabOpener::class);
        $available->shouldReceive('isAvailable')->andReturn(true);
        $available->shouldReceive('openTab')->with('/work')->andReturn(new FocusResult(true, 'Done'));

        $focuser = new TerminalFocuser([$unavailable, $available]);

        $result = $focuser->openTab('/work');

        expect($result->success)->toBeTrue();
    });

    it('returns unsupported when no TerminalTabOpener strategies available', function () {
        $strategy = Mockery::mock(TerminalStrategy::class);
        $strategy->shouldReceive('isAvailable')->andReturn(true);

        $focuser = new TerminalFocuser([$strategy]);

        $result = $focuser->openTab('/project');

        expect($result->success)->toBeFalse()
            ->and($result->message)->toBe('No supported terminal multiplexer detected');
    });

    it('returns unsupported with empty strategies', function () {
        $focuser = new TerminalFocuser([]);

        $result = $focuser->openTab('/project');

        expect($result->success)->toBeFalse();
    });
});

describe('TerminalFocuser canOpenTab', function () {
    it('returns true when a TerminalTabOpener strategy is available', function () {
        $tabOpener = Mockery::mock(TerminalStrategy::class, TerminalTabOpener::class);
        $tabOpener->shouldReceive('isAvailable')->andReturn(true);

        $focuser = new TerminalFocuser([$tabOpener]);

        expect($focuser->canOpenTab())->toBeTrue();
    });

    it('returns false when no TerminalTabOpener strategies exist', function () {
        $strategy = Mockery::mock(TerminalStrategy::class);
        $strategy->shouldReceive('isAvailable')->andReturn(true);

        $focuser = new TerminalFocuser([$strategy]);

        expect($focuser->canOpenTab())->toBeFalse();
    });

    it('returns false when TerminalTabOpener strategies are unavailable', function () {
        $tabOpener = Mockery::mock(TerminalStrategy::class, TerminalTabOpener::class);
        $tabOpener->shouldReceive('isAvailable')->andReturn(false);

        $focuser = new TerminalFocuser([$tabOpener]);

        expect($focuser->canOpenTab())->toBeFalse();
    });

    it('returns false with empty strategies', function () {
        $focuser = new TerminalFocuser([]);

        expect($focuser->canOpenTab())->toBeFalse();
    });
});
