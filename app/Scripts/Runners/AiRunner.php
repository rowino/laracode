<?php

declare(strict_types=1);

namespace App\Scripts\Runners;

use App\Agents\AgentInterface;
use App\Agents\AgentRegistry;
use App\Enums\BuildMode;
use App\Scripts\Interpolator;
use App\Services\StepResult;

/**
 * Usage: $runner->execute(['id' => 'analyze', 'prompt' => 'Review this code', 'mode' => 'plan'], $vars, '/tmp');
 */
class AiRunner implements RunnerInterface
{
    /** @var ?\Closure(string, string): void */
    private ?\Closure $outputCallback = null;

    public function __construct(
        private readonly AgentRegistry $agentRegistry,
        private readonly Interpolator $interpolator,
    ) {}

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
        $id = $step['id'] ?? 'ai-step';
        $prompt = $this->interpolator->interpolate($step['prompt'] ?? '', $variables);

        if ($prompt === '') {
            return new StepResult((string) $id, false, '', 'No prompt specified');
        }

        $agent = $this->resolveAgent($step);
        $mode = $this->resolveMode($step);
        $command = $agent->buildCommand($mode);

        $command[] = '-p';
        $command[] = $prompt;

        if (isset($step['output_format']) && $step['output_format'] !== '') {
            $outputFormat = $this->interpolator->interpolate((string) $step['output_format'], $variables);
            $command[] = '--output-format';
            $command[] = $outputFormat;
        }

        $callback = $this->outputCallback;
        if ($callback !== null) {
            $callback("→ {$agent->name()} [{$mode->value}]: {$prompt}", 'command');
        }

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            array_values($command),
            $descriptorspec,
            $pipes,
            $workDir,
        );

        if (! is_resource($process)) {
            return new StepResult((string) $id, false, '', 'Failed to start AI agent process');
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
            (string) $id,
            $exitCode === 0,
            $output ?: '',
            $error ?: '',
        );
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function resolveAgent(array $step): AgentInterface
    {
        if (isset($step['agent']) && $step['agent'] !== '') {
            return $this->agentRegistry->get((string) $step['agent']);
        }

        return $this->agentRegistry->getDefault();
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function resolveMode(array $step): BuildMode
    {
        if (isset($step['mode']) && $step['mode'] !== '') {
            return BuildMode::from((string) $step['mode']);
        }

        return BuildMode::Interactive;
    }
}
