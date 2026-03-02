<?php

declare(strict_types=1);

use App\Agents\AgentRegistry;
use App\Commands\InitCommand;
use App\Init\InitContext;
use App\Init\InitPipeline;
use App\Services\Settings\SettingsPath;
use App\Services\Settings\SettingsWriter;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testPath = realpath(sys_get_temp_dir()).'/laracode-test-'.uniqid();
    mkdir($this->testPath, 0755, true);

    $this->userSettingsBackup = null;
    if (file_exists(SettingsPath::user())) {
        $this->userSettingsBackup = file_get_contents(SettingsPath::user());
    }

    $userSettingsDir = dirname(SettingsPath::user());
    if (! is_dir($userSettingsDir)) {
        mkdir($userSettingsDir, 0755, true);
    }
    file_put_contents(SettingsPath::user(), json_encode([
        'agents' => ['default' => 'claude', 'paths' => ['claude' => '/usr/bin/claude']],
        'defaultMode' => 'interactive',
    ]));

    $this->registerMockPipeline = function (?InitContext &$captured): void {
        $mock = Mockery::mock(InitPipeline::class);
        $mock->shouldReceive('run')->andReturnUsing(function (InitContext $ctx) use (&$captured) {
            $captured = $ctx;
        });

        $command = new InitCommand(
            $mock,
            app(AgentRegistry::class),
            app(SettingsWriter::class),
        );
        $command->setLaravel(app());
        app('Illuminate\Contracts\Console\Kernel')->registerCommand($command);
    };
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }

    if ($this->userSettingsBackup !== null) {
        file_put_contents(SettingsPath::user(), $this->userSettingsBackup);
    } elseif (file_exists(SettingsPath::user())) {
        @unlink(SettingsPath::user());
    }
});

it('fails when path does not exist', function () {
    $this->artisan('init', ['path' => '/nonexistent/path/that/does/not/exist'])
        ->assertFailed();
});

it('shows success message after pipeline completes', function () {
    $ctx = null;
    ($this->registerMockPipeline)($ctx);

    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful()
        ->expectsOutputToContain('LaraCode initialized');
});

it('runs pipeline with correct context for existing setup', function () {
    $ctx = null;
    ($this->registerMockPipeline)($ctx);

    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful();

    expect($ctx)->toBeInstanceOf(InitContext::class)
        ->and($ctx->projectPath)->toBe($this->testPath)
        ->and($ctx->isFirstTimeSetup)->toBeFalse()
        ->and($ctx->hasAgent)->toBeTrue()
        ->and($ctx->agent)->not->toBeNull();
});

it('detects first-time setup when no user settings exist', function () {
    @unlink(SettingsPath::user());

    $ctx = null;
    ($this->registerMockPipeline)($ctx);

    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful();

    expect($ctx)->toBeInstanceOf(InitContext::class)
        ->and($ctx->isFirstTimeSetup)->toBeTrue();
});

it('detects existing setup when user settings exist', function () {
    $ctx = null;
    ($this->registerMockPipeline)($ctx);

    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful();

    expect($ctx)->toBeInstanceOf(InitContext::class)
        ->and($ctx->isFirstTimeSetup)->toBeFalse();
});

it('uses current directory when no path provided', function () {
    $originalDir = getcwd();
    chdir($this->testPath);

    $ctx = null;
    ($this->registerMockPipeline)($ctx);

    try {
        $this->artisan('init')
            ->assertSuccessful();

        expect($ctx)->toBeInstanceOf(InitContext::class)
            ->and($ctx->projectPath)->toBe($this->testPath);
    } finally {
        chdir($originalDir);
    }
});

it('passes settings writer to context', function () {
    $ctx = null;
    ($this->registerMockPipeline)($ctx);

    $this->artisan('init', ['path' => $this->testPath])
        ->assertSuccessful();

    expect($ctx)->toBeInstanceOf(InitContext::class)
        ->and($ctx->settingsWriter)->toBeInstanceOf(SettingsWriter::class);
});

it('resolves real path for project directory', function () {
    $symlinkPath = $this->testPath.'-link';
    symlink($this->testPath, $symlinkPath);

    $ctx = null;
    ($this->registerMockPipeline)($ctx);

    try {
        $this->artisan('init', ['path' => $symlinkPath])
            ->assertSuccessful();

        expect($ctx)->toBeInstanceOf(InitContext::class)
            ->and($ctx->projectPath)->toBe($this->testPath);
    } finally {
        @unlink($symlinkPath);
    }
});
