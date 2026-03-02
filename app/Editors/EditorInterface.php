<?php

declare(strict_types=1);

namespace App\Editors;

interface EditorInterface
{
    public function name(): string;

    public function executable(): string;

    public function openProject(string $path): bool;
}
