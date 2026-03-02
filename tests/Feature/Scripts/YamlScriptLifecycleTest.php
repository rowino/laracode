<?php

declare(strict_types=1);

use App\Agents\AgentInterface;
use App\Agents\AgentRegistry;
use App\Enums\BuildMode;
use App\Scripts\ConditionEvaluator;
use App\Scripts\Interpolator;
use App\Scripts\PromptRunner;
use App\Scripts\Runners\AiRunner;
use App\Scripts\Runners\RunnerRegistry;
use App\Scripts\Runners\ScriptRunner;
use App\Scripts\Runners\ShellRunner;
use App\Scripts\ScriptCommand;
use App\Scripts\ScriptDefinition;
use App\Scripts\ScriptExecutor;
use App\Scripts\ScriptLoader;
use App\Services\Settings\SettingsService;
use App\Services\StepResult;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-e2e-'.uniqid();
    mkdir($this->testPath.'/.laracode/scripts/deploy', 0755, true);
    mkdir($this->testPath.'/.laracode/scripts/helpers', 0755, true);
    $this->originalCwd = getcwd();
    chdir($this->testPath);
});

afterEach(function () {
    chdir($this->originalCwd);
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

function e2eLoader(): ScriptLoader
{
    return new class extends ScriptLoader
    {
        protected function bundledScriptsPath(): string
        {
            return '/nonexistent';
        }
    };
}

function e2eExecutor(?RunnerRegistry $registry = null): ScriptExecutor
{
    $interpolator = new Interpolator;
    $settings = Mockery::mock(SettingsService::class);
    $settings->shouldReceive('all')->andReturn([]);
    $settings->shouldReceive('setProjectPath');

    return new ScriptExecutor(
        $interpolator,
        new ConditionEvaluator($interpolator),
        new PromptRunner($interpolator),
        $registry ?? new RunnerRegistry,
        $settings,
    );
}

function e2eRegister(ScriptCommand $command): void
{
    app(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($command);
}

// -- Discovery + Registration --

it('discovers YAML file and registers it as an artisan command', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/deploy/staging.yaml', Yaml::dump([
        'name' => 'deploy:staging',
        'description' => 'Deploy to staging environment',
        'signature' => [
            'arguments' => ['env' => ['description' => 'Target env', 'required' => false]],
            'options' => ['dry-run' => ['description' => 'Dry run mode']],
        ],
        'steps' => [['id' => 'echo-env', 'run' => 'echo "deploying to {{env}}"']],
    ]));

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);

    expect($scripts)->toHaveKey('deploy:staging')
        ->and($scripts['deploy:staging']->description)->toBe('Deploy to staging environment');

    $interpolator = new Interpolator;
    $registry = new RunnerRegistry;
    $registry->register('shell', new ShellRunner($interpolator));

    $command = new ScriptCommand($scripts['deploy:staging'], e2eExecutor($registry));
    e2eRegister($command);

    $this->artisan('deploy:staging', ['env' => 'production', '--dry-run' => true])
        ->assertSuccessful();
});

// -- Variables + Conditions --

it('interpolates variables and evaluates conditions during execution', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/conditional.yaml', Yaml::dump([
        'name' => 'conditional',
        'description' => 'Test conditions',
        'variables' => ['GREETING' => 'hello-{{name}}'],
        'signature' => [
            'arguments' => ['name' => ['description' => 'Name', 'required' => true]],
            'options' => ['skip' => ['description' => 'Skip second step']],
        ],
        'steps' => [
            ['id' => 'greet', 'run' => 'echo "{{GREETING}}"', 'capture' => 'OUTPUT'],
            ['id' => 'conditional-step', 'run' => 'echo "not skipped"', 'condition' => '{{skip}} != 1'],
        ],
    ]));

    $capturedSteps = [];
    $mockRunner = Mockery::mock(\App\Scripts\Runners\RunnerInterface::class);
    $mockRunner->shouldReceive('execute')
        ->andReturnUsing(function (array $step, array $variables, string $workDir) use (&$capturedSteps) {
            $capturedSteps[] = ['id' => $step['id'], 'variables' => $variables];

            return new StepResult($step['id'], true, 'output-'.$step['id'], '');
        });

    $registry = new RunnerRegistry;
    $registry->register('shell', $mockRunner);

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);
    $command = new ScriptCommand($scripts['conditional'], e2eExecutor($registry));
    e2eRegister($command);

    $this->artisan('conditional', ['name' => 'world', '--skip' => true])
        ->assertSuccessful();

    expect($capturedSteps)->toHaveCount(1)
        ->and($capturedSteps[0]['id'])->toBe('greet')
        ->and($capturedSteps[0]['variables']['GREETING'])->toBe('hello-world');
});

// -- Script-calls-script with variable passing --

it('invokes sub-script via runner:script with variable passing', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/helpers/greet.yaml', Yaml::dump([
        'name' => 'helpers:greet',
        'description' => 'Greeting helper',
        'hidden' => true,
        'steps' => [['id' => 'say-hi', 'run' => 'echo "Hi {{WHO}} from {{ORIGIN}}"']],
    ]));

    file_put_contents($this->testPath.'/.laracode/scripts/orchestrate.yaml', Yaml::dump([
        'name' => 'orchestrate',
        'description' => 'Orchestrate scripts',
        'variables' => ['ORIGIN' => 'parent'],
        'steps' => [
            [
                'id' => 'call-greet',
                'runner' => 'script',
                'script' => 'helpers:greet',
                'variables' => ['WHO' => 'world'],
                'capture' => 'GREET_OUTPUT',
            ],
            ['id' => 'use-output', 'run' => 'echo "result: {{GREET_OUTPUT}}"'],
        ],
    ]));

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);

    $interpolator = new Interpolator;
    $shellRunner = new ShellRunner($interpolator);
    $registry = new RunnerRegistry;

    $scriptRunner = new ScriptRunner($loader);
    $registry->register('shell', $shellRunner);
    $registry->register('script', $scriptRunner);

    $executor = e2eExecutor($registry);
    $scriptRunner = new ScriptRunner($loader, $executor);
    $registry = new RunnerRegistry;
    $registry->register('shell', $shellRunner);
    $registry->register('script', $scriptRunner);
    $executor = e2eExecutor($registry);
    $scriptRunner = new ScriptRunner($loader, $executor);

    $registry2 = new RunnerRegistry;
    $registry2->register('shell', $shellRunner);
    $registry2->register('script', $scriptRunner);
    $finalExecutor = e2eExecutor($registry2);

    $result = $finalExecutor->execute($scripts['orchestrate'], ['PROJECT_PATH' => $this->testPath]);

    expect($result->success)->toBeTrue()
        ->and($result->stepResults)->toHaveCount(2)
        ->and($result->stepResults[0]->id)->toBe('call-greet')
        ->and($result->stepResults[0]->success)->toBeTrue();
});

// -- AI runner with mocked agent --

it('executes AI runner steps with mocked AgentRegistry', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/ai-task.yaml', Yaml::dump([
        'name' => 'ai-task',
        'description' => 'AI-powered task',
        'steps' => [
            [
                'id' => 'ai-analyze',
                'runner' => 'ai',
                'prompt' => 'Analyze {{SUBJECT}}',
                'mode' => 'plan',
                'capture' => 'AI_RESULT',
            ],
            ['id' => 'use-result', 'run' => 'echo "{{AI_RESULT}}"'],
        ],
    ]));

    $mockAgent = Mockery::mock(AgentInterface::class);
    $mockAgent->shouldReceive('name')->andReturn('test-agent');
    $mockAgent->shouldReceive('executable')->andReturn('echo');
    $mockAgent->shouldReceive('buildCommand')->with(BuildMode::Plan)->andReturn(['echo', '--plan']);

    $settingsService = Mockery::mock(SettingsService::class);
    $settingsService->shouldReceive('get')->andReturnNull();
    $agentRegistry = new AgentRegistry($settingsService);
    $agentRegistry->register($mockAgent);

    $interpolator = new Interpolator;
    $aiRunner = new AiRunner($agentRegistry, $interpolator);
    $shellRunner = new ShellRunner($interpolator);

    $registry = new RunnerRegistry;
    $registry->register('shell', $shellRunner);
    $registry->register('ai', $aiRunner);

    $executor = e2eExecutor($registry);

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);
    $result = $executor->execute($scripts['ai-task'], ['SUBJECT' => 'codebase']);

    expect($result->success)->toBeTrue()
        ->and($result->stepResults)->toHaveCount(2)
        ->and($result->stepResults[0]->id)->toBe('ai-analyze')
        ->and($result->stepResults[0]->success)->toBeTrue();
});

// -- Hidden scripts callable via runner:script --

it('hides scripts from command list but allows calling via runner:script', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/helpers/internal.yaml', Yaml::dump([
        'name' => 'helpers:internal',
        'description' => 'Internal helper',
        'hidden' => true,
        'steps' => [['id' => 'secret', 'run' => 'echo "internal output"']],
    ]));

    file_put_contents($this->testPath.'/.laracode/scripts/public-cmd.yaml', Yaml::dump([
        'name' => 'public-cmd',
        'description' => 'Public command',
        'steps' => [
            ['id' => 'call-internal', 'runner' => 'script', 'script' => 'helpers:internal', 'capture' => 'RESULT'],
            ['id' => 'echo-result', 'run' => 'echo "got: {{RESULT}}"'],
        ],
    ]));

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);

    $nonHidden = array_filter($scripts, fn (ScriptDefinition $s) => ! $s->hidden);
    expect($nonHidden)->toHaveCount(1)
        ->and($nonHidden)->toHaveKey('public-cmd')
        ->and($scripts)->toHaveKey('helpers:internal')
        ->and($scripts['helpers:internal']->hidden)->toBeTrue();

    $interpolator = new Interpolator;
    $shellRunner = new ShellRunner($interpolator);
    $registry = new RunnerRegistry;
    $registry->register('shell', $shellRunner);

    $scriptRunner = new ScriptRunner($loader);
    $registry->register('script', $scriptRunner);

    $executor = e2eExecutor($registry);
    $scriptRunner2 = new ScriptRunner($loader, $executor);
    $registry2 = new RunnerRegistry;
    $registry2->register('shell', $shellRunner);
    $registry2->register('script', $scriptRunner2);
    $finalExecutor = e2eExecutor($registry2);

    $result = $finalExecutor->execute($scripts['public-cmd'], ['PROJECT_PATH' => $this->testPath]);

    expect($result->success)->toBeTrue()
        ->and($result->stepResults[0]->id)->toBe('call-internal')
        ->and($result->stepResults[0]->success)->toBeTrue();
});

// -- on_failure:abort stops execution --

it('stops execution on on_failure:abort', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/abort-test.yaml', Yaml::dump([
        'name' => 'abort-test',
        'description' => 'Test abort behavior',
        'steps' => [
            ['id' => 'step-ok', 'run' => 'echo "ok"'],
            ['id' => 'step-fail', 'run' => 'exit 1', 'on_failure' => 'abort'],
            ['id' => 'step-never', 'run' => 'echo "should not run"'],
        ],
    ]));

    $interpolator = new Interpolator;
    $registry = new RunnerRegistry;
    $registry->register('shell', new ShellRunner($interpolator));

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);

    $command = new ScriptCommand($scripts['abort-test'], e2eExecutor($registry));
    e2eRegister($command);

    $this->artisan('abort-test')
        ->assertFailed();

    $result = e2eExecutor($registry)->execute($scripts['abort-test']);
    expect($result->success)->toBeFalse()
        ->and($result->stepResults)->toHaveCount(2)
        ->and($result->stepResults[0]->success)->toBeTrue()
        ->and($result->stepResults[1]->success)->toBeFalse();
});

// -- on_failure:continue keeps going --

it('continues execution on on_failure:continue', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/continue-test.yaml', Yaml::dump([
        'name' => 'continue-test',
        'description' => 'Test continue behavior',
        'steps' => [
            ['id' => 'step-fail', 'run' => 'exit 1', 'on_failure' => 'continue'],
            ['id' => 'step-after', 'run' => 'echo "still running"'],
        ],
    ]));

    $interpolator = new Interpolator;
    $registry = new RunnerRegistry;
    $registry->register('shell', new ShellRunner($interpolator));

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);
    $result = e2eExecutor($registry)->execute($scripts['continue-test']);

    expect($result->success)->toBeFalse()
        ->and($result->stepResults)->toHaveCount(2)
        ->and($result->stepResults[0]->success)->toBeFalse()
        ->and($result->stepResults[1]->success)->toBeTrue();
});

// -- on_failure:warn marks as skipped --

it('treats warn failure as skipped and succeeds overall', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/warn-test.yaml', Yaml::dump([
        'name' => 'warn-test',
        'description' => 'Test warn behavior',
        'steps' => [
            ['id' => 'step-warn', 'run' => 'exit 1', 'on_failure' => 'warn'],
            ['id' => 'step-after', 'run' => 'echo "still running"'],
        ],
    ]));

    $interpolator = new Interpolator;
    $registry = new RunnerRegistry;
    $registry->register('shell', new ShellRunner($interpolator));

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);
    $result = e2eExecutor($registry)->execute($scripts['warn-test']);

    expect($result->success)->toBeTrue()
        ->and($result->stepResults)->toHaveCount(2)
        ->and($result->stepResults[0]->skipped)->toBeTrue()
        ->and($result->stepResults[1]->success)->toBeTrue();
});

// -- capture: stores output in parent variables --

it('captures step output into variables for later steps', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/capture-test.yaml', Yaml::dump([
        'name' => 'capture-test',
        'description' => 'Test capture',
        'steps' => [
            ['id' => 'get-value', 'run' => 'echo "captured-data"', 'capture' => 'MY_VAR'],
            ['id' => 'use-value', 'run' => 'echo "result: {{MY_VAR}}"'],
        ],
    ]));

    $capturedVars = [];
    $mockRunner = Mockery::mock(\App\Scripts\Runners\RunnerInterface::class);
    $callCount = 0;
    $mockRunner->shouldReceive('execute')
        ->andReturnUsing(function (array $step, array $variables, string $workDir) use (&$capturedVars, &$callCount) {
            $callCount++;
            if ($callCount === 2) {
                $capturedVars = $variables;
            }

            return new StepResult($step['id'], true, $callCount === 1 ? 'captured-data' : 'result: captured-data', '');
        });

    $registry = new RunnerRegistry;
    $registry->register('shell', $mockRunner);

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);
    $result = e2eExecutor($registry)->execute($scripts['capture-test']);

    expect($result->success)->toBeTrue()
        ->and($capturedVars)->toHaveKey('MY_VAR', 'captured-data');
});

// -- Error: missing script in runner:script --

it('fails when runner:script references a non-existent script', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/missing-ref.yaml', Yaml::dump([
        'name' => 'missing-ref',
        'description' => 'References missing script',
        'steps' => [
            ['id' => 'call-missing', 'runner' => 'script', 'script' => 'does:not-exist'],
        ],
    ]));

    $interpolator = new Interpolator;
    $loader = e2eLoader();
    $shellRunner = new ShellRunner($interpolator);
    $scriptRunner = new ScriptRunner($loader, e2eExecutor());
    $registry = new RunnerRegistry;
    $registry->register('shell', $shellRunner);
    $registry->register('script', $scriptRunner);

    $scripts = $loader->discover($this->testPath);
    $result = e2eExecutor($registry)->execute($scripts['missing-ref'], ['PROJECT_PATH' => $this->testPath]);

    expect($result->success)->toBeFalse()
        ->and($result->stepResults[0]->error)->toContain('Script not found');
});

// -- Error: circular script call --

it('detects circular script calls and throws exception', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/loop-a.yaml', Yaml::dump([
        'name' => 'loop-a',
        'description' => 'Circular A',
        'steps' => [['id' => 'call-b', 'runner' => 'script', 'script' => 'loop-b']],
    ]));

    file_put_contents($this->testPath.'/.laracode/scripts/loop-b.yaml', Yaml::dump([
        'name' => 'loop-b',
        'description' => 'Circular B',
        'steps' => [['id' => 'call-a', 'runner' => 'script', 'script' => 'loop-a']],
    ]));

    $interpolator = new Interpolator;
    $loader = e2eLoader();
    $shellRunner = new ShellRunner($interpolator);

    $registry = new RunnerRegistry;
    $registry->register('shell', $shellRunner);

    $executorRef = null;
    $scriptRunner = new ScriptRunner($loader, function () use (&$executorRef) {
        return $executorRef;
    });
    $registry->register('script', $scriptRunner);

    $executorRef = e2eExecutor($registry);

    $scripts = $loader->discover($this->testPath);

    expect(fn () => $executorRef->execute($scripts['loop-a'], ['PROJECT_PATH' => $this->testPath]))
        ->toThrow(RuntimeException::class, 'Circular script call detected');
});

// -- Error: unknown runner --

it('throws on unknown runner type', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/bad-runner.yaml', Yaml::dump([
        'name' => 'bad-runner',
        'description' => 'Unknown runner',
        'steps' => [['id' => 'step1', 'runner' => 'nonexistent', 'run' => 'echo hi']],
    ]));

    $registry = new RunnerRegistry;
    $registry->register('shell', new ShellRunner(new Interpolator));

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);

    expect(fn () => e2eExecutor($registry)->execute($scripts['bad-runner']))
        ->toThrow(InvalidArgumentException::class, 'Unknown runner: nonexistent');
});

// -- Before hooks abort execution on failure --

it('aborts execution when before hook fails', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/before-fail.yaml', Yaml::dump([
        'name' => 'before-fail',
        'description' => 'Before hook failure',
        'before' => [['id' => 'check', 'run' => 'exit 1']],
        'steps' => [['id' => 'main-step', 'run' => 'echo "should not run"']],
    ]));

    $interpolator = new Interpolator;
    $registry = new RunnerRegistry;
    $registry->register('shell', new ShellRunner($interpolator));

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);
    $result = e2eExecutor($registry)->execute($scripts['before-fail']);

    expect($result->success)->toBeFalse()
        ->and($result->stepResults)->toHaveCount(1)
        ->and($result->stepResults[0]->id)->toBe('check');
});

// -- After hooks always run --

it('runs after hooks even when main steps fail', function () {
    $stepIds = [];
    $mockRunner = Mockery::mock(\App\Scripts\Runners\RunnerInterface::class);
    $mockRunner->shouldReceive('execute')
        ->andReturnUsing(function (array $step, array $variables, string $workDir) use (&$stepIds) {
            $stepIds[] = $step['id'];
            $success = $step['id'] !== 'main-fail';

            return new StepResult($step['id'], $success, '', $success ? '' : 'failed');
        });

    $registry = new RunnerRegistry;
    $registry->register('shell', $mockRunner);

    $script = ScriptDefinition::fromArray([
        'name' => 'after-hooks',
        'description' => 'After hook test',
        'steps' => [['id' => 'main-fail', 'run' => 'exit 1']],
        'after' => [['id' => 'cleanup', 'run' => 'echo "cleanup"']],
    ], '/tmp/after.yaml');

    $result = e2eExecutor($registry)->execute($script);

    expect($result->success)->toBeFalse()
        ->and($stepIds)->toContain('main-fail')
        ->and($stepIds)->toContain('cleanup');
});

// -- Full lifecycle: discovery → artisan command → execution with all features --

it('exercises full lifecycle from YAML to artisan command execution', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/deploy/full.yaml', Yaml::dump([
        'name' => 'deploy:full',
        'description' => 'Full lifecycle deploy',
        'signature' => [
            'arguments' => ['target' => ['description' => 'Deploy target', 'required' => false]],
            'options' => ['detail' => ['description' => 'Detailed output']],
        ],
        'variables' => ['ENV' => '{{target}}', 'PREFIX' => 'deploy'],
        'steps' => [
            ['id' => 'get-version', 'run' => 'echo "v1.2.3"', 'capture' => 'VERSION'],
            ['id' => 'deploy', 'run' => 'echo "{{PREFIX}}-{{ENV}}-{{VERSION}}"'],
            ['id' => 'skip-this', 'run' => 'echo "skipped"', 'condition' => '{{ENV}} == never-match'],
        ],
    ]));

    $interpolator = new Interpolator;
    $registry = new RunnerRegistry;
    $registry->register('shell', new ShellRunner($interpolator));

    $loader = e2eLoader();
    $scripts = $loader->discover($this->testPath);

    expect($scripts)->toHaveKey('deploy:full');

    $command = new ScriptCommand($scripts['deploy:full'], e2eExecutor($registry));
    e2eRegister($command);

    $this->artisan('deploy:full', ['target' => 'staging'])
        ->assertSuccessful();

    $result = e2eExecutor($registry)->execute($scripts['deploy:full'], ['target' => 'staging']);

    expect($result->success)->toBeTrue()
        ->and($result->stepResults)->toHaveCount(3)
        ->and($result->stepResults[0]->id)->toBe('get-version')
        ->and($result->stepResults[0]->success)->toBeTrue()
        ->and($result->stepResults[1]->id)->toBe('deploy')
        ->and($result->stepResults[1]->success)->toBeTrue()
        ->and($result->stepResults[2]->id)->toBe('skip-this')
        ->and($result->stepResults[2]->skipped)->toBeTrue();
});
