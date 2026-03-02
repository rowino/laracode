<?php

declare(strict_types=1);

namespace App\Commands\Scripts;

use App\Scripts\ScriptLoader;
use LaravelZero\Framework\Commands\Command;

/**
 * Usage: laracode script:list [--json] [--all] — list discovered YAML scripts.
 */
class ListCommand extends Command
{
    protected $signature = 'script:list
        {--json : Output as JSON}
        {--all : Include hidden scripts}';

    protected $description = 'List all discovered YAML scripts';

    public function handle(ScriptLoader $loader): int
    {
        $cwd = getcwd();
        $scripts = $loader->discover($cwd !== false ? $cwd : '.');

        $showAll = (bool) $this->option('all');

        if (! $showAll) {
            $scripts = array_filter($scripts, fn ($s) => ! $s->hidden);
        }

        if ($scripts === []) {
            $this->warn('No scripts found.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            return $this->outputJson($scripts);
        }

        return $this->outputTable($scripts, $showAll);
    }

    /**
     * @param  array<string, \App\Scripts\ScriptDefinition>  $scripts
     */
    private function outputTable(array $scripts, bool $showAll): int
    {
        $headers = ['Name', 'Description', 'Source'];

        if ($showAll) {
            $headers[] = 'Hidden';
        }

        $rows = [];
        foreach ($scripts as $script) {
            $row = [
                $script->name,
                $script->description ?: '-',
                $this->shortenPath($script->sourcePath),
            ];

            if ($showAll) {
                $row[] = $script->hidden ? 'Yes' : '';
            }

            $rows[] = $row;
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, \App\Scripts\ScriptDefinition>  $scripts
     */
    private function outputJson(array $scripts): int
    {
        $data = [];
        foreach ($scripts as $script) {
            $data[] = [
                'name' => $script->name,
                'description' => $script->description,
                'version' => $script->version,
                'hidden' => $script->hidden,
                'source' => $script->sourcePath,
            ];
        }

        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function shortenPath(string $path): string
    {
        $cwd = getcwd();
        if ($cwd !== false && str_starts_with($path, $cwd)) {
            return ltrim(substr($path, strlen($cwd)), '/') ?: '.';
        }

        $home = getenv('HOME');
        if ($home !== false && str_starts_with($path, $home)) {
            return '~'.substr($path, strlen($home));
        }

        return $path;
    }
}
