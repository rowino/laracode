<?php

declare(strict_types=1);

use App\Agents\AgentDetector;
use App\Agents\AgentInterface;
use App\Agents\AgentRegistry;
use App\Init\Handlers\AgentSetupHandler;
use App\Init\InitContext;
use App\Services\Settings\SettingsWriter;

beforeEach(function () {
    $this->agentDetector = Mockery::mock(AgentDetector::class);
    $this->agentRegistry = Mockery::mock(AgentRegistry::class);
    $this->settingsWriter = Mockery::mock(SettingsWriter::class);
    $this->handler = new AgentSetupHandler($this->agentDetector, $this->agentRegistry);
});

function agentSetupCtx(Mockery\MockInterface $sw, bool $firstTime = true): InitContext
{
    return new InitContext(
        projectPath: sys_get_temp_dir(),
        isFirstTimeSetup: $firstTime,
        hasAgent: false,
        agent: null,
        settingsWriter: $sw,
    );
}

it('has name agent_setup and priority 10', function () {
    expect($this->handler->name())->toBe('agent_setup')
        ->and($this->handler->priority())->toBe(10);
});

it('returns null decisionRequest', function () {
    $ctx = agentSetupCtx($this->settingsWriter);
    expect($this->handler->decisionRequest($ctx))->toBeNull();
});

it('returns empty bootstrap prompts for non-first-time setup', function () {
    $ctx = agentSetupCtx($this->settingsWriter, firstTime: false);

    expect($this->handler->getBootstrapPrompts($ctx))->toBe([]);
});

it('returns agent and mode prompts for first-time setup', function () {
    $this->agentDetector->shouldReceive('detectInstalled')->andReturn([
        'claude' => '/usr/bin/claude',
        'opencode' => '/usr/bin/opencode',
    ]);
    $this->agentRegistry->shouldReceive('has')->andReturn(true);
    $this->agentRegistry->shouldReceive('getDefaultName')->andReturn('claude');

    $ctx = agentSetupCtx($this->settingsWriter);
    $prompts = $this->handler->getBootstrapPrompts($ctx);

    expect($prompts)->toHaveCount(2)
        ->and($prompts[0]['id'])->toBe('agent')
        ->and($prompts[0]['type'])->toBe('select')
        ->and($prompts[0]['options'])->toContain('claude', 'opencode', 'Custom')
        ->and($prompts[1]['id'])->toBe('mode')
        ->and($prompts[1]['type'])->toBe('select');
});

it('processes agent selection and advances to round 2', function () {
    $this->agentDetector->shouldReceive('detectInstalled')->andReturn(['claude' => '/usr/bin/claude']);
    $this->agentRegistry->shouldReceive('has')->andReturn(true);
    $this->agentRegistry->shouldReceive('getDefaultName')->andReturn('claude');

    $ctx = agentSetupCtx($this->settingsWriter);
    $this->handler->getBootstrapPrompts($ctx);

    $this->handler->processBootstrapResponses($ctx, [
        'agent' => 'claude',
        'mode' => 'yolo',
    ]);

    $data = $ctx->handlerData['agent_setup'];
    expect($data['selectedAgent'])->toBe('claude')
        ->and($data['mode'])->toBe('yolo')
        ->and($data['round'])->toBe(2)
        ->and($data['needsCustom'])->toBeFalse();
});

it('requests custom agent prompts when Custom selected', function () {
    $this->agentDetector->shouldReceive('detectInstalled')->andReturn(['claude' => '/usr/bin/claude']);
    $this->agentRegistry->shouldReceive('has')->andReturn(true);
    $this->agentRegistry->shouldReceive('getDefaultName')->andReturn('claude');

    $ctx = agentSetupCtx($this->settingsWriter);
    $this->handler->getBootstrapPrompts($ctx);

    $this->handler->processBootstrapResponses($ctx, ['agent' => 'Custom', 'mode' => 'interactive']);

    $data = $ctx->handlerData['agent_setup'];
    expect($data['needsCustom'])->toBeTrue()
        ->and($data['round'])->toBe(1);

    $customPrompts = $this->handler->getBootstrapPrompts($ctx);
    expect($customPrompts)->toHaveCount(2)
        ->and($customPrompts[0]['id'])->toBe('custom_path')
        ->and($customPrompts[1]['id'])->toBe('custom_name');
});

it('processes valid custom agent path', function () {
    $this->agentDetector->shouldReceive('detectInstalled')->andReturn(['claude' => '/usr/bin/claude']);
    $this->agentRegistry->shouldReceive('has')->andReturn(true);
    $this->agentRegistry->shouldReceive('getDefaultName')->andReturn('claude');
    $this->agentDetector->shouldReceive('validatePath')->with('/usr/local/bin/myagent')->andReturn(true);

    $ctx = agentSetupCtx($this->settingsWriter);
    $this->handler->getBootstrapPrompts($ctx);
    $this->handler->processBootstrapResponses($ctx, ['agent' => 'Custom', 'mode' => 'interactive']);
    $this->handler->getBootstrapPrompts($ctx);
    $this->handler->processBootstrapResponses($ctx, ['custom_path' => '/usr/local/bin/myagent', 'custom_name' => 'myagent']);

    $data = $ctx->handlerData['agent_setup'];
    expect($data['selectedAgent'])->toBe('myagent')
        ->and($data['installed'])->toHaveKey('myagent')
        ->and($data['round'])->toBe(2);
});

it('falls back to first installed agent when custom path invalid', function () {
    $this->agentDetector->shouldReceive('detectInstalled')->andReturn(['claude' => '/usr/bin/claude']);
    $this->agentRegistry->shouldReceive('has')->andReturn(true);
    $this->agentRegistry->shouldReceive('getDefaultName')->andReturn('claude');
    $this->agentDetector->shouldReceive('validatePath')->with('/bad/path')->andReturn(false);

    $ctx = agentSetupCtx($this->settingsWriter);
    $this->handler->getBootstrapPrompts($ctx);
    $this->handler->processBootstrapResponses($ctx, ['agent' => 'Custom', 'mode' => 'interactive']);
    $this->handler->getBootstrapPrompts($ctx);
    $this->handler->processBootstrapResponses($ctx, ['custom_path' => '/bad/path', 'custom_name' => 'bad']);

    $data = $ctx->handlerData['agent_setup'];
    expect($data['selectedAgent'])->toBe('claude');
});

it('returns empty prompts after round 2 (completed)', function () {
    $this->agentDetector->shouldReceive('detectInstalled')->andReturn(['claude' => '/usr/bin/claude']);
    $this->agentRegistry->shouldReceive('has')->andReturn(true);
    $this->agentRegistry->shouldReceive('getDefaultName')->andReturn('claude');

    $ctx = agentSetupCtx($this->settingsWriter);
    $this->handler->getBootstrapPrompts($ctx);
    $this->handler->processBootstrapResponses($ctx, ['agent' => 'claude', 'mode' => 'interactive']);

    expect($this->handler->getBootstrapPrompts($ctx))->toBe([]);
});

it('apply writes user settings and sets context agent', function () {
    $agent = Mockery::mock(AgentInterface::class);
    $agent->shouldReceive('name')->andReturn('claude');

    $this->agentRegistry->shouldReceive('has')->with('claude')->andReturn(true);
    $this->agentRegistry->shouldReceive('setDefault')->with('claude');
    $this->agentRegistry->shouldReceive('get')->with('claude')->andReturn($agent);
    $this->agentRegistry->shouldReceive('getDefaultName')->andReturn('claude');

    $this->settingsWriter->shouldReceive('writeUser')->once()->withArgs(function (array $settings) {
        return $settings['agents']['default'] === 'claude'
            && $settings['defaultMode'] === 'yolo';
    })->andReturn(true);

    $ctx = agentSetupCtx($this->settingsWriter);
    $ctx->handlerData['agent_setup'] = [
        'installed' => ['claude' => '/usr/bin/claude'],
        'selectedAgent' => 'claude',
        'mode' => 'yolo',
        'round' => 2,
    ];

    $this->handler->apply($ctx);

    expect($ctx->hasAgent)->toBeTrue()
        ->and($ctx->agent)->toBe($agent);
});
