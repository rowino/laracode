<?php

declare(strict_types=1);

namespace App\Frameworks;

abstract class AbstractFramework implements FrameworkInterface
{
    protected function composerHas(string $basePath, string $package): bool
    {
        $composerJson = $basePath.'/composer.json';

        if (! file_exists($composerJson)) {
            return false;
        }

        $content = file_get_contents($composerJson);

        if ($content === false) {
            return false;
        }

        return str_contains($content, $package);
    }
}
