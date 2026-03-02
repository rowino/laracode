<?php

declare(strict_types=1);

namespace App\Tui\Terminal;

/**
 * Usage: Focuses an iTerm2 tab/session by matching TTY via AppleScript.
 */
class ItermStrategy implements TerminalStrategy, TerminalTabOpener
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
        return ($this->env['TERM_PROGRAM'] ?? '') === 'iTerm.app';
    }

    public function focus(int $pid): FocusResult
    {
        $tty = $this->getTtyForPid($pid);
        if ($tty === null) {
            return FocusResult::notFound();
        }

        $script = $this->buildAppleScript($tty);
        $output = [];
        // Safe: AppleScript is built with escaped TTY path
        exec('osascript -e '.escapeshellarg($script).' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            return FocusResult::error('Failed to focus iTerm2 session');
        }

        $result = trim(implode('', $output));

        return $result === 'found' ? FocusResult::success() : FocusResult::notFound();
    }

    public function openTab(string $cwd): FocusResult
    {
        $escapedPath = addslashes($cwd);
        $script = <<<APPLESCRIPT
tell application "iTerm2"
    activate
    tell current window
        create tab with default profile
        tell current session
            write text "cd $escapedPath"
        end tell
    end tell
end tell
APPLESCRIPT;

        $output = [];
        // Safe: AppleScript is built with addslashes-escaped path, no shell injection
        exec('osascript -e '.escapeshellarg($script).' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            return FocusResult::error('Failed to open iTerm2 tab');
        }

        return new FocusResult(true, 'Opened new iTerm2 tab');
    }

    private function getTtyForPid(int $pid): ?string
    {
        $current = $pid;
        $visited = [];

        while ($current > 1 && ! isset($visited[$current])) {
            $visited[$current] = true;

            $output = [];
            // Safe: $current is an integer, no injection risk
            exec(sprintf('lsof -p %d -a -d 0 -Fn 2>/dev/null', $current), $output);

            foreach ($output as $line) {
                if (str_starts_with($line, 'n') && str_contains($line, '/dev/ttys')) {
                    return substr($line, 1);
                }
            }

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

    private function buildAppleScript(string $tty): string
    {
        $escapedTty = addslashes($tty);

        return <<<APPLESCRIPT
tell application "iTerm2"
    repeat with w in windows
        repeat with t in tabs of w
            repeat with s in sessions of t
                if tty of s is "$escapedTty" then
                    select t
                    tell w to select
                    return "found"
                end if
            end repeat
        end repeat
    end repeat
end tell
return "not_found"
APPLESCRIPT;
    }
}
