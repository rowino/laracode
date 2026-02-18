<?php

declare(strict_types=1);

namespace App\Agents;

use App\Enums\BuildMode;

/**
 * Usage: Agent implementation for Claude Code - Anthropic's official CLI for Claude.
 */
class ClaudeAgent implements AgentInterface
{
    private const CONFIG_FOLDER = '.claude';

    private const COMMANDS_FOLDER = '.claude/commands';

    private const SKILLS_FOLDER = '.claude/skills';

    private const HOOKS_FOLDER = '.claude/hooks';

    private const SETTINGS_FILE = 'settings.local.json';

    public function name(): string
    {
        return 'claude';
    }

    public function executable(): string
    {
        return 'claude';
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

    public function installHook(string $file): void
    {
        $this->copyToFolder($file, self::HOOKS_FOLDER);
    }

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

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(string $scope, array $settings): void
    {
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
