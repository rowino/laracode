<?php

declare(strict_types=1);

namespace App\Init;

/**
 * Represents a handler's request for AI-driven configuration decisions.
 *
 * Usage: Handlers return this from decisionRequest() to participate in the agent session.
 * The agent receives instructions and responseSchema, explores the project, and writes JSON keyed by responseKey.
 */
readonly class AiDecisionRequest
{
    /**
     * @param  array<string, mixed>  $responseSchema  Expected JSON schema for this handler's section
     */
    public function __construct(
        public string $handlerName,
        public string $instructions,
        public string $responseKey,
        public array $responseSchema,
        public string $displayName = '',
    ) {}
}
