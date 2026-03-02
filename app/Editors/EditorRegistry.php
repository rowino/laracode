<?php

declare(strict_types=1);

namespace App\Editors;

use InvalidArgumentException;

/**
 * Usage: Register and retrieve editor implementations by name.
 */
class EditorRegistry
{
    /** @var array<string, EditorInterface> */
    private array $editors = [];

    public function register(EditorInterface $editor): self
    {
        $this->editors[$editor->name()] = $editor;

        return $this;
    }

    public function get(string $name): EditorInterface
    {
        if (! isset($this->editors[$name])) {
            throw new InvalidArgumentException("Editor '{$name}' is not registered.");
        }

        return $this->editors[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->editors[$name]);
    }

    /**
     * @return array<string, EditorInterface>
     */
    public function all(): array
    {
        return $this->editors;
    }

    /**
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->editors);
    }
}
