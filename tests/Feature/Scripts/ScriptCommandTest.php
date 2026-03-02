<?php

declare(strict_types=1);

use App\Scripts\ConditionEvaluator;
use App\Scripts\Interpolator;
use App\Scripts\PromptRunner;
use App\Scripts\Runners\RunnerInterface;
use App\Scripts\Runners\RunnerRegistry;
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
    $this->testPath = sys_get_temp_dir().'/laracode-script-cmd-'.uniqid();
    mkdir($this->testPath.'/.laracode/scripts/deploy', 0755, true);
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

function makeScriptExecutor(
    ?RunnerRegistry $registry = null,
): ScriptExecutor {
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

function registerScriptCommand(ScriptCommand $command): void
{
    app(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($command);
}

it('registers discovered YAML scripts as artisan commands', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/greet.yaml', Yaml::dump([
        'name' => 'greet',
        'description' => 'Say hello',
        'steps' => [['id' => 'say-hi', 'run' => 'echo hello']],
    ]));

    $loader = new class extends ScriptLoader
    {
        protected function bundledScriptsPath(): string
        {
            return '/nonexistent';
        }
    };
    $scripts = $loader->discover($this->testPath);

    expect($scripts)->toHaveKey('greet')
        ->and($scripts['greet']->name)->toBe('greet')
        ->and($scripts['greet']->description)->toBe('Say hello');
});

it('does not register hidden scripts as commands', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/internal.yaml', Yaml::dump([
        'name' => 'internal',
        'description' => 'Hidden helper',
        'hidden' => true,
        'steps' => [['id' => 'step1', 'run' => 'echo hidden']],
    ]));

    $loader = new class extends ScriptLoader
    {
        protected function bundledScriptsPath(): string
        {
            return '/nonexistent';
        }
    };
    $scripts = $loader->discover($this->testPath);

    $nonHidden = array_filter($scripts, fn (ScriptDefinition $s) => ! $s->hidden);

    expect($scripts)->toHaveKey('internal')
        ->and($scripts['internal']->hidden)->toBeTrue()
        ->and($nonHidden)->toBeEmpty();
});

it('builds correct signature with arguments and options', function () {
    $script = ScriptDefinition::fromArray([
        'name' => 'deploy:app',
        'description' => 'Deploy the application',
        'signature' => [
            'arguments' => [
                'env' => ['description' => 'Target environment', 'required' => false],
            ],
            'options' => [
                'force' => ['description' => 'Force deployment'],
                'tag' => ['description' => 'Version tag', 'value_required' => true],
            ],
        ],
        'steps' => [['id' => 'step1', 'run' => 'echo deploy']],
    ], '/tmp/deploy.yaml');

    $command = new ScriptCommand($script, makeScriptExecutor());
    $definition = $command->getDefinition();

    expect($command->getName())->toBe('deploy:app')
        ->and($command->getDescription())->toBe('Deploy the application')
        ->and($definition->hasArgument('env'))->toBeTrue()
        ->and($definition->getArgument('env')->isRequired())->toBeFalse()
        ->and($definition->getArgument('env')->getDescription())->toBe('Target environment')
        ->and($definition->hasOption('force'))->toBeTrue()
        ->and($definition->getOption('force')->acceptValue())->toBeFalse()
        ->and($definition->hasOption('tag'))->toBeTrue()
        ->and($definition->getOption('tag')->acceptValue())->toBeTrue();
});

it('builds required arguments correctly', function () {
    $script = ScriptDefinition::fromArray([
        'name' => 'test:req',
        'description' => 'Test required arg',
        'signature' => [
            'arguments' => [
                'branch' => ['description' => 'Branch name', 'required' => true],
            ],
        ],
        'steps' => [['id' => 'step1', 'run' => 'echo {{branch}}']],
    ], '/tmp/test.yaml');

    $command = new ScriptCommand($script, makeScriptExecutor());
    $definition = $command->getDefinition();

    expect($definition->hasArgument('branch'))->toBeTrue()
        ->and($definition->getArgument('branch')->isRequired())->toBeTrue()
        ->and($definition->getArgument('branch')->getDescription())->toBe('Branch name');
});

it('executes script steps via ScriptExecutor', function () {
    $script = ScriptDefinition::fromArray([
        'name' => 'test:echo',
        'description' => 'Echo test',
        'steps' => [['id' => 'say-hi', 'run' => 'echo "hello world"']],
    ], '/tmp/echo.yaml');

    $interpolator = new Interpolator;
    $registry = new RunnerRegistry;
    $registry->register('shell', new ShellRunner($interpolator));

    $command = new ScriptCommand($script, makeScriptExecutor($registry));
    registerScriptCommand($command);

    $this->artisan('test:echo')
        ->assertSuccessful();
});

it('maps namespace directories to colon-separated command names', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/deploy/staging.yaml', Yaml::dump([
        'name' => 'deploy:staging',
        'description' => 'Deploy to staging',
        'steps' => [['id' => 'step1', 'run' => 'echo staging']],
    ]));

    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testPath);

    expect($scripts)->toHaveKey('deploy:staging')
        ->and($scripts['deploy:staging']->name)->toBe('deploy:staging');
});

it('passes arguments and options as context to executor', function () {
    $capturedContext = null;

    $script = ScriptDefinition::fromArray([
        'name' => 'test:ctx',
        'description' => 'Test context',
        'signature' => [
            'arguments' => [
                'name' => ['description' => 'Name', 'required' => false],
            ],
            'options' => [
                'env' => ['description' => 'Environment', 'value_required' => true],
            ],
        ],
        'steps' => [['id' => 'step1', 'run' => 'echo "{{name}} {{env}}"']],
    ], '/tmp/test-ctx.yaml');

    $mockRunner = Mockery::mock(RunnerInterface::class);
    $mockRunner->shouldReceive('execute')
        ->once()
        ->andReturnUsing(function (array $step, array $variables, string $workDir) use (&$capturedContext) {
            $capturedContext = $variables;

            return new StepResult('step1', true, 'ok', '');
        });

    $registry = new RunnerRegistry;
    $registry->register('shell', $mockRunner);

    $command = new ScriptCommand($script, makeScriptExecutor($registry));
    registerScriptCommand($command);

    $this->artisan('test:ctx', ['name' => 'world', '--env' => 'prod'])
        ->assertSuccessful();

    expect($capturedContext)->toHaveKey('name', 'world')
        ->and($capturedContext)->toHaveKey('env', 'prod');
});

it('returns failure exit code when steps fail', function () {
    $script = ScriptDefinition::fromArray([
        'name' => 'test:fail',
        'description' => 'Test failure',
        'steps' => [['id' => 'step1', 'run' => 'exit 1']],
    ], '/tmp/test-fail.yaml');

    $interpolator = new Interpolator;
    $registry = new RunnerRegistry;
    $registry->register('shell', new ShellRunner($interpolator));

    $command = new ScriptCommand($script, makeScriptExecutor($registry));
    registerScriptCommand($command);

    $this->artisan('test:fail')
        ->assertFailed();
});

it('exposes script definition via getter', function () {
    $script = ScriptDefinition::fromArray([
        'name' => 'test:getter',
        'description' => 'Test getter',
        'steps' => [['id' => 'step1', 'run' => 'echo ok']],
    ], '/tmp/test.yaml');

    $command = new ScriptCommand($script, makeScriptExecutor());

    expect($command->getScriptDefinition())->toBe($script)
        ->and($command->getScriptDefinition()->name)->toBe('test:getter');
});
