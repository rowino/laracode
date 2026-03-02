<?php

declare(strict_types=1);

use App\Scripts\Runners\ScriptRunner;
use App\Scripts\ScriptDefinition;
use App\Scripts\ScriptExecutor;
use App\Scripts\ScriptLoader;
use App\Services\FlowResult;
use App\Services\StepResult;

beforeEach(function () {
    $this->scriptLoader = Mockery::mock(ScriptLoader::class);
    $this->scriptExecutor = Mockery::mock(ScriptExecutor::class);
    $this->runner = new ScriptRunner($this->scriptLoader, $this->scriptExecutor);
});

describe('execute', function () {
    it('returns failure when no script name specified', function () {
        $result = $this->runner->execute(
            ['id' => 'test'],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeFalse()
            ->and($result->error)->toBe('No script name specified');
    });

    it('returns failure when script not found', function () {
        $this->scriptLoader
            ->shouldReceive('discover')
            ->andReturn([]);

        $result = $this->runner->execute(
            ['id' => 'test', 'script' => 'nonexistent:script'],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeFalse()
            ->and($result->error)->toBe('Script not found: nonexistent:script');
    });

    it('resolves and executes a sub-script', function () {
        $childScript = ScriptDefinition::fromArray([
            'name' => 'child:script',
            'steps' => [['id' => 'step1', 'run' => 'echo hi']],
        ], '/tmp/child.yaml');

        $this->scriptLoader
            ->shouldReceive('discover')
            ->andReturn(['child:script' => $childScript]);

        $this->scriptExecutor
            ->shouldReceive('execute')
            ->with($childScript, Mockery::type('array'))
            ->once()
            ->andReturn(new FlowResult(true, [
                new StepResult('step1', true, "hello\n"),
            ]));

        $result = $this->runner->execute(
            ['id' => 'call-child', 'script' => 'child:script'],
            ['PARENT_VAR' => 'value'],
            sys_get_temp_dir()
        );

        expect($result->id)->toBe('call-child')
            ->and($result->success)->toBeTrue()
            ->and($result->output)->toBe("hello\n");
    });

    it('passes parent variables to child script', function () {
        $childScript = ScriptDefinition::fromArray([
            'name' => 'child:script',
            'steps' => [['id' => 'step1', 'run' => 'echo ok']],
        ], '/tmp/child.yaml');

        $this->scriptLoader
            ->shouldReceive('discover')
            ->andReturn(['child:script' => $childScript]);

        $capturedVars = null;
        $this->scriptExecutor
            ->shouldReceive('execute')
            ->withArgs(function ($script, $vars) use (&$capturedVars) {
                $capturedVars = $vars;

                return true;
            })
            ->andReturn(new FlowResult(true, []));

        $this->runner->execute(
            ['id' => 'test', 'script' => 'child:script'],
            ['PARENT_VAR' => 'parent_value', 'SHARED' => 'shared_value'],
            sys_get_temp_dir()
        );

        expect($capturedVars)->toHaveKey('PARENT_VAR', 'parent_value')
            ->and($capturedVars)->toHaveKey('SHARED', 'shared_value');
    });

    it('merges step-level variable overrides', function () {
        $childScript = ScriptDefinition::fromArray([
            'name' => 'child:script',
            'steps' => [['id' => 'step1', 'run' => 'echo ok']],
        ], '/tmp/child.yaml');

        $this->scriptLoader
            ->shouldReceive('discover')
            ->andReturn(['child:script' => $childScript]);

        $capturedVars = null;
        $this->scriptExecutor
            ->shouldReceive('execute')
            ->withArgs(function ($script, $vars) use (&$capturedVars) {
                $capturedVars = $vars;

                return true;
            })
            ->andReturn(new FlowResult(true, []));

        $this->runner->execute(
            [
                'id' => 'test',
                'script' => 'child:script',
                'variables' => ['OVERRIDE' => 'new_value', 'PARENT_VAR' => 'overridden'],
            ],
            ['PARENT_VAR' => 'original'],
            sys_get_temp_dir()
        );

        expect($capturedVars)->toHaveKey('OVERRIDE', 'new_value')
            ->and($capturedVars)->toHaveKey('PARENT_VAR', 'overridden');
    });

    it('detects circular script calls', function () {
        $scriptA = ScriptDefinition::fromArray([
            'name' => 'script:a',
            'steps' => [['id' => 'step1', 'run' => 'echo']],
        ], '/tmp/a.yaml');

        $this->scriptLoader
            ->shouldReceive('discover')
            ->andReturn(['script:a' => $scriptA]);

        $runner = $this->runner;
        $this->scriptExecutor
            ->shouldReceive('execute')
            ->andReturnUsing(function () use ($runner) {
                return new FlowResult(true, [
                    new StepResult('inner', $runner->execute(
                        ['id' => 'recurse', 'script' => 'script:a'],
                        [],
                        sys_get_temp_dir()
                    )->success),
                ]);
            });

        expect(fn () => $runner->execute(
            ['id' => 'outer', 'script' => 'script:a'],
            [],
            sys_get_temp_dir()
        ))->toThrow(RuntimeException::class, 'Circular script call detected: script:a -> script:a');
    });

    it('combines output from all sub-script step results', function () {
        $childScript = ScriptDefinition::fromArray([
            'name' => 'child:script',
            'steps' => [
                ['id' => 'step1', 'run' => 'echo hello'],
                ['id' => 'step2', 'run' => 'echo world'],
            ],
        ], '/tmp/child.yaml');

        $this->scriptLoader
            ->shouldReceive('discover')
            ->andReturn(['child:script' => $childScript]);

        $this->scriptExecutor
            ->shouldReceive('execute')
            ->andReturn(new FlowResult(true, [
                new StepResult('step1', true, "hello\n"),
                new StepResult('step2', true, "world\n"),
            ]));

        $result = $this->runner->execute(
            ['id' => 'test', 'script' => 'child:script'],
            [],
            sys_get_temp_dir()
        );

        expect($result->output)->toBe("hello\nworld\n");
    });

    it('returns failure when sub-script fails', function () {
        $childScript = ScriptDefinition::fromArray([
            'name' => 'child:script',
            'steps' => [['id' => 'step1', 'run' => 'exit 1']],
        ], '/tmp/child.yaml');

        $this->scriptLoader
            ->shouldReceive('discover')
            ->andReturn(['child:script' => $childScript]);

        $this->scriptExecutor
            ->shouldReceive('execute')
            ->andReturn(new FlowResult(false, [
                new StepResult('step1', false, '', 'command failed'),
            ]));

        $result = $this->runner->execute(
            ['id' => 'test', 'script' => 'child:script'],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeFalse()
            ->and($result->error)->toBe('Sub-script execution failed');
    });

    it('defaults step id to script-step', function () {
        $childScript = ScriptDefinition::fromArray([
            'name' => 'child:script',
            'steps' => [['id' => 'step1', 'run' => 'echo ok']],
        ], '/tmp/child.yaml');

        $this->scriptLoader
            ->shouldReceive('discover')
            ->andReturn(['child:script' => $childScript]);

        $this->scriptExecutor
            ->shouldReceive('execute')
            ->andReturn(new FlowResult(true, []));

        $result = $this->runner->execute(
            ['script' => 'child:script'],
            [],
            sys_get_temp_dir()
        );

        expect($result->id)->toBe('script-step');
    });

    it('pops call stack on failure so reuse is safe', function () {
        $childScript = ScriptDefinition::fromArray([
            'name' => 'child:script',
            'steps' => [['id' => 'step1', 'run' => 'exit 1']],
        ], '/tmp/child.yaml');

        $this->scriptLoader
            ->shouldReceive('discover')
            ->andReturn(['child:script' => $childScript]);

        $this->scriptExecutor
            ->shouldReceive('execute')
            ->twice()
            ->andReturn(
                new FlowResult(false, [new StepResult('step1', false)]),
                new FlowResult(true, [new StepResult('step1', true, 'ok')]),
            );

        $this->runner->execute(
            ['id' => 'first-call', 'script' => 'child:script'],
            [],
            sys_get_temp_dir()
        );

        $result = $this->runner->execute(
            ['id' => 'second-call', 'script' => 'child:script'],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue();
    });

    it('uses PROJECT_PATH variable for script discovery', function () {
        $childScript = ScriptDefinition::fromArray([
            'name' => 'child:script',
            'steps' => [['id' => 'step1', 'run' => 'echo ok']],
        ], '/tmp/child.yaml');

        $this->scriptLoader
            ->shouldReceive('discover')
            ->with('/custom/project')
            ->once()
            ->andReturn(['child:script' => $childScript]);

        $this->scriptExecutor
            ->shouldReceive('execute')
            ->andReturn(new FlowResult(true, []));

        $this->runner->execute(
            ['id' => 'test', 'script' => 'child:script'],
            ['PROJECT_PATH' => '/custom/project'],
            sys_get_temp_dir()
        );
    });
});

describe('resetCallStack', function () {
    it('clears the call stack', function () {
        $scriptA = ScriptDefinition::fromArray([
            'name' => 'script:a',
            'steps' => [['id' => 'step1', 'run' => 'echo']],
        ], '/tmp/a.yaml');

        $this->scriptLoader
            ->shouldReceive('discover')
            ->andReturn(['script:a' => $scriptA]);

        $runner = $this->runner;
        $callCount = 0;
        $this->scriptExecutor
            ->shouldReceive('execute')
            ->andReturnUsing(function () use ($runner, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    $runner->resetCallStack();
                }

                return new FlowResult(true, []);
            });

        $result = $runner->execute(
            ['id' => 'test', 'script' => 'script:a'],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue();
    });
});
