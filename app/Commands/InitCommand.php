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
        if (! file_exists($buildNextPath) || $force) {
            file_put_contents($buildNextPath, $this->getBuildNextContent());
            $this->line('  <info>Created</info> .claude/commands/build-next.md');
        } else {
            $this->line('  <comment>Exists</comment> .claude/commands/build-next.md (use --force to overwrite)');
        }

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
        return <<<'MD'
# Build Next Task

**IMPORTANT: Execute immediately. Do NOT enter plan mode. Do NOT ask for approval. Just do the work.**

Implement the next pending task from the tasks.json file, then exit.

## Process

1. **Find and read the active tasks.json file**
   - Look in `.laracode/specs/*/tasks.json`
   - Read the file with pending tasks

2. **Select the next task**
   - Find first task with `"status": "pending"` (lowest priority number first, then by id)
   - If no pending tasks, output "All tasks completed!" and exit immediately

3. **Mark task in progress**
   - Update the task's status to `"in_progress"` in tasks.json
   - Save immediately

4. **Implement the task NOW**
   - Read the task description and steps
   - Execute each step immediately
   - Do NOT ask for confirmation - just implement
   - Follow project best practices

5. **On completion**
   - Update task status to `"completed"` in tasks.json
   - Output: "✓ Completed: [task description]"

6. **Exit immediately**
   - Only implement ONE task per invocation
   - Exit after completing - do not continue to next task

## Critical Rules

- **NO PLANNING MODE** - Execute directly
- **NO APPROVAL REQUESTS** - Just do the implementation
- Only ONE task per invocation
- Always update tasks.json before and after
- Exit after completing the single task
MD;
    }

    private function getSampleTasksContent(): string
    {
        return json_encode([
            'title' => 'Example Feature',
            'spec_file' => 'spec.md',
            'tasks' => [
                [
                    'id' => 1,
                    'group' => 'Setup',
                    'priority' => 1,
                    'description' => 'Example task - replace with your own',
                    'status' => 'pending',
                    'steps' => [
                        'Step 1: Describe what to do',
                        'Step 2: Add more details',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
