<?php

declare(strict_types=1);

namespace App\Scripts\Runners;

use App\Scripts\Interpolator;
use App\Services\StepResult;

/**
 * Usage: $runner->execute(['id' => 'test', 'run' => 'echo hello'], $vars, '/tmp');
 */
class ShellRunner implements RunnerInterface
{
    /** @var ?\Closure(string, string): void */
    private ?\Closure $outputCallback = null;

    private bool $shellSafe = true;

    public function __construct(
        private readonly Interpolator $interpolator,
    ) {}

    public function setShellSafe(bool $safe): self
    {
        $this->shellSafe = $safe;

        return $this;
    }

    /**
     * @param  \Closure(string $output, string $type): void  $callback
     */
    public function setOutputCallback(\Closure $callback): self
    {
        $this->outputCallback = $callback;

        return $this;
    }

    public function execute(array $step, array $variables, string $workDir): StepResult
    {
        $id = $step['id'] ?? 'step';
        $command = $this->shellSafe
            ? $this->interpolator->interpolateForShell($step['run'] ?? $step['command'] ?? '', $variables)
            : $this->interpolator->interpolate($step['run'] ?? $step['command'] ?? '', $variables);

        if ($command === '') {
            return new StepResult($id, false, '', 'No command specified');
        }

        $callback = $this->outputCallback;
        if ($callback !== null) {
            $callback("→ {$command}", 'command');
        }

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->buildEnv($variables);

        $process = proc_open(
            $command,
            $descriptorspec,
            $pipes,
            $workDir,
            $env,
        );

        if (! is_resource($process)) {
            return new StepResult($id, false, '', 'Failed to start process');
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($callback !== null) {
            if ($output !== false && $output !== '') {
                $callback($output, 'stdout');
            }
            if ($error !== false && $error !== '') {
                $callback($error, 'stderr');
            }
        }

        return new StepResult(
            $id,
            $exitCode === 0,
            $output ?: '',
            $error ?: '',
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, string>
     */
    private function buildEnv(array $variables): array
    {
        /** @var array<string, string> $env */
        $env = getenv();

        foreach ($variables as $key => $value) {
            if (is_scalar($value)) {
                $env[(string) $key] = (string) $value;
            }
        }

        return $env;
    }
}
