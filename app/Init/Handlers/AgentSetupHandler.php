<?php

declare(strict_types=1);

namespace App\Init\Handlers;

use App\Agents\AgentDetector;
use App\Agents\AgentRegistry;
use App\Enums\BuildMode;
use App\Init\AiDecisionRequest;
use App\Init\HasBootstrapPrompts;
use App\Init\InitContext;
use App\Init\InitHandler;

class AgentSetupHandler implements HasBootstrapPrompts, InitHandler
{
    private const  DATA_KEY = 'agent_setup';

    public function __construct(
        private readonly AgentDetector $agentDetector,
        private readonly AgentRegistry $agentRegistry,
    ) {}

    public function name(): string
    {
        return 'agent_setup';
    }

    public function priority(): int
    {
        return 10;
    }

    public function decisionRequest(InitContext $ctx): ?AiDecisionRequest
    {
        return null;
    }

    /** @param  array<string, mixed>  $decisions */
    public function processDecisions(InitContext $ctx, array $decisions): void {}

    /** @return list<array{id: string, type: string, label: string, default?: mixed, options?: list<string|array{label: string, value: string}>, required?: bool}> */
    public function getBootstrapPrompts(InitContext $ctx): array
    {
        if (! $ctx->isFirstTimeSetup) {
            return [];
        }

        $data = $ctx->handlerData[self::DATA_KEY] ?? [];
        $round = $data['round'] ?? 0;

        if ($round === 0) {
            return $this->buildAgentAndModePrompts($ctx);
        }

        if ($round === 1 && ($data['needsCustom'] ?? false)) {
            return $this->buildCustomAgentPrompts();
        }

        return [];
    }

    /** @param  array<string, mixed>  $responses */
    public function processBootstrapResponses(InitContext $ctx, array $responses): void
    {
        $data = $ctx->handlerData[self::DATA_KEY] ?? [];
        $round = $data['round'] ?? 0;

        if ($round === 0) {
            $this->processAgentAndModeResponses($ctx, $data, $responses);

            return;
        }

        if ($round === 1) {
            $this->processCustomAgentResponses($ctx, $data, $responses);
        }
    }

    /** @return array<string, mixed> */
    public function getPromptContext(InitContext $ctx): array
    {
        return [];
    }

    public function apply(InitContext $ctx): void
    {
        if ($ctx->isFirstTimeSetup) {
            $this->applyFirstTimeSetup($ctx);

            return;
        }

        $this->resolveAgentFromRegistry($ctx);
    }

    private function applyFirstTimeSetup(InitContext $ctx): void
    {
        $data = $ctx->handlerData[self::DATA_KEY] ?? [];
        /** @var array<string, string> $installed */
        $installed = $data['installed'] ?? [];
        /** @var string $selectedAgent */
        $selectedAgent = $data['selectedAgent'] ?? '';
        /** @var string $mode */
        $mode = $data['mode'] ?? BuildMode::Interactive->value;

        if ($selectedAgent !== '' && $this->agentRegistry->has($selectedAgent)) {
            $this->agentRegistry->setDefault($selectedAgent);
        }

        $settings = [
            'agents' => [
                'default' => $selectedAgent !== '' ? $selectedAgent : $this->agentRegistry->getDefaultName(),
                'paths' => $installed,
            ],
            'defaultMode' => $mode,
        ];

        $ctx->settingsWriter->writeUser($settings);

        $agentName = $settings['agents']['default'];
        if ($this->agentRegistry->has($agentName)) {
            $ctx->hasAgent = true;
            $ctx->agent = $this->agentRegistry->get($agentName);
        }
    }

    private function resolveAgentFromRegistry(InitContext $ctx): void
    {
        $agentName = $this->agentRegistry->getDefaultName();
        if ($this->agentRegistry->has($agentName)) {
            $ctx->hasAgent = true;
            $ctx->agent = $this->agentRegistry->get($agentName);
        }
    }

    /** @return array<string, string> */
    public function summarize(InitContext $ctx): array
    {
        $data = $ctx->handlerData[self::DATA_KEY] ?? [];

        return [
            'Agent' => $data['selectedAgent'] ?? '(none)',
            'Mode' => $data['mode'] ?? BuildMode::Interactive->value,
        ];
    }

    /** @return list<array{id: string, type: string, label: string, default?: mixed, options?: list<string|array{label: string, value: string}>, required?: bool}> */
    private function buildAgentAndModePrompts(InitContext $ctx): array
    {
        $installed = $this->agentDetector->detectInstalled();
        $data = $ctx->handlerData[self::DATA_KEY] ?? [];
        $data['installed'] = $installed;
        $data['round'] = 0;
        $ctx->handlerData[self::DATA_KEY] = $data;

        $agentOptions = [];
        foreach (array_keys($installed) as $name) {
            $agentOptions[] = $name;
        }
        $agentOptions[] = 'Custom';

        $defaultAgent = '';
        if ($this->agentRegistry->has($this->agentRegistry->getDefaultName())) {
            $defaultAgent = $this->agentRegistry->getDefaultName();
        }
        if ($defaultAgent === '' && count($agentOptions) > 1) {
            $defaultAgent = $agentOptions[0];
        }

        $modeOptions = [];
        foreach (BuildMode::cases() as $mode) {
            $modeOptions[] = ['label' => $mode->description(), 'value' => $mode->value];
        }

        return [
            [
                'id' => 'agent',
                'type' => 'select',
                'label' => 'Select default agent',
                'options' => $agentOptions,
                'default' => $defaultAgent,
            ],
            [
                'id' => 'mode',
                'type' => 'select',
                'label' => 'Select default permission mode',
                'options' => $modeOptions,
                'default' => BuildMode::Interactive->value,
            ],
        ];
    }

    /** @return list<array{id: string, type: string, label: string, default?: mixed, options?: list<string|array{label: string, value: string}>, required?: bool}> */
    private function buildCustomAgentPrompts(): array
    {
        return [
            [
                'id' => 'custom_path',
                'type' => 'text',
                'label' => 'Enter the executable path',
                'required' => true,
            ],
            [
                'id' => 'custom_name',
                'type' => 'text',
                'label' => 'Enter the agent name for this executable',
                'default' => 'custom',
                'required' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $responses
     */
    private function processAgentAndModeResponses(InitContext $ctx, array $data, array $responses): void
    {
        /** @var string $selectedAgent */
        $selectedAgent = $responses['agent'] ?? '';
        /** @var string $mode */
        $mode = $responses['mode'] ?? BuildMode::Interactive->value;

        $data['mode'] = $mode;

        if ($selectedAgent === 'Custom') {
            $data['needsCustom'] = true;
            $data['round'] = 1;
            $ctx->handlerData[self::DATA_KEY] = $data;

            return;
        }

        $data['selectedAgent'] = $selectedAgent;
        $data['needsCustom'] = false;
        $data['round'] = 2;
        $ctx->handlerData[self::DATA_KEY] = $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $responses
     */
    private function processCustomAgentResponses(InitContext $ctx, array $data, array $responses): void
    {
        /** @var string $customPath */
        $customPath = $responses['custom_path'] ?? '';
        /** @var string $customName */
        $customName = $responses['custom_name'] ?? 'custom';

        /** @var array<string, string> $installed */
        $installed = $data['installed'] ?? [];

        if ($customPath !== '' && $this->agentDetector->validatePath($customPath)) {
            $installed[$customName] = $customPath;
            $data['installed'] = $installed;
            $data['selectedAgent'] = $customName;
        } else {
            $data['selectedAgent'] = count($installed) > 0 ? array_key_first($installed) : '';
        }

        $data['needsCustom'] = false;
        $data['round'] = 2;
        $ctx->handlerData[self::DATA_KEY] = $data;
    }
}
