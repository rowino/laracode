<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Services\Settings\Dto\WatchSettings;

class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    private ?string $projectPath = null;

    public function __construct(
        private SettingsLoader $loader
    ) {}

    public function setProjectPath(string $path): void
    {
        $this->projectPath = $path;
        $this->cache = null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->all(), $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = $this->loader->loadAll($this->projectPath);
        }

        return $this->cache;
    }

    /**
     * @return array{default: array<string, mixed>, user: array<string, mixed>, project: array<string, mixed>, local: array<string, mixed>}
     */
    public function layers(): array
    {
        return $this->loader->loadLayers($this->projectPath);
    }

    public function watch(): WatchSettings
    {
        $watchData = $this->get('watch', []);

        return WatchSettings::fromArray(is_array($watchData) ? $watchData : []);
    }

    public function reload(): void
    {
        $this->cache = null;
    }
}
