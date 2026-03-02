<?php

declare(strict_types=1);

namespace App\Tui\Terminal;

/**
 * Usage: Fallback strategy for macOS Terminal.app — focuses windows by TTY and opens new tabs via AppleScript.
 */
class MacTerminalStrategy implements TerminalStrategy, TerminalTabOpener
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
        $termProgram = $this->env['TERM_PROGRAM'] ?? '';

        if ($termProgram === 'Apple_Terminal') {
            return true;
        }

        // Fallback: available when no tmux/iTerm detected on macOS
        return $termProgram === ''
            && empty($this->env['TMUX'])
            && PHP_OS_FAMILY === 'Darwin';
    }

    public function focus(int $pid): FocusResult
    {
        $tty = $this->getTtyForPid($pid);
        if ($tty === null) {
            return FocusResult::notFound();
        }

        $script = $this->buildFocusAppleScript($tty);
        $output = [];
        // Safe: AppleScript is built with escaped TTY path
        exec('osascript -e '.escapeshellarg($script).' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            return FocusResult::error('Failed to focus Terminal.app window');
        }

        $result = trim(implode('', $output));

        return $result === 'found' ? FocusResult::success() : FocusResult::notFound();
    }

    public function openTab(string $cwd): FocusResult
    {
        $escapedPath = addslashes($cwd);
        $script = <<<APPLESCRIPT
tell application "Terminal"
    activate
    do script "cd $escapedPath" in (do script "")
end tell
APPLESCRIPT;

        $output = [];
        exec('osascript -e '.escapeshellarg($script).' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            return FocusResult::error('Failed to open Terminal.app tab');
        }

        return new FocusResult(true, 'Opened new Terminal.app tab');
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

    private function buildFocusAppleScript(string $tty): string
    {
        $escapedTty = addslashes($tty);

        return <<<APPLESCRIPT
tell application "Terminal"
    repeat with w in windows
        repeat with t in tabs of w
            if tty of t is "$escapedTty" then
                set selected tab of w to t
                set index of w to 1
                return "found"
            end if
        end repeat
    end repeat
end tell
return "not_found"
APPLESCRIPT;
    }
}
