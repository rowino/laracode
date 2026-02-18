<?php

declare(strict_types=1);

use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/laracode-settings-show-'.uniqid();
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

describe('settings:show (no key)', function () {
    it('displays all flattened settings with source', function () {
        Artisan::call('settings:show');
        $output = Artisan::output();

        expect($output)
            ->toContain('watch.paths')
            ->toContain('watch.mode')
            ->toContain('default');
    });

    it('shows project as source when project overrides default', function () {
        file_put_contents($this->tempDir.'/.laracode/settings.json', json_encode([
            'watch' => ['mode' => 'yolo'],
        ]));

        Artisan::call('settings:show');
        $output = Artisan::output();

        expect($output)
            ->toContain('watch.mode')
            ->toContain('yolo')
            ->toContain('project');
    });

    it('shows local as source when local overrides project', function () {
        file_put_contents($this->tempDir.'/.laracode/settings.json', json_encode([
            'watch' => ['mode' => 'interactive'],
        ]));
        file_put_contents($this->tempDir.'/.laracode/settings.local.json', json_encode([
            'watch' => ['mode' => 'yolo'],
        ]));

        Artisan::call('settings:show');
        $output = Artisan::output();

        expect($output)
            ->toContain('watch.mode')
            ->toContain('yolo')
            ->toContain('local');
    });

    it('formats array values as JSON', function () {
        Artisan::call('settings:show');
        $output = Artisan::output();

        expect($output)
            ->toContain('watch.excludePatterns')
            ->toContain('**/.idea/**');
    });
});

describe('settings:show {key}', function () {
    it('displays all 4 layers for a given key', function () {
        file_put_contents($this->tempDir.'/.laracode/settings.json', json_encode([
            'watch' => ['mode' => 'accept'],
        ]));

        Artisan::call('settings:show', ['key' => 'watch.mode']);
        $output = Artisan::output();

        expect($output)
            ->toContain('default')
            ->toContain('user')
            ->toContain('project')
            ->toContain('local');
    });

    it('shows em dash for unset layers', function () {
        Artisan::call('settings:show', ['key' => 'watch.mode']);
        $output = Artisan::output();

        expect($output)->toContain("\u{2014}");
    });

    it('fails for completely unknown key', function () {
        $exitCode = Artisan::call('settings:show', ['key' => 'nonexistent.key']);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('not set in any layer');
    });

    it('shows correct active layer when local overrides', function () {
        file_put_contents($this->tempDir.'/.laracode/settings.json', json_encode([
            'watch' => ['mode' => 'interactive'],
        ]));
        file_put_contents($this->tempDir.'/.laracode/settings.local.json', json_encode([
            'watch' => ['mode' => 'yolo'],
        ]));

        Artisan::call('settings:show', ['key' => 'watch.mode']);
        $output = Artisan::output();

        expect($output)
            ->toContain('yolo')
            ->toContain('interactive');
    });
});
