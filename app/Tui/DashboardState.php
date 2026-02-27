<?php

declare(strict_types=1);

namespace App\Tui;

readonly class DashboardState
{
    /**
     * @param  array<array{id: int, status: string, title?: string, description?: string, dependencies?: array<int>, priority?: int, acceptance?: array<string>, steps?: array<string>}>  $tasks
     */
    public function __construct(
        public string $featureTitle,
        public string $branch,
        public array $tasks,
        public int $currentIteration,
        public int $maxIterations,
        public int $elapsedSeconds,
        public ?int $activeTaskId,
        public string $mode,
        public string $statusMessage,
    ) {}

    /**
     * @param  array{title?: string, branch?: string, tasks?: array<array{id: int, status: string, title?: string, description?: string, dependencies?: array<int>, priority?: int, acceptance?: array<string>, steps?: array<string>}>}  $data
     */
    public static function fromTasksArray(
        array $data,
        int $iteration,
        int $maxIterations,
        int $elapsed,
        ?int $activeTaskId,
        string $mode,
        string $statusMessage,
    ): self {
        return new self(
            featureTitle: $data['title'] ?? 'Untitled',
            branch: $data['branch'] ?? 'unknown',
            tasks: $data['tasks'] ?? [],
            currentIteration: $iteration,
            maxIterations: $maxIterations,
            elapsedSeconds: $elapsed,
            activeTaskId: $activeTaskId,
            mode: $mode,
            statusMessage: $statusMessage,
        );
    }

    public function completedCount(): int
    {
        return count(array_filter($this->tasks, fn (array $task) => $task['status'] === 'completed'));
    }

    public function pendingCount(): int
    {
        return count(array_filter($this->tasks, fn (array $task) => $task['status'] === 'pending'));
    }

    public function blockedCount(): int
    {
        $completedIds = [];
        foreach ($this->tasks as $task) {
            if ($task['status'] === 'completed') {
                $completedIds[$task['id']] = true;
            }
        }

        $blocked = 0;
        foreach ($this->tasks as $task) {
            if ($task['status'] !== 'pending') {
                continue;
            }

            $dependencies = $task['dependencies'] ?? [];
            if (empty($dependencies)) {
                continue;
            }

            foreach ($dependencies as $depId) {
                if (! isset($completedIds[$depId])) {
                    $blocked++;
                    break;
                }
            }
        }

        return $blocked;
    }

    public function totalCount(): int
    {
        return count($this->tasks);
    }

    public function progressPercent(): float
    {
        if ($this->totalCount() === 0) {
            return 0.0;
        }

        return round(($this->completedCount() / $this->totalCount()) * 100, 1);
    }

    /**
     * @return array{id: int, status: string, title?: string, description?: string, dependencies?: array<int>, priority?: int, acceptance?: array<string>, steps?: array<string>}|null
     */
    public function activeTask(): ?array
    {
        if ($this->activeTaskId === null) {
            return null;
        }

        foreach ($this->tasks as $task) {
            if ($task['id'] === $this->activeTaskId) {
                return $task;
            }
        }

        return null;
    }
}
