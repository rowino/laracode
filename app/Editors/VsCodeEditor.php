<?php

declare(strict_types=1);

namespace App\Editors;

use Illuminate\Support\Facades\Process;

/**
 * Usage: Opens projects in Visual Studio Code via the `code` CLI.
 */
class VsCodeEditor implements EditorInterface
{
    public function name(): string
    {
        return 'vscode';
    }

    public function executable(): string
    {
        return 'code';
    }

    public function openProject(string $path): bool
    {
        return Process::run([$this->executable(), $path])->successful();
    }
}
