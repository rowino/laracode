<?php

declare(strict_types=1);

namespace App\Init;

readonly class ScanRequest
{
    /**
     * @param  array<string>  $contextFiles  File paths this handler needs included
     * @param  array<string, mixed>  $responseSchema  Expected JSON schema for this handler's section
     */
    public function __construct(
        public string $handlerName,
        public string $description,
        public array $contextFiles,
        public string $responseKey,
        public array $responseSchema,
    ) {}
}
