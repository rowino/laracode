<?php

declare(strict_types=1);

namespace App\Frameworks;

class SymfonyFramework extends AbstractFramework
{
    public function name(): string
    {
        return 'symfony';
    }

    public function watchPaths(): array
    {
        return [
            'src',
            'config',
            'templates',
            'tests',
        ];
    }

    public function excludePatterns(): array
    {
        return [
            '**/vendor/**',
            '**/node_modules/**',
            '**/var/**',
            '**/.git/**',
        ];
    }

    public function matches(string $basePath): bool
    {
        if (file_exists($basePath.'/bin/console') && file_exists($basePath.'/config/bundles.php')) {
            return true;
        }

        return $this->composerHas($basePath, 'symfony/framework-bundle');
    }
}
