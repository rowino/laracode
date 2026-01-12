<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-test-'.uniqid();
    mkdir($this->testPath, 0755, true);
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

it('creates laracode directory structure', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful();

    expect(is_dir($this->testPath.'/.laracode'))->toBeTrue()
        ->and(is_dir($this->testPath.'/.laracode/specs'))->toBeTrue()
        ->and(is_dir($this->testPath.'/.claude'))->toBeTrue()
        ->and(is_dir($this->testPath.'/.claude/commands'))->toBeTrue();
});

it('creates build-next.md command file', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful();

    $buildNextPath = $this->testPath.'/.claude/commands/build-next.md';

    expect(file_exists($buildNextPath))->toBeTrue()
        ->and(file_get_contents($buildNextPath))->toContain('Build Next Task')
        ->and(file_get_contents($buildNextPath))->toContain('NO PLANNING MODE');
});

it('creates example tasks.json', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful();

    $tasksPath = $this->testPath.'/.laracode/specs/example/tasks.json';

    expect(file_exists($tasksPath))->toBeTrue();

    $tasks = json_decode(file_get_contents($tasksPath), true);

    expect($tasks)->toHaveKey('title')
        ->and($tasks)->toHaveKey('tasks')
        ->and($tasks['tasks'])->toBeArray()
        ->and($tasks['tasks'][0])->toHaveKey('status');
});

it('does not overwrite existing files without --force', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);
    file_put_contents($this->testPath.'/.claude/commands/build-next.md', 'original content');

    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful();

    expect(file_get_contents($this->testPath.'/.claude/commands/build-next.md'))
        ->toBe('original content');
});

it('overwrites existing files with --force', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);
    file_put_contents($this->testPath.'/.claude/commands/build-next.md', 'original content');

    $this->artisan('init', ['path' => $this->testPath, '--force' => true])
        ->assertSuccessful();

    expect(file_get_contents($this->testPath.'/.claude/commands/build-next.md'))
        ->toContain('Build Next Task');
});

it('fails when path does not exist', function () {
    $this->artisan('init', ['path' => '/nonexistent/path/that/does/not/exist'])
        ->assertFailed();
});

it('uses current directory when no path provided', function () {
    $originalDir = getcwd();
    chdir($this->testPath);

    try {
        $this->artisan('init')
            ->assertSuccessful();

        expect(is_dir($this->testPath.'/.laracode'))->toBeTrue();
    } finally {
        chdir($originalDir);
    }
});
