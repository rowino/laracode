<?php

declare(strict_types=1);

use App\Scripts\ScriptDefinition;

describe('fromArray', function () {
    it('creates a ScriptDefinition from a full YAML array', function () {
        $data = [
            'name' => 'worktree:add',
            'description' => 'Create a new worktree',
            'version' => 2,
            'signature' => [
                'arguments' => [
                    'branch' => ['description' => 'Branch name', 'required' => false],
                ],
                'options' => [
                    'folder' => ['description' => 'Folder name', 'value_required' => true],
                    'skip-setup' => ['description' => 'Skip setup flows'],
                ],
            ],
            'variables' => [
                'BASE_PATH' => '{{settings.worktrees.basePath}}',
                'FOO' => 'bar',
            ],
            'prompts' => [
                ['id' => 'BRANCH_NAME', 'type' => 'text', 'label' => 'Branch name'],
            ],
            'steps' => [
                ['id' => 'create', 'run' => 'git worktree add {{BRANCH_NAME}}'],
                ['id' => 'setup', 'runner' => 'script', 'script' => 'worktree/shared-setup'],
            ],
            'before' => [
                ['run' => 'echo before'],
            ],
            'after' => [
                ['run' => 'echo after'],
            ],
            'hidden' => true,
        ];

        $def = ScriptDefinition::fromArray($data, '/path/to/script.yaml');

        expect($def)
            ->name->toBe('worktree:add')
            ->description->toBe('Create a new worktree')
            ->version->toBe(2)
            ->arguments->toBe(['branch' => ['description' => 'Branch name', 'required' => false]])
            ->options->toHaveCount(2)
            ->variables->toBe(['BASE_PATH' => '{{settings.worktrees.basePath}}', 'FOO' => 'bar'])
            ->prompts->toHaveCount(1)
            ->steps->toHaveCount(2)
            ->before->toHaveCount(1)
            ->after->toHaveCount(1)
            ->hidden->toBeTrue()
            ->sourcePath->toBe('/path/to/script.yaml');
    });

    it('uses sensible defaults for optional fields', function () {
        $data = [
            'name' => 'simple',
            'steps' => [['run' => 'echo hello']],
        ];

        $def = ScriptDefinition::fromArray($data, '/tmp/simple.yaml');

        expect($def)
            ->name->toBe('simple')
            ->description->toBe('')
            ->version->toBe(1)
            ->arguments->toBe([])
            ->options->toBe([])
            ->variables->toBe([])
            ->prompts->toBe([])
            ->steps->toHaveCount(1)
            ->before->toBe([])
            ->after->toBe([])
            ->hidden->toBeFalse()
            ->sourcePath->toBe('/tmp/simple.yaml');
    });

    it('throws when name is missing', function () {
        ScriptDefinition::fromArray(['steps' => [['run' => 'echo']]], '/tmp/test.yaml');
    })->throws(InvalidArgumentException::class, "ScriptDefinition requires a non-empty 'name' field");

    it('throws when name is empty string', function () {
        ScriptDefinition::fromArray(['name' => '', 'steps' => [['run' => 'echo']]], '/tmp/test.yaml');
    })->throws(InvalidArgumentException::class, "ScriptDefinition requires a non-empty 'name' field");

    it('throws when steps are missing', function () {
        ScriptDefinition::fromArray(['name' => 'test'], '/tmp/test.yaml');
    })->throws(InvalidArgumentException::class, "ScriptDefinition requires a non-empty 'steps' array");

    it('throws when steps array is empty', function () {
        ScriptDefinition::fromArray(['name' => 'test', 'steps' => []], '/tmp/test.yaml');
    })->throws(InvalidArgumentException::class, "ScriptDefinition requires a non-empty 'steps' array");
});

describe('immutability', function () {
    it('is readonly', function () {
        $ref = new ReflectionClass(ScriptDefinition::class);

        expect($ref->isReadOnly())->toBeTrue();
    });
});
