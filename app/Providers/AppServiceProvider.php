<?php

declare(strict_types=1);

namespace App\Providers;

use App\Agents\AgentDetector;
use App\Agents\AgentRegistry;
use App\Agents\AiderAgent;
use App\Agents\ClaudeAgent;
use App\Agents\CodexAgent;
use App\Agents\HappyAgent;
use App\Agents\JunieAgent;
use App\Agents\OpenCodeAgent;
use App\Editors\CursorEditor;
use App\Editors\EditorDetector;
use App\Editors\EditorRegistry;
use App\Editors\PhpStormEditor;
use App\Editors\SublimeEditor;
use App\Editors\VsCodeEditor;
use App\Editors\WindsurfEditor;
use App\Editors\ZedEditor;
use App\Init\Handlers\AgentFilesHandler;
use App\Init\Handlers\AgentSetupHandler;
use App\Init\Handlers\EditorSetupHandler;
use App\Init\Handlers\WatchConfigHandler;
use App\Init\Handlers\WorktreeSetupHandler;
use App\Init\InitPipeline;
use App\Scripts\Interpolator;
use App\Scripts\Runners\AiRunner;
use App\Scripts\Runners\RunnerRegistry;
use App\Scripts\Runners\ScriptRunner;
use App\Scripts\Runners\ShellRunner;
use App\Scripts\ScriptCommand;
use App\Scripts\ScriptExecutor;
use App\Scripts\ScriptLoader;
use App\Services\AgentRunner;
use App\Services\ProjectAnalyzer;
use App\Services\Settings\SettingsLoader;
use App\Services\Settings\SettingsService;
use App\Services\Settings\SettingsWriter;
use Illuminate\Console\Application as Artisan;
use App\Tui\DashboardRenderer;
use App\Tui\SessionRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Artisan::starting(function (Artisan $artisan): void {
            $loader = $this->app->make(ScriptLoader::class);
            $executor = $this->app->make(ScriptExecutor::class);

            $scripts = $loader->discover(getcwd() ?: '.');

            foreach ($scripts as $script) {
                $command = new ScriptCommand($script, $executor);

                if ($script->hidden) {
                    $command->setHidden(true);
                }

                $artisan->add($command);
            }
        });
    }

    public function register(): void
    {
        $this->app->singleton(SettingsLoader::class);
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(SettingsWriter::class);
        $this->app->singleton(ScriptLoader::class);
        $this->app->singleton(ScriptExecutor::class);

        $this->app->singleton(RunnerRegistry::class, function ($app) {
            $interpolator = $app->make(Interpolator::class);
            $registry = new RunnerRegistry;

            $registry->register('shell', new ShellRunner($interpolator));
            $registry->register('script', new ScriptRunner(
                $app->make(ScriptLoader::class),
                fn () => $app->make(ScriptExecutor::class),
            ));
            $registry->register('ai', new AiRunner(
                $app->make(AgentRegistry::class),
                $interpolator,
            ));

            return $registry;
        });
        $this->app->singleton(AgentDetector::class);
        $this->app->singleton(ProjectAnalyzer::class);

        $this->app->singleton(AgentRegistry::class, function ($app) {
            $registry = new AgentRegistry($app->make(SettingsService::class));

            $registry->register(new ClaudeAgent);
            $registry->register(new OpenCodeAgent);
            $registry->register(new CodexAgent);
            $registry->register(new JunieAgent);
            $registry->register(new AiderAgent);
            $registry->register(new HappyAgent);

            return $registry;
        });

        $this->app->singleton(AgentRunner::class);

        $this->app->singleton(EditorRegistry::class, function () {
            $registry = new EditorRegistry;

            $registry->register(new VsCodeEditor);
            $registry->register(new CursorEditor);
            $registry->register(new PhpStormEditor);
            $registry->register(new ZedEditor);
            $registry->register(new SublimeEditor);
            $registry->register(new WindsurfEditor);

            return $registry;
        });
        $this->app->singleton(EditorDetector::class);

        $this->app->singleton(InitPipeline::class, function ($app) {
            $pipeline = new InitPipeline(
                $app->make(AgentRegistry::class),
            );

            $pipeline->register($app->make(AgentSetupHandler::class));
            $pipeline->register($app->make(EditorSetupHandler::class));
            $pipeline->register($app->make(WatchConfigHandler::class));
            $pipeline->register($app->make(WorktreeSetupHandler::class));
            $pipeline->register($app->make(AgentFilesHandler::class));

            return $pipeline;
        });
        $this->app->singleton(DashboardRenderer::class);
        $this->app->singleton(SessionRegistry::class);
    }
}
