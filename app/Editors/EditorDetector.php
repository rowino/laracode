<?php

declare(strict_types=1);

namespace App\Editors;

use Illuminate\Support\Facades\Process;

/**
 * Usage: Detect which editors are installed on the system by checking PATH.
 */
class EditorDetector
{
    public function __construct(
        private EditorRegistry $registry,
    ) {}

    /**
     * @return array<string, EditorInterface>
     */
    public function detectInstalled(): array
    {
        $installed = [];

        foreach ($this->registry->all() as $name => $editor) {
            if ($this->findExecutable($editor->executable()) !== null) {
                $installed[$name] = $editor;
            }
        }

        return $installed;
    }

    public function findExecutable(string $executable): ?string
    {
        $result = Process::run("which {$executable}");

        if ($result->successful()) {
            return trim($result->output());
        }

        return null;
    }
}
