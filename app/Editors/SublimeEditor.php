<?php

declare(strict_types=1);

namespace App\Editors;

use Illuminate\Support\Facades\Process;

/**
 * Usage: Opens projects in Sublime Text via the `subl` CLI.
 */
class SublimeEditor implements EditorInterface
{
    public function name(): string
    {
        return 'sublime';
    }

    public function executable(): string
    {
        return 'subl';
    }

    public function openProject(string $path): bool
    {
        return Process::run([$this->executable(), $path])->successful();
    }
}
