<?php

declare(strict_types=1);

namespace App\Tui\Terminal;

interface TerminalStrategy
{
    public function isAvailable(): bool;

    public function focus(int $pid): FocusResult;
}
