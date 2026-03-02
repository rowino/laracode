<?php

declare(strict_types=1);

use App\Scripts\ScriptLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testDir = sys_get_temp_dir().'/laracode-wt-del-'.uniqid();
    mkdir($this->testDir.'/.laracode', 0755, true);

    initDeleteTestRepo($this->testDir);

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

function runDeleteTestGit(string $dir, string ...$args): array
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

function initDeleteTestRepo(string $dir): void
{
    runDeleteTestGit($dir, 'init');
    runDeleteTestGit($dir, 'config', 'user.email', 'test@example.com');
    runDeleteTestGit($dir, 'config', 'user.name', 'Test User');
    file_put_contents($dir.'/README.md', '# Test');
    runDeleteTestGit($dir, 'add', '.');
    runDeleteTestGit($dir, 'commit', '-m', 'Initial commit');
}

function createDeleteTestWorktree(string $repoDir, string $branch, string $path): void
{
    runDeleteTestGit($repoDir, 'worktree', 'add', '-b', $branch, $path);
}

it('discovers worktree:delete from bundled scripts', function () {
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);

    expect($scripts)->toHaveKey('worktree:delete')
        ->and($scripts['worktree:delete']->name)->toBe('worktree:delete')
        ->and($scripts['worktree:delete']->description)->toBe('Delete a git worktree with optional branch cleanup')
        ->and($scripts['worktree:delete']->hidden)->toBeFalse();
});

it('has correct signature with name argument and options', function () {
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);
    $script = $scripts['worktree:delete'];

    $command = new \App\Scripts\ScriptCommand($script, app(\App\Scripts\ScriptExecutor::class));
    $definition = $command->getDefinition();

    expect($definition->hasArgument('name'))->toBeTrue()
        ->and($definition->getArgument('name')->isRequired())->toBeFalse()
        ->and($definition->hasOption('force'))->toBeTrue()
        ->and($definition->hasOption('delete-branch'))->toBeTrue();
});

it('deletes worktree by name argument with --force', function () {
    $targetPath = $this->worktreesDir.'/to-delete';
    createDeleteTestWorktree($this->testDir, 'feature/to-delete', $targetPath);

    expect(is_dir($targetPath))->toBeTrue();

    $this->artisan('worktree:delete', [
        'name' => 'to-delete',
        '--force' => true,
    ])->assertSuccessful();

    expect(is_dir($targetPath))->toBeFalse();
});

it('deletes worktree and branch with --delete-branch', function () {
    $targetPath = $this->worktreesDir.'/branch-del';
    createDeleteTestWorktree($this->testDir, 'feature/branch-del', $targetPath);

    [$branches] = runDeleteTestGit($this->testDir, 'branch');
    expect($branches)->toContain('feature/branch-del');

    $this->artisan('worktree:delete', [
        'name' => 'branch-del',
        '--force' => true,
        '--delete-branch' => true,
    ])->assertSuccessful();

    expect(is_dir($targetPath))->toBeFalse();

    [$branches] = runDeleteTestGit($this->testDir, 'branch');
    expect($branches)->not->toContain('feature/branch-del');
});

it('fails when worktree name not found', function () {
    $this->artisan('worktree:delete', [
        'name' => 'nonexistent',
        '--force' => true,
    ])->assertFailed();
});

it('reports no worktrees available when none exist', function () {
    $this->artisan('worktree:delete', [
        'name' => 'anything',
        '--force' => true,
    ])->assertFailed();
});

it('displays green success message with checkmark on delete', function () {
    $targetPath = $this->worktreesDir.'/color-del';
    createDeleteTestWorktree($this->testDir, 'feature/color-del', $targetPath);

    Artisan::call('worktree:delete', [
        'name' => 'color-del',
        '--force' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContain("\033[32m✓ Worktree removed: color-del");
});

it('displays green branch deleted message with checkmark', function () {
    $targetPath = $this->worktreesDir.'/branch-color';
    createDeleteTestWorktree($this->testDir, 'feature/branch-color', $targetPath);

    Artisan::call('worktree:delete', [
        'name' => 'branch-color',
        '--force' => true,
        '--delete-branch' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContain("\033[32m✓ Branch deleted: feature/branch-color");
});
