<?php

declare(strict_types=1);

namespace App\Editors;

use Illuminate\Support\Facades\Process;

/**
 * Usage: Opens projects in Zed editor via the `zed` CLI.
 */
class ZedEditor implements EditorInterface
{
    public function name(): string
    {
        return 'zed';
    }

    public function executable(): string
    {
        return 'zed';
    }

    public function openProject(string $path): bool
    {
        return Process::run([$this->executable(), $path])->successful();
    }
}
