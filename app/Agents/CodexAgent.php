<?php

declare(strict_types=1);

namespace App\Agents;

use App\Enums\BuildMode;

/**
 * Usage: Agent implementation for OpenAI Codex CLI - limited support without folder-based commands/skills.
 */
class CodexAgent implements AgentInterface
{
    private const string CONFIG_FOLDER = '.codex';

    private const string SETTINGS_FILE = 'config.toml';

    public function name(): string
    {
        return 'codex';
    }

    public function executable(): string
    {
        return 'codex';
    }

    public function buildCommand(BuildMode $mode): array
    {
        $command = [$this->executable()];

        return match ($mode) {
            BuildMode::Yolo => [...$command, '--full-auto'],
            default => $command,
        };
    }

    public function installConfig(string $file): void {}

    public function installCommand(string $file): void {}

    public function installSkill(string $file): void {}

    public function installHook(string $file): void {}

    /**
     * @return array<string, mixed>
     */
    public function getSettings(string $scope): array
    {
        $path = $this->getSettingsPath($scope);
        if ($path === '' || ! file_exists($path)) {
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
}
