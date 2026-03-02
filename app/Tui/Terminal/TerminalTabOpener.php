<?php

declare(strict_types=1);

namespace App\Tui\Terminal;

interface TerminalTabOpener
{
    public function isAvailable(): bool;

    public function openTab(string $cwd): FocusResult;
}
