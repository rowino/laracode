<?php

declare(strict_types=1);

use App\Scripts\Interpolator;
use App\Scripts\PromptRunner;

beforeEach(function () {
    $this->interpolator = new Interpolator;
    $this->runner = new PromptRunner($this->interpolator);
});

describe('normalizeOptions', function () {
    it('converts label/value objects to value => label format', function () {
        $options = [
            ['label' => 'Production', 'value' => 'prod'],
            ['label' => 'Development', 'value' => 'dev'],
        ];

        expect($this->runner->normalizeOptions($options))->toBe([
            'prod' => 'Production',
            'dev' => 'Development',
        ]);
    });

    it('handles plain strings as value => value', function () {
        expect($this->runner->normalizeOptions(['option1', 'option2', 'option3']))->toBe([
            'option1' => 'option1',
            'option2' => 'option2',
            'option3' => 'option3',
        ]);
    });

    it('handles mixed plain strings and label/value objects', function () {
        $options = [
            'plain_option',
            ['label' => 'Labeled Option', 'value' => 'labeled'],
            'another_plain',
        ];

        expect($this->runner->normalizeOptions($options))->toBe([
            'plain_option' => 'plain_option',
            'labeled' => 'Labeled Option',
            'another_plain' => 'another_plain',
        ]);
    });

    it('returns empty array for empty options', function () {
        expect($this->runner->normalizeOptions([]))->toBe([]);
    });
});

describe('autoMode', function () {
    beforeEach(function () {
        $this->runner->setAutoMode(true);
    });

    it('uses default for text prompt', function () {
        $prompts = [
            ['id' => 'db_name', 'type' => 'text', 'label' => 'Database name', 'default' => 'test_db'],
        ];

        expect($this->runner->runPrompts($prompts, []))
            ->toHaveKey('db_name')
            ->and($this->runner->runPrompts($prompts, [])['db_name'])->toBe('test_db');
    });

    it('uses default for confirm prompt', function () {
        $prompts = [
            ['id' => 'run_migrations', 'type' => 'confirm', 'label' => 'Run migrations?', 'default' => true],
        ];

        $result = $this->runner->runPrompts($prompts, []);

        expect($result['run_migrations'])->toBeTrue();
    });

    it('uses default for select prompt with label/value options', function () {
        $prompts = [
            [
                'id' => 'environment',
                'type' => 'select',
                'label' => 'Select environment',
                'default' => 'prod',
                'options' => [
                    ['label' => 'Production', 'value' => 'prod'],
                    ['label' => 'Development', 'value' => 'dev'],
                ],
            ],
        ];

        $result = $this->runner->runPrompts($prompts, []);

        expect($result['environment'])->toBe('prod');
    });

    it('uses default for select prompt with plain options', function () {
        $prompts = [
            [
                'id' => 'color',
                'type' => 'select',
                'label' => 'Select color',
                'default' => 'blue',
                'options' => ['red', 'blue', 'green'],
            ],
        ];

        $result = $this->runner->runPrompts($prompts, []);

        expect($result['color'])->toBe('blue');
    });

    it('uses default for multiselect prompt', function () {
        $prompts = [
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
        ];

        $result = $this->runner->runPrompts($prompts, []);

        expect($result['features'])->toBe(['auth', 'api']);
    });

    it('uses default for suggest prompt', function () {
        $prompts = [
            [
                'id' => 'branch',
                'type' => 'suggest',
                'label' => 'Branch name',
                'default' => 'main',
                'options' => ['main', 'develop', 'staging'],
            ],
        ];

        $result = $this->runner->runPrompts($prompts, []);

        expect($result['branch'])->toBe('main');
    });

    it('handles unknown prompt type with default', function () {
        $prompts = [
            ['id' => 'custom', 'type' => 'unknown_type', 'label' => 'Custom', 'default' => 'fallback'],
        ];

        $result = $this->runner->runPrompts($prompts, []);

        expect($result['custom'])->toBe('fallback');
    });

    it('handles multiple prompts in sequence', function () {
        $prompts = [
            ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'default' => 'John'],
            ['id' => 'env', 'type' => 'select', 'label' => 'Env', 'default' => 'dev', 'options' => ['dev', 'prod']],
            ['id' => 'migrate', 'type' => 'confirm', 'label' => 'Migrate?', 'default' => false],
        ];

        $result = $this->runner->runPrompts($prompts, []);

        expect($result)->toBe([
            'name' => 'John',
            'env' => 'dev',
            'migrate' => false,
        ]);
    });

    it('handles mixed formats in same flow', function () {
        $prompts = [
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
        ];

        $result = $this->runner->runPrompts($prompts, []);

        expect($result['env'])->toBe('dev')
            ->and($result['log_level'])->toBe('info');
    });

    it('returns empty array for empty prompts', function () {
        expect($this->runner->runPrompts([], []))->toBe([]);
    });
});

describe('label interpolation', function () {
    it('interpolates variables in prompt labels', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptText')
            ->once()
            ->withArgs(fn (string $label) => $label === 'Enter name for feature/auth')
            ->andReturn('my-value');

        $prompts = [
            ['id' => 'name', 'type' => 'text', 'label' => 'Enter name for {{BRANCH}}', 'required' => true],
        ];

        $result = $runner->runPrompts($prompts, ['BRANCH' => 'feature/auth']);

        expect($result['name'])->toBe('my-value');
    });

    it('keeps placeholder in label for missing variables', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptText')
            ->once()
            ->withArgs(fn (string $label) => $label === 'Enter name for {{BRANCH}}')
            ->andReturn('fallback');

        $prompts = [
            ['id' => 'name', 'type' => 'text', 'label' => 'Enter name for {{BRANCH}}', 'required' => true],
        ];

        $result = $runner->runPrompts($prompts, []);

        expect($result['name'])->toBe('fallback');
    });
});

describe('interactive mode prompt dispatch', function () {
    it('calls promptText for text type', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptText')
            ->once()
            ->with('Enter name', 'default_val', true)
            ->andReturn('user_input');

        $prompts = [
            ['id' => 'name', 'type' => 'text', 'label' => 'Enter name', 'default' => 'default_val', 'required' => true],
        ];

        $result = $runner->runPrompts($prompts, []);

        expect($result['name'])->toBe('user_input');
    });

    it('calls promptText with required false', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptText')
            ->once()
            ->with('Optional field', '', false)
            ->andReturn('');

        $prompts = [
            ['id' => 'optional', 'type' => 'text', 'label' => 'Optional field', 'required' => false],
        ];

        $result = $runner->runPrompts($prompts, []);

        expect($result['optional'])->toBe('');
    });

    it('defaults required to true when not specified', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptText')
            ->once()
            ->withArgs(fn ($label, $default, $required) => $required === true)
            ->andReturn('value');

        $prompts = [
            ['id' => 'field', 'type' => 'text', 'label' => 'Field'],
        ];

        $runner->runPrompts($prompts, []);
    });

    it('calls promptConfirm for confirm type', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptConfirm')
            ->once()
            ->with('Continue?', true)
            ->andReturn(false);

        $prompts = [
            ['id' => 'continue', 'type' => 'confirm', 'label' => 'Continue?', 'default' => true],
        ];

        $result = $runner->runPrompts($prompts, []);

        expect($result['continue'])->toBeFalse();
    });

    it('calls promptConfirm with false default when not specified', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptConfirm')
            ->once()
            ->withArgs(fn ($label, $default) => $default === false)
            ->andReturn(true);

        $prompts = [
            ['id' => 'confirm', 'type' => 'confirm', 'label' => 'Proceed?'],
        ];

        $runner->runPrompts($prompts, []);
    });

    it('calls promptSelect for select type', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $options = [
            ['label' => 'Production', 'value' => 'prod'],
            ['label' => 'Development', 'value' => 'dev'],
        ];

        $runner->shouldReceive('promptSelect')
            ->once()
            ->with('Select env', $options, 'prod')
            ->andReturn('dev');

        $prompts = [
            ['id' => 'env', 'type' => 'select', 'label' => 'Select env', 'default' => 'prod', 'options' => $options],
        ];

        $result = $runner->runPrompts($prompts, []);

        expect($result['env'])->toBe('dev');
    });

    it('calls promptMultiselect for multiselect type', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $options = ['auth', 'api', 'ws'];

        $runner->shouldReceive('promptMultiselect')
            ->once()
            ->with('Select features', $options, ['auth'])
            ->andReturn(['auth', 'ws']);

        $prompts = [
            ['id' => 'features', 'type' => 'multiselect', 'label' => 'Select features', 'default' => ['auth'], 'options' => $options],
        ];

        $result = $runner->runPrompts($prompts, []);

        expect($result['features'])->toBe(['auth', 'ws']);
    });

    it('calls promptSuggest for suggest type', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptSuggest')
            ->once()
            ->with('Branch name', ['main', 'develop'], '')
            ->andReturn('feature/new');

        $prompts = [
            ['id' => 'branch', 'type' => 'suggest', 'label' => 'Branch name', 'options' => ['main', 'develop']],
        ];

        $result = $runner->runPrompts($prompts, []);

        expect($result['branch'])->toBe('feature/new');
    });

    it('makes prompt responses available to subsequent prompts', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptText')
            ->once()
            ->withArgs(fn (string $label) => $label === 'Enter project name')
            ->andReturn('my-app');

        $runner->shouldReceive('promptText')
            ->once()
            ->withArgs(fn (string $label) => $label === 'DB name for my-app')
            ->andReturn('my_app_db');

        $prompts = [
            ['id' => 'PROJECT', 'type' => 'text', 'label' => 'Enter project name'],
            ['id' => 'DB_NAME', 'type' => 'text', 'label' => 'DB name for {{PROJECT}}'],
        ];

        $result = $runner->runPrompts($prompts, []);

        expect($result['PROJECT'])->toBe('my-app')
            ->and($result['DB_NAME'])->toBe('my_app_db');
    });
});

describe('setAutoMode', function () {
    it('allows method chaining', function () {
        expect($this->runner->setAutoMode(true))->toBeInstanceOf(PromptRunner::class);
    });

    it('can toggle autoMode off', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->setAutoMode(true);
        $runner->setAutoMode(false);

        $runner->shouldReceive('promptText')
            ->once()
            ->andReturn('interactive_value');

        $prompts = [
            ['id' => 'field', 'type' => 'text', 'label' => 'Field', 'default' => 'auto_default'],
        ];

        $result = $runner->runPrompts($prompts, []);

        expect($result['field'])->toBe('interactive_value');
    });
});

describe('edge cases', function () {
    it('handles non-string default for text prompt as empty string', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptText')
            ->once()
            ->withArgs(fn ($label, $default) => $default === '')
            ->andReturn('value');

        $prompts = [
            ['id' => 'field', 'type' => 'text', 'label' => 'Field', 'default' => 42],
        ];

        $runner->runPrompts($prompts, []);
    });

    it('handles non-array default for multiselect as empty array', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptMultiselect')
            ->once()
            ->withArgs(fn ($label, $options, $default) => $default === [])
            ->andReturn([]);

        $prompts = [
            ['id' => 'items', 'type' => 'multiselect', 'label' => 'Items', 'default' => 'not-an-array', 'options' => ['a', 'b']],
        ];

        $runner->runPrompts($prompts, []);
    });

    it('skips prompt in autoMode only when default is not null', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->setAutoMode(true);

        $runner->shouldReceive('promptText')
            ->once()
            ->andReturn('entered');

        $prompts = [
            ['id' => 'no_default', 'type' => 'text', 'label' => 'No default'],
            ['id' => 'with_default', 'type' => 'text', 'label' => 'With default', 'default' => 'auto'],
        ];

        $result = $runner->runPrompts($prompts, []);

        expect($result['no_default'])->toBe('entered')
            ->and($result['with_default'])->toBe('auto');
    });
});

describe('trimming', function () {
    it('trims whitespace from text prompt responses in interactive mode', function () {
        $runner = Mockery::mock(PromptRunner::class, [new Interpolator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $runner->shouldReceive('promptText')
            ->once()
            ->andReturn('  feature/auth  ');

        $prompts = [
            ['id' => 'branch', 'type' => 'text', 'label' => 'Branch name'],
        ];

        $result = $runner->runPrompts($prompts, []);

        expect($result['branch'])->toBe('feature/auth');
    });

    it('trims whitespace from text prompt defaults in auto mode', function () {
        $this->runner->setAutoMode(true);

        $prompts = [
            ['id' => 'branch', 'type' => 'text', 'label' => 'Branch name', 'default' => '  feature/auth  '],
        ];

        $result = $this->runner->runPrompts($prompts, []);

        expect($result['branch'])->toBe('feature/auth');
    });

    it('does not trim non-string values in auto mode', function () {
        $this->runner->setAutoMode(true);

        $prompts = [
            ['id' => 'migrate', 'type' => 'confirm', 'label' => 'Run migrations?', 'default' => true],
        ];

        $result = $this->runner->runPrompts($prompts, []);

        expect($result['migrate'])->toBeTrue();
    });
});
