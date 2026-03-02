<?php

declare(strict_types=1);

use App\Scripts\ConditionEvaluator;
use App\Scripts\Interpolator;
use App\Scripts\PromptRunner;
use App\Scripts\Runners\RunnerInterface;
use App\Scripts\Runners\RunnerRegistry;
use App\Scripts\ScriptDefinition;
use App\Scripts\ScriptExecutor;
use App\Services\Settings\SettingsService;
use App\Services\StepResult;
use Illuminate\Console\Command;

function makeScript(array $overrides = []): ScriptDefinition
{
    return ScriptDefinition::fromArray(array_merge([
        'name' => 'test:script',
        'steps' => [['id' => 'step1', 'run' => 'echo hello']],
    ], $overrides), '/tmp/test.yaml');
}

function makeTestableExecutor(
    ?Interpolator $interpolator = null,
    ?ConditionEvaluator $conditionEvaluator = null,
    ?PromptRunner $promptRunner = null,
    ?RunnerRegistry $registry = null,
    ?SettingsService $settingsService = null,
    array $gitVars = [],
): ScriptExecutor {
    $interp = $interpolator ?? new Interpolator;
    $cond = $conditionEvaluator ?? new ConditionEvaluator($interp);
    $prompt = $promptRunner ?? new PromptRunner($interp);
    $reg = $registry ?? new RunnerRegistry;

    if ($settingsService === null) {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('all')->andReturn([]);
        $settings->shouldReceive('setProjectPath');
    } else {
        $settings = $settingsService;
    }

    return new class($interp, $cond, $prompt, $reg, $settings, $gitVars) extends ScriptExecutor
    {
        /** @param array<string, string> $gitVars */
        public function __construct(
            Interpolator $interpolator,
            ConditionEvaluator $conditionEvaluator,
            PromptRunner $promptRunner,
            RunnerRegistry $runnerRegistry,
            SettingsService $settingsService,
            private readonly array $gitVars = [],
        ) {
            parent::__construct($interpolator, $conditionEvaluator, $promptRunner, $runnerRegistry, $settingsService);
        }

        /** @return array<string, string> */
        protected function getGitVariables(): array
        {
            return $this->gitVars;
        }
    };
}

function makeFakeRunner(array $results = []): RunnerInterface
{
    return new class($results) implements RunnerInterface
    {
        private int $callIndex = 0;

        /** @var list<array{step: array<string, mixed>, variables: array<string, mixed>, workDir: string}> */
        public array $calls = [];

        /** @param list<StepResult> $results */
        public function __construct(private readonly array $results) {}

        public function execute(array $step, array $variables, string $workDir): StepResult
        {
            $this->calls[] = ['step' => $step, 'variables' => $variables, 'workDir' => $workDir];
            $id = (string) ($step['id'] ?? 'step');

            return $this->results[$this->callIndex++] ?? new StepResult($id, true, 'ok', '');
        }
    };
}

describe('variable resolution', function () {
    it('resolves variables from script definition using context', function () {
        $runner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $runner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'variables' => ['GREETING' => 'Hello {{NAME}}'],
            'steps' => [['id' => 'step1', 'run' => 'echo {{GREETING}}']],
        ]);

        $executor->execute($script, ['NAME' => 'World']);

        expect($runner->calls[0]['variables']['GREETING'])->toBe('Hello World')
            ->and($runner->calls[0]['variables']['NAME'])->toBe('World');
    });

    it('resolves settings variables via SettingsService', function () {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('all')->andReturn([
            'worktrees' => ['basePath' => '/var/worktrees'],
        ]);
        $settings->shouldReceive('setProjectPath');

        $runner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $runner);

        $executor = makeTestableExecutor(settingsService: $settings, registry: $registry);

        $script = makeScript([
            'variables' => ['BASE' => '{{settings.worktrees.basePath}}'],
        ]);

        $executor->execute($script);

        expect($runner->calls[0]['variables']['BASE'])->toBe('/var/worktrees');
    });

    it('resolves git variables when referenced', function () {
        $runner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $runner);

        $executor = makeTestableExecutor(
            registry: $registry,
            gitVars: ['git.currentBranch' => 'feature/test', 'git.defaultBranch' => 'main'],
        );

        $script = makeScript([
            'variables' => ['BRANCH' => '{{git.currentBranch}}', 'DEFAULT' => '{{git.defaultBranch}}'],
        ]);

        $executor->execute($script);

        expect($runner->calls[0]['variables']['BRANCH'])->toBe('feature/test')
            ->and($runner->calls[0]['variables']['DEFAULT'])->toBe('main');
    });

    it('skips settings resolution when no settings references exist', function () {
        $runner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $runner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'variables' => ['FOO' => 'bar'],
        ]);

        $result = $executor->execute($script);

        expect($result->success)->toBeTrue()
            ->and($runner->calls[0]['variables']['FOO'])->toBe('bar');
    });
});

describe('prompt filtering with bind', function () {
    it('skips prompt when bound argument is provided', function () {
        $promptRunner = Mockery::mock(PromptRunner::class);
        $promptRunner->shouldReceive('runPrompts')
            ->once()
            ->withArgs(function (array $prompts) {
                return count($prompts) === 0;
            })
            ->andReturn([]);

        $command = Mockery::mock(Command::class);
        $command->shouldReceive('argument')
            ->with('branch')
            ->andReturn('feature/auth');

        $runner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $runner);

        $executor = makeTestableExecutor(promptRunner: $promptRunner, registry: $registry);

        $script = makeScript([
            'prompts' => [
                ['id' => 'BRANCH_NAME', 'type' => 'text', 'label' => 'Branch name', 'bind' => 'argument.branch'],
            ],
        ]);

        $result = $executor->execute($script, [], $command);

        expect($result->success)->toBeTrue()
            ->and($runner->calls[0]['variables']['BRANCH_NAME'])->toBe('feature/auth');
    });

    it('skips prompt when bound option is provided', function () {
        $promptRunner = Mockery::mock(PromptRunner::class);
        $promptRunner->shouldReceive('runPrompts')
            ->once()
            ->withArgs(function (array $prompts) {
                return count($prompts) === 0;
            })
            ->andReturn([]);

        $command = Mockery::mock(Command::class);
        $command->shouldReceive('option')
            ->with('source')
            ->andReturn('main');

        $runner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $runner);

        $executor = makeTestableExecutor(promptRunner: $promptRunner, registry: $registry);

        $script = makeScript([
            'prompts' => [
                ['id' => 'SOURCE', 'type' => 'text', 'label' => 'Source', 'bind' => 'option.source'],
            ],
        ]);

        $result = $executor->execute($script, [], $command);

        expect($result->success)->toBeTrue()
            ->and($runner->calls[0]['variables']['SOURCE'])->toBe('main');
    });

    it('keeps prompt when bound argument is empty', function () {
        $promptRunner = Mockery::mock(PromptRunner::class);
        $promptRunner->shouldReceive('runPrompts')
            ->once()
            ->withArgs(function (array $prompts) {
                return count($prompts) === 1;
            })
            ->andReturn(['BRANCH_NAME' => 'typed-value']);

        $command = Mockery::mock(Command::class);
        $command->shouldReceive('argument')
            ->with('branch')
            ->andReturn(null);

        $runner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $runner);

        $executor = makeTestableExecutor(promptRunner: $promptRunner, registry: $registry);

        $script = makeScript([
            'prompts' => [
                ['id' => 'BRANCH_NAME', 'type' => 'text', 'label' => 'Branch name', 'bind' => 'argument.branch'],
            ],
        ]);

        $result = $executor->execute($script, [], $command);

        expect($result->success)->toBeTrue()
            ->and($runner->calls[0]['variables']['BRANCH_NAME'])->toBe('typed-value');
    });

    it('keeps all prompts when no command provided', function () {
        $promptRunner = Mockery::mock(PromptRunner::class);
        $promptRunner->shouldReceive('runPrompts')
            ->once()
            ->withArgs(function (array $prompts) {
                return count($prompts) === 1;
            })
            ->andReturn(['BRANCH_NAME' => 'some-branch']);

        $runner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $runner);

        $executor = makeTestableExecutor(promptRunner: $promptRunner, registry: $registry);

        $script = makeScript([
            'prompts' => [
                ['id' => 'BRANCH_NAME', 'type' => 'text', 'label' => 'Branch', 'bind' => 'argument.branch'],
            ],
        ]);

        $result = $executor->execute($script);

        expect($result->success)->toBeTrue();
    });
});

describe('step dispatch', function () {
    it('dispatches to correct runner by type', function () {
        $shellRunner = makeFakeRunner([new StepResult('step1', true, 'shell-out', '')]);
        $aiRunner = makeFakeRunner([new StepResult('step2', true, 'ai-out', '')]);

        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner)
            ->register('ai', $aiRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'steps' => [
                ['id' => 'step1', 'run' => 'echo test'],
                ['id' => 'step2', 'runner' => 'ai', 'prompt' => 'analyze'],
            ],
        ]);

        $result = $executor->execute($script);

        expect($result->success)->toBeTrue()
            ->and($shellRunner->calls)->toHaveCount(1)
            ->and($aiRunner->calls)->toHaveCount(1);
    });

    it('defaults to shell runner when no runner specified', function () {
        $shellRunner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'steps' => [['id' => 'step1', 'run' => 'echo test']],
        ]);

        $executor->execute($script);

        expect($shellRunner->calls)->toHaveCount(1);
    });

    it('skips step when condition is false', function () {
        $shellRunner = makeFakeRunner();
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'steps' => [
                ['id' => 'skip-me', 'run' => 'echo test', 'condition' => '{{SKIP}} == true'],
            ],
        ]);

        $result = $executor->execute($script, ['SKIP' => 'false']);

        expect($result->success)->toBeTrue()
            ->and($result->stepResults)->toHaveCount(1)
            ->and($result->stepResults[0]->skipped)->toBeTrue()
            ->and($shellRunner->calls)->toHaveCount(0);
    });

    it('executes step when condition is true', function () {
        $shellRunner = makeFakeRunner([new StepResult('run-me', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'steps' => [
                ['id' => 'run-me', 'run' => 'echo test', 'condition' => '{{GO}} == yes'],
            ],
        ]);

        $result = $executor->execute($script, ['GO' => 'yes']);

        expect($result->success)->toBeTrue()
            ->and($shellRunner->calls)->toHaveCount(1);
    });
});

describe('on_failure handling', function () {
    it('aborts on failure by default', function () {
        $shellRunner = makeFakeRunner([
            new StepResult('step1', false, '', 'failed'),
            new StepResult('step2', true, 'ok', ''),
        ]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'steps' => [
                ['id' => 'step1', 'run' => 'fail'],
                ['id' => 'step2', 'run' => 'echo ok'],
            ],
        ]);

        $result = $executor->execute($script);

        expect($result->success)->toBeFalse()
            ->and($result->stepResults)->toHaveCount(1)
            ->and($shellRunner->calls)->toHaveCount(1);
    });

    it('aborts on failure when on_failure is abort', function () {
        $shellRunner = makeFakeRunner([
            new StepResult('step1', false, '', 'failed'),
        ]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'steps' => [
                ['id' => 'step1', 'run' => 'fail', 'on_failure' => 'abort'],
                ['id' => 'step2', 'run' => 'echo ok'],
            ],
        ]);

        $result = $executor->execute($script);

        expect($result->success)->toBeFalse()
            ->and($result->stepResults)->toHaveCount(1);
    });

    it('continues on failure when on_failure is continue', function () {
        $shellRunner = makeFakeRunner([
            new StepResult('step1', false, '', 'failed'),
            new StepResult('step2', true, 'ok', ''),
        ]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'steps' => [
                ['id' => 'step1', 'run' => 'fail', 'on_failure' => 'continue'],
                ['id' => 'step2', 'run' => 'echo ok'],
            ],
        ]);

        $result = $executor->execute($script);

        expect($result->success)->toBeFalse()
            ->and($result->stepResults)->toHaveCount(2)
            ->and($shellRunner->calls)->toHaveCount(2);
    });

    it('continues on failure when on_failure is warn', function () {
        $shellRunner = makeFakeRunner([
            new StepResult('step1', false, '', 'warning'),
            new StepResult('step2', true, 'ok', ''),
        ]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'steps' => [
                ['id' => 'step1', 'run' => 'warn', 'on_failure' => 'warn'],
                ['id' => 'step2', 'run' => 'echo ok'],
            ],
        ]);

        $result = $executor->execute($script);

        expect($result->stepResults)->toHaveCount(2)
            ->and($shellRunner->calls)->toHaveCount(2);
    });
});

describe('capture', function () {
    it('stores step output in variables for later steps', function () {
        $shellRunner = makeFakeRunner([
            new StepResult('step1', true, "captured-value\n", ''),
            new StepResult('step2', true, 'ok', ''),
        ]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'steps' => [
                ['id' => 'step1', 'run' => 'echo captured-value', 'capture' => 'RESULT'],
                ['id' => 'step2', 'run' => 'echo {{RESULT}}'],
            ],
        ]);

        $result = $executor->execute($script);

        expect($result->success)->toBeTrue()
            ->and($shellRunner->calls[1]['variables']['RESULT'])->toBe('captured-value');
    });

    it('trims whitespace from captured output', function () {
        $shellRunner = makeFakeRunner([
            new StepResult('step1', true, "  trimmed  \n", ''),
            new StepResult('step2', true, 'ok', ''),
        ]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'steps' => [
                ['id' => 'step1', 'run' => 'echo trimmed', 'capture' => 'VAL'],
                ['id' => 'step2', 'run' => 'use {{VAL}}'],
            ],
        ]);

        $executor->execute($script);

        expect($shellRunner->calls[1]['variables']['VAL'])->toBe('trimmed');
    });
});

describe('before/after hooks', function () {
    it('runs before hooks before main steps', function () {
        $order = [];
        $shellRunner = new class($order) implements RunnerInterface
        {
            /** @param list<string> $order */
            public function __construct(private array &$order) {}

            public function execute(array $step, array $variables, string $workDir): StepResult
            {
                $id = (string) ($step['id'] ?? 'step');
                $this->order[] = $id;

                return new StepResult($id, true, '', '');
            }
        };

        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'before' => [['id' => 'before1', 'run' => 'echo before']],
            'steps' => [['id' => 'main1', 'run' => 'echo main']],
            'after' => [['id' => 'after1', 'run' => 'echo after']],
        ]);

        $executor->execute($script);

        expect($order)->toBe(['before1', 'main1', 'after1']);
    });

    it('aborts when before hook fails', function () {
        $shellRunner = makeFakeRunner([
            new StepResult('before1', false, '', 'hook failed'),
        ]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript([
            'before' => [['id' => 'before1', 'run' => 'fail']],
            'steps' => [['id' => 'main1', 'run' => 'echo main']],
        ]);

        $result = $executor->execute($script);

        expect($result->success)->toBeFalse()
            ->and($result->stepResults)->toHaveCount(1)
            ->and($result->stepResults[0]->id)->toBe('before1');
    });
});

describe('output callback', function () {
    it('forwards step output to callback', function () {
        $messages = [];
        $shellRunner = makeFakeRunner([
            new StepResult('step1', true, 'hello output', 'some error'),
        ]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);
        $executor->setOutputCallback(function (string $output, string $type) use (&$messages) {
            $messages[] = ['output' => $output, 'type' => $type];
        });

        $script = makeScript();

        $executor->execute($script);

        expect($messages)->toHaveCount(2)
            ->and($messages[0])->toBe(['output' => 'hello output', 'type' => 'stdout'])
            ->and($messages[1])->toBe(['output' => 'some error', 'type' => 'stderr']);
    });

    it('skips output callback for script runner steps to prevent duplicate display', function () {
        $messages = [];
        $scriptRunner = makeFakeRunner([
            new StepResult('nested', true, 'nested output', ''),
        ]);
        $shellRunner = makeFakeRunner([
            new StepResult('shell-step', true, 'shell output', ''),
        ]);
        $registry = new RunnerRegistry;
        $registry->register('script', $scriptRunner);
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);
        $executor->setOutputCallback(function (string $output, string $type) use (&$messages) {
            $messages[] = ['output' => $output, 'type' => $type];
        });

        $script = makeScript([
            'steps' => [
                ['id' => 'nested', 'runner' => 'script', 'script' => 'some:script'],
                ['id' => 'shell-step', 'run' => 'echo hello'],
            ],
        ]);

        $executor->execute($script);

        expect($messages)->toHaveCount(1)
            ->and($messages[0])->toBe(['output' => 'shell output', 'type' => 'stdout']);
    });

    it('still captures output from script runner steps', function () {
        $scriptRunner = makeFakeRunner([
            new StepResult('nested', true, 'captured-value', ''),
        ]);
        $shellRunner = makeFakeRunner([
            new StepResult('use-it', true, 'ok', ''),
        ]);
        $registry = new RunnerRegistry;
        $registry->register('script', $scriptRunner);
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);
        $executor->setOutputCallback(function () {});

        $script = makeScript([
            'steps' => [
                ['id' => 'nested', 'runner' => 'script', 'script' => 'some:script', 'capture' => 'RESULT'],
                ['id' => 'use-it', 'run' => 'echo {{RESULT}}'],
            ],
        ]);

        $executor->execute($script);

        expect($shellRunner->calls[0]['variables']['RESULT'])->toBe('captured-value');
    });
});

describe('working directory', function () {
    it('uses WORKTREE_PATH as working directory when set', function () {
        $shellRunner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript();

        $executor->execute($script, ['WORKTREE_PATH' => '/custom/path']);

        expect($shellRunner->calls[0]['workDir'])->toBe('/custom/path');
    });

    it('uses cwd when WORKTREE_PATH is not set', function () {
        $shellRunner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $shellRunner);

        $executor = makeTestableExecutor(registry: $registry);

        $script = makeScript();

        $executor->execute($script);

        expect($shellRunner->calls[0]['workDir'])->toBe(getcwd());
    });
});

describe('prompt default resolution', function () {
    it('resolves settings variables in prompt defaults', function () {
        $capturedPrompts = [];

        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('all')->andReturn([
            'worktrees' => ['defaultSourceBranch' => 'master'],
        ]);
        $settings->shouldReceive('setProjectPath');

        $promptRunner = Mockery::mock(PromptRunner::class);
        $promptRunner->shouldReceive('runPrompts')
            ->once()
            ->withArgs(function (array $prompts) use (&$capturedPrompts) {
                $capturedPrompts = $prompts;

                return true;
            })
            ->andReturn(['SOURCE' => 'master']);

        $runner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $runner);

        $executor = makeTestableExecutor(
            promptRunner: $promptRunner,
            registry: $registry,
            settingsService: $settings,
        );

        $script = makeScript([
            'prompts' => [
                ['id' => 'SOURCE', 'type' => 'text', 'label' => 'Source branch', 'default' => '{{settings.worktrees.defaultSourceBranch}}'],
            ],
        ]);

        $executor->execute($script);

        expect($capturedPrompts[0]['default'])->toBe('master');
    });

    it('resolves git variables in prompt defaults', function () {
        $capturedPrompts = [];

        $promptRunner = Mockery::mock(PromptRunner::class);
        $promptRunner->shouldReceive('runPrompts')
            ->once()
            ->withArgs(function (array $prompts) use (&$capturedPrompts) {
                $capturedPrompts = $prompts;

                return true;
            })
            ->andReturn(['BRANCH' => 'feature/test']);

        $runner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $runner);

        $executor = makeTestableExecutor(
            promptRunner: $promptRunner,
            registry: $registry,
            gitVars: ['git.currentBranch' => 'feature/test', 'git.defaultBranch' => 'main'],
        );

        $script = makeScript([
            'prompts' => [
                ['id' => 'BRANCH', 'type' => 'text', 'label' => 'Branch', 'default' => '{{git.currentBranch}}'],
            ],
        ]);

        $executor->execute($script);

        expect($capturedPrompts[0]['default'])->toBe('feature/test');
    });

    it('resolves combined settings and variable patterns in prompt defaults', function () {
        $capturedPrompts = [];

        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('all')->andReturn([
            'worktrees' => ['basePath' => '../worktrees'],
        ]);
        $settings->shouldReceive('setProjectPath');

        $promptRunner = Mockery::mock(PromptRunner::class);
        $promptRunner->shouldReceive('runPrompts')
            ->once()
            ->withArgs(function (array $prompts) use (&$capturedPrompts) {
                $capturedPrompts = $prompts;

                return true;
            })
            ->andReturn(['PATH' => '../worktrees/my-feature']);

        $runner = makeFakeRunner([new StepResult('step1', true, 'ok', '')]);
        $registry = new RunnerRegistry;
        $registry->register('shell', $runner);

        $executor = makeTestableExecutor(
            promptRunner: $promptRunner,
            registry: $registry,
            settingsService: $settings,
        );

        $script = makeScript([
            'prompts' => [
                ['id' => 'PATH', 'type' => 'text', 'label' => 'Worktree path', 'default' => '{{settings.worktrees.basePath}}/{{BRANCH}}'],
            ],
        ]);

        $executor->execute($script, ['BRANCH' => 'my-feature']);

        expect($capturedPrompts[0]['default'])->toBe('../worktrees/my-feature');
    });
});
