<?php

declare(strict_types=1);

use App\Init\Handlers\WorktreeSetupHandler;
use App\Init\InitContext;
use App\Services\GitHelper;
use App\Services\Settings\SettingsWriter;

beforeEach(function () {
    $this->settingsWriter = Mockery::mock(SettingsWriter::class);
    $this->gitHelper = Mockery::mock(GitHelper::class);
    $this->gitHelper->shouldReceive('defaultBranch')->andReturn('main')->byDefault();
    $this->handler = new WorktreeSetupHandler($this->gitHelper);
});

function worktreeCtx(Mockery\MockInterface $sw, bool $hasAgent = false): InitContext
{
    return new InitContext(
        projectPath: sys_get_temp_dir().'/fake-project',
        isFirstTimeSetup: true,
        hasAgent: $hasAgent,
        agent: null,
        settingsWriter: $sw,
    );
}

it('has name worktree and priority 40', function () {
    expect($this->handler->name())->toBe('worktree')
        ->and($this->handler->priority())->toBe(40);
});

it('decisionRequest returns null', function () {
    $ctx = worktreeCtx($this->settingsWriter);

    expect($this->handler->decisionRequest($ctx))->toBeNull();
});

it('getPromptContext returns default branch', function () {
    $this->gitHelper->shouldReceive('defaultBranch')->andReturn('develop');
    $handler = new WorktreeSetupHandler($this->gitHelper);

    $ctx = worktreeCtx($this->settingsWriter);
    $context = $handler->getPromptContext($ctx);

    expect($context['defaultBranch'])->toBe('develop');
});

it('getPromptContext returns available setup stubs', function () {
    $ctx = worktreeCtx($this->settingsWriter);
    $context = $this->handler->getPromptContext($ctx);

    expect($context['setupStubs'])->toBeArray()
        ->and($context['setupStubs'])->not->toBeEmpty();

    $names = array_column($context['setupStubs'], 'name');
    expect($names)->toContain('setup:composer')
        ->and($names)->toContain('setup:node')
        ->and($names)->toContain('setup:migrate')
        ->and($names)->toContain('setup:env-copy');
});

it('getPromptContext stubs have name and description', function () {
    $ctx = worktreeCtx($this->settingsWriter);
    $context = $this->handler->getPromptContext($ctx);

    foreach ($context['setupStubs'] as $stub) {
        expect($stub)->toHaveKeys(['name', 'description'])
            ->and($stub['name'])->toBeString()->not->toBeEmpty()
            ->and($stub['description'])->toBeString();
    }
});

it('processDecisions is a no-op', function () {
    $ctx = worktreeCtx($this->settingsWriter);

    $this->handler->processDecisions($ctx, ['useWorktrees' => true, 'sharedDirs' => ['vendor']]);

    expect($ctx->handlerData)->toBeEmpty();
});

it('apply is a no-op', function () {
    $this->settingsWriter->shouldNotReceive('mergeLocal');
    $this->settingsWriter->shouldNotReceive('mergeProject');

    $ctx = worktreeCtx($this->settingsWriter);
    $this->handler->apply($ctx);
});

it('summarize returns empty array', function () {
    $ctx = worktreeCtx($this->settingsWriter);

    expect($this->handler->summarize($ctx))->toBe([]);
});
