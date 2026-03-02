<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Value object representing the result of a single step execution.
 *
 * Usage:
 *   $result = new StepResult('copy-env', true, 'File copied', '');
 *   if ($result->success) { ... }
 */
readonly class StepResult
{
    public function __construct(
        public string $id,
        public bool $success,
        public string $output = '',
        public string $error = '',
        public bool $skipped = false,
    ) {}

    /**
     * @return array{id: string, success: bool, output: string, error: string, skipped: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'success' => $this->success,
            'output' => $this->output,
            'error' => $this->error,
            'skipped' => $this->skipped,
        ];
    }
}
