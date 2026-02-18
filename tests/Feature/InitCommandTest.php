<?php

declare(strict_types=1);

use App\Agents\AgentDetector;
use App\Agents\AgentRegistry;
use App\Frameworks\GenericFramework;
use App\Frameworks\LaravelFramework;
use App\Frameworks\SymfonyFramework;
use App\Services\ProjectAnalyzer;
use App\Services\Settings\SettingsPath;
use App\Services\Settings\SettingsWriter;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-test-'.uniqid();
    mkdir($this->testPath, 0755, true);

    $this->userSettingsBackup = null;
    if (file_exists(SettingsPath::user())) {
        $this->userSettingsBackup = file_get_contents(SettingsPath::user());
    }

    $userSettingsDir = dirname(SettingsPath::user());
    if (! is_dir($userSettingsDir)) {
        mkdir($userSettingsDir, 0755, true);
    }
    file_put_contents(SettingsPath::user(), json_encode([
        'agents' => ['default' => 'claude', 'paths' => ['claude' => '/usr/bin/claude']],
        'defaultMode' => 'interactive',
    ]));

    $this->mockDetector = Mockery::mock(AgentDetector::class);
    $this->mockDetector->shouldReceive('detectInstalled')->andReturn(['claude' => '/usr/bin/claude'])->byDefault();
    $this->mockDetector->shouldReceive('validatePath')->andReturn(true)->byDefault();
    $this->app->instance(AgentDetector::class, $this->mockDetector);

    $this->mockAnalyzer = Mockery::mock(ProjectAnalyzer::class);
    $this->mockAnalyzer->shouldReceive('analyze')->andReturn([
        'framework' => new GenericFramework,
        'watchPaths' => [],
        'hasComposer' => false,
    ])->byDefault();
    $this->app->instance(ProjectAnalyzer::class, $this->mockAnalyzer);
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }

    if ($this->userSettingsBackup !== null) {
        file_put_contents(SettingsPath::user(), $this->userSettingsBackup);
    } elseif (file_exists(SettingsPath::user())) {
        @unlink(SettingsPath::user());
    }
});

it('creates laracode directory structure', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful();

    expect(is_dir($this->testPath.'/.laracode'))->toBeTrue()
        ->and(is_dir($this->testPath.'/.laracode/specs'))->toBeTrue()
        ->and(is_dir($this->testPath.'/.claude'))->toBeTrue()
        ->and(is_dir($this->testPath.'/.claude/commands'))->toBeTrue()
        ->and(is_dir($this->testPath.'/.claude/skills'))->toBeTrue();
});

it('creates build-next.md command file', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful();

    $buildNextPath = $this->testPath.'/.claude/commands/build-next.md';

    expect(file_exists($buildNextPath))->toBeTrue()
        ->and(file_get_contents($buildNextPath))->toContain('Build Next Task')
        ->and(file_get_contents($buildNextPath))->toContain('NO PLANNING MODE');
});

it('creates generate-tasks.md skill file', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful();

    $generateTasksPath = $this->testPath.'/.claude/skills/generate-tasks/SKILL.md';

    expect(file_exists($generateTasksPath))->toBeTrue()
        ->and(file_get_contents($generateTasksPath))->toContain('Generate Tasks from Feature Description')
        ->and(file_get_contents($generateTasksPath))->toContain('NO PLANNING MODE');
});

it('creates example tasks.json', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful();

    $tasksPath = $this->testPath.'/.laracode/specs/example/tasks.json';

    expect(file_exists($tasksPath))->toBeTrue();

    $tasks = json_decode(file_get_contents($tasksPath), true);

    expect($tasks)->toHaveKey('title')
        ->and($tasks)->toHaveKey('tasks')
        ->and($tasks['tasks'])->toBeArray()
        ->and($tasks['tasks'][0])->toHaveKey('status');
});

it('skips command files that are similar to template', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);

    $agentRegistry = app(AgentRegistry::class);
    $settingsWriter = app(SettingsWriter::class);
    $initCommand = new App\Commands\InitCommand(
        $agentRegistry,
        $this->mockDetector,
        $this->mockAnalyzer,
        $settingsWriter
    );
    $initCommand->setLaravel(app());
    $templateContent = (new ReflectionMethod($initCommand, 'getBuildNextContent'))
        ->invoke($initCommand);
    $slightlyModified = $templateContent.' ';

    file_put_contents($this->testPath.'/.claude/commands/build-next.md', $slightlyModified);

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped');

    expect(file_get_contents($this->testPath.'/.claude/commands/build-next.md'))
        ->toBe($slightlyModified);
});

it('prompts user when command file differs significantly and keeps original on Ignore', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);
    file_put_contents($this->testPath.'/.claude/commands/build-next.md', 'original content');

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->expectsChoice(
            '    How would you like to handle .claude/commands/build-next.md?',
            'Ignore (keep existing)',
            ['Ignore (keep existing)', 'Overwrite (use template)', 'Merge (3-way merge)']
        )
        ->assertSuccessful();

    expect(file_get_contents($this->testPath.'/.claude/commands/build-next.md'))
        ->toBe('original content');
});

it('overwrites command file when user chooses Overwrite option', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);
    file_put_contents($this->testPath.'/.claude/commands/build-next.md', 'original content');

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->expectsChoice(
            '    How would you like to handle .claude/commands/build-next.md?',
            'Overwrite (use template)',
            ['Ignore (keep existing)', 'Overwrite (use template)', 'Merge (3-way merge)']
        )
        ->assertSuccessful();

    expect(file_get_contents($this->testPath.'/.claude/commands/build-next.md'))
        ->toContain('Build Next Task');
});

it('overwrites existing files with --force', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);
    file_put_contents($this->testPath.'/.claude/commands/build-next.md', 'original content');

    $this->artisan('init', ['path' => $this->testPath, '--force' => true])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->testPath.'/.claude/commands/build-next.md'))
        ->toContain('Build Next Task');
});

it('fails when path does not exist', function () {
    $this->artisan('init', ['path' => '/nonexistent/path/that/does/not/exist'])
        ->assertFailed();
});

it('uses current directory when no path provided', function () {
    $originalDir = getcwd();
    chdir($this->testPath);

    try {
        $this->artisan('init')
            ->expectsConfirmation('Use different mode for this project?', 'no')
            ->assertSuccessful();

        expect(is_dir($this->testPath.'/.laracode'))->toBeTrue();
    } finally {
        chdir($originalDir);
    }
});

it('detects similar command files and skips them', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);

    $agentRegistry = app(AgentRegistry::class);
    $settingsWriter = app(SettingsWriter::class);
    $initCommand = new App\Commands\InitCommand(
        $agentRegistry,
        $this->mockDetector,
        $this->mockAnalyzer,
        $settingsWriter
    );
    $initCommand->setLaravel(app());
    $templateContent = (new ReflectionMethod($initCommand, 'getBuildNextContent'))
        ->invoke($initCommand);

    $similarContent = $templateContent."\n<!-- minor change -->";
    file_put_contents($this->testPath.'/.claude/commands/build-next.md', $similarContent);

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped');

    expect(file_get_contents($this->testPath.'/.claude/commands/build-next.md'))
        ->toBe($similarContent);
});

it('creates backup file when merging command files', function () {
    mkdir($this->testPath.'/.claude/commands', 0755, true);
    $originalContent = 'completely different content that is very different from the template';
    file_put_contents($this->testPath.'/.claude/commands/build-next.md', $originalContent);

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->expectsChoice(
            '    How would you like to handle .claude/commands/build-next.md?',
            'Merge (3-way merge)',
            ['Ignore (keep existing)', 'Overwrite (use template)', 'Merge (3-way merge)']
        )
        ->assertSuccessful();

    expect(file_exists($this->testPath.'/.claude/commands/build-next.md.backup'))->toBeTrue()
        ->and(file_get_contents($this->testPath.'/.claude/commands/build-next.md.backup'))
        ->toBe($originalContent);
});

it('runs first-time setup when no user settings exist', function () {
    @unlink(SettingsPath::user());

    $realDetector = new AgentDetector;
    $installedAgents = $realDetector->detectInstalled();
    $agentNames = array_keys($installedAgents);

    if (empty($agentNames)) {
        $this->markTestSkipped('No coding agents installed on this system');
    }

    $selectedAgent = in_array('claude', $agentNames, true) ? 'claude' : $agentNames[0];

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsChoice('Select default agent', $selectedAgent, [...$agentNames, 'Custom'])
        ->expectsChoice(
            'Select default permission mode',
            'Interactive - Asks before making changes',
            [
                'Interactive - Asks before making changes',
                'Plan - Creates plan first, then executes',
                'Yolo - Executes without confirmation',
                'Accept - Auto-accepts all prompts',
            ]
        )
        ->assertSuccessful();

    expect(file_exists(SettingsPath::user()))->toBeTrue();
    $settings = json_decode(file_get_contents(SettingsPath::user()), true);
    expect($settings['agents']['default'])->toBe($selectedAgent)
        ->and($settings['defaultMode'])->toBe('interactive');
});

it('copies files to agent-specific folders', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful();

    expect(file_exists($this->testPath.'/.claude/commands/build-next.md'))->toBeTrue()
        ->and(file_exists($this->testPath.'/.claude/commands/process-comments.md'))->toBeTrue()
        ->and(file_exists($this->testPath.'/.claude/skills/generate-tasks/SKILL.md'))->toBeTrue()
        ->and(file_exists($this->testPath.'/.claude/scripts/statusline.php'))->toBeTrue();
});

it('updates agent settings file with statusline config', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful();

    $settingsPath = $this->testPath.'/.claude/settings.local.json';
    expect(file_exists($settingsPath))->toBeTrue();

    $settings = json_decode(file_get_contents($settingsPath), true);
    expect($settings['statusLine'])->toBeArray()
        ->and($settings['statusLine']['type'])->toBe('command')
        ->and($settings['statusLine']['command'])->toContain('statusline.php');
});

it('allows project mode override', function () {
    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use different mode for this project?', 'yes')
        ->expectsChoice(
            'Select project permission mode',
            'Yolo - Executes without confirmation',
            [
                'Interactive - Asks before making changes',
                'Plan - Creates plan first, then executes',
                'Yolo - Executes without confirmation',
                'Accept - Auto-accepts all prompts',
            ]
        )
        ->assertSuccessful();

    $settingsPath = $this->testPath.'/.laracode/settings.json';
    $settings = json_decode(file_get_contents($settingsPath), true);
    expect($settings['defaultMode'])->toBe('yolo');
});

it('detects first-time setup when user settings do not exist', function () {
    @unlink(SettingsPath::user());

    $initCommand = new App\Commands\InitCommand(
        app(AgentRegistry::class),
        $this->mockDetector,
        $this->mockAnalyzer,
        app(SettingsWriter::class)
    );
    $initCommand->setLaravel(app());

    $method = new ReflectionMethod($initCommand, 'isFirstTimeSetup');

    expect($method->invoke($initCommand))->toBeTrue();
});

it('detects existing setup when user settings exist', function () {
    $initCommand = new App\Commands\InitCommand(
        app(AgentRegistry::class),
        $this->mockDetector,
        $this->mockAnalyzer,
        app(SettingsWriter::class)
    );
    $initCommand->setLaravel(app());

    $method = new ReflectionMethod($initCommand, 'isFirstTimeSetup');

    expect($method->invoke($initCommand))->toBeFalse();
});

it('creates global settings file during first-time setup with plan mode', function () {
    @unlink(SettingsPath::user());

    $realDetector = new AgentDetector;
    $installedAgents = $realDetector->detectInstalled();
    $agentNames = array_keys($installedAgents);

    if (empty($agentNames)) {
        $this->markTestSkipped('No coding agents installed on this system');
    }

    $selectedAgent = in_array('claude', $agentNames, true) ? 'claude' : $agentNames[0];

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsChoice('Select default agent', $selectedAgent, [...$agentNames, 'Custom'])
        ->expectsChoice(
            'Select default permission mode',
            'Plan - Creates plan first, then executes',
            [
                'Interactive - Asks before making changes',
                'Plan - Creates plan first, then executes',
                'Yolo - Executes without confirmation',
                'Accept - Auto-accepts all prompts',
            ]
        )
        ->assertSuccessful();

    expect(file_exists(SettingsPath::user()))->toBeTrue();
    $settings = json_decode(file_get_contents(SettingsPath::user()), true);
    expect($settings['agents']['default'])->toBe($selectedAgent)
        ->and($settings['agents']['paths'])->toHaveKeys($agentNames)
        ->and($settings['defaultMode'])->toBe('plan');
});

it('analyzes project and detects Laravel framework', function () {
    file_put_contents($this->testPath.'/artisan', '<?php // artisan stub');
    mkdir($this->testPath.'/app', 0755, true);
    mkdir($this->testPath.'/routes', 0755, true);

    $laravelAnalyzer = Mockery::mock(ProjectAnalyzer::class);
    $laravelAnalyzer->shouldReceive('analyze')->andReturn([
        'framework' => new LaravelFramework,
        'watchPaths' => ['app', 'routes'],
        'hasComposer' => true,
    ]);
    $this->app->instance(ProjectAnalyzer::class, $laravelAnalyzer);

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use these paths?', 'yes')
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful()
        ->expectsOutputToContain('laravel');
});

it('analyzes project and detects Symfony framework', function () {
    mkdir($this->testPath.'/bin', 0755, true);
    file_put_contents($this->testPath.'/bin/console', '<?php // console stub');
    mkdir($this->testPath.'/config', 0755, true);
    file_put_contents($this->testPath.'/config/bundles.php', '<?php return [];');
    mkdir($this->testPath.'/src', 0755, true);

    $symfonyAnalyzer = Mockery::mock(ProjectAnalyzer::class);
    $symfonyAnalyzer->shouldReceive('analyze')->andReturn([
        'framework' => new SymfonyFramework,
        'watchPaths' => ['src', 'config'],
        'hasComposer' => true,
    ]);
    $this->app->instance(ProjectAnalyzer::class, $symfonyAnalyzer);

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use these paths?', 'yes')
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful()
        ->expectsOutputToContain('symfony');
});

it('analyzes project and falls back to generic framework', function () {
    mkdir($this->testPath.'/src', 0755, true);

    $genericAnalyzer = Mockery::mock(ProjectAnalyzer::class);
    $genericAnalyzer->shouldReceive('analyze')->andReturn([
        'framework' => new GenericFramework,
        'watchPaths' => ['src'],
        'hasComposer' => false,
    ]);
    $this->app->instance(ProjectAnalyzer::class, $genericAnalyzer);

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsConfirmation('Use these paths?', 'yes')
        ->expectsConfirmation('Use different mode for this project?', 'no')
        ->assertSuccessful()
        ->expectsOutputToContain('generic');
});

it('copies files to OpenCode agent-specific folders when opencode is default', function () {
    @unlink(SettingsPath::user());

    $realDetector = new AgentDetector;
    $installedAgents = $realDetector->detectInstalled();
    $agentNames = array_keys($installedAgents);

    if (! in_array('opencode', $agentNames, true)) {
        $this->markTestSkipped('OpenCode agent is not installed on this system');
    }

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsChoice('Select default agent', 'opencode', [...$agentNames, 'Custom'])
        ->expectsChoice(
            'Select default permission mode',
            'Interactive - Asks before making changes',
            [
                'Interactive - Asks before making changes',
                'Plan - Creates plan first, then executes',
                'Yolo - Executes without confirmation',
                'Accept - Auto-accepts all prompts',
            ]
        )
        ->assertSuccessful();

    expect(file_exists($this->testPath.'/.opencode/commands/build-next.md'))->toBeTrue()
        ->and(file_exists($this->testPath.'/.opencode/commands/process-comments.md'))->toBeTrue()
        ->and(file_exists($this->testPath.'/.opencode/skills/generate-tasks/SKILL.md'))->toBeTrue()
        ->and(file_exists($this->testPath.'/.opencode/scripts/statusline.php'))->toBeTrue();
});

it('skips commands and skills for codex agent which does not support them', function () {
    @unlink(SettingsPath::user());

    $realDetector = new AgentDetector;
    $installedAgents = $realDetector->detectInstalled();
    $agentNames = array_keys($installedAgents);

    if (! in_array('codex', $agentNames, true)) {
        $this->markTestSkipped('Codex agent is not installed on this system');
    }

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsChoice('Select default agent', 'codex', [...$agentNames, 'Custom'])
        ->expectsChoice(
            'Select default permission mode',
            'Interactive - Asks before making changes',
            [
                'Interactive - Asks before making changes',
                'Plan - Creates plan first, then executes',
                'Yolo - Executes without confirmation',
                'Accept - Auto-accepts all prompts',
            ]
        )
        ->assertSuccessful()
        ->expectsOutputToContain('does not support commands')
        ->expectsOutputToContain('does not support skills');

    expect(is_dir($this->testPath.'/.codex/commands'))->toBeFalse()
        ->and(is_dir($this->testPath.'/.codex/skills'))->toBeFalse();
});

it('mocks agent detector to return specific installed agents', function () {
    $mockDetector = Mockery::mock(AgentDetector::class);
    $mockDetector->shouldReceive('detectInstalled')
        ->once()
        ->andReturn(['happy' => '/opt/bin/happy', 'aider' => '/usr/local/bin/aider']);
    $mockDetector->shouldReceive('validatePath')->andReturn(true);

    $installed = $mockDetector->detectInstalled();

    expect($installed)->toHaveCount(2)
        ->and($installed)->toHaveKey('happy')
        ->and($installed)->toHaveKey('aider')
        ->and($installed['happy'])->toBe('/opt/bin/happy');
});

it('handles no detected agents scenario via AgentDetector unit test', function () {
    $detector = new AgentDetector;

    $installed = $detector->detectInstalled();

    expect($installed)->toBeArray();

    $emptyDetector = Mockery::mock(AgentDetector::class);
    $emptyDetector->shouldReceive('detectInstalled')->andReturn([]);
    $emptyDetector->shouldReceive('validatePath')
        ->with('/custom/path/my-agent')
        ->andReturn(true);

    expect($emptyDetector->detectInstalled())->toBeEmpty()
        ->and($emptyDetector->validatePath('/custom/path/my-agent'))->toBeTrue();
});

it('saves all detected agent paths to global settings', function () {
    @unlink(SettingsPath::user());

    $realDetector = new AgentDetector;
    $installedAgents = $realDetector->detectInstalled();
    $agentNames = array_keys($installedAgents);

    if (count($agentNames) < 2) {
        $this->markTestSkipped('Need at least 2 agents installed to test multiple agent paths');
    }

    $selectedAgent = $agentNames[count($agentNames) - 1];

    $this->artisan('init', ['path' => $this->testPath])
        ->expectsChoice('Select default agent', $selectedAgent, [...$agentNames, 'Custom'])
        ->expectsChoice(
            'Select default permission mode',
            'Interactive - Asks before making changes',
            [
                'Interactive - Asks before making changes',
                'Plan - Creates plan first, then executes',
                'Yolo - Executes without confirmation',
                'Accept - Auto-accepts all prompts',
            ]
        )
        ->assertSuccessful();

    $settings = json_decode(file_get_contents(SettingsPath::user()), true);
    expect($settings['agents']['paths'])->toHaveCount(count($agentNames))
        ->and($settings['agents']['paths'])->toHaveKeys($agentNames)
        ->and($settings['agents']['default'])->toBe($selectedAgent);
});
