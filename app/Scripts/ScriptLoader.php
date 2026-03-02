<?php

declare(strict_types=1);

namespace App\Scripts;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Usage: $loader = new ScriptLoader(); $scripts = $loader->discover('/path/to/project');
 */
class ScriptLoader
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return array<string, ScriptDefinition>
     */
    public function discover(string $projectPath): array
    {
        $scripts = [];

        $bundledPath = $this->bundledScriptsPath();
        if (is_dir($bundledPath)) {
            $scripts = $this->loadFromDirectory($bundledPath);
        }

        $projectScriptsPath = rtrim($projectPath, '/').'/.laracode/scripts';
        if (is_dir($projectScriptsPath)) {
            $projectScripts = $this->loadFromDirectory($projectScriptsPath);
            $scripts = array_merge($scripts, $projectScripts);
        }

        return $scripts;
    }

    /**
     * @return array<string, ScriptDefinition>
     */
    private function loadFromDirectory(string $basePath): array
    {
        $resolved = realpath($basePath);
        if ($resolved === false) {
            return [];
        }
        $basePath = $resolved;
        $scripts = [];
        $files = $this->findYamlFiles($basePath);

        foreach ($files as $file) {
            $name = $this->pathToName($basePath, $file);

            try {
                $data = Yaml::parseFile($file);
            } catch (ParseException $e) {
                $this->logger?->warning("Failed to parse YAML script: {$file}", ['error' => $e->getMessage()]);

                continue;
            }

            if (! is_array($data)) {
                continue;
            }

            $data['name'] = $name;

            try {
                $scripts[$name] = ScriptDefinition::fromArray($data, $file);
            } catch (InvalidArgumentException $e) {
                $this->logger?->warning("Invalid script definition: {$file}", ['error' => $e->getMessage()]);

                continue;
            }
        }

        return $scripts;
    }

    /**
     * @return list<string>
     */
    private function findYamlFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'yaml' || $fileInfo->getExtension() === 'yml') {
                $path = $fileInfo->getRealPath() ?: $fileInfo->getPathname();
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    private function pathToName(string $basePath, string $filePath): string
    {
        $relative = substr($filePath, strlen(rtrim($basePath, '/')) + 1);
        $withoutExtension = (string) preg_replace('/\.(yaml|yml)$/', '', $relative);

        return str_replace('/', ':', $withoutExtension);
    }

    protected function bundledScriptsPath(): string
    {
        return dirname(__DIR__, 2).'/stubs/scripts';
    }
}
