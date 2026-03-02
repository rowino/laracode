<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Usage: app(GitHelper::class)->defaultBranch('/path/to/repo')
 */
class GitHelper
{
    public function currentBranch(string $path): string
    {
        return $this->runGitCommand(['git', 'branch', '--show-current'], $path);
    }

    public function defaultBranch(string $path): string
    {
        $output = $this->runGitCommand(['git', 'branch', '--format=%(refname:short)'], $path);

        if ($output === '') {
            return 'main';
        }

        $branches = explode("\n", $output);

        if (in_array('main', $branches, true)) {
            return 'main';
        }

        if (in_array('master', $branches, true)) {
            return 'master';
        }

        if (in_array('develop', $branches, true)) {
            return 'develop';
        }

        return $branches[0];
    }

    /** @param list<string> $command */
    protected function runGitCommand(array $command, string $path): string
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorspec, $pipes, $path);

        if (! is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);
        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }
}
