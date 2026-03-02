<?php

declare(strict_types=1);

namespace App\Scripts\Runners;

use App\Services\StepResult;

interface RunnerInterface
{
    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $variables
     */
    public function execute(array $step, array $variables, string $workDir): StepResult;
}
