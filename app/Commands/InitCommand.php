<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class InitCommand extends Command
{
    protected $signature = 'init
        {path? : Project path (defaults to current directory)}
        {--force : Overwrite existing files}';

    protected $description = 'Initialize LaraCode in an existing project';

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

        $this->info("Initializing LaraCode in: {$projectPath}");
        $this->newLine();

        $force = $this->option('force');

        // Create directory structure
        $directories = [
            '.laracode',
            '.laracode/specs',
            '.claude',
            '.claude/commands',
            '.claude/skills',
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

        // Create build-next.md command
        $buildNextPath = $projectPath.'/.claude/commands/build-next.md';
        $this->handleCommandFile($buildNextPath, $this->getBuildNextContent(), '.claude/commands/build-next.md');

        // Create generate-tasks.md skill
        $generateTasksPath = $projectPath.'/.claude/skills/generate-tasks/SKILL.md';
        $this->handleCommandFile($generateTasksPath, $this->getGenerateTasksContent(), '.claude/skills/generate-tasks/SKILL.md');

        // Create sample tasks.json template
        $samplePath = $projectPath.'/.laracode/specs/example/tasks.json';
        $sampleDir = dirname($samplePath);
        if (! is_dir($sampleDir)) {
            mkdir($sampleDir, 0755, true);
        }
        if (! file_exists($samplePath) || $force) {
            file_put_contents($samplePath, $this->getSampleTasksContent());
            $this->line('  <info>Created</info> .laracode/specs/example/tasks.json');
        } else {
            $this->line('  <comment>Exists</comment> .laracode/specs/example/tasks.json');
        }

        $this->newLine();
        $this->info('✓ LaraCode initialized!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Create a feature spec in .laracode/specs/<feature>/tasks.json');
        $this->line('  2. Run: <info>laracode build .laracode/specs/<feature>/tasks.json</info>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function getBuildNextContent(): string
    {
        return $this->loadStub('commands/build-next.md');
    }

    private function handleCommandFile(string $filePath, string $templateContent, string $displayName): void
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

        if ($this->option('force')) {
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
        $command = "git merge-file -p {$escapedFilePath} {$escapedBasePath} {$escapedTemplatePath}";

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

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

    private function loadStub(string $filename): string
    {
        $stubPath = dirname(__DIR__, 2).'/stubs/'.$filename;
        $content = file_get_contents($stubPath);

        if ($content === false) {
            throw new \RuntimeException("Stub file not found: {$stubPath}");
        }

        return $content;
    }
}
