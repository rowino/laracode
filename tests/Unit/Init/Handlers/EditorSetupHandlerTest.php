<?php

declare(strict_types=1);

use App\Editors\EditorDetector;
use App\Editors\EditorInterface;
use App\Init\Handlers\EditorSetupHandler;
use App\Init\HasBootstrapPrompts;
use App\Init\InitContext;
use App\Init\InitHandler;
use App\Services\Settings\SettingsWriter;

beforeEach(function () {
    $this->editorDetector = Mockery::mock(EditorDetector::class);
    $this->settingsWriter = Mockery::mock(SettingsWriter::class);
    $this->handler = new EditorSetupHandler($this->editorDetector);
});

function editorSetupCtx(Mockery\MockInterface $sw, bool $firstTime = true): InitContext
{
    return new InitContext(
        projectPath: sys_get_temp_dir(),
        isFirstTimeSetup: $firstTime,
        hasAgent: false,
        agent: null,
        settingsWriter: $sw,
    );
}

it('implements InitHandler and HasBootstrapPrompts', function () {
    expect($this->handler)->toBeInstanceOf(InitHandler::class)
        ->and($this->handler)->toBeInstanceOf(HasBootstrapPrompts::class);
});

it('has name editor_setup and priority 15', function () {
    expect($this->handler->name())->toBe('editor_setup')
        ->and($this->handler->priority())->toBe(15);
});

it('returns null decisionRequest', function () {
    $ctx = editorSetupCtx($this->settingsWriter);
    expect($this->handler->decisionRequest($ctx))->toBeNull();
});

it('returns empty bootstrap prompts for non-first-time setup', function () {
    $ctx = editorSetupCtx($this->settingsWriter, firstTime: false);

    expect($this->handler->getBootstrapPrompts($ctx))->toBe([]);
});

it('returns empty bootstrap prompts when no editors installed', function () {
    $this->editorDetector->shouldReceive('detectInstalled')->andReturn([]);

    $ctx = editorSetupCtx($this->settingsWriter);

    expect($this->handler->getBootstrapPrompts($ctx))->toBe([]);
});

it('returns editor select prompt when editors are installed', function () {
    $vscode = Mockery::mock(EditorInterface::class);
    $cursor = Mockery::mock(EditorInterface::class);

    $this->editorDetector->shouldReceive('detectInstalled')->andReturn([
        'vscode' => $vscode,
        'cursor' => $cursor,
    ]);

    $ctx = editorSetupCtx($this->settingsWriter);
    $prompts = $this->handler->getBootstrapPrompts($ctx);

    expect($prompts)->toHaveCount(1)
        ->and($prompts[0]['id'])->toBe('editor')
        ->and($prompts[0]['type'])->toBe('select')
        ->and($prompts[0]['label'])->toBe('Select default editor')
        ->and($prompts[0]['options'])->toContain('vscode', 'cursor')
        ->and($prompts[0]['options'])->toHaveCount(3)
        ->and($prompts[0]['default'])->toBe('vscode');
});

it('returns empty prompts after selection is made', function () {
    $vscode = Mockery::mock(EditorInterface::class);
    $this->editorDetector->shouldReceive('detectInstalled')->andReturn(['vscode' => $vscode]);

    $ctx = editorSetupCtx($this->settingsWriter);
    $this->handler->getBootstrapPrompts($ctx);
    $this->handler->processBootstrapResponses($ctx, ['editor' => 'vscode']);

    expect($this->handler->getBootstrapPrompts($ctx))->toBe([]);
});

it('stores selected editor in handlerData', function () {
    $vscode = Mockery::mock(EditorInterface::class);
    $this->editorDetector->shouldReceive('detectInstalled')->andReturn(['vscode' => $vscode]);

    $ctx = editorSetupCtx($this->settingsWriter);
    $this->handler->getBootstrapPrompts($ctx);
    $this->handler->processBootstrapResponses($ctx, ['editor' => 'cursor']);

    expect($ctx->handlerData['editor_setup']['selected'])->toBe('cursor');
});

it('apply writes editor default to local settings', function () {
    $this->settingsWriter->shouldReceive('mergeLocal')
        ->once()
        ->with(['editor' => ['default' => 'vscode']], sys_get_temp_dir())
        ->andReturn(true);

    $ctx = editorSetupCtx($this->settingsWriter);
    $ctx->handlerData['editor_setup'] = ['selected' => 'vscode'];

    $this->handler->apply($ctx);
});

it('apply does nothing when none selected', function () {
    $this->settingsWriter->shouldNotReceive('mergeLocal');

    $ctx = editorSetupCtx($this->settingsWriter);
    $ctx->handlerData['editor_setup'] = ['selected' => 'none'];

    $this->handler->apply($ctx);
});

it('apply does nothing when no selection made', function () {
    $this->settingsWriter->shouldNotReceive('mergeLocal');

    $ctx = editorSetupCtx($this->settingsWriter);

    $this->handler->apply($ctx);
});

it('summarize returns selected editor', function () {
    $ctx = editorSetupCtx($this->settingsWriter);
    $ctx->handlerData['editor_setup'] = ['selected' => 'phpstorm'];

    expect($this->handler->summarize($ctx))->toBe(['Editor' => 'phpstorm']);
});

it('summarize returns none when no data', function () {
    $ctx = editorSetupCtx($this->settingsWriter);

    expect($this->handler->summarize($ctx))->toBe(['Editor' => '(none)']);
});
