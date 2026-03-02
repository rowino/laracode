<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Value object representing the result of a flow execution.
 *
 * Usage:
 *   $result = $flowExecutor->execute($flow, $context);
 *   if ($result->success) { ... }
 */
readonly class FlowResult
{
    /**
     * @param  list<StepResult>  $stepResults
     * @param  array<string, mixed>  $promptResponses
     */
    public function __construct(
        public bool $success,
        public array $stepResults = [],
        public array $promptResponses = [],
    ) {}

    /**
     * @return array{success: bool, stepResults: list<array{id: string, success: bool, output: string, error: string, skipped: bool}>, promptResponses: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'stepResults' => array_map(fn (StepResult $r) => $r->toArray(), $this->stepResults),
            'promptResponses' => $this->promptResponses,
        ];
    }
}
