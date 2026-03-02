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

function scanMockHandler(string $name, int $priority): Mockery\MockInterface&InitHandler
{
    $handler = Mockery::mock(InitHandler::class);
    $handler->shouldReceive('name')->andReturn($name);
    $handler->shouldReceive('priority')->andReturn($priority);
    $handler->shouldReceive('summarize')->andReturn([])->byDefault();

    return $handler;
}

it('renders blade template with prompt contexts and launches agent', function () {
    $tmpDir = realpath(sys_get_temp_dir()).'/laracode-session-test-'.uniqid();
    mkdir($tmpDir.'/.laracode', 0755, true);

    $agentHandler = scanMockHandler('agent', 10);
    $agentHandler->shouldReceive('apply')->once();

    $handler = scanMockHandler('watch', 30);
    $handler->shouldReceive('getPromptContext')->andReturn([
        'watchPaths' => ['app/', 'config/'],
        'testingCommands' => ['composer test'],
    ]);
    $handler->shouldReceive('processDecisions')->never();
    $handler->shouldReceive('apply')->never();

    $agentFilesHandler = Mockery::mock(AgentFilesHandler::class);
    $agentFilesHandler->shouldReceive('name')->andReturn('agent_files');
    $agentFilesHandler->shouldReceive('priority')->andReturn(50);
    $agentFilesHandler->shouldReceive('getPromptContext')->andReturn([]);
    $agentFilesHandler->shouldReceive('apply')->once();

    $agent = Mockery::mock(AgentInterface::class);
    $agent->shouldReceive('buildCommand')->andReturn(['echo', 'done']);
    $this->agentRegistry->shouldReceive('getDefault')->andReturn($agent);

    $this->pipeline->register($agentHandler);
    $this->pipeline->register($handler);
    $this->pipeline->register($agentFilesHandler);

    $ctx = new InitContext($tmpDir, false, true, null, $this->settingsWriter);
    $this->pipeline->run($ctx);

    // Prompt file should be cleaned up after agent exits
    expect(file_exists($tmpDir.'/.laracode/.init-prompt.md'))->toBeFalse();

    @rmdir($tmpDir.'/.laracode');
    @rmdir($tmpDir);
});

it('creates prompt file in .laracode directory not temp dir', function () {
    $tmpDir = realpath(sys_get_temp_dir()).'/laracode-prompt-path-'.uniqid();
    mkdir($tmpDir.'/.laracode', 0755, true);

    $agentHandler = scanMockHandler('agent', 10);
    $agentHandler->shouldReceive('apply')->once();

    $handler = scanMockHandler('watch', 30);
    $handler->shouldReceive('getPromptContext')->andReturn(['data' => 'value']);

    $agentFilesHandler = Mockery::mock(AgentFilesHandler::class);
    $agentFilesHandler->shouldReceive('name')->andReturn('agent_files');
    $agentFilesHandler->shouldReceive('priority')->andReturn(50);
    $agentFilesHandler->shouldReceive('getPromptContext')->andReturn([]);
    $agentFilesHandler->shouldReceive('apply')->once();

    // Use a script that checks the prompt file path
    $scriptPath = $tmpDir.'/check-path.sh';
    file_put_contents($scriptPath, <<<'BASH'
#!/bin/bash
PROMPT_PATH=$(echo "$@" | grep -oE '[^ ]*/.laracode/.init-prompt.md')
if [ -n "$PROMPT_PATH" ] && [ -f "$PROMPT_PATH" ]; then
    exit 0
fi
exit 1
BASH);
    chmod($scriptPath, 0755);

    $agent = Mockery::mock(AgentInterface::class);
    $agent->shouldReceive('buildCommand')->andReturn([$scriptPath]);
    $this->agentRegistry->shouldReceive('getDefault')->andReturn($agent);

    $this->pipeline->register($agentHandler);
    $this->pipeline->register($handler);
    $this->pipeline->register($agentFilesHandler);

    $ctx = new InitContext($tmpDir, false, true, null, $this->settingsWriter);
    $this->pipeline->run($ctx);

    // Cleanup
    @unlink($scriptPath);
    @rmdir($tmpDir.'/.laracode');
    @rmdir($tmpDir);
});

it('skips agent session when no agent available', function () {
    $agentFilesHandler = Mockery::mock(AgentFilesHandler::class);
    $agentFilesHandler->shouldReceive('name')->andReturn('agent_files');
    $agentFilesHandler->shouldReceive('priority')->andReturn(50);
    $agentFilesHandler->shouldReceive('getPromptContext')->never();
    $agentFilesHandler->shouldReceive('apply')->once();

    $this->pipeline->register($agentFilesHandler);

    $ctx = new InitContext(sys_get_temp_dir(), false, false, null, $this->settingsWriter);
    $this->pipeline->run($ctx);
});

it('skips completed agent handler when collecting prompt context', function () {
    $tmpDir = realpath(sys_get_temp_dir()).'/laracode-skip-completed-'.uniqid();
    mkdir($tmpDir.'/.laracode', 0755, true);

    $agentHandler = scanMockHandler('agent', 10);
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

    @unlink($tmpDir.'/.laracode/.init-prompt.md');
    @rmdir($tmpDir.'/.laracode');
    @rmdir($tmpDir);
});

it('does not call processDecisions on any handler', function () {
    $tmpDir = realpath(sys_get_temp_dir()).'/laracode-no-decisions-'.uniqid();
    mkdir($tmpDir.'/.laracode', 0755, true);

    $agentHandler = scanMockHandler('agent', 10);
    $agentHandler->shouldReceive('apply')->once();

    $handlerA = scanMockHandler('a', 30);
    $handlerA->shouldReceive('getPromptContext')->andReturn(['key' => 'val']);
    $handlerA->shouldReceive('processDecisions')->never();
    $handlerA->shouldReceive('apply')->never();

    $agentFilesHandler = Mockery::mock(AgentFilesHandler::class);
    $agentFilesHandler->shouldReceive('name')->andReturn('agent_files');
    $agentFilesHandler->shouldReceive('priority')->andReturn(50);
    $agentFilesHandler->shouldReceive('getPromptContext')->andReturn([]);
    $agentFilesHandler->shouldReceive('apply')->once();

    $agent = Mockery::mock(AgentInterface::class);
    $agent->shouldReceive('buildCommand')->andReturn(['echo', 'done']);
    $this->agentRegistry->shouldReceive('getDefault')->andReturn($agent);

    $this->pipeline->register($agentHandler);
    $this->pipeline->register($handlerA);
    $this->pipeline->register($agentFilesHandler);

    $ctx = new InitContext($tmpDir, false, true, null, $this->settingsWriter);
    $this->pipeline->run($ctx);

    @rmdir($tmpDir.'/.laracode');
    @rmdir($tmpDir);
});
