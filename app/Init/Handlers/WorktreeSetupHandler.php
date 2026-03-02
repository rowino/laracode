<?php

declare(strict_types=1);

namespace App\Init\Handlers;

use App\Init\AiDecisionRequest;
use App\Init\InitContext;
use App\Init\InitHandler;
use App\Services\GitHelper;

class WorktreeSetupHandler implements InitHandler
{
    public function __construct(
        private readonly GitHelper $gitHelper = new GitHelper,
    ) {}

    public function name(): string
    {
        return 'worktree';
    }

    public function priority(): int
    {
        return 40;
    }

    public function decisionRequest(InitContext $ctx): ?AiDecisionRequest
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function getPromptContext(InitContext $ctx): array
    {
        $defaultBranch = $this->gitHelper->defaultBranch($ctx->projectPath);
        $setupStubs = $this->discoverSetupStubs();

        return [
            'defaultBranch' => $defaultBranch,
            'setupStubs' => $setupStubs,
        ];
    }

    /** @param  array<string, mixed>  $decisions */
    public function processDecisions(InitContext $ctx, array $decisions): void {}

    public function apply(InitContext $ctx): void {}

    /** @return array<string, string> */
    public function summarize(InitContext $ctx): array
    {
        return [];
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    private function discoverSetupStubs(): array
    {
        $stubDir = dirname(__DIR__, 3).'/stubs/scripts/setup';
        $files = glob($stubDir.'/*.yaml');

        if ($files === false || $files === []) {
            return [];
        }

        $stubs = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $name = '';
            $description = '';

            foreach (explode("\n", $content) as $line) {
                if (str_starts_with($line, 'name:')) {
                    $name = trim(substr($line, 5));
                } elseif (str_starts_with($line, 'description:')) {
                    $description = trim(substr($line, 12));
                }

                if ($name !== '' && $description !== '') {
                    break;
                }
            }

            if ($name !== '') {
                $stubs[] = ['name' => $name, 'description' => $description];
            }
        }

        return $stubs;
    }
}
