<?php

declare(strict_types=1);

namespace App\Commands;

use App\Tui\Components\KeyHelp;
use App\Tui\Components\ProgressBar;
use App\Tui\Components\SessionList;
use App\Tui\Components\StatusBar;
use App\Tui\Components\TaskDetail;
use App\Tui\Components\TaskList;
use App\Tui\DashboardState;
use App\Tui\SessionRegistry;
use App\Tui\Terminal\TerminalFocuser;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\BufferedOutput;

use function Termwind\render;
use function Termwind\renderUsing;

/**
 * Usage: Interactive multi-session build dashboard. Run `laracode show` to monitor all active build sessions.
 */
class ShowCommand extends Command
{
    protected $signature = 'show';

    protected $description = 'Monitor all active build sessions';

    private string $view = 'list';

    private int $selectedIndex = 0;

    /** @var array<array{tasksPath: string, pid: int, startedAt: string, mode: string, agent: string, projectPath: string, status: string, completedAt?: string}> */
    private array $sessions = [];

    private bool $running = true;

    private int $exitCode = self::SUCCESS;

    private ?string $flashMessage = null;

    public function __construct(
        private SessionRegistry $registry,
        private SessionList $sessionList,
        private KeyHelp $keyHelp,
        private TaskList $taskList,
        private TaskDetail $taskDetail,
        private ProgressBar $progressBar,
        private StatusBar $statusBar,
        private TerminalFocuser $terminalFocuser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->sessions = $this->registry->getSessions();

        $this->setupTerminal();
        $this->registerSignalHandlers();

        $lastRefresh = 0;
        $dirty = true;

        try {
            while ($this->running) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                $key = $this->readKey();
                if ($key !== null) {
                    $dirty = $this->handleKey($key) || $dirty;
                }

                $now = time();
                if ($now - $lastRefresh >= 2) {
                    $this->sessions = $this->registry->getSessions();
                    $sessionCount = count($this->sessions);
                    if ($this->selectedIndex >= $sessionCount) {
                        $this->selectedIndex = max(0, $sessionCount - 1);
                    }
                    $lastRefresh = $now;
                    $dirty = true;
                }

                if ($dirty) {
                    $this->renderCurrentView();
                    $dirty = false;
                }

                usleep(50000);
            }
        } finally {
            $this->restoreTerminal();
        }

        return $this->exitCode;
    }

    private function setupTerminal(): void
    {
        echo "\e[?1049h";
        system('stty -icanon -echo 2>/dev/null');
        stream_set_blocking(STDIN, false);
        echo "\e[?25l";
    }

    private function restoreTerminal(): void
    {
        stream_set_blocking(STDIN, true);
        if (defined('STDOUT') && function_exists('posix_isatty') && posix_isatty(STDOUT)) {
            echo "\e[?25h";
            system('stty sane 2>/dev/null');
            echo "\e[?1049l";
        }
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $cleanup = function (int $signal): void {
            $this->running = false;
            $this->exitCode = 128 + $signal;
        };

        pcntl_signal(SIGINT, $cleanup);
        pcntl_signal(SIGTERM, $cleanup);
    }

    private function readKey(): ?string
    {
        $char = fread(STDIN, 1);
        if ($char === false || $char === '') {
            return null;
        }

        if ($char === "\e") {
            usleep(1000);
            $bracket = fread(STDIN, 1);
            if ($bracket === '[') {
                usleep(1000);
                $code = fread(STDIN, 1);

                return match ($code) {
                    'A' => 'up',
                    'B' => 'down',
                    default => null,
                };
            }

            if ($bracket === false || $bracket === '') {
                return 'esc';
            }

            return null;
        }

        return match ($char) {
            'q' => 'quit',
            'j' => 'down',
            'k' => 'up',
            'd' => 'dismiss',
            'f' => 'focus',
            "\n", "\r" => 'enter',
            "\x7f" => 'backspace',
            default => null,
        };
    }

    private function handleKey(string $key): bool
    {
        if ($key === 'quit') {
            $this->running = false;

            return false;
        }

        if ($this->view === 'list') {
            return $this->handleListKey($key);
        }

        return $this->handleDetailKey($key);
    }

    private function handleListKey(string $key): bool
    {
        return match ($key) {
            'up' => $this->moveSelection(-1),
            'down' => $this->moveSelection(1),
            'enter' => $this->enterDetailView(),
            'dismiss' => $this->dismissSession(),
            'focus' => $this->focusSession(),
            default => false,
        };
    }

    private function handleDetailKey(string $key): bool
    {
        return match ($key) {
            'esc', 'backspace' => $this->backToList(),
            'dismiss' => $this->dismissSession(),
            default => false,
        };
    }

    private function moveSelection(int $direction): bool
    {
        $count = count($this->sessions);
        if ($count === 0) {
            return false;
        }

        $newIndex = $this->selectedIndex + $direction;
        if ($newIndex < 0 || $newIndex >= $count) {
            return false;
        }

        $this->selectedIndex = $newIndex;

        return true;
    }

    private function enterDetailView(): bool
    {
        if ($this->sessions === []) {
            return false;
        }

        $this->view = 'detail';

        return true;
    }

    private function backToList(): bool
    {
        $this->view = 'list';

        return true;
    }

    private function dismissSession(): bool
    {
        if (! isset($this->sessions[$this->selectedIndex])) {
            return false;
        }

        $session = $this->sessions[$this->selectedIndex];
        $isDismissable = $session['status'] === 'completed'
            || $session['status'] === 'crashed'
            || ! $this->isProcessAlive($session['pid']);

        if (! $isDismissable) {
            return false;
        }

        $this->registry->deregister($session['tasksPath']);
        $this->sessions = $this->registry->getSessions();

        $sessionCount = count($this->sessions);
        if ($this->selectedIndex >= $sessionCount) {
            $this->selectedIndex = max(0, $sessionCount - 1);
        }

        if ($this->view === 'detail' && $this->sessions === []) {
            $this->view = 'list';
        }

        $this->flashMessage = 'Session dismissed';

        return true;
    }

    private function focusSession(): bool
    {
        if (! isset($this->sessions[$this->selectedIndex])) {
            return false;
        }

        $session = $this->sessions[$this->selectedIndex];

        if ($session['status'] !== 'running' || ! $this->isProcessAlive($session['pid'])) {
            return false;
        }

        $result = $this->terminalFocuser->focus($session['pid']);
        $this->flashMessage = $result->message;

        return true;
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (! function_exists('posix_kill')) {
            return file_exists("/proc/$pid");
        }

        return posix_kill($pid, 0);
    }

    private function renderCurrentView(): void
    {
        ob_start();
        echo "\033[2J\033[H";

        if ($this->view === 'detail') {
            $this->renderDetailView();
        } else {
            $this->renderListView();
        }

        echo "\033[J";
        $frame = ob_get_clean();
        echo $frame;
    }

    private function renderListView(): void
    {
        $count = count($this->sessions);
        $countLabel = $count === 1 ? '1 session' : "{$count} sessions";

        $header = <<<HTML
            <div class="text-white px-2 py-0 mb-1">
                <span class="font-bold">laracode <span class="text-cyan-400">[Sessions]</span></span>
                <span class="ml-40 text-yellow-400">{$countLabel}</span>
            </div>
        HTML;

        $canDismiss = false;
        $canFocus = false;
        if (isset($this->sessions[$this->selectedIndex])) {
            $selected = $this->sessions[$this->selectedIndex];
            $canDismiss = $selected['status'] === 'completed'
                || $selected['status'] === 'crashed'
                || ! $this->isProcessAlive($selected['pid']);
            $canFocus = $selected['status'] === 'running' && $this->isProcessAlive($selected['pid']);
        }

        $sessionListHtml = $this->sessionList->render($this->sessions, $this->selectedIndex);
        $keyHelpHtml = $this->keyHelp->render('list', $canDismiss, $canFocus);

        $flashHtml = $this->consumeFlashHtml();

        $this->renderHtml("<div>{$header}{$sessionListHtml}{$flashHtml}{$keyHelpHtml}</div>");
    }

    private function renderDetailView(): void
    {
        if (! isset($this->sessions[$this->selectedIndex])) {
            $this->view = 'list';
            $this->renderListView();

            return;
        }

        $session = $this->sessions[$this->selectedIndex];
        $state = $this->buildDashboardState($session);

        if ($state === null) {
            $this->view = 'list';
            $this->renderListView();

            return;
        }

        $featureTitle = htmlspecialchars($state->featureTitle);
        $elapsed = $this->formatElapsedSeconds($state->elapsedSeconds);
        $projectPath = htmlspecialchars($this->shortenPath($session['projectPath']));

        $header = <<<HTML
            <div class="text-white px-2 py-0 mb-1">
                <div class="flex justify-between">
                    <span class="font-bold">laracode <span class="text-green-400">[Viewing]</span> {$featureTitle}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray">{$projectPath}</span>
                    <span class="font-bold ml-1">{$elapsed}</span>
                </div>
            </div>
        HTML;

        $canDismiss = $session['status'] === 'completed'
            || $session['status'] === 'crashed'
            || ! $this->isProcessAlive($session['pid']);

        $flashHtml = $this->consumeFlashHtml();

        $html = '<div>'
            .implode("\n", [
                $header,
                $this->taskList->render($state),
                $this->progressBar->render($state),
                '<hr>',
                $this->taskDetail->render($state),
                $this->statusBar->render($state),
                $flashHtml,
                $this->keyHelp->render('detail', $canDismiss),
            ])
            .'</div>';

        $this->renderHtml($html);
    }

    private function consumeFlashHtml(): string
    {
        if ($this->flashMessage === null) {
            return '';
        }

        $message = htmlspecialchars($this->flashMessage);
        $this->flashMessage = null;

        return "<div class=\"px-2 text-yellow-400\">{$message}</div>";
    }

    /**
     * @param  array{tasksPath: string, pid: int, startedAt: string, mode: string, agent: string, projectPath: string, status: string, completedAt?: string}  $session
     */
    private function buildDashboardState(array $session): ?DashboardState
    {
        $tasksPath = $session['tasksPath'];
        if (! file_exists($tasksPath)) {
            return null;
        }

        $content = file_get_contents($tasksPath);
        if ($content === false) {
            return null;
        }

        /** @var array{title?: string, branch?: string, tasks?: array<array{id: int, status: string}>}|null $data */
        $data = json_decode($content, true);
        if (! is_array($data)) {
            return null;
        }

        $startedAt = strtotime($session['startedAt']);
        $elapsed = $startedAt !== false ? time() - $startedAt : 0;

        $activeTaskId = null;
        foreach ($data['tasks'] ?? [] as $task) {
            if ($task['status'] === 'in_progress') {
                $activeTaskId = $task['id'];
                break;
            }
        }

        $tasks = $data['tasks'] ?? [];
        $total = count($tasks);
        $completed = count(array_filter($tasks, fn (array $t) => $t['status'] === 'completed'));

        return DashboardState::fromTasksArray(
            $data,
            $completed,
            $total,
            $elapsed,
            $activeTaskId,
            $session['mode'],
            "{$completed}/{$total} completed",
        );
    }

    private function formatElapsedSeconds(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;

        return $minutes > 0 ? "{$minutes}m{$secs}s" : "{$secs}s";
    }

    private function shortenPath(string $path): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
        if ($home !== '' && str_starts_with($path, $home)) {
            return '~'.substr($path, strlen($home));
        }

        return $path;
    }

    private function renderHtml(string $html): void
    {
        $buffer = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        renderUsing($buffer);
        render($html);
        $captured = $buffer->fetch();
        renderUsing(null);

        echo $captured;
    }
}
