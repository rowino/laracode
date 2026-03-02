<?php

declare(strict_types=1);

namespace App\Scripts\Runners;

use App\Scripts\ScriptExecutor;
use App\Scripts\ScriptLoader;
use App\Services\StepResult;
use RuntimeException;

/**
 * Usage: $runner->execute(['id' => 'setup', 'script' => 'worktree:shared-setup'], $vars, '/tmp');
 */
class ScriptRunner implements RunnerInterface
{
    /** @var list<string> */
    private array $callStack = [];

    /** @var (\Closure(): ScriptExecutor)|null */
    private ?\Closure $executorFactory = null;

    private ?ScriptExecutor $resolvedExecutor = null;

    public function __construct(
        private readonly ScriptLoader $scriptLoader,
        ScriptExecutor|\Closure|null $scriptExecutor = null,
    ) {
        if ($scriptExecutor instanceof ScriptExecutor) {
            $this->resolvedExecutor = $scriptExecutor;
        } elseif ($scriptExecutor instanceof \Closure) {
            $this->executorFactory = $scriptExecutor;
        }
    }

    public function execute(array $step, array $variables, string $workDir): StepResult
    {
        $id = $step['id'] ?? 'script-step';
        $scriptName = $step['script'] ?? '';

        if ($scriptName === '') {
            return new StepResult((string) $id, false, '', 'No script name specified');
        }

        if (in_array($scriptName, $this->callStack, true)) {
            $chain = implode(' -> ', [...$this->callStack, $scriptName]);
            throw new RuntimeException("Circular script call detected: {$chain}");
        }

        $projectPath = $variables['PROJECT_PATH'] ?? (getcwd() ?: '.');
        $scripts = $this->scriptLoader->discover((string) $projectPath);

        if (! isset($scripts[$scriptName])) {
            return new StepResult((string) $id, false, '', "Script not found: {$scriptName}");
        }

        $scriptDefinition = $scripts[$scriptName];

        $childVariables = $variables;
        if (isset($step['variables']) && is_array($step['variables'])) {
            $childVariables = array_merge($childVariables, $step['variables']);
        }

        $this->callStack[] = $scriptName;

        try {
            $result = $this->resolveExecutor()->execute($scriptDefinition, $childVariables);
        } finally {
            array_pop($this->callStack);
        }

        $combinedOutput = '';
        foreach ($result->stepResults as $stepResult) {
            if ($stepResult->output !== '') {
                $combinedOutput .= $stepResult->output;
            }
        }

        return new StepResult(
            (string) $id,
            $result->success,
            $combinedOutput,
            $result->success ? '' : 'Sub-script execution failed',
        );
    }

    public function resetCallStack(): void
    {
        $this->callStack = [];
    }

    private function resolveExecutor(): ScriptExecutor
    {
        if ($this->resolvedExecutor === null) {
            if ($this->executorFactory === null) {
                throw new RuntimeException('ScriptRunner requires a ScriptExecutor instance or factory closure');
            }
            $this->resolvedExecutor = ($this->executorFactory)();
        }

        return $this->resolvedExecutor;
    }
}
