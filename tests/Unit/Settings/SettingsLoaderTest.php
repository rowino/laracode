<?php

declare(strict_types=1);

use App\Services\Settings\SettingsLoader;

beforeEach(function () {
    $this->loader = new SettingsLoader;
    $this->tempDir = sys_get_temp_dir().'/settings_loader_test_'.uniqid();
    mkdir($this->tempDir, 0755, true);
    mkdir($this->tempDir.'/.laracode', 0755, true);
});

afterEach(function () {
    $files = [
        $this->tempDir.'/.laracode/settings.json',
        $this->tempDir.'/.laracode/settings.local.json',
    ];
    foreach ($files as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
    if (is_dir($this->tempDir.'/.laracode')) {
        rmdir($this->tempDir.'/.laracode');
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

describe('loadFile', function () {
    it('loads valid JSON file', function () {
        $filePath = $this->tempDir.'/.laracode/settings.json';
        file_put_contents($filePath, json_encode(['watch' => ['paths' => ['src/']]]));

        $result = $this->loader->loadFile($filePath);

        expect($result)->toBe(['watch' => ['paths' => ['src/']]]);
    });

    it('returns empty array for non-existent file', function () {
        $result = $this->loader->loadFile($this->tempDir.'/nonexistent.json');

        expect($result)->toBe([]);
    });

    it('returns empty array for invalid JSON', function () {
        $filePath = $this->tempDir.'/.laracode/settings.json';
        file_put_contents($filePath, 'not valid json');

        $result = $this->loader->loadFile($filePath);

        expect($result)->toBe([]);
    });

    it('returns empty array for non-array JSON', function () {
        $filePath = $this->tempDir.'/.laracode/settings.json';
        file_put_contents($filePath, '"just a string"');

        $result = $this->loader->loadFile($filePath);

        expect($result)->toBe([]);
    });

    it('returns empty array for unreadable file', function () {
        $result = $this->loader->loadFile('/root/unreadable/settings.json');

        expect($result)->toBe([]);
    });
});

describe('deepMerge', function () {
    it('merges simple arrays', function () {
        $result = $this->loader->deepMerge(
            ['a' => 1],
            ['b' => 2]
        );

        expect($result)->toBe(['a' => 1, 'b' => 2]);
    });

    it('overwrites scalar values', function () {
        $result = $this->loader->deepMerge(
            ['a' => 1],
            ['a' => 2]
        );

        expect($result)->toBe(['a' => 2]);
    });

    it('replaces list arrays instead of merging', function () {
        $result = $this->loader->deepMerge(
            ['paths' => ['app/', 'routes/']],
            ['paths' => ['src/']]
        );

        expect($result)->toBe(['paths' => ['src/']]);
    });

    it('deep merges associative arrays', function () {
        $result = $this->loader->deepMerge(
            ['watch' => ['paths' => ['app/'], 'mode' => 'interactive']],
            ['watch' => ['searchWord' => '@ai']]
        );

        expect($result)->toBe([
            'watch' => [
                'paths' => ['app/'],
                'mode' => 'interactive',
                'searchWord' => '@ai',
            ],
        ]);
    });

    it('handles multiple arrays', function () {
        $result = $this->loader->deepMerge(
            ['a' => 1],
            ['b' => 2],
            ['c' => 3]
        );

        expect($result)->toBe(['a' => 1, 'b' => 2, 'c' => 3]);
    });

    it('handles empty arrays', function () {
        $result = $this->loader->deepMerge(
            ['a' => 1],
            [],
            ['b' => 2]
        );

        expect($result)->toBe(['a' => 1, 'b' => 2]);
    });

    it('respects precedence order (later wins)', function () {
        $result = $this->loader->deepMerge(
            ['watch' => ['mode' => 'interactive']],
            ['watch' => ['mode' => 'accept']],
            ['watch' => ['mode' => 'yolo']]
        );

        expect($result['watch']['mode'])->toBe('yolo');
    });
});
