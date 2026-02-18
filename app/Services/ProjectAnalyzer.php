<?php

declare(strict_types=1);

namespace App\Services;

use App\Frameworks\FrameworkDetector;
use App\Frameworks\FrameworkInterface;

/**
 * Usage: Analyze project structure to detect framework and suggest watch paths.
 */
class ProjectAnalyzer
{
    private FrameworkDetector $detector;

    public function __construct(
        private ?string $basePath = null,
        ?FrameworkDetector $detector = null
    ) {
        $this->detector = $detector ?? new FrameworkDetector;
    }

    public function detectFramework(?string $basePath = null): FrameworkInterface
    {
        return $this->detector->detect($this->resolvePath($basePath));
    }

    /**
     * @return list<string> Paths that exist in the project
     */
    public function suggestWatchPaths(?string $basePath = null): array
    {
        $path = $this->resolvePath($basePath);
        $framework = $this->detectFramework($path);

        return array_values(array_filter(
            $framework->watchPaths(),
            fn (string $candidate): bool => is_dir($path.'/'.$candidate)
        ));
    }

    /**
     * @return list<string>
     */
    public function suggestExcludePatterns(?string $basePath = null): array
    {
        return $this->detectFramework($basePath)->excludePatterns();
    }

    /**
     * @return array{framework: FrameworkInterface, watchPaths: list<string>, hasComposer: bool}
     */
    public function analyze(?string $basePath = null): array
    {
        $path = $this->resolvePath($basePath);

        return [
            'framework' => $this->detectFramework($path),
            'watchPaths' => $this->suggestWatchPaths($path),
            'hasComposer' => file_exists($path.'/composer.json'),
        ];
    }

    private function resolvePath(?string $basePath): string
    {
        if ($basePath !== null) {
            return $basePath;
        }

        if ($this->basePath !== null) {
            return $this->basePath;
        }

        $cwd = getcwd();

        return $cwd !== false ? $cwd : '.';
    }
}
