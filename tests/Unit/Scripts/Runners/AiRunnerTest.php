<?php

declare(strict_types=1);

use App\Agents\AgentInterface;
use App\Agents\AgentRegistry;
use App\Enums\BuildMode;
use App\Scripts\Interpolator;
use App\Scripts\Runners\AiRunner;
use App\Services\Settings\SettingsService;

function createAiRunnerMockAgent(string $name = 'test-agent', string $executable = 'echo'): AgentInterface
{
    $agent = Mockery::mock(AgentInterface::class);
    $agent->shouldReceive('name')->andReturn($name);
    $agent->shouldReceive('executable')->andReturn($executable);
    $agent->shouldReceive('buildCommand')->andReturnUsing(function (BuildMode $mode) use ($executable) {
        return match ($mode) {
            BuildMode::Yolo => [$executable, '--yolo'],
            BuildMode::Plan => [$executable, '--plan'],
            BuildMode::Accept => [$executable, '--accept'],
            BuildMode::Interactive => [$executable],
        };
    });

    return $agent;
}

function createAiRunner(?AgentRegistry $registry = null): AiRunner
{
    if ($registry === null) {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->andReturnNull();
        $registry = new AgentRegistry($settings);
        $registry->register(createAiRunnerMockAgent());
    }

    return new AiRunner($registry, new Interpolator);
}

describe('execute', function () {
    it('returns failure when no prompt specified', function () {
        $runner = createAiRunner();
        $result = $runner->execute(
            ['id' => 'test'],
            [],
            sys_get_temp_dir()
        );

        expect($result->id)->toBe('test')
            ->and($result->success)->toBeFalse()
            ->and($result->error)->toBe('No prompt specified');
    });

    it('returns failure for empty prompt', function () {
        $runner = createAiRunner();
        $result = $runner->execute(
            ['id' => 'test', 'prompt' => ''],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeFalse()
            ->and($result->error)->toBe('No prompt specified');
    });

    it('defaults id to ai-step when missing', function () {
        $runner = createAiRunner();
        $result = $runner->execute(
            ['prompt' => 'hello'],
            [],
            sys_get_temp_dir()
        );

        expect($result->id)->toBe('ai-step');
    });

    it('interpolates variables in prompt', function () {
        // Use "echo" as executable so buildCommand returns ['echo']
        // Then -p <prompt> makes it: echo -p "interpolated"
        // which will output: -p interpolated
        $runner = createAiRunner();
        $result = $runner->execute(
            ['id' => 'interp', 'prompt' => '{{MESSAGE}}'],
            ['MESSAGE' => 'hello world'],
            sys_get_temp_dir()
        );

        // echo -p "hello world" -> outputs "-p hello world"
        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('hello world');
    });

    it('uses default agent from registry when no agent specified', function () {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->andReturnNull();
        $registry = new AgentRegistry($settings);

        $agent = createAiRunnerMockAgent('default-agent', 'echo');
        $registry->register($agent);

        $runner = new AiRunner($registry, new Interpolator);
        $result = $runner->execute(
            ['id' => 'test', 'prompt' => 'test prompt'],
            [],
            sys_get_temp_dir()
        );

        // echo -p "test prompt" -> success
        expect($result->success)->toBeTrue();
    });

    it('resolves specific agent from step.agent key', function () {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->andReturnNull();
        $registry = new AgentRegistry($settings);

        $defaultAgent = createAiRunnerMockAgent('default-agent', 'false');
        $specificAgent = createAiRunnerMockAgent('specific-agent', 'echo');

        $registry->register($defaultAgent);
        $registry->register($specificAgent);

        $runner = new AiRunner($registry, new Interpolator);
        $result = $runner->execute(
            ['id' => 'test', 'prompt' => 'test', 'agent' => 'specific-agent'],
            [],
            sys_get_temp_dir()
        );

        // echo -p test -> success (not "false" which would fail)
        expect($result->success)->toBeTrue();
    });

    it('throws for unknown agent name', function () {
        $runner = createAiRunner();
        $runner->execute(
            ['id' => 'test', 'prompt' => 'test', 'agent' => 'nonexistent'],
            [],
            sys_get_temp_dir()
        );
    })->throws(InvalidArgumentException::class, "Agent 'nonexistent' is not registered.");

    it('maps mode string to BuildMode', function () {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->andReturnNull();
        $registry = new AgentRegistry($settings);

        $agent = Mockery::mock(AgentInterface::class);
        $agent->shouldReceive('name')->andReturn('test');
        $agent->shouldReceive('executable')->andReturn('echo');
        $agent->shouldReceive('buildCommand')
            ->with(Mockery::on(fn (BuildMode $m) => $m === BuildMode::Plan))
            ->once()
            ->andReturn(['echo', '--plan']);
        $registry->register($agent);

        $runner = new AiRunner($registry, new Interpolator);
        $result = $runner->execute(
            ['id' => 'test', 'prompt' => 'plan this', 'mode' => 'plan'],
            [],
            sys_get_temp_dir()
        );

        // echo --plan -p "plan this" -> success
        expect($result->success)->toBeTrue();
    });

    it('defaults to interactive mode when no mode specified', function () {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->andReturnNull();
        $registry = new AgentRegistry($settings);

        $agent = Mockery::mock(AgentInterface::class);
        $agent->shouldReceive('name')->andReturn('test');
        $agent->shouldReceive('executable')->andReturn('echo');
        $agent->shouldReceive('buildCommand')
            ->with(Mockery::on(fn (BuildMode $m) => $m === BuildMode::Interactive))
            ->once()
            ->andReturn(['echo']);
        $registry->register($agent);

        $runner = new AiRunner($registry, new Interpolator);
        $result = $runner->execute(
            ['id' => 'test', 'prompt' => 'hello'],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue();
    });

    it('throws for invalid mode string', function () {
        $runner = createAiRunner();
        $runner->execute(
            ['id' => 'test', 'prompt' => 'test', 'mode' => 'invalid'],
            [],
            sys_get_temp_dir()
        );
    })->throws(ValueError::class);

    it('appends output_format flag when specified', function () {
        $runner = createAiRunner();
        $result = $runner->execute(
            ['id' => 'test', 'prompt' => 'analyze', 'output_format' => 'json'],
            [],
            sys_get_temp_dir()
        );

        // echo -p analyze --output-format json
        expect($result->success)->toBeTrue()
            ->and($result->output)->toContain('--output-format')
            ->and($result->output)->toContain('json');
    });

    it('captures stdout for capture key', function () {
        $runner = createAiRunner();
        $result = $runner->execute(
            ['id' => 'test', 'prompt' => 'hello', 'capture' => 'AI_RESULT'],
            [],
            sys_get_temp_dir()
        );

        // capture is handled by ScriptExecutor, AiRunner just returns output
        expect($result->success)->toBeTrue()
            ->and($result->output)->not->toBeEmpty();
    });
});

describe('output callback', function () {
    it('invokes callback with command info before execution', function () {
        $messages = [];
        $runner = createAiRunner();
        $runner->setOutputCallback(function (string $output, string $type) use (&$messages) {
            $messages[] = ['output' => $output, 'type' => $type];
        });

        $runner->execute(
            ['id' => 'test', 'prompt' => 'hello world'],
            [],
            sys_get_temp_dir()
        );

        expect($messages[0]['type'])->toBe('command')
            ->and($messages[0]['output'])->toContain('test-agent')
            ->and($messages[0]['output'])->toContain('interactive')
            ->and($messages[0]['output'])->toContain('hello world');
    });

    it('invokes callback with stdout', function () {
        $messages = [];
        $runner = createAiRunner();
        $runner->setOutputCallback(function (string $output, string $type) use (&$messages) {
            $messages[] = ['output' => $output, 'type' => $type];
        });

        $runner->execute(
            ['id' => 'test', 'prompt' => 'hello'],
            [],
            sys_get_temp_dir()
        );

        $stdoutMessages = array_filter($messages, fn ($m) => $m['type'] === 'stdout');
        expect($stdoutMessages)->not->toBeEmpty();
    });

    it('does not invoke callback when none is set', function () {
        $runner = createAiRunner();
        $result = $runner->execute(
            ['id' => 'test', 'prompt' => 'hello'],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue();
    });
});
