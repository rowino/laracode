<?php

declare(strict_types=1);

use App\Agents\AgentInterface;
use App\Init\Handlers\AgentFilesHandler;
use App\Init\InitContext;
use App\Services\Settings\SettingsWriter;

function recursiveDeleteAgentFilesTest(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

beforeEach(function () {
    $this->settingsWriter = Mockery::mock(SettingsWriter::class);
    $this->handler = new AgentFilesHandler;
    $this->testDir = realpath(sys_get_temp_dir()).'/laracode-agent-files-test-'.uniqid();
    mkdir($this->testDir, 0755, true);
});

afterEach(function () {
    recursiveDeleteAgentFilesTest($this->testDir);
});

function agentFilesCtx(string $dir, Mockery\MockInterface $sw, bool $firstTime = true, bool $hasAgent = false, ?object $agent = null): InitContext
{
    return new InitContext(
        projectPath: $dir,
        isFirstTimeSetup: $firstTime,
        hasAgent: $hasAgent,
        agent: $agent,
        settingsWriter: $sw,
    );
}

it('has name agent_files and priority 50', function () {
    expect($this->handler->name())->toBe('agent_files')
        ->and($this->handler->priority())->toBe(50);
});

it('decisionRequest always returns null', function () {
    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, firstTime: true);
    expect($this->handler->decisionRequest($ctx))->toBeNull();

    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, firstTime: false);
    expect($this->handler->decisionRequest($ctx))->toBeNull();
});

it('processDecisions is a no-op', function () {
    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter);

    $this->handler->processDecisions($ctx, ['overrideMode' => true, 'projectMode' => 'plan']);

    expect($ctx->handlerData)->toBeEmpty();
});

it('apply creates directories', function () {
    $this->settingsWriter->shouldReceive('mergeProject')->zeroOrMoreTimes()->andReturn(true);

    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, hasAgent: false);
    $this->handler->apply($ctx);

    expect(is_dir($this->testDir.'/.laracode'))->toBeTrue()
        ->and(is_dir($this->testDir.'/.laracode/specs'))->toBeTrue();
});

it('apply creates settings.json from stub', function () {
    $this->settingsWriter->shouldReceive('mergeProject')->zeroOrMoreTimes()->andReturn(true);

    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, hasAgent: false);
    $ctx->watchPaths = ['app', 'tests'];
    $this->handler->apply($ctx);

    $settingsPath = $this->testDir.'/.laracode/settings.json';
    expect(file_exists($settingsPath))->toBeTrue();

    $content = json_decode(file_get_contents($settingsPath), true);
    expect($content['watch']['paths'])->toBe(['app', 'tests']);
});

it('apply creates sample tasks.json', function () {
    $this->settingsWriter->shouldReceive('mergeProject')->zeroOrMoreTimes()->andReturn(true);

    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, hasAgent: false);
    $this->handler->apply($ctx);

    $samplePath = $this->testDir.'/.laracode/specs/example/tasks.json';
    expect(file_exists($samplePath))->toBeTrue();

    $content = json_decode(file_get_contents($samplePath), true);
    expect($content)->toHaveKey('tasks')
        ->and($content['created'])->not->toContain('{{CREATED_DATE}}');
});

it('apply merges watch paths when settings.json already exists', function () {
    mkdir($this->testDir.'/.laracode', 0755, true);
    file_put_contents($this->testDir.'/.laracode/settings.json', json_encode(['watch' => ['paths' => ['old']]]));

    $this->settingsWriter->shouldReceive('mergeProject')->once()->withArgs(function (array $settings) {
        return $settings === ['watch' => ['paths' => ['app', 'tests']]];
    })->andReturn(true);

    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, hasAgent: false);
    $ctx->watchPaths = ['app', 'tests'];
    $this->handler->apply($ctx);
});

it('apply does not write project mode override', function () {
    $this->settingsWriter->shouldNotReceive('mergeProject');

    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, hasAgent: false);
    $ctx->handlerData['agent_files'] = ['projectMode' => 'plan'];
    $this->handler->apply($ctx);

    expect(file_exists($this->testDir.'/.laracode/settings.json'))->toBeTrue();
});

it('apply copies stubs and configures agent when agent available', function () {
    $agent = Mockery::mock(AgentInterface::class);
    $agent->shouldReceive('name')->andReturn('claude');

    $agent->shouldReceive('installCommand')->andReturnUsing(function (string $file) {
        $targetDir = getcwd().'/commands/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });
    $agent->shouldReceive('installSkill')->andReturnUsing(function (string $file) {
        $skillName = pathinfo(dirname($file), PATHINFO_FILENAME);
        if ($skillName === '' || $skillName === '.') {
            $skillName = pathinfo($file, PATHINFO_FILENAME);
        }
        $targetDir = getcwd().'/skills/'.$skillName.'/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });
    $agent->shouldReceive('installHook')->andReturnUsing(function (string $file) {
        $targetDir = getcwd().'/hooks/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });
    $agent->shouldReceive('installConfig')->andReturnUsing(function (string $file) {
        $targetDir = getcwd().'/config/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });
    $agent->shouldReceive('getSettings')->with('project')->andReturn([]);
    $agent->shouldReceive('updateSettings')->with('project', Mockery::type('array'))->twice();

    $this->settingsWriter->shouldReceive('mergeProject')->zeroOrMoreTimes()->andReturn(true);

    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, hasAgent: true, agent: $agent);
    $ctx->watchPaths = ['app'];
    $this->handler->apply($ctx);

    expect(is_dir($this->testDir.'/.laracode'))->toBeTrue();
});

it('apply skips agent stubs when no agent', function () {
    $this->settingsWriter->shouldReceive('mergeProject')->zeroOrMoreTimes()->andReturn(true);

    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, hasAgent: false, agent: null);
    $this->handler->apply($ctx);

    expect(file_exists($this->testDir.'/.laracode/settings.json'))->toBeTrue();
});

it('auto-overwrites divergent files when similarity below 90%', function () {
    $agent = Mockery::mock(AgentInterface::class);
    $agent->shouldReceive('name')->andReturn('claude');
    $agent->shouldReceive('installCommand')->andReturnUsing(function (string $file) {
        $targetDir = getcwd().'/commands/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });
    $agent->shouldReceive('installSkill')->andReturnUsing(function (string $file) {
        $skillName = pathinfo(dirname($file), PATHINFO_FILENAME);
        if ($skillName === '' || $skillName === '.') {
            $skillName = pathinfo($file, PATHINFO_FILENAME);
        }
        $targetDir = getcwd().'/skills/'.$skillName.'/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });
    $agent->shouldReceive('installHook')->andReturnUsing(function (string $file) {
        $targetDir = getcwd().'/hooks/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });
    $agent->shouldReceive('installConfig')->andReturnUsing(function (string $file) {
        $targetDir = getcwd().'/config/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });
    $agent->shouldReceive('getSettings')->with('project')->andReturn([]);
    $agent->shouldReceive('updateSettings')->with('project', Mockery::type('array'))->twice();

    $this->settingsWriter->shouldReceive('mergeProject')->zeroOrMoreTimes()->andReturn(true);

    $commandsDir = $this->testDir.'/commands';
    mkdir($commandsDir, 0755, true);
    file_put_contents($commandsDir.'/build-next.md', 'completely different old content');

    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, hasAgent: true, agent: $agent);
    $ctx->watchPaths = ['app'];
    $this->handler->apply($ctx);

    $content = file_get_contents($this->testDir.'/commands/build-next.md');
    expect($content)->not->toBe('completely different old content')
        ->and($content)->toContain('Build Next Task');
});

it('summarize returns empty without agent', function () {
    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, hasAgent: false);

    $summary = $this->handler->summarize($ctx);

    expect($summary)->toBe([]);
});

it('summarize shows detected conflicts', function () {
    $agent = Mockery::mock(AgentInterface::class);
    $agent->shouldReceive('name')->andReturn('claude');
    $agent->shouldReceive('installCommand')->andReturnUsing(function (string $file) {
        $targetDir = getcwd().'/commands/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });
    $agent->shouldReceive('installSkill')->andReturnUsing(function (string $file) {
        $skillName = pathinfo(dirname($file), PATHINFO_FILENAME);
        if ($skillName === '' || $skillName === '.') {
            $skillName = pathinfo($file, PATHINFO_FILENAME);
        }
        $targetDir = getcwd().'/skills/'.$skillName.'/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });
    $agent->shouldReceive('installHook')->andReturnUsing(function (string $file) {
        $targetDir = getcwd().'/hooks/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });
    $agent->shouldReceive('installConfig')->andReturnUsing(function (string $file) {
        $targetDir = getcwd().'/config/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        copy($file, $targetDir.basename($file));
    });

    $commandsDir = $this->testDir.'/commands';
    mkdir($commandsDir, 0755, true);
    file_put_contents($commandsDir.'/build-next.md', 'completely different content that diverges from stub');

    $ctx = agentFilesCtx($this->testDir, $this->settingsWriter, hasAgent: true, agent: $agent);

    $summary = $this->handler->summarize($ctx);

    expect($summary)->toHaveKey('Files to overwrite');
});
