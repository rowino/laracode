<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Settings\SettingsService;
use LaravelZero\Framework\Commands\Command;

/**
 * Usage: laracode settings:show [key] — display all settings or inspect a single key across layers.
 */
class SettingsShowCommand extends Command
{
    protected $signature = 'settings:show {key? : Dot-notation key to inspect (e.g. watch.mode)}';

    protected $description = 'Display settings with source layer info';

    public function __construct(
        private SettingsService $settings
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $cwd = getcwd();
        $this->settings->setProjectPath($cwd !== false ? $cwd : '.');

        /** @var string|null $key */
        $key = $this->argument('key');

        if ($key !== null) {
            return $this->showKey($key);
        }

        return $this->showAll();
    }

    private function showAll(): int
    {
        $merged = $this->settings->all();
        $layers = $this->settings->layers();

        $flat = $this->flatten($merged);

        if (empty($flat)) {
            $this->warn('No settings found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($flat as $key => $value) {
            $rows[] = [$key, $this->formatValue($value), $this->resolveSource($key, $layers)];
        }

        $this->table(['Key', 'Value', 'Source'], $rows);

        return self::SUCCESS;
    }

    private function showKey(string $key): int
    {
        $layers = $this->settings->layers();
        $activeValue = $this->settings->get($key);

        if ($activeValue === null) {
            $allNull = true;
            foreach ($layers as $layerData) {
                if (data_get($layerData, $key) !== null) {
                    $allNull = false;
                    break;
                }
            }
            if ($allNull) {
                $this->warn("Key '{$key}' is not set in any layer.");

                return self::FAILURE;
            }
        }

        $activeSource = $this->resolveSource($key, $layers);

        $rows = [];
        foreach ($layers as $layerName => $layerData) {
            $layerValue = data_get($layerData, $key);
            $isActive = $layerName === $activeSource;

            $displayValue = $layerValue !== null ? $this->formatValue($layerValue) : "\u{2014}";
            $displayLayer = $isActive ? "<bg=green;fg=white> {$layerName} </>" : $layerName;
            $displayValue = $isActive ? "<bg=green;fg=white> {$displayValue} </>" : $displayValue;

            $rows[] = [$displayLayer, $displayValue];
        }

        $this->info("Key: {$key}");
        $this->table(['Layer', 'Value'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function flatten(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $dotKey = $prefix !== '' ? "{$prefix}.{$key}" : $key;

            if (is_array($value) && ! array_is_list($value)) {
                $result = array_merge($result, $this->flatten($value, $dotKey));
            } else {
                $result[$dotKey] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array{default: array<string, mixed>, user: array<string, mixed>, project: array<string, mixed>, local: array<string, mixed>}  $layers
     */
    private function resolveSource(string $key, array $layers): string
    {
        $order = ['local', 'project', 'user', 'default'];

        foreach ($order as $layerName) {
            if (data_get($layers[$layerName], $key) !== null) {
                return $layerName;
            }
        }

        return 'default';
    }

    private function formatValue(mixed $value): string
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
