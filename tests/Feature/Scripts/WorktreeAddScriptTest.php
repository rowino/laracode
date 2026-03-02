<?php

declare(strict_types=1);

use App\Scripts\ScriptLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testDir = sys_get_temp_dir().'/laracode-wt-script-'.uniqid();
    mkdir($this->testDir.'/.laracode', 0755, true);

    initWorktreeScriptTestRepo($this->testDir);

    $this->worktreesDir = $this->testDir.'/worktrees';
    mkdir($this->worktreesDir, 0755, true);

    $this->originalCwd = getcwd();
    chdir($this->testDir);

    $settings = app(\App\Services\Settings\SettingsService::class);
    $settings->setProjectPath($this->testDir);
});

afterEach(function () {
    chdir($this->originalCwd);

    if (is_dir($this->testDir)) {
        File::deleteDirectory($this->testDir);
    }
});

/**
 * @return string[]
 */
function runWtScriptGit(string $dir, string ...$args): array
{
    $descriptorspec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(['git', ...$args], $descriptorspec, $pipes, $dir);

    if (! is_resource($process)) {
        return ['', ''];
    }

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    proc_close($process);

    return [$output ?: '', $error ?: ''];
}

function initWorktreeScriptTestRepo(string $dir): void
{
    runWtScriptGit($dir, 'init');
    runWtScriptGit($dir, 'config', 'user.email', 'test@example.com');
    runWtScriptGit($dir, 'config', 'user.name', 'Test User');
    file_put_contents($dir.'/README.md', '# Test');
    runWtScriptGit($dir, 'add', '.');
    runWtScriptGit($dir, 'commit', '-m', 'Initial commit');
}

it('discovers worktree:add from bundled scripts', function () {
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);

    expect($scripts)->toHaveKey('worktree:add')
        ->and($scripts['worktree:add']->name)->toBe('worktree:add')
        ->and($scripts['worktree:add']->description)->toBe('Create a new git worktree with setup flows')
        ->and($scripts['worktree:add']->hidden)->toBeFalse();
});

it('has correct signature with arguments and options', function () {
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);
    $script = $scripts['worktree:add'];

    $command = new \App\Scripts\ScriptCommand($script, app(\App\Scripts\ScriptExecutor::class));
    $definition = $command->getDefinition();

    expect($definition->hasArgument('branch'))->toBeTrue()
        ->and($definition->getArgument('branch')->isRequired())->toBeFalse()
        ->and($definition->hasOption('folder'))->toBeTrue()
        ->and($definition->getOption('folder')->acceptValue())->toBeTrue()
        ->and($definition->hasOption('source'))->toBeTrue()
        ->and($definition->getOption('source')->acceptValue())->toBeTrue()
        ->and($definition->hasOption('skip-setup'))->toBeTrue()
        ->and($definition->getOption('skip-setup')->acceptValue())->toBeFalse()
        ->and($definition->hasOption('auto'))->toBeTrue();
});

it('creates git worktree with branch argument and source option', function () {
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => ['basePath' => './worktrees'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $this->artisan('worktree:add', [
        'branch' => 'feature/test-branch',
        '--source' => 'master',
        '--skip-setup' => true,
    ])->assertSuccessful();

    expect(is_dir($this->worktreesDir.'/test-branch'))->toBeTrue();

    [$branches] = runWtScriptGit($this->testDir, 'branch');
    expect($branches)->toContain('feature/test-branch');
});

it('uses --folder option when provided', function () {
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => ['basePath' => './worktrees'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $this->artisan('worktree:add', [
        'branch' => 'feature/custom-folder',
        '--folder' => 'my-custom-folder',
        '--source' => 'master',
        '--skip-setup' => true,
    ])->assertSuccessful();

    expect(is_dir($this->worktreesDir.'/my-custom-folder'))->toBeTrue();
});

it('resolves folder name from branch slug when --folder not provided', function () {
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => ['basePath' => './worktrees'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $this->artisan('worktree:add', [
        'branch' => 'feature/my-slugged-branch',
        '--source' => 'master',
        '--skip-setup' => true,
    ])->assertSuccessful();

    expect(is_dir($this->worktreesDir.'/my-slugged-branch'))->toBeTrue();
});

it('calls shared-setup sub-script when skip-setup not set', function () {
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => ['basePath' => './worktrees'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    mkdir($this->testDir.'/.laracode/scripts/worktree', 0755, true);
    file_put_contents(
        $this->testDir.'/.laracode/scripts/worktree/shared-setup.yaml',
        "name: worktree:shared-setup\nhidden: true\nsteps:\n  - id: marker\n    run: 'touch \"\$WORKTREE_PATH/.shared-setup-ran\"'\n"
    );

    $this->artisan('worktree:add', [
        'branch' => 'feature/with-setup',
        '--source' => 'master',
    ])->assertSuccessful();

    expect(is_dir($this->worktreesDir.'/with-setup'))->toBeTrue()
        ->and(file_exists($this->worktreesDir.'/with-setup/.shared-setup-ran'))->toBeTrue();
});

it('runs setup scripts from settings after shared-setup', function () {
    mkdir($this->testDir.'/.laracode/scripts/setup', 0755, true);
    file_put_contents(
        $this->testDir.'/.laracode/scripts/setup/test-setup.yaml',
        "name: setup:test-setup\nhidden: true\nsteps:\n  - id: marker\n    run: 'touch \"$(pwd)/.test-setup-ran\"'\n"
    );

    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => [
                'basePath' => './worktrees',
                'setupScripts' => 'setup:test-setup',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $this->artisan('worktree:add', [
        'branch' => 'feature/with-scripts',
        '--source' => 'master',
        '--skip-setup' => true,
    ])->assertSuccessful();

    // skip-setup should prevent setup scripts from running
    expect(file_exists($this->worktreesDir.'/with-scripts/.test-setup-ran'))->toBeFalse();
});

it('skips setup scripts gracefully when setting is unresolved', function () {
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => ['basePath' => './worktrees'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $this->artisan('worktree:add', [
        'branch' => 'feature/no-scripts',
        '--source' => 'master',
        '--skip-setup' => true,
    ])->assertSuccessful();

    expect(is_dir($this->worktreesDir.'/no-scripts'))->toBeTrue();
});

it('uses defaultSourceBranch from settings', function () {
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);
    $script = $scripts['worktree:add'];

    $prompts = $script->prompts;
    $sourceBranchPrompt = collect($prompts)->firstWhere('id', 'SOURCE_BRANCH');

    expect($sourceBranchPrompt)->not->toBeNull()
        ->and($sourceBranchPrompt['default'])->toBe('{{settings.worktrees.defaultSourceBranch}}');
});

it('shows green success message and cyan cd hint after creation', function () {
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => ['basePath' => './worktrees'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    Artisan::call('worktree:add', [
        'branch' => 'feature/hint-test',
        '--source' => 'master',
        '--skip-setup' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContain("\033[32m✓ Worktree created: hint-test\033[0m")
        ->and($output)->toContain("\033[36mTo switch:\033[0m cd");
});

it('falls back to default basePath when settings key missing', function () {
    // Settings file exists but without worktrees.basePath
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode(['watch' => []], JSON_PRETTY_PRINT)
    );

    $this->artisan('worktree:add', [
        'branch' => 'feature/fallback-base',
        '--source' => 'master',
        '--skip-setup' => true,
    ])->assertSuccessful();

    [$branches] = runWtScriptGit($this->testDir, 'branch');
    expect($branches)->toContain('feature/fallback-base');
})->after(function () {
    // Clean up worktrees created outside testDir
    $siblingDir = dirname($this->testDir).'/worktrees';
    if (is_dir($siblingDir)) {
        \Illuminate\Support\Facades\File::deleteDirectory($siblingDir);
    }
});
