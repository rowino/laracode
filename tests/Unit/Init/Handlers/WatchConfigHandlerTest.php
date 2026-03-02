<?php

declare(strict_types=1);

use App\Init\Handlers\WatchConfigHandler;
use App\Init\InitContext;
use App\Services\ProjectAnalyzer;
use App\Services\Settings\SettingsWriter;

beforeEach(function () {
    $this->projectAnalyzer = Mockery::mock(ProjectAnalyzer::class);
    $this->settingsWriter = Mockery::mock(SettingsWriter::class);
    $this->handler = new WatchConfigHandler($this->projectAnalyzer);
    $this->tmpDir = sys_get_temp_dir().'/watch-handler-test-'.uniqid();
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    if (is_dir($this->tmpDir)) {
        array_map('unlink', glob($this->tmpDir.'/*') ?: []);
        rmdir($this->tmpDir);
    }
});

function watchCtx(Mockery\MockInterface $sw, ?string $projectPath = null): InitContext
{
    return new InitContext(
        projectPath: $projectPath ?? sys_get_temp_dir(),
        isFirstTimeSetup: false,
        hasAgent: false,
        agent: null,
        settingsWriter: $sw,
    );
}

it('has name watch and priority 30', function () {
    expect($this->handler->name())->toBe('watch')
        ->and($this->handler->priority())->toBe(30);
});

it('returns null for decisionRequest', function () {
    $ctx = watchCtx($this->settingsWriter);

    expect($this->handler->decisionRequest($ctx))->toBeNull();
});

it('getPromptContext returns discovered scripts and watch paths', function () {
    $this->projectAnalyzer->shouldReceive('suggestWatchPaths')
        ->with($this->tmpDir)
        ->andReturn(['app', 'config']);

    $ctx = watchCtx($this->settingsWriter, $this->tmpDir);
    file_put_contents($this->tmpDir.'/composer.json', json_encode([
        'scripts' => ['test' => 'pest', 'lint' => 'pint'],
    ]));

    $result = $this->handler->getPromptContext($ctx);

    expect($result['watchPaths'])->toBe(['app', 'config'])
        ->and($result['testingCommands'])->toContain('composer test')
        ->and($result['lintingCommands'])->toContain('composer lint')
        ->and($result['packageManager'])->toBe('npm');
});

it('getPromptContext returns empty arrays when no scripts found', function () {
    $this->projectAnalyzer->shouldReceive('suggestWatchPaths')
        ->with($this->tmpDir)
        ->andReturn([]);

    $ctx = watchCtx($this->settingsWriter, $this->tmpDir);

    $result = $this->handler->getPromptContext($ctx);

    expect($result['watchPaths'])->toBe([])
        ->and($result['testingCommands'])->toBe([])
        ->and($result['lintingCommands'])->toBe([])
        ->and($result['packageManager'])->toBe('npm');
});

it('getPromptContext detects package manager from lockfile', function () {
    $this->projectAnalyzer->shouldReceive('suggestWatchPaths')
        ->with($this->tmpDir)
        ->andReturn([]);

    $ctx = watchCtx($this->settingsWriter, $this->tmpDir);
    file_put_contents($this->tmpDir.'/pnpm-lock.yaml', '');

    $result = $this->handler->getPromptContext($ctx);

    expect($result['packageManager'])->toBe('pnpm');
});

it('getPromptContext includes node scripts with correct prefix', function () {
    $this->projectAnalyzer->shouldReceive('suggestWatchPaths')
        ->with($this->tmpDir)
        ->andReturn([]);

    $ctx = watchCtx($this->settingsWriter, $this->tmpDir);
    file_put_contents($this->tmpDir.'/package.json', json_encode([
        'scripts' => ['test' => 'jest', 'lint' => 'eslint .'],
    ]));
    file_put_contents($this->tmpDir.'/yarn.lock', '');

    $result = $this->handler->getPromptContext($ctx);

    expect($result['testingCommands'])->toBe(['yarn test'])
        ->and($result['lintingCommands'])->toBe(['yarn lint'])
        ->and($result['packageManager'])->toBe('yarn');
});

it('processDecisions is a no-op', function () {
    $ctx = watchCtx($this->settingsWriter);

    $this->handler->processDecisions($ctx, ['watchPaths' => ['app']]);

    expect($ctx->handlerData)->toBe([]);
});

it('apply is a no-op', function () {
    $this->settingsWriter->shouldNotReceive('mergeProject');

    $ctx = watchCtx($this->settingsWriter);
    $ctx->handlerData['watch'] = [
        'confirmedWatchPaths' => ['app'],
        'confirmedTestingCommands' => ['composer test'],
        'confirmedLintingCommands' => [],
    ];

    $this->handler->apply($ctx);
});

it('summarize returns correct data', function () {
    $ctx = watchCtx($this->settingsWriter);
    $ctx->handlerData['watch'] = [
        'confirmedWatchPaths' => ['app', 'tests'],
        'confirmedTestingCommands' => ['composer test'],
        'confirmedLintingCommands' => ['composer lint'],
        'packageManager' => 'pnpm',
    ];

    $summary = $this->handler->summarize($ctx);

    expect($summary['Watch paths'])->toBe('app, tests')
        ->and($summary['Testing'])->toBe('composer test')
        ->and($summary['Linting'])->toBe('composer lint')
        ->and($summary['Package manager'])->toBe('pnpm');
});

it('summarize returns none for empty data', function () {
    $ctx = watchCtx($this->settingsWriter);

    $summary = $this->handler->summarize($ctx);

    expect($summary['Watch paths'])->toBe('(none)')
        ->and($summary['Testing'])->toBe('(none)')
        ->and($summary['Linting'])->toBe('(none)');
});

// --- Script Discovery Tests ---

it('discovers composer testing scripts', function () {
    file_put_contents($this->tmpDir.'/composer.json', json_encode([
        'scripts' => [
            'test' => './vendor/bin/pest',
            'test:coverage' => './vendor/bin/pest --coverage',
            'build' => 'echo build',
        ],
    ]));

    $result = $this->handler->discoverScripts($this->tmpDir);

    expect($result['testing'])->toContain('composer test', 'composer test:coverage')
        ->and($result['testing'])->not->toContain('composer build')
        ->and($result['linting'])->not->toContain('composer test');
});

it('discovers composer linting scripts', function () {
    file_put_contents($this->tmpDir.'/composer.json', json_encode([
        'scripts' => [
            'lint' => './vendor/bin/pint',
            'phpstan' => './vendor/bin/phpstan analyse',
            'rector' => './vendor/bin/rector process',
            'format' => './vendor/bin/pint',
        ],
    ]));

    $result = $this->handler->discoverScripts($this->tmpDir);

    expect($result['linting'])->toContain('composer lint', 'composer phpstan', 'composer rector', 'composer format')
        ->and($result['testing'])->toBe([]);
});

it('discovers node scripts with npm prefix', function () {
    file_put_contents($this->tmpDir.'/package.json', json_encode([
        'scripts' => [
            'test' => 'jest',
            'lint' => 'eslint .',
            'dev' => 'vite',
        ],
    ]));
    file_put_contents($this->tmpDir.'/package-lock.json', '{}');

    $result = $this->handler->discoverScripts($this->tmpDir);

    expect($result['testing'])->toBe(['npm run test'])
        ->and($result['linting'])->toBe(['npm run lint'])
        ->and($result['packageManager'])->toBe('npm');
});

it('discovers node scripts with pnpm prefix', function () {
    file_put_contents($this->tmpDir.'/package.json', json_encode([
        'scripts' => [
            'test' => 'vitest',
            'lint' => 'eslint .',
        ],
    ]));
    file_put_contents($this->tmpDir.'/pnpm-lock.yaml', '');

    $result = $this->handler->discoverScripts($this->tmpDir);

    expect($result['testing'])->toBe(['pnpm run test'])
        ->and($result['linting'])->toBe(['pnpm run lint'])
        ->and($result['packageManager'])->toBe('pnpm');
});

it('discovers node scripts with yarn prefix', function () {
    file_put_contents($this->tmpDir.'/package.json', json_encode([
        'scripts' => ['test' => 'jest', 'lint' => 'eslint .'],
    ]));
    file_put_contents($this->tmpDir.'/yarn.lock', '');

    $result = $this->handler->discoverScripts($this->tmpDir);

    expect($result['testing'])->toBe(['yarn test'])
        ->and($result['linting'])->toBe(['yarn lint'])
        ->and($result['packageManager'])->toBe('yarn');
});

it('discovers node scripts with bun prefix', function () {
    file_put_contents($this->tmpDir.'/package.json', json_encode([
        'scripts' => ['test' => 'jest'],
    ]));
    file_put_contents($this->tmpDir.'/bun.lockb', '');

    $result = $this->handler->discoverScripts($this->tmpDir);

    expect($result['testing'])->toBe(['bun run test'])
        ->and($result['packageManager'])->toBe('bun');
});

it('discovers scripts from both composer and node', function () {
    file_put_contents($this->tmpDir.'/composer.json', json_encode([
        'scripts' => ['test' => 'pest', 'lint' => 'pint'],
    ]));
    file_put_contents($this->tmpDir.'/package.json', json_encode([
        'scripts' => ['test' => 'jest', 'lint' => 'eslint .'],
    ]));

    $result = $this->handler->discoverScripts($this->tmpDir);

    expect($result['testing'])->toContain('composer test', 'npm run test')
        ->and($result['linting'])->toContain('composer lint', 'npm run lint');
});

it('skips pre- and post- composer scripts', function () {
    file_put_contents($this->tmpDir.'/composer.json', json_encode([
        'scripts' => [
            'test' => 'pest',
            'pre-test' => 'echo pre',
            'post-test' => 'echo post',
        ],
    ]));

    $result = $this->handler->discoverScripts($this->tmpDir);

    expect($result['testing'])->toBe(['composer test']);
});

it('returns empty when no composer.json or package.json', function () {
    $result = $this->handler->discoverScripts($this->tmpDir);

    expect($result['testing'])->toBe([])
        ->and($result['linting'])->toBe([])
        ->and($result['packageManager'])->toBe('npm');
});

// --- Package Manager Detection Tests ---

it('detects pnpm from lockfile', function () {
    file_put_contents($this->tmpDir.'/pnpm-lock.yaml', '');
    expect($this->handler->detectPackageManager($this->tmpDir))->toBe('pnpm');
});

it('detects yarn from lockfile', function () {
    file_put_contents($this->tmpDir.'/yarn.lock', '');
    expect($this->handler->detectPackageManager($this->tmpDir))->toBe('yarn');
});

it('detects bun from lockfile', function () {
    file_put_contents($this->tmpDir.'/bun.lockb', '');
    expect($this->handler->detectPackageManager($this->tmpDir))->toBe('bun');
});

it('detects npm from lockfile', function () {
    file_put_contents($this->tmpDir.'/package-lock.json', '{}');
    expect($this->handler->detectPackageManager($this->tmpDir))->toBe('npm');
});

it('defaults to npm when no lockfile found', function () {
    expect($this->handler->detectPackageManager($this->tmpDir))->toBe('npm');
});

it('prioritizes pnpm over yarn over npm', function () {
    file_put_contents($this->tmpDir.'/pnpm-lock.yaml', '');
    file_put_contents($this->tmpDir.'/yarn.lock', '');
    file_put_contents($this->tmpDir.'/package-lock.json', '{}');

    expect($this->handler->detectPackageManager($this->tmpDir))->toBe('pnpm');
});
