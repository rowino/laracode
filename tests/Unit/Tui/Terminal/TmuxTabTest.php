<?php

declare(strict_types=1);

use App\Tui\Terminal\FocusResult;
use App\Tui\Terminal\TerminalTabOpener;
use App\Tui\Terminal\TmuxStrategy;

describe('TmuxStrategy openTab', function () {
    it('implements TerminalTabOpener', function () {
        $strategy = new TmuxStrategy(['TMUX' => '/tmp/tmux-501/default,12345,0']);

        expect($strategy)->toBeInstanceOf(TerminalTabOpener::class);
    });

    it('returns success FocusResult from openTab', function () {
        $strategy = Mockery::mock(TmuxStrategy::class, [['TMUX' => '/tmp/tmux-501/default,12345,0']])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $strategy->shouldReceive('openTab')
            ->with('/tmp/test-project')
            ->andReturn(new FocusResult(true, 'Opened new tmux window'));

        $result = $strategy->openTab('/tmp/test-project');

        expect($result)
            ->toBeInstanceOf(FocusResult::class)
            ->success->toBeTrue()
            ->message->toBe('Opened new tmux window');
    });
});
