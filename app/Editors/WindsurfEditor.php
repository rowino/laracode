<?php

declare(strict_types=1);

namespace App\Editors;

use Illuminate\Support\Facades\Process;

/**
 * Usage: Opens projects in Windsurf editor via the `windsurf` CLI.
 */
class WindsurfEditor implements EditorInterface
{
    public function name(): string
    {
        return 'windsurf';
    }

    public function executable(): string
    {
        return 'windsurf';
    }

    public function openProject(string $path): bool
    {
        return Process::run([$this->executable(), $path])->successful();
    }
}
