<?php

declare(strict_types=1);

namespace App\Scripts;

use App\Scripts\Runners\RunnerRegistry;
use App\Services\FlowResult;
use App\Services\GitHelper;
use App\Services\Settings\SettingsService;
use App\Services\StepResult;
use Illuminate\Console\Command;

/**
 * Usage: $executor->execute($scriptDef, ['BRANCH' => 'main'], $command);
 */
class ScriptExecutor
{
    /** @var ?\Closure(string, string): void */
    private ?\Closure $outputCallback = null;

    /** @var ?\Closure(string, string, string): void */
    private ?\Closure $stepCallback = null;

    /** @var array<string, string> */
    private array $settingsFlat = [];

    /** @var array<string, string> */
    private array $gitFlat = [];

    public function __construct(
        private readonly Interpolator $interpolator,
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly PromptRunner $promptRunner,
        private readonly RunnerRegistry $runnerRegistry,
        private readonly SettingsService $settingsService,
        private readonly GitHelper $gitHelper = new GitHelper,
    ) {}

    /**
     * @param  \Closure(string $output, string $type): void  $callback
     */
    public function setOutputCallback(\Closure $callback): self
    {
        $this->outputCallback = $callback;

        return $this;
    }

    /**
     * @param  \Closure(string $stepId, string $event, string $error): void  $callback  event: start|success|failure|skip
     */
    public function setStepCallback(\Closure $callback): self
    {
        $this->stepCallback = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function execute(ScriptDefinition $script, array $context = [], ?Command $command = null): FlowResult
    {
        $variables = $context;

        $variables = $this->resolveVariables($script->variables, $variables);
        $variables = $this->resolveSettingsVariables($variables);
        $variables = $this->resolveGitVariables($variables);

        $prompts = $this->resolvePromptDefaults($script->prompts, $variables);
        [$prompts, $boundValues] = $this->filterBoundPrompts($prompts, $command);
        $variables = array_merge($variables, $boundValues);
        /** @var list<array{id: string, type: string, label: string, default?: mixed, options?: list<string|array{label: string, value: string}>, required?: bool, promptEveryRun?: bool}> $prompts */
        $promptResponses = $this->promptRunner->runPrompts($prompts, $variables);
        $variables = array_merge($variables, $promptResponses);

        $beforeResults = $this->runSteps($script->before, $variables);
        if (! $this->allSucceeded($beforeResults)) {
            return new FlowResult(false, $beforeResults, $promptResponses);
        }

        $stepResults = $this->runSteps($script->steps, $variables);
        $mainSuccess = $this->allSucceeded($stepResults);

        $afterResults = $this->runSteps($script->after, $variables);

        $allResults = array_merge($beforeResults, $stepResults, $afterResults);

        return new FlowResult(
            $mainSuccess && $this->allSucceeded($afterResults),
            $allResults,
            $promptResponses,
        );
    }

    /**
     * @param  array<string, string>  $definitions
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function resolveVariables(array $definitions, array $variables): array
    {
        foreach ($definitions as $key => $value) {
            $variables[$key] = $this->interpolator->interpolate($value, $variables);
        }

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function resolveSettingsVariables(array $variables): array
    {
        $settings = $this->settingsService->all();
        $this->settingsFlat = $this->flattenSettings($settings, 'settings');

        foreach ($variables as $key => $value) {
            if (is_string($value) && str_contains($value, '{{settings.')) {
                $variables[$key] = $this->interpolateDotted($value, $this->settingsFlat);
            }
        }

        return $variables;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function interpolateDotted(string $template, array $replacements): string
    {
        return preg_replace_callback(
            '/\{\{([\w.]+)\}\}/',
            function (array $matches) use ($replacements): string {
                return $replacements[$matches[1]] ?? $matches[0];
            },
            $template
        ) ?? $template;
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, string>
     */
    private function flattenSettings(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $flatKey = $prefix !== '' ? "{$prefix}.{$key}" : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenSettings($value, $flatKey));
            } else {
                $result[$flatKey] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function resolveGitVariables(array $variables): array
    {
        $this->gitFlat = $this->getGitVariables();

        foreach ($variables as $key => $value) {
            if (is_string($value) && str_contains($value, '{{git.')) {
                $variables[$key] = $this->interpolateDotted($value, $this->gitFlat);
            }
        }

        return $variables;
    }

    /**
     * @param  list<array<string, mixed>>  $prompts
     * @param  array<string, mixed>  $variables
     * @return list<array<string, mixed>>
     */
    private function resolvePromptDefaults(array $prompts, array $variables): array
    {
        foreach ($prompts as &$prompt) {
            if (isset($prompt['default']) && is_string($prompt['default'])) {
                $default = $this->interpolateDotted($prompt['default'], $this->settingsFlat);
                $default = $this->interpolateDotted($default, $this->gitFlat);
                $default = $this->interpolator->interpolate($default, $variables);
                $prompt['default'] = $default;
            }
        }

        return $prompts;
    }

    /**
     * @return array<string, string>
     */
    protected function getGitVariables(): array
    {
        $cwd = getcwd() ?: '.';

        return [
            'git.currentBranch' => $this->gitHelper->currentBranch($cwd),
            'git.defaultBranch' => $this->gitHelper->defaultBranch($cwd),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $prompts
     * @return array{list<array<string, mixed>>, array<string, mixed>}
     */
    private function filterBoundPrompts(array $prompts, ?Command $command): array
    {
        $filtered = [];
        /** @var array<string, mixed> $boundValues */
        $boundValues = [];

        foreach ($prompts as $prompt) {
            $bind = $prompt['bind'] ?? null;

            if ($bind !== null && $command !== null) {
                $value = $this->resolveBoundValue((string) $bind, $command);
                if ($value !== null) {
                    $boundValues[(string) $prompt['id']] = $value;

                    continue;
                }
            }

            $filtered[] = $prompt;
        }

        return [$filtered, $boundValues];
    }

    private function resolveBoundValue(string $bind, Command $command): mixed
    {
        if (str_starts_with($bind, 'argument.')) {
            $argName = substr($bind, 9);
            $value = $command->argument($argName);

            return $value !== null && $value !== '' ? $value : null;
        }

        if (str_starts_with($bind, 'option.')) {
            $optName = substr($bind, 7);
            $value = $command->option($optName);

            return $value !== null && $value !== false && $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $variables
     * @return list<StepResult>
     */
    private function runSteps(array $steps, array &$variables): array
    {
        $results = [];

        foreach ($steps as $index => $step) {
            $id = (string) ($step['id'] ?? "step_{$index}");
            $condition = $step['condition'] ?? null;

            if ($condition !== null && ! $this->conditionEvaluator->evaluate((string) $condition, $variables)) {
                $results[] = new StepResult($id, true, '', '', skipped: true);
                $this->notifyStep($id, 'skip');

                continue;
            }

            $this->notifyStep($id, 'start');

            $runnerName = (string) ($step['runner'] ?? 'shell');
            $runner = $this->runnerRegistry->get($runnerName);

            if (isset($step['variables']) && is_array($step['variables'])) {
                $interpolated = [];
                foreach ($step['variables'] as $key => $value) {
                    $interpolated[$key] = is_string($value)
                        ? $this->interpolator->interpolate($value, $variables)
                        : $value;
                }
                $step['variables'] = $interpolated;
            }

            $workDir = $this->resolveWorkDir($variables);
            $result = $runner->execute($step, $variables, $workDir);

            if ($this->outputCallback !== null && $runnerName !== 'script') {
                $callback = $this->outputCallback;
                if ($result->output !== '') {
                    $callback($result->output, 'stdout');
                }
                if ($result->error !== '') {
                    $callback($result->error, 'stderr');
                }
            }

            $this->notifyStep($id, $result->success ? 'success' : 'failure', $result->error);

            $capture = $step['capture'] ?? null;
            if ($capture !== null && is_string($capture)) {
                $variables[$capture] = trim($result->output);
            }

            $onFailure = (string) ($step['on_failure'] ?? 'abort');
            if (! $result->success && ! $result->skipped && $onFailure === 'warn') {
                $results[] = new StepResult($result->id, true, $result->output, $result->error, skipped: true);
            } elseif (! $result->success && ! $result->skipped && $onFailure === 'continue') {
                $results[] = $result;
            } else {
                $results[] = $result;

                if (! $result->success && ! $result->skipped) {
                    break;
                }
            }
        }

        return $results;
    }

    private function notifyStep(string $id, string $event, string $error = ''): void
    {
        if ($this->stepCallback !== null) {
            ($this->stepCallback)($id, $event, $error);
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function resolveWorkDir(array $variables): string
    {
        if (isset($variables['WORKTREE_PATH']) && is_string($variables['WORKTREE_PATH'])) {
            return $variables['WORKTREE_PATH'];
        }

        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('Cannot resolve working directory: getcwd() failed and WORKTREE_PATH is not set');
        }

        return $cwd;
    }

    /**
     * @param  list<StepResult>  $results
     */
    private function allSucceeded(array $results): bool
    {
        foreach ($results as $result) {
            if (! $result->success && ! $result->skipped) {
                return false;
            }
        }

        return true;
    }
}
