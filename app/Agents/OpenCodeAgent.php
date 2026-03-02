<?php

declare(strict_types=1);

namespace App\Agents;

use App\Enums\BuildMode;

/**
 * Usage: Agent implementation for OpenCode - an open-source Claude Code alternative.
 */
class OpenCodeAgent implements AgentInterface
{
    private const string CONFIG_FOLDER = '.opencode';

    private const string COMMANDS_FOLDER = '.opencode/commands';

    private const string SKILLS_FOLDER = '.opencode/skills';

    private const string SETTINGS_FILE = 'opencode.json';

    public function name(): string
    {
        return 'opencode';
    }

    public function executable(): string
    {
        return 'opencode';
    }

    public function buildCommand(BuildMode $mode): array
    {
        $command = [$this->executable()];

        return match ($mode) {
            BuildMode::Yolo => [...$command, '--dangerously-skip-permissions'],
            BuildMode::Plan => [...$command, '--permission-mode', 'plan'],
            BuildMode::Accept => [...$command, '--permission-mode', 'acceptEdits'],
            BuildMode::Interactive => $command,
        };
    }

    public function installConfig(string $file): void
    {
        $this->copyToFolder($file, self::CONFIG_FOLDER);
    }

    public function installCommand(string $file): void
    {
        $this->copyToFolder($file, self::COMMANDS_FOLDER);
    }

    public function installSkill(string $file): void
    {
        $skillName = pathinfo(dirname($file), PATHINFO_FILENAME);
        if ($skillName === '' || $skillName === '.') {
            $skillName = pathinfo($file, PATHINFO_FILENAME);
        }

        $targetDir = self::SKILLS_FOLDER.'/'.$skillName;
        $this->copyToFolder($file, $targetDir);
    }

    public function installHook(string $file): void {}

    /**
     * @return array<string, mixed>
     */
    public function getSettings(string $scope): array
    {
        if ($scope === 'project') {
            $this->migrateLegacySettings();
        }

        $path = $this->getSettingsPath($scope);
        if ($path === '' || ! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(string $scope, array $settings): void
    {
        if ($scope === 'project') {
            $this->migrateLegacySettings();
        }

        $path = $this->getSettingsPath($scope);
        if ($path === '') {
            return;
        }

        $existing = $this->getSettings($scope);
        $merged = array_replace_recursive($existing, $settings);

        $this->ensureDirectory(dirname($path));

        file_put_contents(
            $path,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    public function isAgentUsed(array $folders): bool
    {
        return in_array(self::CONFIG_FOLDER, $folders, true);
    }

    /**
     * Migrates legacy settings from ./opencode.json to ./.opencode/opencode.json
     *
     * This method handles backward compatibility by copying settings from the legacy
     * root-level location to the new directory-based location. The legacy file is
     * kept after migration for safety. Migration only occurs if the legacy file exists
     * and the new location does not already exist.
     */
    private function migrateLegacySettings(): void
    {
        $cwd = getcwd();
        $basePath = $cwd !== false ? $cwd : '.';

        $legacyPath = $basePath.'/'.self::SETTINGS_FILE;
        $newPath = $basePath.'/'.self::CONFIG_FOLDER.'/'.self::SETTINGS_FILE;

        if (! file_exists($legacyPath) || file_exists($newPath)) {
            return;
        }

        $this->ensureDirectory(dirname($newPath));

        $content = file_get_contents($legacyPath);
        if ($content !== false) {
            file_put_contents($newPath, $content);
        }
    }

    private function getSettingsPath(string $scope): string
    {
        if ($scope === 'user') {
            $home = getenv('HOME');
            if ($home === false || $home === '') {
                return '';
            }

            return $home.'/'.self::CONFIG_FOLDER.'/'.self::SETTINGS_FILE;
        }

        $cwd = getcwd();
        $basePath = $cwd !== false ? $cwd : '.';

        return $basePath.'/'.self::CONFIG_FOLDER.'/'.self::SETTINGS_FILE;
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
