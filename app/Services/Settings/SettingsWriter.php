<?php

declare(strict_types=1);

namespace App\Services\Settings;

class SettingsWriter
{
    public function __construct(
        private SettingsLoader $loader
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public function writeUser(array $settings): bool
    {
        $path = SettingsPath::user();
        if ($path === '') {
            return false;
        }

        return $this->write($path, $settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function writeProject(array $settings, ?string $basePath = null): bool
    {
        return $this->write(SettingsPath::project($basePath), $settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function writeLocal(array $settings, ?string $basePath = null): bool
    {
        return $this->write(SettingsPath::local($basePath), $settings);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function mergeUser(array $data): bool
    {
        $existing = $this->loader->loadFile(SettingsPath::user());

        return $this->writeUser($this->loader->deepMerge($existing, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function mergeProject(array $data, ?string $basePath = null): bool
    {
        $existing = $this->loader->loadFile(SettingsPath::project($basePath));

        return $this->writeProject($this->loader->deepMerge($existing, $data), $basePath);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function mergeLocal(array $data, ?string $basePath = null): bool
    {
        $existing = $this->loader->loadFile(SettingsPath::local($basePath));

        return $this->writeLocal($this->loader->deepMerge($existing, $data), $basePath);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function write(string $path, array $settings): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            if (! mkdir($directory, 0755, true)) {
                return false;
            }
        }

        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $result = file_put_contents($path, $json."\n");

        return $result !== false;
    }
}
