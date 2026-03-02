<?php

declare(strict_types=1);

namespace App\Tui\Terminal;

/**
 * Usage: Orchestrates terminal focus and tab opening by trying strategies in order (tmux, iTerm2, macOS Terminal).
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
            new MacTerminalStrategy,
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

    public function openTab(string $cwd): FocusResult
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy instanceof TerminalTabOpener && $strategy->isAvailable()) {
                return $strategy->openTab($cwd);
            }
        }

        return FocusResult::unsupported();
    }

    public function canOpenTab(): bool
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy instanceof TerminalTabOpener && $strategy->isAvailable()) {
                return true;
            }
        }

        return false;
    }
}
