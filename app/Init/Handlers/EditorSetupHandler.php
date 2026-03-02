<?php

declare(strict_types=1);

namespace App\Init\Handlers;

use App\Editors\EditorDetector;
use App\Init\AiDecisionRequest;
use App\Init\HasBootstrapPrompts;
use App\Init\InitContext;
use App\Init\InitHandler;

class EditorSetupHandler implements HasBootstrapPrompts, InitHandler
{
    private const string DATA_KEY = 'editor_setup';

    public function __construct(
        private readonly EditorDetector $editorDetector,
    ) {}

    public function name(): string
    {
        return 'editor_setup';
    }

    public function priority(): int
    {
        return 15;
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

        if (! empty($data['selected'])) {
            return [];
        }

        $installed = $this->editorDetector->detectInstalled();

        if (empty($installed)) {
            return [];
        }

        $data['installed'] = array_keys($installed);
        $ctx->handlerData[self::DATA_KEY] = $data;

        $options = [];
        foreach ($installed as $name => $editor) {
            $options[] = $name;
        }
        $options[] = ['label' => 'None', 'value' => 'none'];

        return [
            [
                'id' => 'editor',
                'type' => 'select',
                'label' => 'Select default editor',
                'options' => $options,
                'default' => $options[0],
            ],
        ];
    }

    /** @param  array<string, mixed>  $responses */
    public function processBootstrapResponses(InitContext $ctx, array $responses): void
    {
        $data = $ctx->handlerData[self::DATA_KEY] ?? [];
        $selected = $responses['editor'] ?? 'none';
        $data['selected'] = $selected;
        $ctx->handlerData[self::DATA_KEY] = $data;
    }

    /** @return array<string, mixed> */
    public function getPromptContext(InitContext $ctx): array
    {
        return [];
    }

    public function apply(InitContext $ctx): void
    {
        $data = $ctx->handlerData[self::DATA_KEY] ?? [];
        $selected = $data['selected'] ?? '';

        if ($selected === '' || $selected === 'none') {
            return;
        }

        $ctx->settingsWriter->mergeLocal(['editor' => ['default' => $selected]], $ctx->projectPath);
    }

    /** @return array<string, string> */
    public function summarize(InitContext $ctx): array
    {
        $data = $ctx->handlerData[self::DATA_KEY] ?? [];

        return [
            'Editor' => $data['selected'] ?? '(none)',
        ];
    }
}
