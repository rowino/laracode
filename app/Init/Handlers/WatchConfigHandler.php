<?php

declare(strict_types=1);

namespace App\Init\Handlers;

use App\Init\AiDecisionRequest;
use App\Init\InitContext;
use App\Init\InitHandler;
use App\Services\ProjectAnalyzer;

class WatchConfigHandler implements InitHandler
{
    private const string DATA_KEY = 'watch';

    private const array TESTING_KEYWORDS = ['test', 'spec', 'jest', 'phpunit', 'pest', 'vitest', 'mocha', 'ava', 'cypress'];

    private const array LINTING_KEYWORDS = ['lint', 'format', 'pint', 'phpstan', 'eslint', 'prettier', 'rector', 'cs', 'style', 'check', 'analyse', 'analyze', 'stan'];

    public function __construct(
        private readonly ProjectAnalyzer $projectAnalyzer,
    ) {}

    public function name(): string
    {
        return 'watch';
    }

    public function priority(): int
    {
        return 30;
    }

    public function decisionRequest(InitContext $ctx): ?AiDecisionRequest
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function getPromptContext(InitContext $ctx): array
    {
        $discovered = $this->discoverScripts($ctx->projectPath);
        $watchPaths = $this->projectAnalyzer->suggestWatchPaths($ctx->projectPath);

        return [
            'watchPaths' => $watchPaths,
            'testingCommands' => $discovered['testing'],
            'lintingCommands' => $discovered['linting'],
            'packageManager' => $discovered['packageManager'],
        ];
    }

    /** @param  array<string, mixed>  $decisions */
    public function processDecisions(InitContext $ctx, array $decisions): void {}

    public function apply(InitContext $ctx): void {}

    /** @return array<string, string> */
    public function summarize(InitContext $ctx): array
    {
        $data = $ctx->handlerData[self::DATA_KEY] ?? [];

        /** @var list<string> $watchPaths */
        $watchPaths = $data['confirmedWatchPaths'] ?? [];
        /** @var list<string> $testing */
        $testing = $data['confirmedTestingCommands'] ?? [];
        /** @var list<string> $linting */
        $linting = $data['confirmedLintingCommands'] ?? [];

        return [
            'Watch paths' => ! empty($watchPaths) ? implode(', ', $watchPaths) : '(none)',
            'Testing' => ! empty($testing) ? implode(', ', $testing) : '(none)',
            'Linting' => ! empty($linting) ? implode(', ', $linting) : '(none)',
            'Package manager' => $data['packageManager'] ?? 'npm',
        ];
    }

    /**
     * @return array{testing: list<string>, linting: list<string>, packageManager: string}
     */
    public function discoverScripts(string $projectPath): array
    {
        $testing = [];
        $linting = [];
        $packageManager = $this->detectPackageManager($projectPath);

        $composerScripts = $this->parseComposerScripts($projectPath);
        foreach ($composerScripts as $name => $command) {
            $fullCommand = "composer {$name}";
            if ($this->matchesKeywords($name, $command, self::TESTING_KEYWORDS)) {
                $testing[] = $fullCommand;
            } elseif ($this->matchesKeywords($name, $command, self::LINTING_KEYWORDS)) {
                $linting[] = $fullCommand;
            }
        }

        $nodeScripts = $this->parseNodeScripts($projectPath);
        $nodePrefix = $this->nodeRunPrefix($packageManager);
        foreach ($nodeScripts as $name => $command) {
            $fullCommand = "{$nodePrefix} {$name}";
            if ($this->matchesKeywords($name, $command, self::TESTING_KEYWORDS)) {
                $testing[] = $fullCommand;
            } elseif ($this->matchesKeywords($name, $command, self::LINTING_KEYWORDS)) {
                $linting[] = $fullCommand;
            }
        }

        return [
            'testing' => array_values(array_unique($testing)),
            'linting' => array_values(array_unique($linting)),
            'packageManager' => $packageManager,
        ];
    }

    public function detectPackageManager(string $projectPath): string
    {
        if (file_exists($projectPath.DIRECTORY_SEPARATOR.'pnpm-lock.yaml')) {
            return 'pnpm';
        }

        if (file_exists($projectPath.DIRECTORY_SEPARATOR.'yarn.lock')) {
            return 'yarn';
        }

        if (file_exists($projectPath.DIRECTORY_SEPARATOR.'bun.lockb') || file_exists($projectPath.DIRECTORY_SEPARATOR.'bun.lock')) {
            return 'bun';
        }

        if (file_exists($projectPath.DIRECTORY_SEPARATOR.'package-lock.json')) {
            return 'npm';
        }

        if (file_exists($projectPath.DIRECTORY_SEPARATOR.'package.json')) {
            return 'npm';
        }

        return 'npm';
    }

    /**
     * @return array<string, string>
     */
    private function parseComposerScripts(string $projectPath): array
    {
        $path = $projectPath.DIRECTORY_SEPARATOR.'composer.json';
        if (! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $json = json_decode($content, true);
        if (! is_array($json) || ! isset($json['scripts']) || ! is_array($json['scripts'])) {
            return [];
        }

        $scripts = [];
        foreach ($json['scripts'] as $name => $command) {
            if (str_starts_with((string) $name, 'pre-') || str_starts_with((string) $name, 'post-')) {
                continue;
            }
            $scripts[(string) $name] = is_array($command) ? implode(' && ', $command) : (string) $command;
        }

        return $scripts;
    }

    /**
     * @return array<string, string>
     */
    private function parseNodeScripts(string $projectPath): array
    {
        $path = $projectPath.DIRECTORY_SEPARATOR.'package.json';
        if (! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $json = json_decode($content, true);
        if (! is_array($json) || ! isset($json['scripts']) || ! is_array($json['scripts'])) {
            return [];
        }

        $scripts = [];
        foreach ($json['scripts'] as $name => $command) {
            $scripts[(string) $name] = (string) $command;
        }

        return $scripts;
    }

    private function nodeRunPrefix(string $packageManager): string
    {
        return match ($packageManager) {
            'pnpm' => 'pnpm run',
            'yarn' => 'yarn',
            'bun' => 'bun run',
            default => 'npm run',
        };
    }

    /**
     * @param  list<string>  $keywords
     */
    private function matchesKeywords(string $name, string $command, array $keywords): bool
    {
        $haystack = strtolower($name.' '.$command);

        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
