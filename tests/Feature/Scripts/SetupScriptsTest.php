<?php

declare(strict_types=1);

use App\Scripts\ScriptLoader;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testDir = sys_get_temp_dir().'/laracode-setup-scripts-'.uniqid();
    mkdir($this->testDir.'/.laracode', 0755, true);

    $this->originalCwd = getcwd();
    $this->originalPath = getenv('PATH');
    chdir($this->testDir);
});

afterEach(function () {
    putenv("PATH={$this->originalPath}");
    chdir($this->originalCwd);

    if (is_dir($this->testDir)) {
        File::deleteDirectory($this->testDir);
    }
});

it('discovers setup:composer from bundled scripts', function () {
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);

    expect($scripts)->toHaveKey('setup:composer')
        ->and($scripts['setup:composer']->name)->toBe('setup:composer')
        ->and($scripts['setup:composer']->description)->toBe('Install PHP dependencies')
        ->and($scripts['setup:composer']->hidden)->toBeTrue();
});

it('discovers setup:node from bundled scripts', function () {
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);

    expect($scripts)->toHaveKey('setup:node')
        ->and($scripts['setup:node']->name)->toBe('setup:node')
        ->and($scripts['setup:node']->description)->toBe('Install Node.js dependencies (auto-detects pnpm/yarn/npm)')
        ->and($scripts['setup:node']->hidden)->toBeTrue();
});

it('discovers setup:migrate from bundled scripts', function () {
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);

    expect($scripts)->toHaveKey('setup:migrate')
        ->and($scripts['setup:migrate']->name)->toBe('setup:migrate')
        ->and($scripts['setup:migrate']->description)->toBe('Run database migrations')
        ->and($scripts['setup:migrate']->hidden)->toBeTrue();
});

it('discovers setup:env-copy from bundled scripts', function () {
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);

    expect($scripts)->toHaveKey('setup:env-copy')
        ->and($scripts['setup:env-copy']->name)->toBe('setup:env-copy')
        ->and($scripts['setup:env-copy']->description)->toBe('Copy and configure environment files for worktree')
        ->and($scripts['setup:env-copy']->hidden)->toBeTrue();
});

it('setup:composer aborts when no composer.json present', function () {
    $this->artisan('setup:composer')
        ->assertFailed();
});

it('setup:composer runs composer install', function () {
    file_put_contents($this->testDir.'/composer.json', json_encode([
        'name' => 'test/project',
        'require' => new stdClass,
    ]));

    file_put_contents($this->testDir.'/composer', "#!/bin/bash\necho \"composer \$@\" > {$this->testDir}/composer-invocation.txt");
    chmod($this->testDir.'/composer', 0755);

    putenv("PATH={$this->testDir}:{$this->originalPath}");

    $this->artisan('setup:composer')
        ->assertSuccessful();

    $invocation = file_get_contents($this->testDir.'/composer-invocation.txt');
    expect($invocation)->toContain('install')
        ->and($invocation)->toContain('--no-interaction');
});

it('setup:node aborts when no package.json present', function () {
    $this->artisan('setup:node')
        ->assertFailed();
});

it('setup:node runs npm install and build', function () {
    file_put_contents($this->testDir.'/package.json', json_encode([
        'name' => 'test-project',
        'version' => '1.0.0',
    ]));

    file_put_contents($this->testDir.'/npm', "#!/bin/bash\necho \"npm \$@\" >> {$this->testDir}/npm-invocation.txt");
    chmod($this->testDir.'/npm', 0755);

    putenv("PATH={$this->testDir}:{$this->originalPath}");

    $this->artisan('setup:node')
        ->assertSuccessful();

    $invocation = file_get_contents($this->testDir.'/npm-invocation.txt');
    expect($invocation)->toContain('install')
        ->and($invocation)->toContain('run build');
});

it('setup:node detects yarn from lockfile', function () {
    file_put_contents($this->testDir.'/package.json', json_encode([
        'name' => 'test-project',
        'version' => '1.0.0',
    ]));
    file_put_contents($this->testDir.'/yarn.lock', '');

    file_put_contents($this->testDir.'/yarn', "#!/bin/bash\necho \"yarn \$@\" >> {$this->testDir}/yarn-invocation.txt");
    chmod($this->testDir.'/yarn', 0755);

    putenv("PATH={$this->testDir}:{$this->originalPath}");

    $this->artisan('setup:node')
        ->assertSuccessful();

    $invocation = file_get_contents($this->testDir.'/yarn-invocation.txt');
    expect($invocation)->toContain('install')
        ->and($invocation)->toContain('build');
});

it('setup:migrate aborts when no artisan file present', function () {
    $this->artisan('setup:migrate')
        ->assertFailed();
});

it('setup:migrate runs php artisan migrate', function () {
    file_put_contents($this->testDir.'/artisan', "#!/usr/bin/env php\n<?php echo 'migrated';");

    file_put_contents($this->testDir.'/php', "#!/bin/bash\necho \"php \$@\" > {$this->testDir}/php-invocation.txt");
    chmod($this->testDir.'/php', 0755);

    putenv("PATH={$this->testDir}:{$this->originalPath}");

    $this->artisan('setup:migrate')
        ->assertSuccessful();
});

it('user can override bundled setup:composer with project script', function () {
    mkdir($this->testDir.'/.laracode/scripts/setup', 0755, true);
    file_put_contents($this->testDir.'/.laracode/scripts/setup/composer.yaml', <<<'YAML'
name: setup:composer
description: Custom composer setup
steps:
  - id: custom
    run: 'echo "custom override"'
YAML);

    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);

    expect($scripts)->toHaveKey('setup:composer')
        ->and($scripts['setup:composer']->description)->toBe('Custom composer setup')
        ->and($scripts['setup:composer']->sourcePath)->toContain('.laracode/scripts/setup/composer.yaml');
});
