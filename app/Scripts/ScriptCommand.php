<?php

declare(strict_types=1);

namespace App\Scripts;

use App\Services\Settings\SettingsService;
use LaravelZero\Framework\Commands\Command;

use function Termwind\render;
use function Termwind\renderUsing;

/**
 * Usage: new ScriptCommand($scriptDefinition, $scriptExecutor) — dynamic artisan command from YAML
 */
class ScriptCommand extends Command
{
    public function __construct(
        private readonly ScriptDefinition $script,
        private readonly ScriptExecutor $executor,
    ) {
        $this->signature = $this->buildSignature();
        $this->description = $this->script->description;

        parent::__construct();
    }

    public function handle(SettingsService $settingsService): int
    {
        $settingsService->setProjectPath(getcwd() ?: '.');

        renderUsing($this->output);

        $context = $this->buildContext();

        $this->executor->setOutputCallback(function (string $output, string $type): void {
            if ($type === 'stdout') {
                $this->output->write($output);

                return;
            }
            $safe = $this->escapeHtml($output);
            $class = match ($type) {
                'command' => 'text-cyan',
                'stderr' => 'text-red',
                default => 'text-gray',
            };
            render("<div class=\"{$class}\">{$safe}</div>");
        });

        $this->executor->setStepCallback(function (string $id, string $event, string $error): void {
            match ($event) {
                'start' => render('<div class="text-cyan">⧗ Running: '.$this->escapeHtml($id).'</div>'),
                'success' => render('<div class="text-green">✓ '.$this->escapeHtml($id).'</div>'),
                'failure' => render('<div class="text-red">✗ '.$this->escapeHtml($id).': '.$this->escapeHtml($error).'</div>'),
                'skip' => null,
                default => null,
            };
        });

        $result = $this->executor->execute($this->script, $context, $this);

        if (! $result->success) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    public function getScriptDefinition(): ScriptDefinition
    {
        return $this->script;
    }

    private function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function buildSignature(): string
    {
        $parts = [$this->script->name];

        foreach ($this->script->arguments as $name => $config) {
            $required = ($config['required'] ?? false) === true;
            $description = $config['description'] ?? '';
            $descPart = $description !== '' ? " : {$description}" : '';

            $parts[] = $required
                ? "{{$name}{$descPart}}"
                : "{{$name}?{$descPart}}";
        }

        foreach ($this->script->options as $name => $config) {
            $valueRequired = ($config['value_required'] ?? false) === true;
            $description = $config['description'] ?? '';
            $descPart = $description !== '' ? " : {$description}" : '';

            $parts[] = $valueRequired
                ? "{--{$name}={$descPart}}"
                : "{--{$name}{$descPart}}";
        }

        return implode("\n        ", $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(): array
    {
        $context = [];

        foreach ($this->script->arguments as $name => $config) {
            $value = $this->argument($name);
            if ($value !== null) {
                $context[$name] = $value;
            }
        }

        foreach ($this->script->options as $name => $config) {
            $value = $this->option($name);
            if ($value !== null && $value !== false) {
                $contextKey = str_replace('-', '_', $name);
                $context[$contextKey] = $value;
            }
        }

        return $context;
    }
}
