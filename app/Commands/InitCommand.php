<?php

declare(strict_types=1);

namespace App\Commands;

use App\Agents\AgentDetector;
use App\Agents\AgentInterface;
use App\Agents\AgentRegistry;
use App\Enums\BuildMode;
use App\Frameworks\FrameworkInterface;
use App\Services\ProjectAnalyzer;
use App\Services\Settings\SettingsPath;
use App\Services\Settings\SettingsWriter;
use LaravelZero\Framework\Commands\Command;

class InitCommand extends Command
{
    protected $signature = 'init
        {path? : Project path (defaults to current directory)}
        {--force : Overwrite existing files}';

    protected $description = 'Initialize LaraCode in an existing project';

    public function __construct(
        private AgentRegistry $agentRegistry,
        private AgentDetector $agentDetector,
        private ProjectAnalyzer $projectAnalyzer,
        private SettingsWriter $settingsWriter
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string|null $pathArg */
        $pathArg = $this->argument('path');
        $cwd = getcwd();
        $projectPath = $pathArg ?? ($cwd !== false ? $cwd : '.');
        $realPath = realpath($projectPath);
        $projectPath = $realPath !== false ? $realPath : $projectPath;

        if (! is_dir($projectPath)) {
            $this->error("Directory not found: {$projectPath}");

            return self::FAILURE;
        }

        $wasFirstTimeSetup = $this->isFirstTimeSetup();
        if ($wasFirstTimeSetup) {
            $this->runGlobalSetup();
        }

        $this->info("Initializing LaraCode in: {$projectPath}");
        $this->newLine();

        $force = $this->option('force');

        $agent = $this->agentRegistry->getDefault();

        $analysis = $this->analyzeProject($projectPath);

        if (! $wasFirstTimeSetup) {
            $this->promptProjectModeOverride($projectPath);
        }

        $this->createDirectories($projectPath);

        $this->copyAgentFiles($projectPath, $agent, $force);

        $this->createLaracodeFiles($projectPath, $analysis['watchPaths'], $force);

        $this->updateSettingsWithStatusline($projectPath, $agent);

        $this->newLine();
        $this->info('✓ LaraCode initialized!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Create a feature spec in .laracode/specs/<feature>/tasks.json');
        $this->line('  2. Run: <info>laracode build .laracode/specs/<feature>/tasks.json</info>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function isFirstTimeSetup(): bool
    {
        $userSettingsPath = SettingsPath::user();

        return $userSettingsPath === '' || ! file_exists($userSettingsPath);
    }

    private function runGlobalSetup(): void
    {
        $this->info('First-time setup detected. Let\'s configure LaraCode.');
        $this->newLine();

        $installed = $this->configureAgents();

        $defaultMode = $this->configureDefaultMode();

        $settings = [
            'agents' => [
                'default' => $this->agentRegistry->getDefaultName(),
                'paths' => $installed,
            ],
            'defaultMode' => $defaultMode,
        ];

        $this->settingsWriter->writeUser($settings);

        $this->newLine();
        $this->info('Global settings saved to ~/.laracode/settings.json');
        $this->newLine();
    }

    /**
     * @return array<string, string>
     */
    private function configureAgents(): array
    {
        $installed = $this->agentDetector->detectInstalled();

        if (empty($installed)) {
            $this->warn('No coding agents detected. Make sure you have a coding agent installed.');
            $this->line('  Supported agents: '.implode(', ', AgentDetector::KNOWN_AGENTS));
            $this->newLine();
        } else {
            $this->line('Detected coding agents:');
            foreach ($installed as $name => $path) {
                $this->line("  <info>{$name}</info>: {$path}");
            }
            $this->newLine();
        }

        $agentNames = [...array_keys($installed), 'Custom'];
        $defaultName = $this->agentRegistry->getDefaultName();
        $defaultIndex = array_search($defaultName, $agentNames, true);
        $defaultIndex = $defaultIndex !== false ? $defaultIndex : 0;

        /** @var string $selectedAgent */
        $selectedAgent = $this->choice(
            'Select default agent',
            $agentNames,
            $defaultIndex
        );

        if ($selectedAgent === 'Custom') {
            $installed = $this->promptCustomAgent($installed);
        } elseif ($this->agentRegistry->has($selectedAgent)) {
            $this->agentRegistry->setDefault($selectedAgent);
        }

        return $installed;
    }

    private function configureDefaultMode(): string
    {
        $cases = BuildMode::cases();
        $modeLabels = array_map(fn (BuildMode $mode) => $mode->description(), $cases);

        /** @var string $selectedLabel */
        $selectedLabel = $this->choice(
            'Select default permission mode',
            $modeLabels,
            0
        );

        foreach ($cases as $mode) {
            if ($mode->description() === $selectedLabel) {
                return $mode->value;
            }
        }

        return BuildMode::Interactive->value;
    }

    /**
     * @return array{framework: FrameworkInterface, watchPaths: list<string>, hasComposer: bool}
     */
    private function analyzeProject(string $projectPath): array
    {
        $analysis = $this->projectAnalyzer->analyze($projectPath);

        $this->line("Detected framework: <info>{$analysis['framework']->name()}</info>");

        if (! empty($analysis['watchPaths'])) {
            $pathList = implode(', ', $analysis['watchPaths']);
            $this->line("Suggested watch paths: <info>{$pathList}</info>");

            $useDefaultPaths = $this->confirm('Use these paths?', true);

            if (! $useDefaultPaths) {
                $customPaths = $this->ask('Enter comma-separated watch paths');
                if ($customPaths !== null && $customPaths !== '') {
                    $analysis['watchPaths'] = array_map('trim', explode(',', $customPaths));
                }
            }
        }

        $this->newLine();

        return $analysis;
    }

    private function promptProjectModeOverride(string $projectPath): void
    {
        $userSettingsPath = SettingsPath::user();
        if ($userSettingsPath === '' || ! file_exists($userSettingsPath)) {
            return;
        }

        $content = file_get_contents($userSettingsPath);
        if ($content === false) {
            return;
        }

        $userSettings = json_decode($content, true);
        if (! is_array($userSettings)) {
            return;
        }

        $globalMode = $userSettings['defaultMode'] ?? 'interactive';
        $this->line("Your global default mode is '<info>{$globalMode}</info>'.");

        $override = $this->confirm('Use different mode for this project?', false);
        if ($override) {
            $cases = BuildMode::cases();
            $modeLabels = array_map(fn (BuildMode $mode) => $mode->description(), $cases);

            /** @var string $selectedLabel */
            $selectedLabel = $this->choice(
                'Select project permission mode',
                $modeLabels,
                0
            );

            $projectMode = BuildMode::Interactive->value;
            foreach ($cases as $mode) {
                if ($mode->description() === $selectedLabel) {
                    $projectMode = $mode->value;
                    break;
                }
            }

            $this->settingsWriter->mergeProject(['defaultMode' => $projectMode], $projectPath);
            $this->line("  <info>Saved</info> project mode: {$projectMode}");
        }

        $this->newLine();
    }

    private function createDirectories(string $projectPath): void
    {
        $directories = [
            '.laracode',
            '.laracode/specs',
        ];

        foreach ($directories as $dir) {
            $fullPath = $projectPath.'/'.$dir;
            if (! is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
                $this->line("  <info>Created</info> {$dir}/");
            } else {
                $this->line("  <comment>Exists</comment> {$dir}/");
            }
        }

        $this->newLine();
    }

    /**
     * @param  bool|array<int, string>|string|null  $force
     */
    private function copyAgentFiles(string $projectPath, AgentInterface $agent, $force): void
    {
        $isForce = is_bool($force) ? $force : false;

        $this->line("Creating agent files for: <info>{$agent->name()}</info>");

        $stubs = [
            ['stub' => 'commands/build-next.md', 'install' => 'installCommand', 'type' => 'commands'],
            ['stub' => 'commands/process-comments.md', 'install' => 'installCommand', 'type' => 'commands'],
            ['stub' => 'skills/generate-tasks/SKILL.md', 'install' => 'installSkill', 'type' => 'skills'],
            ['stub' => 'hooks/session-start.php', 'install' => 'installHook', 'type' => 'hooks'],
        ];

        foreach ($stubs as $item) {
            $targetRelPath = $this->probeAgentInstallPath($agent, $item['stub'], $item['install']);

            if ($targetRelPath === null) {
                $this->line('  <comment>Skipped</comment> '.basename($item['stub'])." (agent does not support {$item['type']})");

                continue;
            }

            $targetAbsPath = $projectPath.'/'.$targetRelPath;
            $stubContent = $this->loadStub($item['stub']);
            $this->handleCommandFile($targetAbsPath, $stubContent, $targetRelPath, $isForce);
        }

        $configBasePath = $this->probeAgentConfigBase($agent);
        if ($configBasePath !== null) {
            $scriptsRelPath = $configBasePath.'/scripts/statusline.php';
            $scriptsAbsPath = $projectPath.'/'.$scriptsRelPath;
            $stubContent = $this->loadStub('scripts/statusline.php');
            $this->handleCommandFile($scriptsAbsPath, $stubContent, $scriptsRelPath, $isForce);
        }
    }

    /**
     * @param  list<string>  $watchPaths
     * @param  bool|array<int, string>|string|null  $force
     */
    private function createLaracodeFiles(string $projectPath, array $watchPaths, bool|array|string|null $force): void
    {
        $isForce = is_bool($force) ? $force : false;

        $settingsPath = $projectPath.'/.laracode/settings.json';

        if (! file_exists($settingsPath) || $isForce) {
            file_put_contents($settingsPath, $this->getSettingsContent($watchPaths));
            $this->line('  <info>Created</info> .laracode/settings.json');
        } else {
            $this->settingsWriter->mergeProject(['watch' => ['paths' => $watchPaths]], $projectPath);
            $this->line('  <info>Updated</info> .laracode/settings.json (watch paths)');
        }

        $samplePath = $projectPath.'/.laracode/specs/example/tasks.json';
        $sampleDir = dirname($samplePath);
        if (! is_dir($sampleDir)) {
            mkdir($sampleDir, 0755, true);
        }
        if (! file_exists($samplePath) || $isForce) {
            file_put_contents($samplePath, $this->getSampleTasksContent());
            $this->line('  <info>Created</info> .laracode/specs/example/tasks.json');
        } else {
            $this->line('  <comment>Exists</comment> .laracode/specs/example/tasks.json');
        }
    }

    /**
     * @param  array<string, string>  $installed
     * @return array<string, string>
     */
    private function promptCustomAgent(array $installed): array
    {
        /** @var string|null $customPath */
        $customPath = $this->ask('Enter the executable path');
        if ($customPath === null || $customPath === '') {
            $this->warn('No path provided. Skipping custom agent.');

            return $installed;
        }

        if (! $this->agentDetector->validatePath($customPath)) {
            $this->warn("Invalid executable path: {$customPath}");

            /** @var string $retry */
            $retry = $this->choice(
                'What would you like to do?',
                ['Try another path', 'Skip'],
                0
            );

            if ($retry === 'Try another path') {
                return $this->promptCustomAgent($installed);
            }

            return $installed;
        }

        /** @var string $agentName */
        $agentName = $this->ask('Enter the agent name for this executable') ?? 'custom';
        $installed[$agentName] = $customPath;

        if ($this->agentRegistry->has($agentName)) {
            $this->agentRegistry->setDefault($agentName);
        }

        return $installed;
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
                    $relativePath = substr($file->getPathname(), strlen($targetDir) + 1);

                    return $relativePath;
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

    private function getBuildNextContent(): string
    {
        return $this->loadStub('commands/build-next.md');
    }

    private function handleCommandFile(string $filePath, string $templateContent, string $displayName, bool $force = false): void
    {
        $dir = dirname($filePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! file_exists($filePath)) {
            file_put_contents($filePath, $templateContent);
            $this->line("  <info>Created</info> {$displayName}");

            return;
        }

        if ($force) {
            file_put_contents($filePath, $templateContent);
            $this->line("  <info>Overwritten</info> {$displayName}");

            return;
        }

        $existingContent = file_get_contents($filePath);
        if ($existingContent === false) {
            file_put_contents($filePath, $templateContent);
            $this->line("  <info>Created</info> {$displayName}");

            return;
        }

        $similarity = $this->calculateSimilarity($existingContent, $templateContent);

        if ($similarity >= 90.0) {
            $this->line("  <comment>Skipped</comment> {$displayName} (similar to template)");

            return;
        }

        $this->line("  <comment>Conflict</comment> {$displayName} (differs from template)");
        $choice = $this->choice(
            "    How would you like to handle {$displayName}?",
            ['Ignore (keep existing)', 'Overwrite (use template)', 'Merge (3-way merge)'],
            0
        );

        if ($choice === 'Ignore (keep existing)') {
            $this->line("  <comment>Kept</comment> {$displayName}");

            return;
        }

        if ($choice === 'Overwrite (use template)') {
            file_put_contents($filePath, $templateContent);
            $this->line("  <info>Overwritten</info> {$displayName}");

            return;
        }

        $this->mergeCommandFile($filePath, $templateContent, $displayName);
    }

    private function calculateSimilarity(string $existing, string $template): float
    {
        $existing = trim($existing);
        $template = trim($template);

        if ($existing === $template) {
            return 100.0;
        }

        $similarity = 0;
        similar_text($existing, $template, $similarity);

        return $similarity;
    }

    private function mergeCommandFile(string $filePath, string $templateContent, string $displayName): void
    {
        $backupPath = $filePath.'.backup';
        $basePath = $filePath.'.base';
        $templatePath = $filePath.'.template';

        $existingContent = file_get_contents($filePath);
        if ($existingContent === false) {
            $existingContent = '';
        }

        file_put_contents($backupPath, $existingContent);
        file_put_contents($basePath, '');
        file_put_contents($templatePath, $templateContent);

        $escapedFilePath = escapeshellarg($filePath);
        $escapedBasePath = escapeshellarg($basePath);
        $escapedTemplatePath = escapeshellarg($templatePath);
        $mergeCommand = "git merge-file -p {$escapedFilePath} {$escapedBasePath} {$escapedTemplatePath}";

        $output = [];
        $returnCode = 0;
        exec($mergeCommand, $output, $returnCode);

        if ($returnCode === 0 || $returnCode === 1) {
            $mergedContent = implode("\n", $output);
            file_put_contents($filePath, $mergedContent);
            $this->line("  <info>Merged</info> {$displayName} (backup: {$displayName}.backup)");

            if ($returnCode === 1) {
                $this->line('  <comment>Warning:</comment> Merge conflicts detected. Review the file manually.');
            }
        } else {
            $this->line("  <error>Merge failed</error> {$displayName} (keeping original)");
        }

        @unlink($basePath);
        @unlink($templatePath);
    }

    private function getSampleTasksContent(): string
    {
        $stub = $this->loadStub('samples/tasks.json');

        return str_replace('{{CREATED_DATE}}', date('c'), $stub);
    }

    private function getGenerateTasksContent(): string
    {
        return $this->loadStub('skills/generate-tasks/SKILL.md');
    }

    private function getProcessCommentsContent(): string
    {
        return $this->loadStub('commands/process-comments.md');
    }

    /**
     * @param  list<string>  $watchPaths
     */
    private function getSettingsContent(array $watchPaths): string
    {
        $stub = $this->loadStub('settings.json');
        $settings = json_decode($stub, true);

        if (is_array($settings) && isset($settings['watch'])) {
            $settings['watch']['paths'] = $watchPaths;
        }

        return json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function loadStub(string $filename): string
    {
        $stubPath = dirname(__DIR__, 2).'/stubs/'.$filename;
        $content = file_get_contents($stubPath);

        if ($content === false) {
            throw new \RuntimeException("Stub file not found: {$stubPath}");
        }

        return $content;
    }

    private function getStatuslineContent(): string
    {
        return $this->loadStub('scripts/statusline.php');
    }

    private function getSessionStartHookContent(): string
    {
        return $this->loadStub('hooks/session-start.php');
    }

    private function updateSettingsWithStatusline(string $projectPath, AgentInterface $agent): void
    {
        $configBase = $this->probeAgentConfigBase($agent);
        if ($configBase === null) {
            return;
        }

        $scriptsFolder = $configBase.'/scripts';

        $originalDir = getcwd();
        chdir($projectPath);

        try {
            $existingSettings = $agent->getSettings('project');

            if (! isset($existingSettings['statusLine'])) {
                $agent->updateSettings('project', [
                    'statusLine' => [
                        'type' => 'command',
                        'command' => 'php '.$scriptsFolder.'/statusline.php',
                    ],
                ]);
                $this->line('  <info>Updated</info> agent settings (statusLine config)');
            } else {
                $this->line('  <comment>Exists</comment> agent settings (statusLine already configured)');
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
                $this->line('  <info>Updated</info> agent settings (SessionStart hook)');
            } else {
                $this->line('  <comment>Exists</comment> agent settings (SessionStart hook already configured)');
            }

        } finally {
            if ($originalDir !== false) {
                chdir($originalDir);
            }
        }
    }
}
