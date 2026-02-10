<?php

declare(strict_types=1);

use App\Services\Settings\Dto\WatchSettings;

describe('constructor defaults', function () {
    it('has correct default values', function () {
        $settings = new WatchSettings;

        expect($settings->paths)->toBe([])
            ->and($settings->searchWord)->toBe('')
            ->and($settings->stopWord)->toBe('')
            ->and($settings->mode)->toBe('interactive')
            ->and($settings->excludePatterns)->toBe([]);
    });

    it('accepts custom values', function () {
        $settings = new WatchSettings(
            paths: ['src/'],
            searchWord: '@todo',
            stopWord: 'done!',
            mode: 'yolo',
            excludePatterns: ['**/test/**']
        );

        expect($settings->paths)->toBe(['src/'])
            ->and($settings->searchWord)->toBe('@todo')
            ->and($settings->stopWord)->toBe('done!')
            ->and($settings->mode)->toBe('yolo')
            ->and($settings->excludePatterns)->toBe(['**/test/**']);
    });
});

describe('fromArray', function () {
    it('creates instance from array', function () {
        $settings = WatchSettings::fromArray([
            'paths' => ['custom/'],
            'searchWord' => '@fix',
            'stopWord' => 'fixed!',
            'mode' => 'accept',
            'excludePatterns' => ['**/vendor/**'],
        ]);

        expect($settings->paths)->toBe(['custom/'])
            ->and($settings->searchWord)->toBe('@fix')
            ->and($settings->stopWord)->toBe('fixed!')
            ->and($settings->mode)->toBe('accept')
            ->and($settings->excludePatterns)->toBe(['**/vendor/**']);
    });

    it('uses defaults for missing keys', function () {
        $settings = WatchSettings::fromArray([]);

        expect($settings->paths)->toBe([])
            ->and($settings->searchWord)->toBe('')
            ->and($settings->stopWord)->toBe('')
            ->and($settings->mode)->toBe('interactive')
            ->and($settings->excludePatterns)->toBe([]);
    });

    it('uses defaults for invalid types', function () {
        $settings = WatchSettings::fromArray([
            'paths' => 'not an array',
            'searchWord' => 123,
            'stopWord' => null,
            'mode' => [],
            'excludePatterns' => 'not an array',
        ]);

        expect($settings->paths)->toBe([])
            ->and($settings->searchWord)->toBe('')
            ->and($settings->stopWord)->toBe('')
            ->and($settings->mode)->toBe('interactive')
            ->and($settings->excludePatterns)->toBe([]);
    });

    it('filters non-string values from arrays', function () {
        $settings = WatchSettings::fromArray([
            'paths' => ['valid/', 123, null, 'also-valid/'],
            'excludePatterns' => ['**/test/**', 456, '**/vendor/**'],
        ]);

        expect($settings->paths)->toBe(['valid/', 'also-valid/'])
            ->and($settings->excludePatterns)->toBe(['**/test/**', '**/vendor/**']);
    });

    it('handles partial data', function () {
        $settings = WatchSettings::fromArray([
            'searchWord' => '@custom',
        ]);

        expect($settings->searchWord)->toBe('@custom')
            ->and($settings->paths)->toBe([])
            ->and($settings->mode)->toBe('interactive');
    });
});

describe('withOverrides', function () {
    it('creates new instance with overridden values', function () {
        $original = new WatchSettings(
            paths: ['app/'],
            searchWord: '@claude',
            stopWord: 'claude!',
            mode: 'interactive'
        );

        $overridden = $original->withOverrides([
            'mode' => 'yolo',
            'searchWord' => '@todo',
        ]);

        expect($overridden->mode)->toBe('yolo')
            ->and($overridden->searchWord)->toBe('@todo')
            ->and($overridden->paths)->toBe(['app/'])
            ->and($overridden->stopWord)->toBe('claude!');
    });

    it('does not modify original instance', function () {
        $original = new WatchSettings(mode: 'interactive');

        $original->withOverrides(['mode' => 'yolo']);

        expect($original->mode)->toBe('interactive');
    });

    it('ignores empty overrides', function () {
        $original = new WatchSettings(paths: ['app/']);

        $overridden = $original->withOverrides([]);

        expect($overridden->paths)->toBe(['app/']);
    });

    it('ignores invalid override types', function () {
        $original = new WatchSettings(
            paths: ['app/'],
            searchWord: '@claude',
            mode: 'interactive'
        );

        $overridden = $original->withOverrides([
            'paths' => 'not array',
            'searchWord' => 123,
            'mode' => [],
        ]);

        expect($overridden->paths)->toBe(['app/'])
            ->and($overridden->searchWord)->toBe('@claude')
            ->and($overridden->mode)->toBe('interactive');
    });

    it('ignores empty paths array override', function () {
        $original = new WatchSettings(paths: ['app/']);

        $overridden = $original->withOverrides(['paths' => []]);

        expect($overridden->paths)->toBe(['app/']);
    });

    it('replaces paths when provided non-empty', function () {
        $original = new WatchSettings(paths: ['app/', 'routes/']);

        $overridden = $original->withOverrides(['paths' => ['src/']]);

        expect($overridden->paths)->toBe(['src/']);
    });

    it('merges excludePatterns instead of replacing', function () {
        $original = new WatchSettings(excludePatterns: ['**/vendor/**']);

        $overridden = $original->withOverrides([
            'excludePatterns' => ['**/node_modules/**'],
        ]);

        expect($overridden->excludePatterns)->toBe([
            '**/vendor/**',
            '**/node_modules/**',
        ]);
    });

    it('deduplicates merged excludePatterns', function () {
        $original = new WatchSettings(excludePatterns: ['**/vendor/**', '**/test/**']);

        $overridden = $original->withOverrides([
            'excludePatterns' => ['**/vendor/**', '**/node_modules/**'],
        ]);

        expect($overridden->excludePatterns)->toBe([
            '**/vendor/**',
            '**/test/**',
            '**/node_modules/**',
        ]);
    });
});

describe('isConfigured', function () {
    it('returns false when both are empty', function () {
        $settings = new WatchSettings;

        expect($settings->isConfigured())->toBeFalse();
    });

    it('returns false when only searchWord is set', function () {
        $settings = new WatchSettings(searchWord: '@ai');

        expect($settings->isConfigured())->toBeFalse();
    });

    it('returns false when only stopWord is set', function () {
        $settings = new WatchSettings(stopWord: 'ai!');

        expect($settings->isConfigured())->toBeFalse();
    });

    it('returns true when both are set', function () {
        $settings = new WatchSettings(searchWord: '@ai', stopWord: 'ai!');

        expect($settings->isConfigured())->toBeTrue();
    });
});

describe('immutability', function () {
    it('is readonly', function () {
        $settings = new WatchSettings;

        $reflection = new ReflectionClass($settings);
        expect($reflection->isReadOnly())->toBeTrue();
    });
});
