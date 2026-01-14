<?php

declare(strict_types=1);

namespace App\Services;

class TaskSelector
{
    /**
     * @param  array<array{id: int, status: string, dependencies?: array<int>, priority?: int}>  $tasks
     * @return array{id: int, status: string, dependencies?: array<int>, priority?: int}|null
     */
    public function selectNextTask(array $tasks): ?array
    {
        $completedIds = $this->getCompletedTaskIds($tasks);
        $availableTasks = $this->getAvailableTasks($tasks, $completedIds);

        if (empty($availableTasks)) {
            return null;
        }

        usort($availableTasks, function ($a, $b) {
            $priorityA = $a['priority'] ?? PHP_INT_MAX;
            $priorityB = $b['priority'] ?? PHP_INT_MAX;

            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            return $a['id'] <=> $b['id'];
        });

        return $availableTasks[0];
    }

    /**
     * @param  array<array{id: int, status: string}>  $tasks
     * @return array<int, true>
     */
    public function getCompletedTaskIds(array $tasks): array
    {
        $completedIds = [];
        foreach ($tasks as $task) {
            if ($task['status'] === 'completed') {
                $completedIds[$task['id']] = true;
            }
        }

        return $completedIds;
    }

    /**
     * @param  array<array{id: int, status: string, dependencies?: array<int>, priority?: int}>  $tasks
     * @param  array<int, true>  $completedIds
     * @return array<array{id: int, status: string, dependencies?: array<int>, priority?: int}>
     */
    public function getAvailableTasks(array $tasks, array $completedIds): array
    {
        $available = [];

        foreach ($tasks as $task) {
            if ($task['status'] !== 'pending') {
                continue;
            }

            if ($this->areDependenciesSatisfied($task, $completedIds)) {
                $available[] = $task;
            }
        }

        return $available;
    }

    /**
     * @param  array{id: int, status: string, dependencies?: array<int>}  $task
     * @param  array<int, true>  $completedIds
     */
    public function areDependenciesSatisfied(array $task, array $completedIds): bool
    {
        $dependencies = $task['dependencies'] ?? [];

        if (empty($dependencies)) {
            return true;
        }

        foreach ($dependencies as $depId) {
            if (! isset($completedIds[$depId])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array{id: int, status: string, dependencies?: array<int>}>  $tasks
     */
    public function countBlockedTasks(array $tasks): int
    {
        $completedIds = $this->getCompletedTaskIds($tasks);
        $blocked = 0;

        foreach ($tasks as $task) {
            if ($task['status'] !== 'pending') {
                continue;
            }

            if (! $this->areDependenciesSatisfied($task, $completedIds)) {
                $blocked++;
            }
        }

        return $blocked;
    }

    /**
     * @param  array<array{id: int, status: string}>  $tasks
     */
    public function hasPendingTasks(array $tasks): bool
    {
        foreach ($tasks as $task) {
            if ($task['status'] === 'pending') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array{id: int, status: string, dependencies?: array<int>}>  $tasks
     */
    public function isDeadlocked(array $tasks): bool
    {
        if (! $this->hasPendingTasks($tasks)) {
            return false;
        }

        $completedIds = $this->getCompletedTaskIds($tasks);
        $availableTasks = $this->getAvailableTasks($tasks, $completedIds);

        return empty($availableTasks);
    }

    /**
     * @param  array<array{id: int, dependencies?: array<int>}>  $tasks
     * @return array<int>
     */
    public function detectCircularDependencies(array $tasks): array
    {
        $taskMap = [];
        foreach ($tasks as $task) {
            $taskMap[$task['id']] = $task['dependencies'] ?? [];
        }

        $circularTasks = [];

        foreach ($taskMap as $taskId => $dependencies) {
            if ($this->hasCircularPath($taskId, $taskMap, [])) {
                $circularTasks[] = $taskId;
            }
        }

        return $circularTasks;
    }

    /**
     * @param  array<int, array<int>>  $taskMap
     * @param  array<int, true>  $visited
     */
    private function hasCircularPath(int $taskId, array $taskMap, array $visited): bool
    {
        if (isset($visited[$taskId])) {
            return true;
        }

        if (! isset($taskMap[$taskId])) {
            return false;
        }

        $visited[$taskId] = true;

        foreach ($taskMap[$taskId] as $depId) {
            if ($this->hasCircularPath($depId, $taskMap, $visited)) {
                return true;
            }
        }

        return false;
    }
}
