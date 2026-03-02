<?php

declare(strict_types=1);

namespace App\Init;

use App\Agents\AgentInterface;
use App\Frameworks\FrameworkInterface;
use App\Services\Settings\SettingsWriter;

class InitContext
{
    /** @var array<string, mixed> Per-handler state keyed by handler name */
    public array $handlerData = [];

    public ?FrameworkInterface $framework = null;

    /** @var array<string> */
    public array $watchPaths = [];

    public function __construct(
        public readonly string $projectPath,
        public readonly bool $isFirstTimeSetup,
        public bool $hasAgent,
        public ?AgentInterface $agent,
        public readonly SettingsWriter $settingsWriter,
    ) {}
}
