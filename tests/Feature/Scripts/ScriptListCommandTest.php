<?php

declare(strict_types=1);

use App\Scripts\ScriptLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-script-list-'.uniqid();
    mkdir($this->testPath.'/.laracode/scripts/deploy', 0755, true);

    $this->originalDir = getcwd();
    chdir($this->testPath);

    $this->app->singleton(ScriptLoader::class, function () {
        return new class extends ScriptLoader
        {
            protected function bundledScriptsPath(): string
            {
                return '/nonexistent';
            }
        };
    });
});

afterEach(function () {
    chdir($this->originalDir);
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

it('lists discovered scripts in a table', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/greet.yaml', Yaml::dump([
        'name' => 'greet',
        'description' => 'Say hello',
        'steps' => [['id' => 'say-hi', 'run' => 'echo hello']],
    ]));

    file_put_contents($this->testPath.'/.laracode/scripts/deploy/staging.yaml', Yaml::dump([
        'name' => 'deploy:staging',
        'description' => 'Deploy to staging',
        'steps' => [['id' => 'step1', 'run' => 'echo deploy']],
    ]));

    $this->artisan('script:list')
        ->expectsTable(
            ['Name', 'Description', 'Source'],
            [
                ['deploy:staging', 'Deploy to staging', '.laracode/scripts/deploy/staging.yaml'],
                ['greet', 'Say hello', '.laracode/scripts/greet.yaml'],
            ]
        )
        ->assertSuccessful();
});

it('excludes hidden scripts by default', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/visible.yaml', Yaml::dump([
        'name' => 'visible',
        'description' => 'Visible script',
        'steps' => [['id' => 'step1', 'run' => 'echo ok']],
    ]));

    file_put_contents($this->testPath.'/.laracode/scripts/internal.yaml', Yaml::dump([
        'name' => 'internal',
        'description' => 'Hidden helper',
        'hidden' => true,
        'steps' => [['id' => 'step1', 'run' => 'echo hidden']],
    ]));

    $this->artisan('script:list')
        ->expectsTable(
            ['Name', 'Description', 'Source'],
            [
                ['visible', 'Visible script', '.laracode/scripts/visible.yaml'],
            ]
        )
        ->assertSuccessful();
});

it('shows hidden scripts with --all flag', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/visible.yaml', Yaml::dump([
        'name' => 'visible',
        'description' => 'Visible script',
        'steps' => [['id' => 'step1', 'run' => 'echo ok']],
    ]));

    file_put_contents($this->testPath.'/.laracode/scripts/internal.yaml', Yaml::dump([
        'name' => 'internal',
        'description' => 'Hidden helper',
        'hidden' => true,
        'steps' => [['id' => 'step1', 'run' => 'echo hidden']],
    ]));

    $this->artisan('script:list', ['--all' => true])
        ->expectsTable(
            ['Name', 'Description', 'Source', 'Hidden'],
            [
                ['internal', 'Hidden helper', '.laracode/scripts/internal.yaml', 'Yes'],
                ['visible', 'Visible script', '.laracode/scripts/visible.yaml', ''],
            ]
        )
        ->assertSuccessful();
});

it('outputs JSON with --json flag', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/greet.yaml', Yaml::dump([
        'name' => 'greet',
        'description' => 'Say hello',
        'version' => 2,
        'steps' => [['id' => 'say-hi', 'run' => 'echo hello']],
    ]));

    Artisan::call('script:list', ['--json' => true]);
    $output = Artisan::output();

    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveCount(1)
        ->and($decoded[0]['name'])->toBe('greet')
        ->and($decoded[0]['description'])->toBe('Say hello')
        ->and($decoded[0]['version'])->toBe(2)
        ->and($decoded[0]['hidden'])->toBeFalse();
});

it('shows warning when no scripts found', function () {
    $this->artisan('script:list')
        ->expectsOutputToContain('No scripts found')
        ->assertSuccessful();
});

it('shows dash for empty descriptions', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/nodesc.yaml', Yaml::dump([
        'name' => 'nodesc',
        'steps' => [['id' => 'step1', 'run' => 'echo ok']],
    ]));

    $this->artisan('script:list')
        ->expectsTable(
            ['Name', 'Description', 'Source'],
            [
                ['nodesc', '-', '.laracode/scripts/nodesc.yaml'],
            ]
        )
        ->assertSuccessful();
});

it('includes hidden scripts in json when --all and --json combined', function () {
    file_put_contents($this->testPath.'/.laracode/scripts/hidden.yaml', Yaml::dump([
        'name' => 'hidden',
        'description' => 'Hidden one',
        'hidden' => true,
        'steps' => [['id' => 'step1', 'run' => 'echo ok']],
    ]));

    Artisan::call('script:list', ['--all' => true, '--json' => true]);
    $output = Artisan::output();

    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveCount(1)
        ->and($decoded[0]['name'])->toBe('hidden')
        ->and($decoded[0]['hidden'])->toBeTrue();
});
