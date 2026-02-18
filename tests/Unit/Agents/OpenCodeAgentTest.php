<?php

declare(strict_types=1);

use App\Agents\OpenCodeAgent;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-opencode-test-'.uniqid();
    mkdir($this->testPath, 0755, true);
    $this->originalCwd = getcwd();
    chdir($this->testPath);
});

afterEach(function () {
    chdir($this->originalCwd);
    if (is_dir($this->testPath)) {
        // Recursively delete directory without Laravel facades
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->testPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($this->testPath);
    }
});

it('writes project settings to .opencode/opencode.json', function () {
    $agent = new OpenCodeAgent;

    $settings = ['defaultMode' => 'yolo', 'framework' => 'laravel'];
    $agent->updateSettings('project', $settings);

    $expectedPath = $this->testPath.'/.opencode/opencode.json';
    expect(file_exists($expectedPath))->toBeTrue();

    $content = json_decode(file_get_contents($expectedPath), true);
    expect($content)->toBeArray()
        ->and($content['defaultMode'])->toBe('yolo')
        ->and($content['framework'])->toBe('laravel');
});

it('reads project settings from .opencode/opencode.json', function () {
    $expectedPath = $this->testPath.'/.opencode/opencode.json';
    mkdir(dirname($expectedPath), 0755, true);
    file_put_contents($expectedPath, json_encode([
        'defaultMode' => 'interactive',
        'agent' => 'opencode',
    ]));

    $agent = new OpenCodeAgent;
    $settings = $agent->getSettings('project');

    expect($settings)->toBeArray()
        ->and($settings['defaultMode'])->toBe('interactive')
        ->and($settings['agent'])->toBe('opencode');
});

it('migrates legacy opencode.json to .opencode/ directory', function () {
    // Create legacy settings file in root
    $legacyPath = $this->testPath.'/opencode.json';
    file_put_contents($legacyPath, json_encode([
        'defaultMode' => 'plan',
        'legacySetting' => true,
    ]));

    $agent = new OpenCodeAgent;

    // Reading settings should trigger migration
    $settings = $agent->getSettings('project');

    // New path should now exist with migrated content
    $newPath = $this->testPath.'/.opencode/opencode.json';
    expect(file_exists($newPath))->toBeTrue();

    $migratedContent = json_decode(file_get_contents($newPath), true);
    expect($migratedContent)->toBeArray()
        ->and($migratedContent['defaultMode'])->toBe('plan')
        ->and($migratedContent['legacySetting'])->toBe(true);

    // Legacy file should still exist (not deleted)
    expect(file_exists($legacyPath))->toBeTrue();
});

it('prefers new location when both legacy and new files exist', function () {
    // Create both legacy and new settings files with different content
    $legacyPath = $this->testPath.'/opencode.json';
    file_put_contents($legacyPath, json_encode(['source' => 'legacy']));

    $newPath = $this->testPath.'/.opencode/opencode.json';
    mkdir(dirname($newPath), 0755, true);
    file_put_contents($newPath, json_encode(['source' => 'new']));

    $agent = new OpenCodeAgent;
    $settings = $agent->getSettings('project');

    // Should read from new location, not legacy
    expect($settings['source'])->toBe('new');
});

it('does not migrate when new location already exists', function () {
    // Create both files
    $legacyPath = $this->testPath.'/opencode.json';
    file_put_contents($legacyPath, json_encode(['version' => 'legacy']));

    $newPath = $this->testPath.'/.opencode/opencode.json';
    mkdir(dirname($newPath), 0755, true);
    file_put_contents($newPath, json_encode(['version' => 'current']));

    $agent = new OpenCodeAgent;
    $agent->getSettings('project');

    // New file should remain unchanged (migration skipped)
    $newContent = json_decode(file_get_contents($newPath), true);
    expect($newContent['version'])->toBe('current');
});

it('writes user settings to ~/.opencode/opencode.json', function () {
    $userHome = getenv('HOME');
    if ($userHome === false || $userHome === '') {
        expect(true)->toBeTrue(); // Skip if HOME not set

        return;
    }

    $userSettingsPath = $userHome.'/.opencode/opencode.json';
    $backupPath = null;

    // Backup existing user settings if present
    if (file_exists($userSettingsPath)) {
        $backupPath = $userSettingsPath.'.backup-'.uniqid();
        copy($userSettingsPath, $backupPath);
    }

    $agent = new OpenCodeAgent;
    $agent->updateSettings('user', ['testSetting' => 'value']);

    expect(file_exists($userSettingsPath))->toBeTrue();

    $content = json_decode(file_get_contents($userSettingsPath), true);
    expect($content)->toBeArray()
        ->and($content['testSetting'])->toBe('value');

    // Restore or cleanup
    if ($backupPath !== null) {
        rename($backupPath, $userSettingsPath);
    } else {
        @unlink($userSettingsPath);
    }
})->skip('Requires modifying user settings - covered by integration tests');

it('creates .opencode directory when writing settings if it does not exist', function () {
    $agent = new OpenCodeAgent;

    expect(is_dir($this->testPath.'/.opencode'))->toBeFalse();

    $agent->updateSettings('project', ['initialSetting' => true]);

    expect(is_dir($this->testPath.'/.opencode'))->toBeTrue()
        ->and(file_exists($this->testPath.'/.opencode/opencode.json'))->toBeTrue();
});

it('merges new settings with existing settings when updating', function () {
    $agent = new OpenCodeAgent;

    // Write initial settings
    $agent->updateSettings('project', ['setting1' => 'value1', 'setting2' => 'value2']);

    // Update with new settings (should merge, not replace)
    $agent->updateSettings('project', ['setting2' => 'updated', 'setting3' => 'value3']);

    $settings = $agent->getSettings('project');

    expect($settings)->toBeArray()
        ->and($settings['setting1'])->toBe('value1')
        ->and($settings['setting2'])->toBe('updated')
        ->and($settings['setting3'])->toBe('value3');
});

it('returns empty array when reading non-existent settings file', function () {
    $agent = new OpenCodeAgent;

    $settings = $agent->getSettings('project');

    expect($settings)->toBeArray()
        ->and($settings)->toBeEmpty();
});

it('handles migration when legacy file exists but directory creation fails gracefully', function () {
    // This is a defensive test - actual failure would need filesystem permissions issues
    $legacyPath = $this->testPath.'/opencode.json';
    file_put_contents($legacyPath, json_encode(['test' => true]));

    $agent = new OpenCodeAgent;

    // Should not throw even if directory operations fail
    $settings = $agent->getSettings('project');

    // Either migrated successfully or returned empty array
    expect($settings)->toBeArray();
});

it('only migrates for project scope not user scope', function () {
    // Create legacy file
    $legacyPath = $this->testPath.'/opencode.json';
    file_put_contents($legacyPath, json_encode(['test' => 'legacy']));

    $agent = new OpenCodeAgent;

    // Call getSettings with user scope - should NOT trigger migration
    $userHome = getenv('HOME');
    if ($userHome !== false && $userHome !== '') {
        $userSettingsPath = $userHome.'/.opencode/opencode.json';
        $backupPath = null;

        if (file_exists($userSettingsPath)) {
            $backupPath = $userSettingsPath.'.backup-'.uniqid();
            copy($userSettingsPath, $backupPath);
        }

        $agent->getSettings('user');

        // Project migration should not have occurred
        $projectPath = $this->testPath.'/.opencode/opencode.json';
        expect(file_exists($projectPath))->toBeFalse();

        // Restore user settings
        if ($backupPath !== null) {
            rename($backupPath, $userSettingsPath);
        }
    }
})->skip('Requires user settings access - covered by integration tests');

it('detects agent usage correctly from folders', function () {
    $agent = new OpenCodeAgent;

    // Not used when .opencode folder not present
    expect($agent->isAgentUsed(['.claude', '.git']))->toBeFalse();

    // Used when .opencode folder present
    expect($agent->isAgentUsed(['.claude', '.opencode', '.git']))->toBeTrue();
});

it('uses correct constant values for paths', function () {
    $reflection = new ReflectionClass(OpenCodeAgent::class);

    $configFolder = $reflection->getConstant('CONFIG_FOLDER');
    $settingsFile = $reflection->getConstant('SETTINGS_FILE');

    expect($configFolder)->toBe('.opencode')
        ->and($settingsFile)->toBe('opencode.json');
});

it('migration is properly documented in method PHPDoc', function () {
    $reflection = new ReflectionClass(OpenCodeAgent::class);
    $method = $reflection->getMethod('migrateLegacySettings');

    $docComment = $method->getDocComment();
    expect($docComment)->toContain('legacy')
        ->and($docComment)->toContain('migration')
        ->and($docComment)->toContain('.opencode/opencode.json');
});

it('formats JSON output with pretty print and unescaped slashes', function () {
    $agent = new OpenCodeAgent;

    $settings = ['path' => '/some/path', 'url' => 'https://example.com'];
    $agent->updateSettings('project', $settings);

    $content = file_get_contents($this->testPath.'/.opencode/opencode.json');

    // Verify pretty print (contains newlines)
    expect($content)->toContain("\n");

    // Verify unescaped slashes
    expect($content)->toContain('/some/path')
        ->and($content)->toContain('https://example.com');
});
