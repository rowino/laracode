<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Enums\BuildMode;

/**
 * Usage: Spawn, monitor, and terminate agent processes.
 * Used by WatchCommand/WatchService for comment processing and BuildCommand for task execution.
 */
class AgentRunner
{
    private const int POLL_INTERVAL_MS = 100000; // 100ms

    private const int TERMINATE_GRACE_MS = 500000; // 500ms

    public function __construct(
        private AgentRegistry $agentRegistry
    ) {}

    /**
     * @param  array<string, mixed>  $lockMetadata
     * @return resource|false
     */
    public function run(BuildMode $mode, string $prompt, string $projectPath, string $lockPath, ?string $indexFile = null, array $lockMetadata = []): mixed
    {
        $agent = $this->agentRegistry->getDefault();
        $command = array_values($agent->buildCommand($mode));

        $command[] = $prompt;

        if ($indexFile !== null) {
            $command[] = $indexFile;
        }

        $descriptorspec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open(
            $command,
            $descriptorspec,
            $pipes,
            $projectPath,
            $this->buildEnvironment($lockPath)
        );

        if (! is_resource($process)) {
            return false;
        }

        $this->writeLockFile($lockPath, $process, $mode, $lockMetadata);

        return $process;
    }

    /**
     * Build environment array for spawned agent process.
     * Inherits parent environment (PATH, HOME, etc) and adds LARACODE_LOCK_FILE.
     *
     * @return array<string, string>
     */
    private function buildEnvironment(string $lockPath): array
    {
        /** @var array<string, string>|false $parentEnv */
        $parentEnv = getenv();
        $env = is_array($parentEnv) ? $parentEnv : [];

        $env['LARACODE_LOCK_FILE'] = $lockPath;

        return $env;
    }

    /**
     * @param  resource  $process
     */
    public function monitor(mixed $process, string $lockPath, callable $onLockRemoved): void
    {
        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            if (! file_exists($lockPath)) {
                $onLockRemoved($status['pid']);
                break;
            }

            usleep(self::POLL_INTERVAL_MS);
        }
    }

    /**
     * @param  resource  $process
     */
    public function terminate(mixed $process, int $pid): void
    {
        posix_kill($pid, SIGTERM);
        usleep(self::TERMINATE_GRACE_MS);

        $status = proc_get_status($process);
        if ($status['running']) {
            posix_kill($pid, SIGKILL);
        }

        proc_close($process);
    }

    /**
     * @param  resource  $process
     * @param  array<string, mixed>  $metadata
     */
    private function writeLockFile(string $lockPath, mixed $process, BuildMode $mode, array $metadata = []): void
    {
        $dir = dirname($lockPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $status = proc_get_status($process);

        $data = [
            'pid' => $status['pid'],
            'started' => date('c'),
            'mode' => $mode->value,
            ...$metadata,
        ];

        file_put_contents($lockPath, json_encode($data, JSON_PRETTY_PRINT));
    }
}
