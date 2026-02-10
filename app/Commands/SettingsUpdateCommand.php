<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Settings\SettingsService;
use App\Services\Settings\SettingsWriter;
use LaravelZero\Framework\Commands\Command;

/**
 * Usage: laracode settings:update --scope=local --key=watch.mode --value=yolo — update a setting in a specific scope.
 */
class SettingsUpdateCommand extends Command
{
    protected $signature = 'settings:update {--scope= : Scope to write to (user, project, local)} {--key= : Dot-notation key (e.g. watch.mode)} {--value= : Value to set (JSON auto-detected)}';

    protected $description = 'Update a setting in a specific scope';

    public function __construct(
        private SettingsService $settings,
        private SettingsWriter $writer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $cwd = getcwd();
        $this->settings->setProjectPath($cwd !== false ? $cwd : '.');

        $scope = $this->resolveScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        $key = $this->option('key') ?? $this->ask('Key (dot-notation)?');
        if (! is_string($key) || $key === '') {
            $this->error('Key is required.');

            return self::FAILURE;
        }

        $rawValue = $this->option('value') ?? $this->ask('Value?');
        if ($rawValue === null) {
            $this->error('Value is required.');

            return self::FAILURE;
        }

        $value = $this->parseValue((string) $rawValue);
        $data = [];
        data_set($data, $key, $value);

        $success = match ($scope) {
            'user' => $this->writer->mergeUser($data),
            'project' => $this->writer->mergeProject($data),
            'local' => $this->writer->mergeLocal($data),
            default => false,
        };

        if (! $success) {
            $this->error("Failed to write setting to {$scope} scope.");

            return self::FAILURE;
        }

        $this->settings->reload();
        $this->info("Set <comment>{$key}</comment> = <comment>{$this->formatDisplay($value)}</comment> in <comment>{$scope}</comment> scope.");

        return self::SUCCESS;
    }

    private function resolveScope(): ?string
    {
        $scope = $this->option('scope');
        if ($scope === null) {
            $scope = $this->choice('Scope?', ['user', 'project', 'local']);
        }

        $scope = is_string($scope) ? $scope : '';
        $valid = ['user', 'project', 'local'];
        if (! in_array($scope, $valid, true)) {
            $this->error("Invalid scope '{$scope}'. Must be one of: ".implode(', ', $valid));

            return null;
        }

        return $scope;
    }

    private function parseValue(string $raw): mixed
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $raw;
    }

    private function formatDisplay(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
