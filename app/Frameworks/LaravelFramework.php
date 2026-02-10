<?php

declare(strict_types=1);

namespace App\Frameworks;

class LaravelFramework extends AbstractFramework
{
    public function name(): string
    {
        return 'laravel';
    }

    public function watchPaths(): array
    {
        return [
            'app',
            'config',
            'database',
            'resources',
            'routes',
            'tests',
        ];
    }

    public function excludePatterns(): array
    {
        return [
            '**/vendor/**',
            '**/node_modules/**',
            '**/storage/**',
            '**/public/**',
            '**/.git/**',
            '**/bootstrap/cache/**',
        ];
    }

    public function matches(string $basePath): bool
    {
        if (file_exists($basePath.'/artisan')) {
            return true;
        }

        return $this->composerHas($basePath, 'laravel/framework');
    }
}
