<?php

declare(strict_types=1);

namespace App\Agents;

use Illuminate\Support\Facades\Process;

/**
 * Usage: Detect which coding agents are installed on the system by checking PATH.
 */
class AgentDetector
{
    public const KNOWN_AGENTS = [
        'claude',
        'opencode',
        'codex',
        'junie',
        'aider',
        'happy',
    ];

    /**
     * @return array<string, string> Agent name to executable path mapping
     */
    public function detectInstalled(): array
    {
        $installed = [];

        foreach (self::KNOWN_AGENTS as $agent) {
            $path = $this->findExecutable($agent);
            if ($path !== null) {
                $installed[$agent] = $path;
            }
        }

        return $installed;
    }

    public function isInstalled(string $agent): bool
    {
        return $this->findExecutable($agent) !== null;
    }

    public function findExecutable(string $executable): ?string
    {
        $result = Process::run("which {$executable}");

        if ($result->successful()) {
            return trim($result->output());
        }

        return null;
    }

    public function validatePath(string $path): bool
    {
        if (! file_exists($path)) {
            return false;
        }

        return is_executable($path);
    }
}
