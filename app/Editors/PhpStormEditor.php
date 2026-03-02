<?php

declare(strict_types=1);

namespace App\Editors;

use Illuminate\Support\Facades\Process;

/**
 * Usage: Opens projects in PhpStorm via the `phpstorm` CLI launcher.
 */
class PhpStormEditor implements EditorInterface
{
    public function name(): string
    {
        return 'phpstorm';
    }

    public function executable(): string
    {
        return 'phpstorm';
    }

    public function openProject(string $path): bool
    {
        return Process::run([$this->executable(), $path])->successful();
    }
}
