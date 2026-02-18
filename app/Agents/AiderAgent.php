<?php

declare(strict_types=1);

namespace App\Agents;

use App\Enums\BuildMode;

/**
 * Usage: Agent implementation for Aider - limited support without folder-based commands/skills.
 */
class AiderAgent implements AgentInterface
{
    private const SETTINGS_FILE = '.aider.conf.yml';

    public function name(): string
    {
        return 'aider';
    }

    public function executable(): string
    {
        return 'aider';
    }

    public function buildCommand(BuildMode $mode): array
    {
        $command = [$this->executable()];

        return match ($mode) {
            BuildMode::Yolo => [...$command, '--yes-always'],
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
        if ($scope === 'user') {
            $home = getenv('HOME');
            if ($home === false || $home === '') {
                return [];
            }

            $path = $home.'/'.self::SETTINGS_FILE;
        } else {
            $cwd = getcwd();
            $basePath = $cwd !== false ? $cwd : '.';
            $path = $basePath.'/'.self::SETTINGS_FILE;
        }

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
        $cwd = getcwd();
        $basePath = $cwd !== false ? $cwd : '.';

        return file_exists($basePath.'/'.self::SETTINGS_FILE);
    }
}
