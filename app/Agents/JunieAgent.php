<?php

declare(strict_types=1);

namespace App\Agents;

use App\Enums\BuildMode;

/**
 * Usage: Agent implementation for JetBrains Junie - IDE-integrated with limited CLI support.
 */
class JunieAgent implements AgentInterface
{
    private const string CONFIG_FOLDER = '.junie';

    private const string SETTINGS_FILE = 'guidelines.md';

    public function name(): string
    {
        return 'junie';
    }

    public function executable(): string
    {
        return 'junie';
    }

    public function buildCommand(BuildMode $mode): array
    {
        return [$this->executable()];
    }

    public function installConfig(string $file): void
    {
        $this->copyToFolder($file, self::CONFIG_FOLDER);
    }

    public function installCommand(string $file): void
    {
        $this->copyToFolder($file, self::CONFIG_FOLDER);
    }

    public function installSkill(string $file): void {}

    public function installHook(string $file): void {}

    /**
     * @return array<string, mixed>
     */
    public function getSettings(string $scope): array
    {
        if ($scope === 'user') {
            return [];
        }

        $cwd = getcwd();
        $basePath = $cwd !== false ? $cwd : '.';
        $path = $basePath.'/'.self::CONFIG_FOLDER.'/'.self::SETTINGS_FILE;

        if (! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        return ['raw' => $content];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(string $scope, array $settings): void {}

    public function isAgentUsed(array $folders): bool
    {
        return in_array(self::CONFIG_FOLDER, $folders, true);
    }

    private function copyToFolder(string $file, string $targetFolder): void
    {
        $cwd = getcwd();
        $basePath = $cwd !== false ? $cwd : '.';
        $targetDir = $basePath.'/'.$targetFolder;

        $this->ensureDirectory($targetDir);

        $destination = $targetDir.'/'.basename($file);
        copy($file, $destination);
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
