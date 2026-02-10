<?php

declare(strict_types=1);

use App\Services\Settings\Dto\WatchSettings;
use App\Services\Settings\SettingsLoader;
use App\Services\Settings\SettingsService;

function deleteSettingsTestDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir.'/'.$item;
        is_dir($path) ? deleteSettingsTestDir($path) : @unlink($path);
    }
    @rmdir($dir);
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/settings_service_test_'.uniqid();
    mkdir($this->tempDir, 0755, true);
    mkdir($this->tempDir.'/.laracode', 0755, true);

    $this->loader = new SettingsLoader;
    $this->service = new SettingsService($this->loader);
    $this->service->setProjectPath($this->tempDir);
});

afterEach(function () {
    deleteSettingsTestDir($this->tempDir);
});

describe('get', function () {
    it('retrieves top-level value', function () {
        $settingsPath = $this->tempDir.'/.laracode/settings.json';
        file_put_contents($settingsPath, json_encode([
            'watch' => ['mode' => 'yolo'],
        ]));

        $this->service->reload();
        $result = $this->service->get('watch');

        expect($result['mode'])->toBe('yolo');
    });

    it('retrieves nested value with dot notation', function () {
        $settingsPath = $this->tempDir.'/.laracode/settings.json';
        file_put_contents($settingsPath, json_encode([
            'watch' => ['mode' => 'yolo', 'paths' => ['src/']],
        ]));

        $this->service->reload();

        expect($this->service->get('watch.mode'))->toBe('yolo')
            ->and($this->service->get('watch.paths'))->toBe(['src/']);
    });

    it('returns default for missing key', function () {
        $result = $this->service->get('nonexistent', 'default_value');

        expect($result)->toBe('default_value');
    });

    it('returns null for missing key without default', function () {
        $result = $this->service->get('nonexistent');

        expect($result)->toBeNull();
    });
});

describe('all', function () {
    it('returns all merged settings', function () {
        $settingsPath = $this->tempDir.'/.laracode/settings.json';
        file_put_contents($settingsPath, json_encode([
            'watch' => ['mode' => 'interactive'],
            'custom' => ['key' => 'value'],
        ]));

        $this->service->reload();
        $result = $this->service->all();

        expect($result)->toHaveKey('watch')
            ->and($result)->toHaveKey('custom')
            ->and($result['custom']['key'])->toBe('value');
    });

    it('caches settings on subsequent calls', function () {
        $settingsPath = $this->tempDir.'/.laracode/settings.json';
        file_put_contents($settingsPath, json_encode([
            'watch' => ['mode' => 'interactive'],
        ]));

        $this->service->reload();
        $first = $this->service->all();
        file_put_contents($settingsPath, json_encode([
            'watch' => ['mode' => 'yolo'],
        ]));
        $second = $this->service->all();

        expect($first)->toBe($second)
            ->and($first['watch']['mode'])->toBe('interactive');
    });
});

describe('watch', function () {
    it('returns WatchSettings DTO', function () {
        $result = $this->service->watch();

        expect($result)->toBeInstanceOf(WatchSettings::class);
    });

    it('returns WatchSettings with project settings', function () {
        $settingsPath = $this->tempDir.'/.laracode/settings.json';
        file_put_contents($settingsPath, json_encode([
            'watch' => [
                'paths' => ['custom/'],
                'searchWord' => '@todo',
                'mode' => 'yolo',
            ],
        ]));

        $this->service->reload();
        $result = $this->service->watch();

        expect($result->paths)->toBe(['custom/'])
            ->and($result->searchWord)->toBe('@todo')
            ->and($result->mode)->toBe('yolo');
    });

    it('uses defaults when no watch settings', function () {
        $result = $this->service->watch();

        expect($result->paths)->toBe([])
            ->and($result->searchWord)->toBe('')
            ->and($result->stopWord)->toBe('')
            ->and($result->mode)->toBe('interactive');
    });
});

describe('reload', function () {
    it('clears cache and reloads settings', function () {
        $settingsPath = $this->tempDir.'/.laracode/settings.json';
        file_put_contents($settingsPath, json_encode([
            'watch' => ['mode' => 'interactive'],
        ]));

        $this->service->reload();
        $first = $this->service->get('watch.mode');
        file_put_contents($settingsPath, json_encode([
            'watch' => ['mode' => 'yolo'],
        ]));
        $this->service->reload();
        $second = $this->service->get('watch.mode');

        expect($first)->toBe('interactive')
            ->and($second)->toBe('yolo');
    });
});

describe('setProjectPath', function () {
    it('changes project path and clears cache', function () {
        $otherDir = sys_get_temp_dir().'/settings_service_other_'.uniqid();
        mkdir($otherDir, 0755, true);
        mkdir($otherDir.'/.laracode', 0755, true);

        $settingsPath = $otherDir.'/.laracode/settings.json';
        file_put_contents($settingsPath, json_encode([
            'watch' => ['mode' => 'other_project'],
        ]));

        $this->service->setProjectPath($otherDir);
        $result = $this->service->get('watch.mode');

        expect($result)->toBe('other_project');

        deleteSettingsTestDir($otherDir);
    });
});

describe('layers', function () {
    it('returns 4-key associative array', function () {
        $result = $this->service->layers();

        expect($result)
            ->toBeArray()
            ->toHaveKeys(['default', 'user', 'project', 'local']);
    });

    it('returns each layer independently without merging', function () {
        $projectFile = $this->tempDir.'/.laracode/settings.json';
        $localFile = $this->tempDir.'/.laracode/settings.local.json';

        file_put_contents($projectFile, json_encode(['watch' => ['mode' => 'accept']]));
        file_put_contents($localFile, json_encode(['watch' => ['mode' => 'yolo']]));

        $this->service->reload();
        $result = $this->service->layers();

        expect($result['project'])->toBe(['watch' => ['mode' => 'accept']])
            ->and($result['local'])->toBe(['watch' => ['mode' => 'yolo']]);
    });

    it('returns default layer from config', function () {
        $result = $this->service->layers();

        expect($result['default'])
            ->toBeArray()
            ->toHaveKey('watch');
    });

    it('returns empty arrays for missing layer files', function () {
        $result = $this->service->layers();

        expect($result['project'])->toBe([])
            ->and($result['local'])->toBe([]);
    });
});

describe('loadAll', function () {
    it('loads and merges project and local settings', function () {
        $projectPath = $this->tempDir.'/.laracode/settings.json';
        $localPath = $this->tempDir.'/.laracode/settings.local.json';

        file_put_contents($projectPath, json_encode([
            'watch' => ['paths' => ['app/'], 'mode' => 'interactive'],
        ]));
        file_put_contents($localPath, json_encode([
            'watch' => ['mode' => 'yolo'],
        ]));

        $this->service->reload();
        $result = $this->service->all();

        expect($result['watch']['paths'])->toBe(['app/'])
            ->and($result['watch']['mode'])->toBe('yolo');
    });

    it('uses defaults when no settings files exist', function () {
        $result = $this->service->all();

        expect($result)->toHaveKey('watch')
            ->and($result['watch'])->toHaveKey('paths')
            ->and($result['watch'])->toHaveKey('mode');
    });

    it('local settings override project settings', function () {
        $projectPath = $this->tempDir.'/.laracode/settings.json';
        $localPath = $this->tempDir.'/.laracode/settings.local.json';

        file_put_contents($projectPath, json_encode([
            'watch' => ['searchWord' => '@project'],
        ]));
        file_put_contents($localPath, json_encode([
            'watch' => ['searchWord' => '@local'],
        ]));

        $this->service->reload();
        $result = $this->service->all();

        expect($result['watch']['searchWord'])->toBe('@local');
    });
});
