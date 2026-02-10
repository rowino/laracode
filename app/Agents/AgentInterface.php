<?php

declare(strict_types=1);

namespace App\Agents;

use App\Enums\BuildMode;

/**
 * Usage: Implement for each coding agent (Claude, OpenCode, etc.) to provide
 * self-managing agent configuration, installation, and command building.
 */
interface AgentInterface
{
    /**
     * Get the unique identifier name for this agent.
     */
    public function name(): string;

    /**
     * Get the executable name/path for this agent.
     */
    public function executable(): string;

    /**
     * Build the command array to invoke this agent with the specified mode.
     *
     * @return array<string> Command and arguments array suitable for Process execution
     */
    public function buildCommand(BuildMode $mode): array;

    /**
     * Install a configuration file to the agent's config location.
     */
    public function installConfig(string $file): void;

    /**
     * Install a command file to the agent's commands location.
     */
    public function installCommand(string $file): void;

    /**
     * Install a skill to the agent's skills location.
     */
    public function installSkill(string $file): void;

    /**
     * Install a hook to the agent's hooks location.
     */
    public function installHook(string $file): void;

    /**
     * Get settings for the specified scope.
     *
     * @param  string  $scope  'project' or 'user'
     * @return array<string, mixed>
     */
    public function getSettings(string $scope): array;

    /**
     * Update settings for the specified scope by merging with existing.
     *
     * @param  string  $scope  'project' or 'user'
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(string $scope, array $settings): void;

    /**
     * Check if this agent is used in the project based on presence of config folders.
     *
     * @param  array<string>  $folders  List of folder paths to check
     */
    public function isAgentUsed(array $folders): bool;
}
