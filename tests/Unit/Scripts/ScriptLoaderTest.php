<?php

declare(strict_types=1);

use App\Scripts\ScriptDefinition;
use App\Scripts\ScriptLoader;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/script-loader-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $path = $file->getRealPath() ?: $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($this->tempDir);
    }
});

function loaderWithoutBundled(): ScriptLoader
{
    return new class extends ScriptLoader
    {
        protected function bundledScriptsPath(): string
        {
            return '/nonexistent-bundled-path';
        }
    };
}

function createYamlFile(string $dir, string $relativePath, string $content): void
{
    $fullPath = $dir.'/'.$relativePath;
    $parentDir = dirname($fullPath);
    if (! is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }
    file_put_contents($fullPath, $content);
}

describe('discover', function () {
    it('discovers YAML files in .laracode/scripts recursively', function () {
        $scriptsDir = $this->tempDir.'/.laracode/scripts';
        createYamlFile($scriptsDir, 'deploy.yaml', <<<'YAML'
description: Deploy the app
steps:
  - run: "echo deploy"
YAML);
        createYamlFile($scriptsDir, 'worktree/add.yaml', <<<'YAML'
description: Add a worktree
steps:
  - run: "git worktree add"
YAML);

        $loader = loaderWithoutBundled();
        $scripts = $loader->discover($this->tempDir);

        expect($scripts)
            ->toHaveCount(2)
            ->toHaveKeys(['deploy', 'worktree:add']);

        expect($scripts['deploy'])
            ->toBeInstanceOf(ScriptDefinition::class)
            ->name->toBe('deploy')
            ->description->toBe('Deploy the app');

        expect($scripts['worktree:add'])
            ->name->toBe('worktree:add')
            ->description->toBe('Add a worktree');
    });

    it('maps directory structure to command names correctly', function () {
        $scriptsDir = $this->tempDir.'/.laracode/scripts';
        createYamlFile($scriptsDir, 'root-script.yaml', <<<'YAML'
description: Root level
steps:
  - run: "echo root"
YAML);
        createYamlFile($scriptsDir, 'ns/child.yaml', <<<'YAML'
description: Namespaced
steps:
  - run: "echo child"
YAML);
        createYamlFile($scriptsDir, 'deep/nested/script.yaml', <<<'YAML'
description: Deep nested
steps:
  - run: "echo deep"
YAML);

        $loader = loaderWithoutBundled();
        $scripts = $loader->discover($this->tempDir);

        expect($scripts)->toHaveKeys(['root-script', 'ns:child', 'deep:nested:script']);
    });

    it('supports .yml extension', function () {
        $scriptsDir = $this->tempDir.'/.laracode/scripts';
        createYamlFile($scriptsDir, 'test.yml', <<<'YAML'
description: YML extension
steps:
  - run: "echo yml"
YAML);

        $loader = loaderWithoutBundled();
        $scripts = $loader->discover($this->tempDir);

        expect($scripts)
            ->toHaveCount(1)
            ->toHaveKey('test');
    });

    it('falls back to bundled scripts', function () {
        $bundledDir = $this->tempDir.'/bundled';
        createYamlFile($bundledDir, 'bundled-script.yaml', <<<'YAML'
description: Bundled
steps:
  - run: "echo bundled"
YAML);

        $loader = new class($bundledDir) extends ScriptLoader
        {
            public function __construct(private readonly string $bundledPath) {}

            protected function bundledScriptsPath(): string
            {
                return $this->bundledPath;
            }
        };

        $scripts = $loader->discover($this->tempDir);

        expect($scripts)
            ->toHaveCount(1)
            ->toHaveKey('bundled-script');

        expect($scripts['bundled-script'])
            ->description->toBe('Bundled');
    });

    it('project scripts override bundled scripts with same name', function () {
        $bundledDir = $this->tempDir.'/bundled';
        createYamlFile($bundledDir, 'deploy.yaml', <<<'YAML'
description: Bundled deploy
steps:
  - run: "echo bundled"
YAML);

        $scriptsDir = $this->tempDir.'/project/.laracode/scripts';
        createYamlFile($scriptsDir, 'deploy.yaml', <<<'YAML'
description: Project deploy
steps:
  - run: "echo project"
YAML);

        $loader = new class($bundledDir) extends ScriptLoader
        {
            public function __construct(private readonly string $bundledPath) {}

            protected function bundledScriptsPath(): string
            {
                return $this->bundledPath;
            }
        };

        $scripts = $loader->discover($this->tempDir.'/project');

        expect($scripts)
            ->toHaveCount(1)
            ->toHaveKey('deploy');

        expect($scripts['deploy'])
            ->description->toBe('Project deploy');
    });

    it('skips invalid YAML files', function () {
        $scriptsDir = $this->tempDir.'/.laracode/scripts';
        createYamlFile($scriptsDir, 'valid.yaml', <<<'YAML'
description: Valid
steps:
  - run: "echo valid"
YAML);
        createYamlFile($scriptsDir, 'invalid.yaml', <<<'YAML'
: : : this is not valid yaml [
YAML);

        $loader = loaderWithoutBundled();
        $scripts = $loader->discover($this->tempDir);

        expect($scripts)
            ->toHaveCount(1)
            ->toHaveKey('valid');
    });

    it('skips YAML files missing required fields', function () {
        $scriptsDir = $this->tempDir.'/.laracode/scripts';
        createYamlFile($scriptsDir, 'valid.yaml', <<<'YAML'
description: Valid
steps:
  - run: "echo valid"
YAML);
        createYamlFile($scriptsDir, 'no-steps.yaml', <<<'YAML'
description: No steps defined
YAML);

        $loader = loaderWithoutBundled();
        $scripts = $loader->discover($this->tempDir);

        expect($scripts)
            ->toHaveCount(1)
            ->toHaveKey('valid');
    });

    it('returns empty array when no scripts directory exists', function () {
        $loader = loaderWithoutBundled();
        $scripts = $loader->discover($this->tempDir);

        expect($scripts)->toBe([]);
    });

    it('sets name from directory path, overriding any name in YAML', function () {
        $scriptsDir = $this->tempDir.'/.laracode/scripts';
        createYamlFile($scriptsDir, 'worktree/add.yaml', <<<'YAML'
name: should-be-overridden
description: The name should come from path
steps:
  - run: "echo test"
YAML);

        $loader = loaderWithoutBundled();
        $scripts = $loader->discover($this->tempDir);

        expect($scripts['worktree:add'])->name->toBe('worktree:add');
    });

    it('sets sourcePath to the absolute file path', function () {
        $scriptsDir = $this->tempDir.'/.laracode/scripts';
        createYamlFile($scriptsDir, 'test.yaml', <<<'YAML'
description: Test
steps:
  - run: "echo test"
YAML);

        $loader = loaderWithoutBundled();
        $scripts = $loader->discover($this->tempDir);

        expect($scripts['test']->sourcePath)->toEndWith('/test.yaml');
    });

    it('preserves hidden flag from YAML', function () {
        $scriptsDir = $this->tempDir.'/.laracode/scripts';
        createYamlFile($scriptsDir, 'visible.yaml', <<<'YAML'
description: Visible
steps:
  - run: "echo visible"
YAML);
        createYamlFile($scriptsDir, 'hidden.yaml', <<<'YAML'
description: Hidden
hidden: true
steps:
  - run: "echo hidden"
YAML);

        $loader = loaderWithoutBundled();
        $scripts = $loader->discover($this->tempDir);

        expect($scripts['visible']->hidden)->toBeFalse()
            ->and($scripts['hidden']->hidden)->toBeTrue();
    });

    it('skips YAML files that parse to non-array', function () {
        $scriptsDir = $this->tempDir.'/.laracode/scripts';
        createYamlFile($scriptsDir, 'scalar.yaml', 'just a string');
        createYamlFile($scriptsDir, 'valid.yaml', <<<'YAML'
description: Valid
steps:
  - run: "echo valid"
YAML);

        $loader = loaderWithoutBundled();
        $scripts = $loader->discover($this->tempDir);

        expect($scripts)
            ->toHaveCount(1)
            ->toHaveKey('valid');
    });
});
