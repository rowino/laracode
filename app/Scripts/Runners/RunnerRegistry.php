<?php

declare(strict_types=1);

namespace App\Scripts\Runners;

/**
 * Usage: $registry->register('shell', $shellRunner); $registry->get('shell')->execute(...);
 */
class RunnerRegistry
{
    /** @var array<string, RunnerInterface> */
    private array $runners = [];

    public function register(string $name, RunnerInterface $runner): self
    {
        $this->runners[$name] = $runner;

        return $this;
    }

    public function get(string $name): RunnerInterface
    {
        if (! isset($this->runners[$name])) {
            throw new \InvalidArgumentException("Unknown runner: {$name}");
        }

        return $this->runners[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->runners[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->runners);
    }
}
