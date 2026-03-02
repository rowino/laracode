<?php

declare(strict_types=1);

namespace App\Commands;

use App\Agents\AgentRegistry;
use App\Init\InitContext;
use App\Init\InitPipeline;
use App\Services\Settings\SettingsPath;
use App\Services\Settings\SettingsWriter;
use LaravelZero\Framework\Commands\Command;

class InitCommand extends Command
{
    protected $signature = 'init
        {path? : Project path (defaults to current directory)}
        {--force : Overwrite existing files}';

    protected $description = 'Initialize LaraCode in an existing project';

    public function __construct(
        private readonly InitPipeline $pipeline,
        private readonly AgentRegistry $agentRegistry,
        private readonly SettingsWriter $settingsWriter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string|null $pathArg */
        $pathArg = $this->argument('path');
        $cwd = getcwd();
        $projectPath = $pathArg ?? ($cwd !== false ? $cwd : '.');
        $realPath = realpath($projectPath);
        $projectPath = $realPath !== false ? $realPath : $projectPath;

        if (! is_dir($projectPath)) {
            $this->error("Directory not found: {$projectPath}");

            return self::FAILURE;
        }

        $this->info("Initializing LaraCode in: {$projectPath}");
        $this->newLine();

        $agent = $this->agentRegistry->getDefault();

        $ctx = new InitContext(
            projectPath: $projectPath,
            isFirstTimeSetup: $this->isFirstTimeSetup(),
            hasAgent: true,
            agent: $agent,
            settingsWriter: $this->settingsWriter,
        );

        $this->pipeline->run($ctx);

        $this->newLine();
        $this->info('✓ LaraCode initialized!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Create a feature spec in .laracode/specs/<feature>/tasks.json');
        $this->line('  2. Run: <info>laracode build .laracode/specs/<feature>/tasks.json</info>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function isFirstTimeSetup(): bool
    {
        $userSettingsPath = SettingsPath::user();

        return $userSettingsPath === '' || ! file_exists($userSettingsPath);
    }
}
