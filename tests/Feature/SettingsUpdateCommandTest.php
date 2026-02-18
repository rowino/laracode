<?php

declare(strict_types=1);

use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/laracode-settings-update-'.uniqid();
    mkdir($this->tempDir.'/.laracode', 0755, true);

    $this->settings = app(SettingsService::class);
    $this->settings->setProjectPath($this->tempDir);
    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalDir);
    if (is_dir($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

describe('settings:update with all options', function () {
    it('writes to local scope via options', function () {
        $exitCode = Artisan::call('settings:update', [
            '--scope' => 'local',
            '--key' => 'watch.mode',
            '--value' => 'yolo',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('watch.mode')
            ->and($output)->toContain('yolo')
            ->and($output)->toContain('local');

        $localFile = $this->tempDir.'/.laracode/settings.local.json';
        expect(file_exists($localFile))->toBeTrue();

        $written = json_decode(file_get_contents($localFile), true);
        expect($written['watch']['mode'])->toBe('yolo');
    });

    it('writes to project scope via options', function () {
        $exitCode = Artisan::call('settings:update', [
            '--scope' => 'project',
            '--key' => 'watch.searchWord',
            '--value' => '@bot',
        ]);

        expect($exitCode)->toBe(0);

        $projectFile = $this->tempDir.'/.laracode/settings.json';
        $written = json_decode(file_get_contents($projectFile), true);
        expect($written['watch']['searchWord'])->toBe('@bot');
    });

    it('merges with existing settings instead of replacing', function () {
        file_put_contents($this->tempDir.'/.laracode/settings.local.json', json_encode([
            'watch' => ['mode' => 'interactive'],
            'custom' => 'value',
        ]));

        Artisan::call('settings:update', [
            '--scope' => 'local',
            '--key' => 'watch.mode',
            '--value' => 'yolo',
        ]);

        $written = json_decode(file_get_contents($this->tempDir.'/.laracode/settings.local.json'), true);
        expect($written['watch']['mode'])->toBe('yolo')
            ->and($written['custom'])->toBe('value');
    });
});

describe('settings:update JSON value parsing', function () {
    it('parses JSON array values', function () {
        Artisan::call('settings:update', [
            '--scope' => 'local',
            '--key' => 'watch.paths',
            '--value' => '["src","lib"]',
        ]);

        $written = json_decode(file_get_contents($this->tempDir.'/.laracode/settings.local.json'), true);
        expect($written['watch']['paths'])->toBe(['src', 'lib']);
    });

    it('parses JSON object values', function () {
        Artisan::call('settings:update', [
            '--scope' => 'local',
            '--key' => 'custom',
            '--value' => '{"enabled":true,"count":5}',
        ]);

        $written = json_decode(file_get_contents($this->tempDir.'/.laracode/settings.local.json'), true);
        expect($written['custom'])->toBe(['enabled' => true, 'count' => 5]);
    });

    it('parses JSON boolean true', function () {
        Artisan::call('settings:update', [
            '--scope' => 'local',
            '--key' => 'watch.enabled',
            '--value' => 'true',
        ]);

        $written = json_decode(file_get_contents($this->tempDir.'/.laracode/settings.local.json'), true);
        expect($written['watch']['enabled'])->toBeTrue();
    });

    it('parses JSON numeric values', function () {
        Artisan::call('settings:update', [
            '--scope' => 'local',
            '--key' => 'watch.interval',
            '--value' => '500',
        ]);

        $written = json_decode(file_get_contents($this->tempDir.'/.laracode/settings.local.json'), true);
        expect($written['watch']['interval'])->toBe(500);
    });

    it('keeps plain strings as strings', function () {
        Artisan::call('settings:update', [
            '--scope' => 'local',
            '--key' => 'watch.mode',
            '--value' => 'hello world',
        ]);

        $written = json_decode(file_get_contents($this->tempDir.'/.laracode/settings.local.json'), true);
        expect($written['watch']['mode'])->toBe('hello world');
    });
});

describe('settings:update validation', function () {
    it('rejects invalid scope', function () {
        $exitCode = Artisan::call('settings:update', [
            '--scope' => 'invalid',
            '--key' => 'watch.mode',
            '--value' => 'test',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Invalid scope');
    });
});

describe('settings:update reloads settings', function () {
    it('reloaded settings reflect the change', function () {
        Artisan::call('settings:update', [
            '--scope' => 'local',
            '--key' => 'watch.mode',
            '--value' => 'yolo',
        ]);

        $this->settings->reload();
        expect($this->settings->get('watch.mode'))->toBe('yolo');
    });
});
