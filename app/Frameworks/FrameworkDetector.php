<?php

declare(strict_types=1);

namespace App\Frameworks;

/**
 * Usage: Detect project framework by iterating registered frameworks and returning first match.
 */
class FrameworkDetector
{
    /** @var list<FrameworkInterface> */
    private array $frameworks;

    public function __construct()
    {
        $this->frameworks = [
            new LaravelFramework,
            new SymfonyFramework,
            new GenericFramework,
        ];
    }

    public function detect(string $basePath): FrameworkInterface
    {
        foreach ($this->frameworks as $framework) {
            if ($framework->matches($basePath)) {
                return $framework;
            }
        }

        return new GenericFramework;
    }
}
