<?php

declare(strict_types=1);

namespace App\Frameworks;

interface FrameworkInterface
{
    public function name(): string;

    /** @return list<string> */
    public function watchPaths(): array;

    /** @return list<string> */
    public function excludePatterns(): array;

    public function matches(string $basePath): bool;
}
