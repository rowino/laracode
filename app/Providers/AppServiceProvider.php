<?php

namespace App\Providers;

use App\Agents\AgentDetector;
use App\Agents\AgentRegistry;
use App\Agents\AiderAgent;
use App\Agents\ClaudeAgent;
use App\Agents\CodexAgent;
use App\Agents\HappyAgent;
use App\Agents\JunieAgent;
use App\Agents\OpenCodeAgent;
use App\Services\AgentRunner;
use App\Services\ProjectAnalyzer;
use App\Services\Settings\SettingsLoader;
use App\Services\Settings\SettingsService;
use App\Services\Settings\SettingsWriter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsLoader::class);
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(SettingsWriter::class);
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
    }
}
