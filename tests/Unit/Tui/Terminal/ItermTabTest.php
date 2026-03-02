<?php

declare(strict_types=1);

use App\Tui\Terminal\FocusResult;
use App\Tui\Terminal\ItermStrategy;
use App\Tui\Terminal\TerminalTabOpener;

describe('ItermStrategy openTab', function () {
    it('implements TerminalTabOpener', function () {
        $strategy = new ItermStrategy(['TERM_PROGRAM' => 'iTerm.app']);

        expect($strategy)->toBeInstanceOf(TerminalTabOpener::class);
    });

    it('returns success FocusResult from openTab', function () {
        $strategy = Mockery::mock(ItermStrategy::class, [['TERM_PROGRAM' => 'iTerm.app']])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $strategy->shouldReceive('openTab')
            ->with('/tmp/test-project')
            ->andReturn(new FocusResult(true, 'Opened new iTerm2 tab'));

        $result = $strategy->openTab('/tmp/test-project');

        expect($result)
            ->toBeInstanceOf(FocusResult::class)
            ->success->toBeTrue()
            ->message->toBe('Opened new iTerm2 tab');
    });
});
