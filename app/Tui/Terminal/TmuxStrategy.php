<?php

declare(strict_types=1);

namespace App\Tui\Terminal;

/**
 * Usage: Focuses a tmux pane by walking the PID tree to find matching pane.
 */
class TmuxStrategy implements TerminalStrategy
{
    /** @var array<string, string> */
    private array $env;

    /** @param array<string, string>|null $env */
    public function __construct(?array $env = null)
    {
        $this->env = $env ?? getenv();
    }

    public function isAvailable(): bool
    {
        return ! empty($this->env['TMUX']);
    }

    public function focus(int $pid): FocusResult
    {
        $panes = $this->listPanes();
        if ($panes === []) {
            return FocusResult::notFound();
        }

        $paneId = $this->findPaneForPid($pid, $panes);
        if ($paneId === null) {
            return FocusResult::notFound();
        }

        $this->selectPane($paneId);

        return FocusResult::success();
    }

    /**
     * @return array<int, string> Map of pane PID => pane ID
     */
    private function listPanes(): array
    {
        $output = [];
        // Safe: no user input in command
        exec("tmux list-panes -a -F '#{pane_id} #{pane_pid}' 2>/dev/null", $output, $exitCode);

        if ($exitCode !== 0) {
            return [];
        }

        $panes = [];
        foreach ($output as $line) {
            $parts = explode(' ', trim($line), 2);
            if (count($parts) === 2) {
                $panes[(int) $parts[1]] = $parts[0];
            }
        }

        return $panes;
    }

    /**
     * @param  array<int, string>  $panes
     */
    private function findPaneForPid(int $pid, array $panes): ?string
    {
        $current = $pid;
        $visited = [];

        while ($current > 1 && ! isset($visited[$current])) {
            if (isset($panes[$current])) {
                return $panes[$current];
            }

            $visited[$current] = true;
            $parent = $this->getParentPid($current);

            if ($parent === null || $parent === $current) {
                break;
            }

            $current = $parent;
        }

        return null;
    }

    private function getParentPid(int $pid): ?int
    {
        $output = [];
        // Safe: $pid is an integer, no injection risk
        exec(sprintf('ps -o ppid= -p %d 2>/dev/null', $pid), $output);

        if ($output === []) {
            return null;
        }

        $ppid = (int) trim($output[0]);

        return $ppid > 0 ? $ppid : null;
    }

    private function selectPane(string $paneId): void
    {
        $escaped = escapeshellarg($paneId);
        exec("tmux select-window -t $escaped 2>/dev/null");
        exec("tmux select-pane -t $escaped 2>/dev/null");
    }
}
