<?php

declare(strict_types=1);

namespace App\Init;

use App\Agents\AgentRegistry;
use App\Enums\BuildMode;
use App\Init\Handlers\AgentFilesHandler;
use App\Scripts\PromptRunner;
use Illuminate\Support\Facades\View;

class InitPipeline
{
    /** @var list<InitHandler> Sorted by priority (lower first) */
    private array $handlers = [];

    /** @var array<string, bool> Tracks which handlers are complete */
    private array $completed = [];

    public function __construct(
        private readonly AgentRegistry $agentRegistry,
    ) {}

    public function register(InitHandler $handler): self
    {
        $this->handlers[] = $handler;
        usort($this->handlers, fn (InitHandler $a, InitHandler $b) => $a->priority() <=> $b->priority());

        return $this;
    }

    /** @return list<InitHandler> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    public function run(InitContext $ctx): void
    {
        $this->runAgentSelection($ctx);
        $this->runAdditionalBootstrap($ctx);
        $this->runAgentSession($ctx);
        $this->runApplyPhase($ctx);
    }

    /**
     * Runs the agent selection handler (lowest priority) separately.
     * Uses bootstrap prompts for first-time agent/mode selection, then applies.
     */
    private function runAgentSelection(InitContext $ctx): void
    {
        $agentHandler = $this->findAgentHandler();
        if (! $agentHandler) {
            return;
        }

        $this->runBootstrapPrompts($agentHandler, $ctx);

        $agentHandler->apply($ctx);
        $this->completed[$agentHandler->name()] = true;
    }

    private function runAdditionalBootstrap(InitContext $ctx): void
    {
        foreach ($this->handlers as $handler) {
            if ($this->isCompleted($handler)) {
                continue;
            }

            if (! $handler instanceof HasBootstrapPrompts) {
                continue;
            }

            $this->runBootstrapPrompts($handler, $ctx);
            $handler->apply($ctx);
            $this->completed[$handler->name()] = true;
        }
    }

    private function runBootstrapPrompts(InitHandler $handler, InitContext $ctx): void
    {
        if (! $handler instanceof HasBootstrapPrompts) {
            return;
        }

        /** @var PromptRunner $promptRunner */
        $promptRunner = app(PromptRunner::class);
        $maxRounds = 10;

        for ($i = 0; $i < $maxRounds; $i++) {
            $prompts = $handler->getBootstrapPrompts($ctx);

            if (empty($prompts)) {
                break;
            }

            $responses = $promptRunner->runPrompts($prompts, ['projectPath' => $ctx->projectPath]);
            $handler->processBootstrapResponses($ctx, $responses);
        }
    }

        $agentHandler->apply($ctx);
        $this->completed[$agentHandler->name()] = true;
    }

    /**
     * Collects prompt context from all non-completed handlers, renders a combined
     * prompt via Blade, and launches the agent interactively. The agent creates
     * configuration files directly in the project.
     */
    private function runAgentSession(InitContext $ctx): void
    {
        if (! $ctx->hasAgent) {
            return;
        }

        $promptContexts = [];
        foreach ($this->handlers as $handler) {
            if ($this->isCompleted($handler)) {
                continue;
            }
            $context = $handler->getPromptContext($ctx);
            if (! empty($context)) {
                $promptContexts[$handler->name()] = $context;
            }
        }

        if (empty($promptContexts)) {
            return;
        }

        $promptPath = $ctx->projectPath.'/.laracode/.init-prompt.md';

        $promptDir = dirname($promptPath);
        if (! is_dir($promptDir)) {
            mkdir($promptDir, 0755, true);
        }

        $prompt = View::make('prompts.init-decisions', [
            'promptContexts' => $promptContexts,
            'projectPath' => $ctx->projectPath,
        ])->render();

        file_put_contents($promptPath, $prompt);

        $agent = $ctx->agent ?? $this->agentRegistry->getDefault();
        $command = array_values($agent->buildCommand(BuildMode::Interactive));
        $command[] = "Read and follow the project setup instructions in {$promptPath}";

        $descriptorspec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open($command, $descriptorspec, $pipes, $ctx->projectPath);

        if (! is_resource($process)) {
            @unlink($promptPath);

            return;
        }

        proc_close($process);
        @unlink($promptPath);
    }

    private function runApplyPhase(InitContext $ctx): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler instanceof AgentFilesHandler) {
                $handler->apply($ctx);

                break;
            }
        }
    }

    private function findAgentHandler(): ?InitHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->priority() <= 10) {
                return $handler;
            }
        }

        return null;
    }

    private function isCompleted(InitHandler $handler): bool
    {
        return $this->completed[$handler->name()] ?? false;
    }
}
