<?php

declare(strict_types=1);

namespace App\Tui\Terminal;

/**
 * Usage: Orchestrates terminal focus by trying strategies in order (tmux, iTerm2).
 */
class TerminalFocuser
{
    /** @var list<TerminalStrategy> */
    private array $strategies;

    /** @param list<TerminalStrategy>|null $strategies */
    public function __construct(?array $strategies = null)
    {
        $this->strategies = $strategies ?? [
            new TmuxStrategy,
            new ItermStrategy,
        ];
    }

    public function focus(int $pid): FocusResult
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->isAvailable()) {
                return $strategy->focus($pid);
            }
        }

        return FocusResult::unsupported();
    }

    public function isSupported(): bool
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->isAvailable()) {
                return true;
            }
        }

        return false;
    }
}
