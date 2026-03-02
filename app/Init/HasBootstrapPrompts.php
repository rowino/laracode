<?php

declare(strict_types=1);

namespace App\Init;

interface HasBootstrapPrompts
{
    /** @return list<array{id: string, type: string, label: string, default?: mixed, options?: list<string|array{label: string, value: string}>, required?: bool}> */
    public function getBootstrapPrompts(InitContext $ctx): array;

    /** @param  array<string, mixed>  $responses */
    public function processBootstrapResponses(InitContext $ctx, array $responses): void;
}
