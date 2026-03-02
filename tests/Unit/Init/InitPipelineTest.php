<?php

declare(strict_types=1);

use App\Agents\AgentInterface;
use App\Agents\AgentRegistry;
use App\Init\Handlers\AgentFilesHandler;
use App\Init\InitContext;
use App\Init\InitHandler;
use App\Init\InitPipeline;
use App\Services\Settings\SettingsWriter;

beforeEach(function () {
    $this->agentRegistry = Mockery::mock(AgentRegistry::class);
    $this->pipeline = new InitPipeline($this->agentRegistry);
    $this->settingsWriter = Mockery::mock(SettingsWriter::class);
});

function createMockHandler(string $name, int $priority): Mockery\MockInterface&InitHandler
{
    $handler = Mockery::mock(InitHandler::class);
    $handler->shouldReceive('name')->andReturn($name);
    $handler->shouldReceive('priority')->andReturn($priority);
    $handler->shouldReceive('summarize')->andReturn([])->byDefault();

    return $handler;
}

function createTestContext(Mockery\MockInterface $settingsWriter, bool $firstTime = false, bool $hasAgent = false): InitContext
{
    return new InitContext(
        projectPath: sys_get_temp_dir(),
        isFirstTimeSetup: $firstTime,
        hasAgent: $hasAgent,
        agent: null,
        settingsWriter: $settingsWriter,
    );
}

it('registers handlers sorted by priority', function () {
    $handlerA = createMockHandler('a', 50);
    $handlerB = createMockHandler('b', 10);
    $handlerC = createMockHandler('c', 30);

    $this->pipeline->register($handlerA);
    $this->pipeline->register($handlerB);
    $this->pipeline->register($handlerC);

    $names = array_map(fn (InitHandler $h) => $h->name(), $this->pipeline->handlers());

    expect($names)->toBe(['b', 'c', 'a']);
});

it('only calls apply on AgentFilesHandler in apply phase', function () {
    $agentFilesHandler = Mockery::mock(AgentFilesHandler::class);
    $agentFilesHandler->shouldReceive('name')->andReturn('agent_files');
    $agentFilesHandler->shouldReceive('priority')->andReturn(50);
    $agentFilesHandler->shouldReceive('getPromptContext')->andReturn([]);
    $agentFilesHandler->shouldReceive('apply')->once();

    $otherHandler = createMockHandler('other', 30);
    $otherHandler->shouldReceive('getPromptContext')->andReturn([]);
    $otherHandler->shouldReceive('apply')->never();

    $this->pipeline->register($agentFilesHandler);
    $this->pipeline->register($otherHandler);

    $ctx = createTestContext($this->settingsWriter, hasAgent: false);
    $this->pipeline->run($ctx);
});

it('skips agent session when no agent available', function () {
    $agentFilesHandler = Mockery::mock(AgentFilesHandler::class);
    $agentFilesHandler->shouldReceive('name')->andReturn('agent_files');
    $agentFilesHandler->shouldReceive('priority')->andReturn(50);
    $agentFilesHandler->shouldReceive('getPromptContext')->never();
    $agentFilesHandler->shouldReceive('apply')->once();

    $this->pipeline->register($agentFilesHandler);

    $ctx = createTestContext($this->settingsWriter, hasAgent: false);
    $this->pipeline->run($ctx);
});

it('calls apply on agent handler during agent selection', function () {
    $tmpDir = realpath(sys_get_temp_dir()).'/laracode-unit-agent-'.uniqid();
    mkdir($tmpDir.'/.laracode', 0755, true);

    $agentHandler = createMockHandler('agent', 10);
    $agentHandler->shouldReceive('apply')->once();

    $agentFilesHandler = Mockery::mock(AgentFilesHandler::class);
    $agentFilesHandler->shouldReceive('name')->andReturn('agent_files');
    $agentFilesHandler->shouldReceive('priority')->andReturn(50);
    $agentFilesHandler->shouldReceive('getPromptContext')->andReturn([]);
    $agentFilesHandler->shouldReceive('apply')->once();

    $agent = Mockery::mock(AgentInterface::class);
    $agent->shouldReceive('buildCommand')->andReturn(['echo', 'done']);
    $this->agentRegistry->shouldReceive('getDefault')->andReturn($agent);

    $this->pipeline->register($agentHandler);
    $this->pipeline->register($agentFilesHandler);

    $ctx = new InitContext($tmpDir, false, true, null, $this->settingsWriter);
    $this->pipeline->run($ctx);

    @rmdir($tmpDir.'/.laracode');
    @rmdir($tmpDir);
});

it('skips completed agent handler when collecting prompt context', function () {
    $tmpDir = realpath(sys_get_temp_dir()).'/laracode-unit-skip-'.uniqid();
    mkdir($tmpDir.'/.laracode', 0755, true);

    $agentHandler = createMockHandler('agent', 10);
    $agentHandler->shouldReceive('apply')->once();
    $agentHandler->shouldReceive('getPromptContext')->never();

    $agentFilesHandler = Mockery::mock(AgentFilesHandler::class);
    $agentFilesHandler->shouldReceive('name')->andReturn('agent_files');
    $agentFilesHandler->shouldReceive('priority')->andReturn(50);
    $agentFilesHandler->shouldReceive('getPromptContext')->once()->andReturn([]);
    $agentFilesHandler->shouldReceive('apply')->once();

    $agent = Mockery::mock(AgentInterface::class);
    $agent->shouldReceive('buildCommand')->andReturn(['echo', 'done']);
    $this->agentRegistry->shouldReceive('getDefault')->andReturn($agent);

    $this->pipeline->register($agentHandler);
    $this->pipeline->register($agentFilesHandler);

    $ctx = new InitContext($tmpDir, false, true, null, $this->settingsWriter);
    $this->pipeline->run($ctx);

    @rmdir($tmpDir.'/.laracode');
    @rmdir($tmpDir);
});

it('handles pipeline with no handlers', function () {
    $ctx = createTestContext($this->settingsWriter);
    $this->pipeline->run($ctx);

    expect(true)->toBeTrue();
});
