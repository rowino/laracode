<?php

declare(strict_types=1);

use App\Scripts\ScriptLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testDir = sys_get_temp_dir().'/laracode-wt-list-'.uniqid();
    mkdir($this->testDir.'/.laracode', 0755, true);

    initListScriptTestRepo($this->testDir);

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

function runListScriptTestGit(string $dir, string ...$args): array
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

function initListScriptTestRepo(string $dir): void
{
    runListScriptTestGit($dir, 'init');
    runListScriptTestGit($dir, 'config', 'user.email', 'test@example.com');
    runListScriptTestGit($dir, 'config', 'user.name', 'Test User');
    file_put_contents($dir.'/README.md', '# Test');
    runListScriptTestGit($dir, 'add', '.');
    runListScriptTestGit($dir, 'commit', '-m', 'Initial commit');
}

it('discovers worktree:list from bundled scripts', function () {
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);

    expect($scripts)->toHaveKey('worktree:list')
        ->and($scripts['worktree:list']->name)->toBe('worktree:list')
        ->and($scripts['worktree:list']->description)->toBe('List existing git worktrees with status')
        ->and($scripts['worktree:list']->hidden)->toBeFalse();
});

it('lists the main worktree', function () {
    Artisan::call('worktree:list');
    $output = Artisan::output();

    expect($output)->toContain('Total: 1 worktree(s)');
});

it('lists multiple worktrees', function () {
    $targetPath = $this->worktreesDir.'/feature-one';
    runListScriptTestGit($this->testDir, 'worktree', 'add', '-b', 'feature/one', $targetPath);

    Artisan::call('worktree:list');
    $output = Artisan::output();

    expect($output)->toContain('Total: 2 worktree(s)')
        ->and($output)->toContain('feature-one');
});

it('shows current worktree with green asterisk marker', function () {
    Artisan::call('worktree:list');
    $output = Artisan::output();

    expect($output)->toContain("\033[32m*");
});

it('displays bold headers', function () {
    Artisan::call('worktree:list');
    $output = Artisan::output();

    expect($output)->toContain("\033[1m")
        ->and($output)->toContain('Name')
        ->and($output)->toContain('Branch')
        ->and($output)->toContain('Status');
});

it('shows green color for current/clean status', function () {
    Artisan::call('worktree:list');
    $output = Artisan::output();

    expect($output)->toContain("\033[32m");
});

it('shows yellow color for dirty status', function () {
    $targetPath = $this->worktreesDir.'/feature-dirty';
    runListScriptTestGit($this->testDir, 'worktree', 'add', '-b', 'feature/dirty', $targetPath);
    file_put_contents($targetPath.'/dirty.txt', 'uncommitted');

    Artisan::call('worktree:list');
    $output = Artisan::output();

    expect($output)->toContain("\033[33m")
        ->and($output)->toContain('dirty');
});

it('shows dim total line', function () {
    Artisan::call('worktree:list');
    $output = Artisan::output();

    expect($output)->toMatch('/\033\[2m.*Total: \d+ worktree/');
});
