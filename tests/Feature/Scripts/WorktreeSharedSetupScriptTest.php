<?php

declare(strict_types=1);

use App\Scripts\ScriptExecutor;
use App\Scripts\ScriptLoader;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testDir = sys_get_temp_dir().'/laracode-shared-setup-'.uniqid();
    mkdir($this->testDir.'/.laracode', 0755, true);

    initSharedSetupTestRepo($this->testDir);

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

function runSharedSetupGit(string $dir, string ...$args): array
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

function initSharedSetupTestRepo(string $dir): void
{
    runSharedSetupGit($dir, 'init');
    runSharedSetupGit($dir, 'config', 'user.email', 'test@example.com');
    runSharedSetupGit($dir, 'config', 'user.name', 'Test User');
    file_put_contents($dir.'/README.md', '# Test');
    runSharedSetupGit($dir, 'add', '.');
    runSharedSetupGit($dir, 'commit', '-m', 'Initial commit');
}

function createWorktreeForSharedSetup(string $baseDir, string $worktreesDir, string $branch): string
{
    $folder = basename($branch);
    $worktreePath = $worktreesDir.'/'.$folder;
    runSharedSetupGit($baseDir, 'worktree', 'add', '-b', $branch, $worktreePath, 'HEAD');

    return $worktreePath;
}

it('discovers shared-setup as hidden script', function () {
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);

    expect($scripts)->toHaveKey('worktree:shared-setup')
        ->and($scripts['worktree:shared-setup']->hidden)->toBeTrue()
        ->and($scripts['worktree:shared-setup']->description)->toBe('Set up shared directories via symlinks in a worktree');
});

it('skips shared dirs when sharedDirs is not configured', function () {
    $worktreePath = createWorktreeForSharedSetup($this->testDir, $this->worktreesDir, 'feature/no-shared');

    $executor = app(ScriptExecutor::class);
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);
    $script = $scripts['worktree:shared-setup'];

    $result = $executor->execute($script, [
        'WORKTREE_PATH' => $worktreePath,
        'BASE_PATH' => $this->worktreesDir,
        'PROJECT_PATH' => $this->testDir,
    ]);

    $sharedPath = $this->worktreesDir.'/.shared';

    expect($result->success)->toBeTrue()
        ->and(is_link($worktreePath.'/vendor'))->toBeFalse()
        ->and(is_link($worktreePath.'/node_modules'))->toBeFalse()
        ->and(is_link($worktreePath.'/storage'))->toBeFalse()
        ->and(is_dir($sharedPath))->toBeFalse();
});

it('creates symlinks when sharedDirs is configured', function () {
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => ['sharedDirs' => 'vendor node_modules storage'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $worktreePath = createWorktreeForSharedSetup($this->testDir, $this->worktreesDir, 'feature/symlink-test');

    $executor = app(ScriptExecutor::class);
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);
    $script = $scripts['worktree:shared-setup'];

    $result = $executor->execute($script, [
        'WORKTREE_PATH' => $worktreePath,
        'BASE_PATH' => $this->worktreesDir,
        'PROJECT_PATH' => $this->testDir,
    ]);

    $sharedPath = $this->worktreesDir.'/.shared';

    expect($result->success)->toBeTrue()
        ->and(is_link($worktreePath.'/vendor'))->toBeTrue()
        ->and(is_link($worktreePath.'/node_modules'))->toBeTrue()
        ->and(is_link($worktreePath.'/storage'))->toBeTrue()
        ->and(is_dir($sharedPath.'/vendor'))->toBeTrue()
        ->and(is_dir($sharedPath.'/node_modules'))->toBeTrue()
        ->and(is_dir($sharedPath.'/storage'))->toBeTrue();
});

it('seeds shared dir from worktree when shared dir is empty', function () {
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => ['sharedDirs' => 'vendor node_modules storage'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $worktreePath = createWorktreeForSharedSetup($this->testDir, $this->worktreesDir, 'feature/seed-test');

    mkdir($worktreePath.'/vendor/autoload', 0755, true);
    file_put_contents($worktreePath.'/vendor/autoload.php', '<?php // autoload');

    $executor = app(ScriptExecutor::class);
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);
    $script = $scripts['worktree:shared-setup'];

    $result = $executor->execute($script, [
        'WORKTREE_PATH' => $worktreePath,
        'BASE_PATH' => $this->worktreesDir,
        'PROJECT_PATH' => $this->testDir,
    ]);

    $sharedPath = $this->worktreesDir.'/.shared';

    expect($result->success)->toBeTrue()
        ->and(is_link($worktreePath.'/vendor'))->toBeTrue()
        ->and(file_exists($sharedPath.'/vendor/autoload.php'))->toBeTrue()
        ->and(file_get_contents($sharedPath.'/vendor/autoload.php'))->toBe('<?php // autoload')
        ->and(is_dir($sharedPath.'/vendor/autoload'))->toBeTrue();
});

it('does not overwrite existing shared dir content', function () {
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => ['sharedDirs' => 'vendor node_modules storage'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $sharedPath = $this->worktreesDir.'/.shared';
    mkdir($sharedPath.'/vendor', 0755, true);
    file_put_contents($sharedPath.'/vendor/existing.txt', 'existing content');

    $worktreePath = createWorktreeForSharedSetup($this->testDir, $this->worktreesDir, 'feature/no-overwrite');

    mkdir($worktreePath.'/vendor', 0755, true);
    file_put_contents($worktreePath.'/vendor/new.txt', 'new content');

    $executor = app(ScriptExecutor::class);
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);
    $script = $scripts['worktree:shared-setup'];

    $result = $executor->execute($script, [
        'WORKTREE_PATH' => $worktreePath,
        'BASE_PATH' => $this->worktreesDir,
        'PROJECT_PATH' => $this->testDir,
    ]);

    expect($result->success)->toBeTrue()
        ->and(file_exists($sharedPath.'/vendor/existing.txt'))->toBeTrue()
        ->and(file_get_contents($sharedPath.'/vendor/existing.txt'))->toBe('existing content')
        ->and(file_exists($sharedPath.'/vendor/new.txt'))->toBeFalse();
});

it('uses custom shared dirs from settings', function () {
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => ['sharedDirs' => 'vendor storage'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $worktreePath = createWorktreeForSharedSetup($this->testDir, $this->worktreesDir, 'feature/custom-dirs');

    $executor = app(ScriptExecutor::class);
    $loader = new ScriptLoader;
    $scripts = $loader->discover($this->testDir);
    $script = $scripts['worktree:shared-setup'];

    $result = $executor->execute($script, [
        'WORKTREE_PATH' => $worktreePath,
        'BASE_PATH' => $this->worktreesDir,
        'PROJECT_PATH' => $this->testDir,
    ]);

    $sharedPath = $this->worktreesDir.'/.shared';

    expect($result->success)->toBeTrue()
        ->and(is_link($worktreePath.'/vendor'))->toBeTrue()
        ->and(is_link($worktreePath.'/storage'))->toBeTrue()
        ->and(is_link($worktreePath.'/node_modules'))->toBeFalse()
        ->and(is_dir($sharedPath.'/vendor'))->toBeTrue()
        ->and(is_dir($sharedPath.'/storage'))->toBeTrue();
});

it('is callable from parent script via runner:script', function () {
    file_put_contents(
        $this->testDir.'/.laracode/settings.json',
        json_encode([
            'worktrees' => [
                'basePath' => './worktrees',
                'sharedDirs' => 'vendor node_modules',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $this->artisan('worktree:add', [
        'branch' => 'feature/parent-call-test',
        '--source' => 'HEAD',
    ])->assertSuccessful();

    $worktreePath = $this->worktreesDir.'/parent-call-test';
    $sharedPath = $this->worktreesDir.'/.shared';

    expect(is_dir($worktreePath))->toBeTrue()
        ->and(is_link($worktreePath.'/vendor'))->toBeTrue()
        ->and(is_dir($sharedPath.'/vendor'))->toBeTrue();
});
