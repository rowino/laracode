<?php

declare(strict_types=1);

namespace App\Init;

interface InitHandler
{
    public function name(): string;

    /** Lower = runs earlier */
    public function priority(): int;

    public function decisionRequest(InitContext $ctx): ?AiDecisionRequest;

    /** @param  array<string, mixed>  $decisions */
    public function processDecisions(InitContext $ctx, array $decisions): void;

    public function apply(InitContext $ctx): void;

    /** @return array<string, mixed> */
    public function getPromptContext(InitContext $ctx): array;

    /** @return array<string, string> */
    public function summarize(InitContext $ctx): array;
}
