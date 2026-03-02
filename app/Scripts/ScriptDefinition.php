<?php

declare(strict_types=1);

namespace App\Scripts;

use InvalidArgumentException;

/**
 * Usage: $def = ScriptDefinition::fromArray($yamlData, '/path/to/script.yaml');
 */
readonly class ScriptDefinition
{
    /**
     * @param  array<string, array{description?: string, required?: bool}>  $arguments
     * @param  array<string, array{description?: string, value_required?: bool}>  $options
     * @param  array<string, string>  $variables
     * @param  list<array<string, mixed>>  $prompts
     * @param  list<array<string, mixed>>  $steps
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     */
    public function __construct(
        public string $name,
        public string $description,
        public int $version,
        public array $arguments,
        public array $options,
        public array $variables,
        public array $prompts,
        public array $steps,
        public array $before,
        public array $after,
        public bool $hidden,
        public string $sourcePath,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $sourcePath): self
    {
        if (! isset($data['name']) || ! is_string($data['name']) || $data['name'] === '') {
            throw new InvalidArgumentException("ScriptDefinition requires a non-empty 'name' field");
        }

        if (! isset($data['steps']) || ! is_array($data['steps']) || $data['steps'] === []) {
            throw new InvalidArgumentException("ScriptDefinition requires a non-empty 'steps' array");
        }

        $signature = $data['signature'] ?? [];

        return new self(
            name: $data['name'],
            description: (string) ($data['description'] ?? ''),
            version: (int) ($data['version'] ?? 1),
            arguments: (array) ($signature['arguments'] ?? []),
            options: (array) ($signature['options'] ?? []),
            variables: (array) ($data['variables'] ?? []),
            prompts: array_values((array) ($data['prompts'] ?? [])),
            steps: array_values((array) $data['steps']),
            before: array_values((array) ($data['before'] ?? [])),
            after: array_values((array) ($data['after'] ?? [])),
            hidden: (bool) ($data['hidden'] ?? false),
            sourcePath: $sourcePath,
        );
    }
}
