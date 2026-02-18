<?php

declare(strict_types=1);

namespace App\Agents;

use App\Services\Settings\SettingsService;
use InvalidArgumentException;

/**
 * Usage: Register and retrieve coding agent implementations.
 * Manages the collection of available agents and determines which one is default.
 */
class AgentRegistry
{
    /** @var array<string, AgentInterface> */
    private array $agents = [];

    private ?string $defaultName = null;

    public function __construct(
        private SettingsService $settings
    ) {}

    public function register(AgentInterface $agent): self
    {
        $this->agents[$agent->name()] = $agent;

        return $this;
    }

    public function get(string $name): AgentInterface
    {
        if (! isset($this->agents[$name])) {
            throw new InvalidArgumentException("Agent '{$name}' is not registered.");
        }

        return $this->agents[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->agents[$name]);
    }

    /**
     * @return array<string, AgentInterface>
     */
    public function all(): array
    {
        return $this->agents;
    }

    /**
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->agents);
    }

    public function getDefault(): AgentInterface
    {
        $name = $this->getDefaultName();

        return $this->get($name);
    }

    public function getDefaultName(): string
    {
        if ($this->defaultName !== null) {
            return $this->defaultName;
        }

        $settingsDefault = $this->settings->get('agents.default');

        if ($settingsDefault !== null && $this->has($settingsDefault)) {
            return $settingsDefault;
        }

        if (empty($this->agents)) {
            throw new InvalidArgumentException('No agents registered.');
        }

        return array_key_first($this->agents);
    }

    public function setDefault(string $name): self
    {
        if (! $this->has($name)) {
            throw new InvalidArgumentException("Agent '{$name}' is not registered.");
        }

        $this->defaultName = $name;

        return $this;
    }
}
