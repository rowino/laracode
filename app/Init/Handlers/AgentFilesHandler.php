<?php

declare(strict_types=1);

namespace App\Init\Handlers;

use App\Agents\AgentInterface;
use App\Init\AiDecisionRequest;
use App\Init\InitContext;
use App\Init\InitHandler;

class AgentFilesHandler implements InitHandler
{
    /** @var list<array{stub: string, install: string, type: string}> */
    private const array STUBS = [
        ['stub' => 'commands/build-next.md', 'install' => 'installCommand', 'type' => 'commands'],
        ['stub' => 'commands/process-comments.md', 'install' => 'installCommand', 'type' => 'commands'],
        ['stub' => 'skills/generate-tasks/SKILL.md', 'install' => 'installSkill', 'type' => 'skills'],
        ['stub' => 'skills/yaml-scripts/SKILL.md', 'install' => 'installSkill', 'type' => 'skills'],
        ['stub' => 'hooks/session-start.php', 'install' => 'installHook', 'type' => 'hooks'],
    ];

    public function name(): string
    {
        return 'agent_files';
    }

    public function priority(): int
    {
        return 50;
    }

    public function decisionRequest(InitContext $ctx): ?AiDecisionRequest
    {
        return null;
    }

    /** @param  array<string, mixed>  $decisions */
    public function processDecisions(InitContext $ctx, array $decisions): void {}

    /** @return array<string, mixed> */
    public function getPromptContext(InitContext $ctx): array
    {
        return [];
    }

    public function apply(InitContext $ctx): void
    {
        $this->createDirectories($ctx);

        if ($ctx->hasAgent && $ctx->agent !== null) {
            $this->copyAgentStubs($ctx);
            $this->copyStatuslineScript($ctx);
            $this->createLaracodeFiles($ctx);
            $this->configureAgentSettings($ctx);
        } else {
            $this->createLaracodeFiles($ctx);
        }

    }

    /** @return array<string, string> */
    public function summarize(InitContext $ctx): array
    {
        $summary = [];

        if ($ctx->hasAgent && $ctx->agent !== null) {
            $fileList = [];
            foreach (self::STUBS as $item) {
                $targetPath = $this->probeAgentInstallPath($ctx->agent, $item['stub'], $item['install']);
                if ($targetPath !== null) {
                    $fileList[] = $targetPath;
                }
            }
            $summary['Files to install'] = ! empty($fileList) ? implode(', ', $fileList) : '(none)';

            $conflicts = $this->detectConflicts($ctx);
            if (! empty($conflicts)) {
                $conflictPaths = array_map(fn (array $c) => sprintf('%s (%.0f%% similar)', $c['path'], $c['similarity']), $conflicts);
                $summary['Files to overwrite'] = implode(', ', $conflictPaths);
            }
        }

        return $summary;
    }

    /** @return list<array{path: string, similarity: float}> */
    private function detectConflicts(InitContext $ctx): array
    {
        $agent = $ctx->agent;
        if ($agent === null) {
            return [];
        }

        $conflicts = [];

        foreach (self::STUBS as $item) {
            $targetRelPath = $this->probeAgentInstallPath($agent, $item['stub'], $item['install']);
            if ($targetRelPath === null) {
                continue;
            }

            $conflict = $this->checkFileConflict($ctx->projectPath, $targetRelPath, $item['stub']);
            if ($conflict !== null) {
                $conflicts[] = $conflict;
            }
        }

        $configBase = $this->probeAgentConfigBase($agent);
        if ($configBase !== null) {
            $statusRelPath = $configBase.'/scripts/statusline.php';
            $conflict = $this->checkFileConflict($ctx->projectPath, $statusRelPath, 'scripts/statusline.php');
            if ($conflict !== null) {
                $conflicts[] = $conflict;
            }
        }

        return $conflicts;
    }

    /** @return array{path: string, similarity: float}|null */
    private function checkFileConflict(string $projectPath, string $relPath, string $stubName): ?array
    {
        $absPath = $projectPath.'/'.$relPath;
        if (! file_exists($absPath)) {
            return null;
        }

        $existing = file_get_contents($absPath);
        if ($existing === false) {
            return null;
        }

        $stubContent = $this->loadStub($stubName);
        $similarity = 0.0;
        similar_text(trim($existing), trim($stubContent), $similarity);

        if ($similarity >= 90.0) {
            return null;
        }

        return ['path' => $relPath, 'similarity' => $similarity];
    }

    private function createDirectories(InitContext $ctx): void
    {
        $directories = [
            '.laracode',
            '.laracode/specs',
        ];

        foreach ($directories as $dir) {
            $fullPath = $ctx->projectPath.'/'.$dir;
            if (! is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
        }
    }

    private function copyAgentStubs(InitContext $ctx): void
    {
        $agent = $ctx->agent;
        if ($agent === null) {
            return;
        }

        foreach (self::STUBS as $item) {
            $targetRelPath = $this->probeAgentInstallPath($agent, $item['stub'], $item['install']);

            if ($targetRelPath === null) {
                continue;
            }

            if ($item['type'] === 'skills') {
                $this->copySkillDirectory($ctx, $item['stub'], $targetRelPath);

                continue;
            }

            $targetAbsPath = $ctx->projectPath.'/'.$targetRelPath;
            $stubContent = $this->loadStub($item['stub']);
            $this->handleFileInstall($targetAbsPath, $stubContent);
        }
    }

    private function copySkillDirectory(InitContext $ctx, string $skillStubPath, string $targetSkillMdPath): void
    {
        $stubBaseDir = dirname(__DIR__, 3).'/stubs/'.dirname($skillStubPath);
        $targetBaseDir = $ctx->projectPath.'/'.dirname($targetSkillMdPath);

        if (! is_dir($stubBaseDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stubBaseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $relativePath = substr($file->getPathname(), strlen($stubBaseDir) + 1);
            $targetPath = $targetBaseDir.'/'.$relativePath;
            $stubContent = file_get_contents($file->getPathname());
            if ($stubContent !== false) {
                $this->handleFileInstall($targetPath, $stubContent);
            }
        }
    }

    private function copyStatuslineScript(InitContext $ctx): void
    {
        $agent = $ctx->agent;
        if ($agent === null) {
            return;
        }

        $configBasePath = $this->probeAgentConfigBase($agent);
        if ($configBasePath === null) {
            return;
        }

        $scriptsRelPath = $configBasePath.'/scripts/statusline.php';
        $scriptsAbsPath = $ctx->projectPath.'/'.$scriptsRelPath;
        $stubContent = $this->loadStub('scripts/statusline.php');
        $this->handleFileInstall($scriptsAbsPath, $stubContent);
    }

    private function createLaracodeFiles(InitContext $ctx): void
    {
        $settingsPath = $ctx->projectPath.'/.laracode/settings.json';

        if (! file_exists($settingsPath)) {
            file_put_contents($settingsPath, $this->getSettingsContent(array_values($ctx->watchPaths)));
        } else {
            if (! empty($ctx->watchPaths)) {
                $ctx->settingsWriter->mergeProject(['watch' => ['paths' => $ctx->watchPaths]], $ctx->projectPath);
            }
        }

        $sampleDir = $ctx->projectPath.'/.laracode/specs/example';
        $samplePath = $sampleDir.'/tasks.json';
        if (! is_dir($sampleDir)) {
            mkdir($sampleDir, 0755, true);
        }
        if (! file_exists($samplePath)) {
            $stub = $this->loadStub('samples/tasks.json');
            file_put_contents($samplePath, str_replace('{{CREATED_DATE}}', date('c'), $stub));
        }
    }

    private function configureAgentSettings(InitContext $ctx): void
    {
        $agent = $ctx->agent;
        if ($agent === null) {
            return;
        }

        $configBase = $this->probeAgentConfigBase($agent);
        if ($configBase === null) {
            return;
        }

        $scriptsFolder = $configBase.'/scripts';

        $originalDir = getcwd();
        chdir($ctx->projectPath);

        try {
            $existingSettings = $agent->getSettings('project');

            if (! isset($existingSettings['statusLine'])) {
                $agent->updateSettings('project', [
                    'statusLine' => [
                        'type' => 'command',
                        'command' => 'php '.$scriptsFolder.'/statusline.php',
                    ],
                ]);
            }

            if (! isset($existingSettings['hooks']['SessionStart'])) {
                $agent->updateSettings('project', [
                    'hooks' => [
                        'SessionStart' => [
                            [
                                'matcher' => '*',
                                'hooks' => [
                                    [
                                        'type' => 'command',
                                        'command' => 'php .claude/hooks/session-start.php',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);
            }
        } finally {
            if ($originalDir !== false) {
                chdir($originalDir);
            }
        }
    }

    private function probeAgentInstallPath(AgentInterface $agent, string $stubPath, string $installMethod): ?string
    {
        $probeDir = sys_get_temp_dir().'/laracode-probe-'.uniqid();
        mkdir($probeDir, 0755, true);

        $sourceDir = $probeDir.'/source';
        $sourceSubdir = $sourceDir.'/'.dirname($stubPath);
        if (! is_dir($sourceSubdir)) {
            mkdir($sourceSubdir, 0755, true);
        }
        $sourceFile = $sourceDir.'/'.$stubPath;
        file_put_contents($sourceFile, 'probe');

        $targetDir = $probeDir.'/target';
        mkdir($targetDir, 0755, true);

        $originalDir = getcwd();
        chdir($targetDir);

        try {
            $agent->{$installMethod}($sourceFile);

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($targetDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    return substr($file->getPathname(), strlen($targetDir) + 1);
                }
            }

            return null;
        } finally {
            if ($originalDir !== false) {
                chdir($originalDir);
            }

            $this->cleanupTempDir($probeDir);
        }
    }

    private function probeAgentConfigBase(AgentInterface $agent): ?string
    {
        $result = $this->probeAgentInstallPath($agent, 'probe.txt', 'installConfig');
        if ($result === null) {
            return null;
        }

        return dirname($result);
    }

    private function handleFileInstall(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! file_exists($filePath)) {
            file_put_contents($filePath, $content);

            return;
        }

        $existing = file_get_contents($filePath);
        if ($existing === false) {
            file_put_contents($filePath, $content);

            return;
        }

        $similarity = 0.0;
        similar_text(trim($existing), trim($content), $similarity);

        if ($similarity >= 90.0) {
            return;
        }

        file_put_contents($filePath, $content);
    }

    private function loadStub(string $filename): string
    {
        $stubPath = dirname(__DIR__, 3).'/stubs/'.$filename;
        $content = file_get_contents($stubPath);

        if ($content === false) {
            throw new \RuntimeException("Stub file not found: {$stubPath}");
        }

        return $content;
    }

    /** @param  list<string>  $watchPaths */
    private function getSettingsContent(array $watchPaths): string
    {
        $stub = $this->loadStub('settings.json');
        $settings = json_decode($stub, true);

        if (is_array($settings) && isset($settings['watch'])) {
            $settings['watch']['paths'] = $watchPaths;
        }

        return json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function cleanupTempDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
