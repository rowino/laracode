<?php

declare(strict_types=1);

use App\Services\FlowExecutor;
use App\Services\FlowResult;
use App\Services\StepResult;

beforeEach(function () {
    $this->executor = new FlowExecutor;
    $this->tempDir = sys_get_temp_dir().'/flow_executor_test_'.uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        $files = glob($this->tempDir.'/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        rmdir($this->tempDir);
    }
});

describe('interpolate', function () {
    it('replaces {{VAR}} with values', function () {
        $result = $this->executor->interpolate('Hello {{NAME}}!', ['NAME' => 'World']);

        expect($result)->toBe('Hello World!');
    });

    it('replaces multiple variables', function () {
        $result = $this->executor->interpolate(
            '{{GREETING}} {{NAME}}, welcome to {{PLACE}}',
            ['GREETING' => 'Hello', 'NAME' => 'John', 'PLACE' => 'Paris']
        );

        expect($result)->toBe('Hello John, welcome to Paris');
    });

    it('handles missing variables by keeping placeholder', function () {
        $result = $this->executor->interpolate('Hello {{NAME}}!', []);

        expect($result)->toBe('Hello {{NAME}}!');
    });

    it('handles partial missing variables', function () {
        $result = $this->executor->interpolate(
            '{{GREETING}} {{NAME}}!',
            ['GREETING' => 'Hello']
        );

        expect($result)->toBe('Hello {{NAME}}!');
    });

    it('converts non-string values to strings', function () {
        $result = $this->executor->interpolate(
            'Count: {{NUM}}, Active: {{BOOL}}',
            ['NUM' => 42, 'BOOL' => true]
        );

        expect($result)->toBe('Count: 42, Active: 1');
    });

    it('handles arrays as empty strings', function () {
        $result = $this->executor->interpolate(
            'Value: {{ARR}}',
            ['ARR' => ['a', 'b']]
        );

        expect($result)->toBe('Value: ');
    });

    it('applies filter to variable', function () {
        $result = $this->executor->interpolate(
            '{{NAME|upper}}',
            ['NAME' => 'hello']
        );

        expect($result)->toBe('HELLO');
    });
});

describe('applyFilter', function () {
    it('converts to snake_case with snake filter', function () {
        $result = $this->executor->applyFilter('featureAuth', 'snake');

        expect($result)->toBe('feature_auth');
    });

    it('handles spaces and special chars in snake filter', function () {
        $result = $this->executor->applyFilter('Feature Auth Module', 'snake');

        expect($result)->toBe('feature_auth_module');
    });

    it('handles already snake_case in snake filter', function () {
        $result = $this->executor->applyFilter('already_snake_case', 'snake');

        expect($result)->toBe('already_snake_case');
    });

    it('converts to slug with slug filter', function () {
        $result = $this->executor->applyFilter('Feature Auth Module', 'slug');

        expect($result)->toBe('feature-auth-module');
    });

    it('handles special characters in slug filter', function () {
        $result = $this->executor->applyFilter('Feature/Auth!Module@Test', 'slug');

        expect($result)->toBe('feature-auth-module-test');
    });

    it('converts to uppercase with upper filter', function () {
        $result = $this->executor->applyFilter('hello world', 'upper');

        expect($result)->toBe('HELLO WORLD');
    });

    it('converts to lowercase with lower filter', function () {
        $result = $this->executor->applyFilter('HELLO WORLD', 'lower');

        expect($result)->toBe('hello world');
    });

    it('returns unchanged value for unknown filter', function () {
        $result = $this->executor->applyFilter('hello', 'unknown');

        expect($result)->toBe('hello');
    });
});

describe('evaluateCondition', function () {
    it('returns true for equals comparison with matching values', function () {
        $result = $this->executor->evaluateCondition(
            '{{TYPE}} == production',
            ['TYPE' => 'production']
        );

        expect($result)->toBeTrue();
    });

    it('returns false for equals comparison with non-matching values', function () {
        $result = $this->executor->evaluateCondition(
            '{{TYPE}} == production',
            ['TYPE' => 'development']
        );

        expect($result)->toBeFalse();
    });

    it('returns true for not-equals comparison with different values', function () {
        $result = $this->executor->evaluateCondition(
            '{{TYPE}} != production',
            ['TYPE' => 'development']
        );

        expect($result)->toBeTrue();
    });

    it('returns false for not-equals comparison with matching values', function () {
        $result = $this->executor->evaluateCondition(
            '{{TYPE}} != production',
            ['TYPE' => 'production']
        );

        expect($result)->toBeFalse();
    });

    it('handles quoted strings in comparison', function () {
        $result = $this->executor->evaluateCondition(
            '"{{TYPE}}" == "production"',
            ['TYPE' => 'production']
        );

        expect($result)->toBeTrue();
    });

    it('handles boolean true value', function () {
        $result = $this->executor->evaluateCondition('true', []);

        expect($result)->toBeTrue();
    });

    it('handles boolean false value', function () {
        $result = $this->executor->evaluateCondition('false', []);

        expect($result)->toBeFalse();
    });

    it('handles numeric 1 as true', function () {
        $result = $this->executor->evaluateCondition('1', []);

        expect($result)->toBeTrue();
    });

    it('handles numeric 0 as false', function () {
        $result = $this->executor->evaluateCondition('0', []);

        expect($result)->toBeFalse();
    });

    it('handles empty string as false', function () {
        $result = $this->executor->evaluateCondition('', []);

        expect($result)->toBeFalse();
    });

    it('handles variable reference in boolean context', function () {
        $result = $this->executor->evaluateCondition('ENABLED', ['ENABLED' => true]);

        expect($result)->toBeTrue();
    });

    it('handles interpolated variable as boolean', function () {
        $result = $this->executor->evaluateCondition('{{ENABLED}}', ['ENABLED' => true]);

        expect($result)->toBeTrue();
    });
});

describe('runSteps', function () {
    it('executes commands in order', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $steps = [
            ['id' => 'step1', 'command' => 'echo "first" > first.txt'],
            ['id' => 'step2', 'command' => 'echo "second" > second.txt'],
        ];

        $results = $this->executor->runSteps($steps, []);

        expect($results)->toHaveCount(2)
            ->and($results[0]->id)->toBe('step1')
            ->and($results[0]->success)->toBeTrue()
            ->and($results[1]->id)->toBe('step2')
            ->and($results[1]->success)->toBeTrue()
            ->and(file_exists($this->tempDir.'/first.txt'))->toBeTrue()
            ->and(file_exists($this->tempDir.'/second.txt'))->toBeTrue();
    });

    it('assigns default id when not provided', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $steps = [
            ['command' => 'echo "test"'],
        ];

        $results = $this->executor->runSteps($steps, []);

        expect($results[0]->id)->toBe('step');
    });

    it('skips steps with false conditions', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $steps = [
            ['id' => 'always', 'command' => 'echo "always" > always.txt'],
            ['id' => 'skipped', 'command' => 'echo "skipped" > skipped.txt', 'condition' => 'false'],
            ['id' => 'conditional', 'command' => 'echo "conditional" > conditional.txt', 'condition' => 'true'],
        ];

        $results = $this->executor->runSteps($steps, []);

        expect($results)->toHaveCount(3)
            ->and($results[0]->success)->toBeTrue()
            ->and($results[0]->skipped)->toBeFalse()
            ->and($results[1]->success)->toBeTrue()
            ->and($results[1]->skipped)->toBeTrue()
            ->and($results[2]->success)->toBeTrue()
            ->and($results[2]->skipped)->toBeFalse()
            ->and(file_exists($this->tempDir.'/always.txt'))->toBeTrue()
            ->and(file_exists($this->tempDir.'/skipped.txt'))->toBeFalse()
            ->and(file_exists($this->tempDir.'/conditional.txt'))->toBeTrue();
    });

    it('evaluates conditions with variables', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $steps = [
            ['id' => 'prod', 'command' => 'echo "prod" > prod.txt', 'condition' => '{{ENV}} == production'],
            ['id' => 'dev', 'command' => 'echo "dev" > dev.txt', 'condition' => '{{ENV}} == development'],
        ];

        $results = $this->executor->runSteps($steps, ['ENV' => 'production']);

        expect($results[0]->skipped)->toBeFalse()
            ->and($results[1]->skipped)->toBeTrue()
            ->and(file_exists($this->tempDir.'/prod.txt'))->toBeTrue()
            ->and(file_exists($this->tempDir.'/dev.txt'))->toBeFalse();
    });

    it('interpolates variables in commands', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $steps = [
            ['id' => 'create', 'command' => 'echo "{{MESSAGE}}" > output.txt'],
        ];

        $results = $this->executor->runSteps($steps, ['MESSAGE' => 'Hello World']);

        expect($results[0]->success)->toBeTrue();

        $content = trim(file_get_contents($this->tempDir.'/output.txt'));
        expect($content)->toBe('Hello World');
    });

    it('uses WORKTREE_PATH variable as working directory', function () {
        $altDir = sys_get_temp_dir().'/flow_executor_alt_'.uniqid();
        mkdir($altDir, 0755, true);

        $steps = [
            ['id' => 'create', 'command' => 'echo "test" > worktree.txt'],
        ];

        $results = $this->executor->runSteps($steps, ['WORKTREE_PATH' => $altDir]);

        expect($results[0]->success)->toBeTrue()
            ->and(file_exists($altDir.'/worktree.txt'))->toBeTrue();

        unlink($altDir.'/worktree.txt');
        rmdir($altDir);
    });

    it('captures command output', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $steps = [
            ['id' => 'output', 'command' => 'echo "captured output"'],
        ];

        $results = $this->executor->runSteps($steps, []);

        expect($results[0]->output)->toContain('captured output');
    });

    it('captures command errors', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $steps = [
            ['id' => 'error', 'command' => 'ls /nonexistent_directory_12345'],
        ];

        $results = $this->executor->runSteps($steps, []);

        expect($results[0]->success)->toBeFalse()
            ->and($results[0]->error)->not->toBeEmpty();
    });
});

describe('execute', function () {
    it('returns FlowResult with success when all steps pass', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $flow = [
            'id' => 'test-flow',
            'steps' => [
                ['id' => 'step1', 'command' => 'echo "test"'],
            ],
        ];

        $result = $this->executor->execute($flow);

        expect($result)->toBeInstanceOf(FlowResult::class)
            ->and($result->success)->toBeTrue()
            ->and($result->stepResults)->toHaveCount(1)
            ->and($result->stepResults[0]->success)->toBeTrue();
    });

    it('returns FlowResult with failure when step fails', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $flow = [
            'id' => 'test-flow',
            'steps' => [
                ['id' => 'fail', 'command' => 'exit 1'],
            ],
        ];

        $result = $this->executor->execute($flow);

        expect($result->success)->toBeFalse()
            ->and($result->stepResults[0]->success)->toBeFalse();
    });

    it('considers skipped steps as success', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $flow = [
            'id' => 'test-flow',
            'steps' => [
                ['id' => 'skipped', 'command' => 'exit 1', 'condition' => 'false'],
            ],
        ];

        $result = $this->executor->execute($flow);

        expect($result->success)->toBeTrue()
            ->and($result->stepResults[0]->skipped)->toBeTrue();
    });

    it('passes context variables to steps', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $flow = [
            'steps' => [
                ['id' => 'create', 'command' => 'echo "{{BRANCH}}" > branch.txt'],
            ],
        ];

        $result = $this->executor->execute($flow, ['BRANCH' => 'feature/auth']);

        expect($result->success)->toBeTrue();

        $content = trim(file_get_contents($this->tempDir.'/branch.txt'));
        expect($content)->toBe('feature/auth');
    });
});

describe('autoMode', function () {
    it('uses defaults when autoMode is enabled', function () {
        $this->executor->setAutoMode(true);

        $flow = [
            'prompts' => [
                ['id' => 'db_name', 'type' => 'text', 'label' => 'Database name', 'default' => 'test_db'],
            ],
            'steps' => [],
        ];

        $result = $this->executor->execute($flow);

        expect($result->promptResponses)->toHaveKey('db_name')
            ->and($result->promptResponses['db_name'])->toBe('test_db');
    });
});

describe('StepResult', function () {
    it('has correct structure', function () {
        $result = new StepResult('test-step', true, 'output text', 'error text', false);

        expect($result->id)->toBe('test-step')
            ->and($result->success)->toBeTrue()
            ->and($result->output)->toBe('output text')
            ->and($result->error)->toBe('error text')
            ->and($result->skipped)->toBeFalse();
    });

    it('converts to array correctly', function () {
        $result = new StepResult('test-step', true, 'output', 'error', true);
        $array = $result->toArray();

        expect($array)->toBe([
            'id' => 'test-step',
            'success' => true,
            'output' => 'output',
            'error' => 'error',
            'skipped' => true,
        ]);
    });
});

describe('FlowResult', function () {
    it('has correct structure', function () {
        $stepResults = [new StepResult('step1', true)];
        $promptResponses = ['db_name' => 'test'];

        $result = new FlowResult(true, $stepResults, $promptResponses);

        expect($result->success)->toBeTrue()
            ->and($result->stepResults)->toBe($stepResults)
            ->and($result->promptResponses)->toBe($promptResponses);
    });

    it('converts to array correctly', function () {
        $stepResults = [new StepResult('step1', true, 'out', 'err', false)];
        $promptResponses = ['db_name' => 'test'];

        $result = new FlowResult(true, $stepResults, $promptResponses);
        $array = $result->toArray();

        expect($array['success'])->toBeTrue()
            ->and($array['stepResults'])->toHaveCount(1)
            ->and($array['stepResults'][0]['id'])->toBe('step1')
            ->and($array['promptResponses'])->toBe(['db_name' => 'test']);
    });
});

describe('setWorkingDirectory', function () {
    it('allows method chaining', function () {
        $result = $this->executor->setWorkingDirectory('/tmp');

        expect($result)->toBeInstanceOf(FlowExecutor::class);
    });

    it('trims trailing slashes', function () {
        $this->executor->setWorkingDirectory($this->tempDir.'/');

        $steps = [
            ['id' => 'create', 'command' => 'echo "test" > trimtest.txt'],
        ];

        $results = $this->executor->runSteps($steps, []);

        expect($results[0]->success)->toBeTrue()
            ->and(file_exists($this->tempDir.'/trimtest.txt'))->toBeTrue();
    });
});

describe('setAutoMode', function () {
    it('allows method chaining', function () {
        $result = $this->executor->setAutoMode(true);

        expect($result)->toBeInstanceOf(FlowExecutor::class);
    });
});

describe('label/value options in autoMode', function () {
    it('uses default value with label/value select options', function () {
        $executor = new FlowExecutor;
        $executor->setAutoMode(true);

        $flow = [
            'prompts' => [
                [
                    'id' => 'environment',
                    'type' => 'select',
                    'label' => 'Select environment',
                    'default' => 'prod',
                    'options' => [
                        ['label' => 'Production', 'value' => 'prod'],
                        ['label' => 'Development', 'value' => 'dev'],
                        ['label' => 'Staging', 'value' => 'staging'],
                    ],
                ],
            ],
            'steps' => [],
        ];

        $result = $executor->execute($flow);

        expect($result->promptResponses)->toHaveKey('environment')
            ->and($result->promptResponses['environment'])->toBe('prod');
    });

    it('uses default values with label/value multiselect options', function () {
        $executor = new FlowExecutor;
        $executor->setAutoMode(true);

        $flow = [
            'prompts' => [
                [
                    'id' => 'features',
                    'type' => 'multiselect',
                    'label' => 'Select features',
                    'default' => ['auth', 'api'],
                    'options' => [
                        ['label' => 'Authentication', 'value' => 'auth'],
                        ['label' => 'API Support', 'value' => 'api'],
                        ['label' => 'WebSockets', 'value' => 'ws'],
                    ],
                ],
            ],
            'steps' => [],
        ];

        $result = $executor->execute($flow);

        expect($result->promptResponses)->toHaveKey('features')
            ->and($result->promptResponses['features'])->toBe(['auth', 'api']);
    });

    it('uses default with plain string select options (backwards compatible)', function () {
        $executor = new FlowExecutor;
        $executor->setAutoMode(true);

        $flow = [
            'prompts' => [
                [
                    'id' => 'color',
                    'type' => 'select',
                    'label' => 'Select color',
                    'default' => 'blue',
                    'options' => ['red', 'blue', 'green'],
                ],
            ],
            'steps' => [],
        ];

        $result = $executor->execute($flow);

        expect($result->promptResponses)->toHaveKey('color')
            ->and($result->promptResponses['color'])->toBe('blue');
    });

    it('uses defaults with plain string multiselect options (backwards compatible)', function () {
        $executor = new FlowExecutor;
        $executor->setAutoMode(true);

        $flow = [
            'prompts' => [
                [
                    'id' => 'items',
                    'type' => 'multiselect',
                    'label' => 'Select items',
                    'default' => ['a', 'c'],
                    'options' => ['a', 'b', 'c', 'd'],
                ],
            ],
            'steps' => [],
        ];

        $result = $executor->execute($flow);

        expect($result->promptResponses)->toHaveKey('items')
            ->and($result->promptResponses['items'])->toBe(['a', 'c']);
    });

    it('handles mixed formats in same flow', function () {
        $executor = new FlowExecutor;
        $executor->setAutoMode(true);

        $flow = [
            'prompts' => [
                [
                    'id' => 'env',
                    'type' => 'select',
                    'label' => 'Environment (label/value)',
                    'default' => 'dev',
                    'options' => [
                        ['label' => 'Development', 'value' => 'dev'],
                        ['label' => 'Production', 'value' => 'prod'],
                    ],
                ],
                [
                    'id' => 'log_level',
                    'type' => 'select',
                    'label' => 'Log level (plain)',
                    'default' => 'info',
                    'options' => ['debug', 'info', 'warning', 'error'],
                ],
            ],
            'steps' => [],
        ];

        $result = $executor->execute($flow);

        expect($result->promptResponses)->toHaveKey('env')
            ->and($result->promptResponses['env'])->toBe('dev')
            ->and($result->promptResponses)->toHaveKey('log_level')
            ->and($result->promptResponses['log_level'])->toBe('info');
    });
});

describe('setOutputCallback', function () {
    it('allows method chaining', function () {
        $result = $this->executor->setOutputCallback(function (string $output, string $type): void {});

        expect($result)->toBeInstanceOf(FlowExecutor::class);
    });

    it('receives stdout output when step succeeds', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $capturedOutput = [];
        $this->executor->setOutputCallback(function (string $output, string $type) use (&$capturedOutput): void {
            $capturedOutput[] = ['output' => $output, 'type' => $type];
        });

        $steps = [
            ['id' => 'echo', 'command' => 'echo "hello callback"'],
        ];

        $this->executor->runSteps($steps, []);

        $stdoutCaptures = array_filter($capturedOutput, fn ($c) => $c['type'] === 'stdout');
        expect($stdoutCaptures)->toHaveCount(1)
            ->and(array_values($stdoutCaptures)[0]['output'])->toContain('hello callback');
    });

    it('receives stderr output when step fails', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $capturedOutput = [];
        $this->executor->setOutputCallback(function (string $output, string $type) use (&$capturedOutput): void {
            $capturedOutput[] = ['output' => $output, 'type' => $type];
        });

        $steps = [
            ['id' => 'fail', 'command' => 'ls /nonexistent_directory_callback_test_12345'],
        ];

        $this->executor->runSteps($steps, []);

        $stderrCaptures = array_filter($capturedOutput, fn ($c) => $c['type'] === 'stderr');
        expect($stderrCaptures)->not->toBeEmpty();
    });

    it('does not call callback for empty stdout/stderr output', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $stdoutStderrCount = 0;
        $this->executor->setOutputCallback(function (string $output, string $type) use (&$stdoutStderrCount): void {
            if ($type === 'stdout' || $type === 'stderr') {
                $stdoutStderrCount++;
            }
        });

        $steps = [
            ['id' => 'silent', 'command' => 'true'],
        ];

        $this->executor->runSteps($steps, []);

        expect($stdoutStderrCount)->toBe(0);
    });

    it('calls callback for each step with output', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $capturedOutput = [];
        $this->executor->setOutputCallback(function (string $output, string $type) use (&$capturedOutput): void {
            $capturedOutput[] = ['output' => trim($output), 'type' => $type];
        });

        $steps = [
            ['id' => 'step1', 'command' => 'echo "first"'],
            ['id' => 'step2', 'command' => 'echo "second"'],
        ];

        $this->executor->runSteps($steps, []);

        $stdoutCaptures = array_filter($capturedOutput, fn ($c) => $c['type'] === 'stdout');
        expect(array_values($stdoutCaptures))->toHaveCount(2)
            ->and(array_values($stdoutCaptures)[0]['output'])->toBe('first')
            ->and(array_values($stdoutCaptures)[1]['output'])->toBe('second');
    });

    it('calls callback with command type before execution', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $capturedOutput = [];
        $this->executor->setOutputCallback(function (string $output, string $type) use (&$capturedOutput): void {
            $capturedOutput[] = ['output' => $output, 'type' => $type];
        });

        $steps = [
            ['id' => 'test', 'command' => 'echo "hello"'],
        ];

        $this->executor->runSteps($steps, []);

        $commandCaptures = array_filter($capturedOutput, fn ($c) => $c['type'] === 'command');
        expect(array_values($commandCaptures))->toHaveCount(1)
            ->and(array_values($commandCaptures)[0]['output'])->toContain('→')
            ->and(array_values($commandCaptures)[0]['output'])->toContain('echo "hello"');
    });

    it('interpolates variables in command callback', function () {
        $this->executor->setWorkingDirectory($this->tempDir);

        $capturedOutput = [];
        $this->executor->setOutputCallback(function (string $output, string $type) use (&$capturedOutput): void {
            $capturedOutput[] = ['output' => $output, 'type' => $type];
        });

        $steps = [
            ['id' => 'test', 'command' => 'echo "{{NAME}}"'],
        ];

        $this->executor->runSteps($steps, ['NAME' => 'World']);

        $commandCaptures = array_filter($capturedOutput, fn ($c) => $c['type'] === 'command');
        expect(array_values($commandCaptures))->toHaveCount(1)
            ->and(array_values($commandCaptures)[0]['output'])->toContain('echo "World"')
            ->and(array_values($commandCaptures)[0]['output'])->not->toContain('{{NAME}}');
    });
});
