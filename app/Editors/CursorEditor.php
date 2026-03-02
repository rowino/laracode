<?php

declare(strict_types=1);

namespace App\Editors;

use Illuminate\Support\Facades\Process;

/**
 * Usage: Opens projects in Cursor editor via the `cursor` CLI.
 */
class CursorEditor implements EditorInterface
{
    public function name(): string
    {
        return 'cursor';
    }

    public function executable(): string
    {
        return 'cursor';
    }

    public function openProject(string $path): bool
    {
        return Process::run([$this->executable(), $path])->successful();
    }
}
