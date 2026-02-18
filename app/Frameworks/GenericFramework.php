<?php

declare(strict_types=1);

namespace App\Frameworks;

class GenericFramework extends AbstractFramework
{
    public function name(): string
    {
        return 'generic';
    }

    public function watchPaths(): array
    {
        return [
            'src',
            'lib',
            'tests',
        ];
    }

    public function excludePatterns(): array
    {
        return [
            '**/vendor/**',
            '**/node_modules/**',
            '**/.git/**',
        ];
    }

    public function matches(string $basePath): bool
    {
        return true;
    }
}
