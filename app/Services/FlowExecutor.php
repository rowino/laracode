<?php

declare(strict_types=1);

namespace App\Services;

use App\Scripts\ConditionEvaluator;
use App\Scripts\Interpolator;
use App\Scripts\PromptRunner;
use App\Scripts\Runners\ShellRunner;

/**
 * Service class to execute setup flows with variable interpolation, prompts, and conditional steps.
 *
 * Usage:
 *   $executor = new FlowExecutor();
 *   $executor->setCommand($command);
 *   $result = $executor->execute($flow, ['BRANCH_NAME' => 'feature/auth']);
 */
class FlowExecutor
{
    private string $workingDirectory = '';

    private readonly Interpolator $interpolator;

    private readonly ConditionEvaluator $conditionEvaluator;

    private readonly PromptRunner $promptRunner;

    private readonly ShellRunner $shellRunner;

    public function __construct(
        ?Interpolator $interpolator = null,
        ?ConditionEvaluator $conditionEvaluator = null,
        ?PromptRunner $promptRunner = null,
        ?ShellRunner $shellRunner = null,
    ) {
        $this->interpolator = $interpolator ?? new Interpolator;
        $this->conditionEvaluator = $conditionEvaluator ?? new ConditionEvaluator($this->interpolator);
        $this->promptRunner = $promptRunner ?? new PromptRunner($this->interpolator);
        $this->shellRunner = $shellRunner ?? (new ShellRunner($this->interpolator))->setShellSafe(false);
    }

    public function setWorkingDirectory(string $path): self
    {
        $this->workingDirectory = rtrim($path, '/');

        return $this;
    }

    public function setAutoMode(bool $auto): self
    {
        $this->promptRunner->setAutoMode($auto);

        return $this;
    }

    /**
     * @param  \Closure(string $output, string $type): void  $callback
     */
    public function setOutputCallback(\Closure $callback): self
    {
        $this->shellRunner->setOutputCallback($callback);

        return $this;
    }

    /**
     * @param  array{id?: string, name?: string, prompts?: list<array{id: string, type: string, label: string, default?: mixed, options?: list<string|array{label: string, value: string}>, required?: bool, promptEveryRun?: bool}>, steps?: list<array{id?: string, command: string, condition?: string, description?: string}>}  $flow
     * @param  array<string, mixed>  $context
     */
    public function execute(array $flow, array $context = []): FlowResult
    {
        $variables = $context;

        $prompts = $flow['prompts'] ?? [];
        $steps = $flow['steps'] ?? [];

        $promptResponses = $this->runPrompts($prompts, $variables);
        $variables = array_merge($variables, $promptResponses);

        $stepResults = $this->runSteps($steps, $variables);

        $allSuccess = array_reduce(
            $stepResults,
            fn (bool $carry, StepResult $result) => $carry && ($result->success || $result->skipped),
            true
        );

        return new FlowResult($allSuccess, $stepResults, $promptResponses);
    }

    /**
     * @param  list<array{id: string, type: string, label: string, default?: mixed, options?: list<string|array{label: string, value: string}>, required?: bool, promptEveryRun?: bool}>  $prompts
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function runPrompts(array $prompts, array $variables): array
    {
        return $this->promptRunner->runPrompts($prompts, $variables);
    }

    /**
     * @param  list<array{id?: string, command: string, condition?: string, description?: string}>  $steps
     * @param  array<string, mixed>  $variables
     * @return list<StepResult>
     */
    public function runSteps(array $steps, array $variables): array
    {
        $results = [];

        foreach ($steps as $index => $step) {
            $id = $step['id'] ?? "step_{$index}";
            $condition = $step['condition'] ?? null;

            if ($condition !== null && ! $this->evaluateCondition($condition, $variables)) {
                $results[] = new StepResult($id, true, '', '', skipped: true);

                continue;
            }

            $results[] = $this->executeStep($step, $variables);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function interpolate(string $template, array $variables): string
    {
        return $this->interpolator->interpolate($template, $variables);
    }

    public function applyFilter(string $value, string $filter): string
    {
        return $this->interpolator->applyFilter($value, $filter);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function evaluateCondition(string $condition, array $variables): bool
    {
        return $this->conditionEvaluator->evaluate($condition, $variables);
    }

    /**
     * @param  array{id?: string, command: string, condition?: string, description?: string}  $step
     * @param  array<string, mixed>  $variables
     */
    private function executeStep(array $step, array $variables): StepResult
    {
        $workDir = $this->workingDirectory ?: getcwd() ?: '/';

        if (isset($variables['WORKTREE_PATH']) && is_string($variables['WORKTREE_PATH'])) {
            $workDir = $variables['WORKTREE_PATH'];
        }

        return $this->shellRunner->execute($step, $variables, $workDir);
    }
}
