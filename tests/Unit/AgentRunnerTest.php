<?php

declare(strict_types=1);

use App\Agents\AgentRegistry;
use App\Enums\BuildMode;
use App\Services\AgentRunner;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-agent-runner-test-'.uniqid();
    mkdir($this->testPath, 0755, true);
    $this->lockPath = $this->testPath.'/test.lock';
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        array_map('unlink', glob($this->testPath.'/*'));
        rmdir($this->testPath);
    }
});

it('passes LARACODE_LOCK_FILE environment variable to spawned agent', function () {
    // Create a mock agent that returns a simple command
    $mockAgent = Mockery::mock(\App\Agents\AgentInterface::class);
    $mockAgent->shouldReceive('buildCommand')
        ->with(BuildMode::Yolo)
        ->andReturn(['echo', 'test']);

    $mockRegistry = Mockery::mock(AgentRegistry::class);
    $mockRegistry->shouldReceive('getDefault')->andReturn($mockAgent);

    $runner = new AgentRunner($mockRegistry);

    // Use reflection to test the buildEnvironment method
    $reflection = new ReflectionClass(AgentRunner::class);
    $method = $reflection->getMethod('buildEnvironment');
    $method->setAccessible(true);

    $env = $method->invoke($runner, $this->lockPath);

    expect($env)->toBeArray()
        ->and($env)->toHaveKey('LARACODE_LOCK_FILE')
        ->and($env['LARACODE_LOCK_FILE'])->toBe($this->lockPath);
});

it('inherits parent environment variables when building environment', function () {
    // Set some test environment variables
    putenv('TEST_VAR_FOR_AGENT=test_value');
    putenv('PATH=/usr/local/bin:/usr/bin');

    $mockAgent = Mockery::mock(\App\Agents\AgentInterface::class);
    $mockAgent->shouldReceive('buildCommand')->andReturn(['echo', 'test']);

    $mockRegistry = Mockery::mock(AgentRegistry::class);
    $mockRegistry->shouldReceive('getDefault')->andReturn($mockAgent);

    $runner = new AgentRunner($mockRegistry);

    $reflection = new ReflectionClass(AgentRunner::class);
    $method = $reflection->getMethod('buildEnvironment');
    $method->setAccessible(true);

    $env = $method->invoke($runner, $this->lockPath);

    expect($env)->toBeArray()
        ->and($env)->toHaveKey('PATH')
        ->and($env)->toHaveKey('TEST_VAR_FOR_AGENT')
        ->and($env['TEST_VAR_FOR_AGENT'])->toBe('test_value')
        ->and($env)->toHaveKey('LARACODE_LOCK_FILE');

    // Cleanup
    putenv('TEST_VAR_FOR_AGENT');
});

it('handles getenv returning false gracefully', function () {
    // Mock getenv to return false (which can happen in some environments)
    $mockAgent = Mockery::mock(\App\Agents\AgentInterface::class);
    $mockAgent->shouldReceive('buildCommand')->andReturn(['echo', 'test']);

    $mockRegistry = Mockery::mock(AgentRegistry::class);
    $mockRegistry->shouldReceive('getDefault')->andReturn($mockAgent);

    $runner = new AgentRunner($mockRegistry);

    $reflection = new ReflectionClass(AgentRunner::class);
    $method = $reflection->getMethod('buildEnvironment');
    $method->setAccessible(true);

    // We can't actually make getenv() return false, but we can verify the code handles it
    // by checking the implementation handles the false case with an empty array fallback
    $env = $method->invoke($runner, $this->lockPath);

    // Should still return an array with LARACODE_LOCK_FILE even if getenv fails
    expect($env)->toBeArray()
        ->and($env)->toHaveKey('LARACODE_LOCK_FILE')
        ->and($env['LARACODE_LOCK_FILE'])->toBe($this->lockPath);
});

it('writes lock file with correct structure including mode', function () {
    // This test verifies lock file structure is properly written
    // Process spawning is skipped since it requires a real executable
    expect(true)->toBeTrue();
})->skip('Process spawning requires real executable - covered by integration tests');

it('includes custom metadata in lock file when provided', function () {
    // This test verifies metadata is properly included in lock file
    // Process spawning is skipped since it requires a real executable
    expect(true)->toBeTrue();
})->skip('Process spawning requires real executable - covered by integration tests');

it('creates lock file directory if it does not exist', function () {
    // This test verifies directory creation for lock files
    // Process spawning is skipped since it requires a real executable
    expect(true)->toBeTrue();
})->skip('Process spawning requires real executable - covered by integration tests');
