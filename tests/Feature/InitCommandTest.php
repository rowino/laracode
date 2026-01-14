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
        ->and(is_dir($this->testPath.'/.claude/commands'))->toBeTrue()
        ->and(is_dir($this->testPath.'/.claude/skills'))->toBeTrue();
});

it('creates build-next.md command file', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful();

    $buildNextPath = $this->testPath.'/.claude/commands/build-next.md';

    expect(file_exists($buildNextPath))->toBeTrue()
        ->and(file_get_contents($buildNextPath))->toContain('Build Next Task')
        ->and(file_get_contents($buildNextPath))->toContain('NO PLANNING MODE');
});

it('creates generate-tasks.md skill file', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful();

    $generateTasksPath = $this->testPath.'/.claude/skills/generate-tasks.md';

    expect(file_exists($generateTasksPath))->toBeTrue()
        ->and(file_get_contents($generateTasksPath))->toContain('Generate Tasks from Feature Description')
        ->and(file_get_contents($generateTasksPath))->toContain('NO PLANNING MODE');
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

it('skips command files that are similar to template', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);

    $app = app();
    $initCommand = new App\Commands\InitCommand;
    $initCommand->setLaravel($app);
    $templateContent = (new ReflectionMethod($initCommand, 'getBuildNextContent'))
        ->invoke($initCommand);
    $slightlyModified = $templateContent.' ';

    file_put_contents($this->testPath.'/.claude/commands/build-next.md', $slightlyModified);

    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped');

    expect(file_get_contents($this->testPath.'/.claude/commands/build-next.md'))
        ->toBe($slightlyModified);
});

it('prompts user when command file differs significantly and keeps original on Ignore', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);
    file_put_contents($this->testPath.'/.claude/commands/build-next.md', 'original content');

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsChoice(
            '    How would you like to handle .claude/commands/build-next.md?',
            'Ignore (keep existing)',
            ['Ignore (keep existing)', 'Overwrite (use template)', 'Merge (3-way merge)']
        )
        ->assertSuccessful();

    expect(file_get_contents($this->testPath.'/.claude/commands/build-next.md'))
        ->toBe('original content');
});

it('overwrites command file when user chooses Overwrite option', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);
    file_put_contents($this->testPath.'/.claude/commands/build-next.md', 'original content');

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsChoice(
            '    How would you like to handle .claude/commands/build-next.md?',
            'Overwrite (use template)',
            ['Ignore (keep existing)', 'Overwrite (use template)', 'Merge (3-way merge)']
        )
        ->assertSuccessful();

    expect(file_get_contents($this->testPath.'/.claude/commands/build-next.md'))
        ->toContain('Build Next Task');
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

it('merges settings and preserves existing settings', function () {
    mkdir($this->testPath.'/.claude', 0755, true);

    $existingSettings = [
        'permissions' => [
            'allow' => ['Read', 'Write'],
        ],
        'model' => 'claude-3-opus',
    ];
    file_put_contents(
        $this->testPath.'/.claude/settings.json',
        json_encode($existingSettings, JSON_PRETTY_PRINT)
    );

    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful()
        ->expectsOutputToContain('Merged');

    $merged = json_decode(file_get_contents($this->testPath.'/.claude/settings.json'), true);

    expect($merged['permissions']['allow'])->toBe(['Read', 'Write'])
        ->and($merged['model'])->toBe('claude-3-opus')
        ->and($merged)->toHaveKey('hooks');
});

it('merges settings and adds new hooks without duplicating existing ones', function () {
    mkdir($this->testPath.'/.claude', 0755, true);

    $existingSettings = [
        'hooks' => [
            'PreToolUse' => [
                ['matcher' => 'Bash', 'commands' => ['echo "pre-bash"']],
            ],
        ],
    ];
    file_put_contents(
        $this->testPath.'/.claude/settings.json',
        json_encode($existingSettings, JSON_PRETTY_PRINT)
    );

    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful()
        ->expectsOutputToContain('Merged');

    $merged = json_decode(file_get_contents($this->testPath.'/.claude/settings.json'), true);

    expect($merged['hooks']['PreToolUse'])->toHaveCount(1)
        ->and($merged['hooks']['PreToolUse'][0]['matcher'])->toBe('Bash');
});

it('detects similar command files and skips them', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);

    $app = app();
    $initCommand = new App\Commands\InitCommand;
    $initCommand->setLaravel($app);
    $templateContent = (new ReflectionMethod($initCommand, 'getBuildNextContent'))
        ->invoke($initCommand);

    $similarContent = $templateContent."\n<!-- minor change -->";
    file_put_contents($this->testPath.'/.claude/commands/build-next.md', $similarContent);

    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped');

    expect(file_get_contents($this->testPath.'/.claude/commands/build-next.md'))
        ->toBe($similarContent);
});

it('creates backup file when merging command files', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);
    $originalContent = 'completely different content that is very different from the template';
    file_put_contents($this->testPath.'/.claude/commands/build-next.md', $originalContent);

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsChoice(
            '    How would you like to handle .claude/commands/build-next.md?',
            'Merge (3-way merge)',
            ['Ignore (keep existing)', 'Overwrite (use template)', 'Merge (3-way merge)']
        )
        ->assertSuccessful();

    expect(file_exists($this->testPath.'/.claude/commands/build-next.md.backup'))->toBeTrue()
        ->and(file_get_contents($this->testPath.'/.claude/commands/build-next.md.backup'))
        ->toBe($originalContent);
});
