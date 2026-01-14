<?php

declare(strict_types=1);

use App\Services\TaskSelector;

beforeEach(function () {
    $this->selector = new TaskSelector;
});

it('selects highest priority available task', function () {
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'priority' => 50, 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => []],
        ['id' => 3, 'status' => 'pending', 'priority' => 30, 'dependencies' => []],
    ];

    $next = $this->selector->selectNextTask($tasks);

    expect($next)->not->toBeNull()
        ->and($next['id'])->toBe(2)
        ->and($next['priority'])->toBe(10);
});

it('falls back to id ordering when priorities are equal', function () {
    $tasks = [
        ['id' => 3, 'status' => 'pending', 'priority' => 10, 'dependencies' => []],
        ['id' => 1, 'status' => 'pending', 'priority' => 10, 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => []],
    ];

    $next = $this->selector->selectNextTask($tasks);

    expect($next)->not->toBeNull()
        ->and($next['id'])->toBe(1);
});

it('skips tasks with unsatisfied dependencies', function () {
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'priority' => 50, 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => [1]],
        ['id' => 3, 'status' => 'pending', 'priority' => 20, 'dependencies' => []],
    ];

    $next = $this->selector->selectNextTask($tasks);

    expect($next)->not->toBeNull()
        ->and($next['id'])->toBe(3);
});

it('selects task when all dependencies are satisfied', function () {
    $tasks = [
        ['id' => 1, 'status' => 'completed', 'priority' => 50, 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => [1]],
        ['id' => 3, 'status' => 'pending', 'priority' => 20, 'dependencies' => []],
    ];

    $next = $this->selector->selectNextTask($tasks);

    expect($next)->not->toBeNull()
        ->and($next['id'])->toBe(2);
});

it('returns null when no pending tasks exist', function () {
    $tasks = [
        ['id' => 1, 'status' => 'completed', 'dependencies' => []],
        ['id' => 2, 'status' => 'completed', 'dependencies' => []],
    ];

    $next = $this->selector->selectNextTask($tasks);

    expect($next)->toBeNull();
});

it('returns null when all pending tasks are blocked (deadlock)', function () {
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'priority' => 10, 'dependencies' => [2]],
        ['id' => 2, 'status' => 'pending', 'priority' => 20, 'dependencies' => [1]],
    ];

    $next = $this->selector->selectNextTask($tasks);

    expect($next)->toBeNull();
});

it('handles tasks without priority field', function () {
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => []],
    ];

    $next = $this->selector->selectNextTask($tasks);

    expect($next)->not->toBeNull()
        ->and($next['id'])->toBe(2);
});

it('handles tasks without dependencies field', function () {
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'priority' => 10],
        ['id' => 2, 'status' => 'pending', 'priority' => 20],
    ];

    $next = $this->selector->selectNextTask($tasks);

    expect($next)->not->toBeNull()
        ->and($next['id'])->toBe(1);
});

it('counts blocked tasks correctly', function () {
    $tasks = [
        ['id' => 1, 'status' => 'completed', 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'dependencies' => [1]],
        ['id' => 3, 'status' => 'pending', 'dependencies' => [4]],
        ['id' => 4, 'status' => 'pending', 'dependencies' => []],
    ];

    $blocked = $this->selector->countBlockedTasks($tasks);

    expect($blocked)->toBe(1);
});

it('counts multiple blocked tasks', function () {
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'dependencies' => [1]],
        ['id' => 3, 'status' => 'pending', 'dependencies' => [1]],
        ['id' => 4, 'status' => 'pending', 'dependencies' => [2, 3]],
    ];

    $blocked = $this->selector->countBlockedTasks($tasks);

    expect($blocked)->toBe(3);
});

it('returns zero blocked when all pending tasks are available', function () {
    $tasks = [
        ['id' => 1, 'status' => 'completed', 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'dependencies' => [1]],
        ['id' => 3, 'status' => 'pending', 'dependencies' => []],
    ];

    $blocked = $this->selector->countBlockedTasks($tasks);

    expect($blocked)->toBe(0);
});

it('detects deadlock state', function () {
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'dependencies' => [2]],
        ['id' => 2, 'status' => 'pending', 'dependencies' => [1]],
    ];

    expect($this->selector->isDeadlocked($tasks))->toBeTrue();
});

it('reports no deadlock when tasks are available', function () {
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'dependencies' => [1]],
    ];

    expect($this->selector->isDeadlocked($tasks))->toBeFalse();
});

it('reports no deadlock when no pending tasks exist', function () {
    $tasks = [
        ['id' => 1, 'status' => 'completed', 'dependencies' => []],
        ['id' => 2, 'status' => 'completed', 'dependencies' => [1]],
    ];

    expect($this->selector->isDeadlocked($tasks))->toBeFalse();
});

it('detects circular dependencies', function () {
    $tasks = [
        ['id' => 1, 'dependencies' => [2]],
        ['id' => 2, 'dependencies' => [1]],
        ['id' => 3, 'dependencies' => []],
    ];

    $circular = $this->selector->detectCircularDependencies($tasks);

    expect($circular)->toContain(1)
        ->and($circular)->toContain(2)
        ->and($circular)->not->toContain(3);
});

it('detects self-referencing circular dependency', function () {
    $tasks = [
        ['id' => 1, 'dependencies' => [1]],
        ['id' => 2, 'dependencies' => []],
    ];

    $circular = $this->selector->detectCircularDependencies($tasks);

    expect($circular)->toContain(1)
        ->and($circular)->not->toContain(2);
});

it('detects multi-hop circular dependency', function () {
    $tasks = [
        ['id' => 1, 'dependencies' => [2]],
        ['id' => 2, 'dependencies' => [3]],
        ['id' => 3, 'dependencies' => [1]],
    ];

    $circular = $this->selector->detectCircularDependencies($tasks);

    expect($circular)->toContain(1)
        ->and($circular)->toContain(2)
        ->and($circular)->toContain(3);
});

it('returns empty array when no circular dependencies exist', function () {
    $tasks = [
        ['id' => 1, 'dependencies' => []],
        ['id' => 2, 'dependencies' => [1]],
        ['id' => 3, 'dependencies' => [1, 2]],
    ];

    $circular = $this->selector->detectCircularDependencies($tasks);

    expect($circular)->toBeEmpty();
});

it('handles complex dependency chains correctly', function () {
    $tasks = [
        ['id' => 1, 'status' => 'completed', 'priority' => 1, 'dependencies' => []],
        ['id' => 2, 'status' => 'completed', 'priority' => 5, 'dependencies' => [1]],
        ['id' => 3, 'status' => 'completed', 'priority' => 10, 'dependencies' => [1]],
        ['id' => 4, 'status' => 'pending', 'priority' => 15, 'dependencies' => [2, 3]],
        ['id' => 5, 'status' => 'pending', 'priority' => 20, 'dependencies' => [4]],
        ['id' => 6, 'status' => 'pending', 'priority' => 25, 'dependencies' => []],
    ];

    $next = $this->selector->selectNextTask($tasks);

    expect($next)->not->toBeNull()
        ->and($next['id'])->toBe(4);
});

it('correctly identifies available tasks', function () {
    $tasks = [
        ['id' => 1, 'status' => 'completed', 'priority' => 1, 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => [1]],
        ['id' => 3, 'status' => 'pending', 'priority' => 20, 'dependencies' => [4]],
        ['id' => 4, 'status' => 'pending', 'priority' => 5, 'dependencies' => []],
    ];

    $completedIds = $this->selector->getCompletedTaskIds($tasks);
    $available = $this->selector->getAvailableTasks($tasks, $completedIds);

    expect($available)->toHaveCount(2);
    $availableIds = array_column($available, 'id');
    expect($availableIds)->toContain(2)
        ->and($availableIds)->toContain(4)
        ->and($availableIds)->not->toContain(3);
});

it('returns true when pending tasks exist', function () {
    $tasks = [
        ['id' => 1, 'status' => 'completed', 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'dependencies' => []],
    ];

    expect($this->selector->hasPendingTasks($tasks))->toBeTrue();
});

it('returns false when no pending tasks exist', function () {
    $tasks = [
        ['id' => 1, 'status' => 'completed', 'dependencies' => []],
        ['id' => 2, 'status' => 'in_progress', 'dependencies' => []],
    ];

    expect($this->selector->hasPendingTasks($tasks))->toBeFalse();
});

it('returns false for empty task list', function () {
    expect($this->selector->hasPendingTasks([]))->toBeFalse();
});

it('returns true when dependencies are satisfied', function () {
    $task = ['id' => 2, 'status' => 'pending', 'dependencies' => [1]];
    $completedIds = [1 => true];

    expect($this->selector->areDependenciesSatisfied($task, $completedIds))->toBeTrue();
});

it('returns false when dependencies are not satisfied', function () {
    $task = ['id' => 2, 'status' => 'pending', 'dependencies' => [1, 3]];
    $completedIds = [1 => true];

    expect($this->selector->areDependenciesSatisfied($task, $completedIds))->toBeFalse();
});

it('returns true when task has no dependencies', function () {
    $task = ['id' => 1, 'status' => 'pending', 'dependencies' => []];

    expect($this->selector->areDependenciesSatisfied($task, []))->toBeTrue();
});

it('returns true when dependencies field is missing', function () {
    $task = ['id' => 1, 'status' => 'pending'];

    expect($this->selector->areDependenciesSatisfied($task, []))->toBeTrue();
});

it('skips completed and in_progress tasks when selecting next', function () {
    $tasks = [
        ['id' => 1, 'status' => 'completed', 'priority' => 1, 'dependencies' => []],
        ['id' => 2, 'status' => 'in_progress', 'priority' => 2, 'dependencies' => []],
        ['id' => 3, 'status' => 'pending', 'priority' => 3, 'dependencies' => []],
    ];

    $next = $this->selector->selectNextTask($tasks);

    expect($next)->not->toBeNull()
        ->and($next['id'])->toBe(3);
});

it('handles multiple dependencies correctly', function () {
    $tasks = [
        ['id' => 1, 'status' => 'completed', 'priority' => 1, 'dependencies' => []],
        ['id' => 2, 'status' => 'completed', 'priority' => 2, 'dependencies' => []],
        ['id' => 3, 'status' => 'pending', 'priority' => 3, 'dependencies' => [1, 2]],
        ['id' => 4, 'status' => 'pending', 'priority' => 4, 'dependencies' => [1, 5]],
    ];

    $next = $this->selector->selectNextTask($tasks);

    expect($next)->not->toBeNull()
        ->and($next['id'])->toBe(3);
});

it('counts blocked when dependency is in_progress', function () {
    $tasks = [
        ['id' => 1, 'status' => 'in_progress', 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'dependencies' => [1]],
    ];

    $blocked = $this->selector->countBlockedTasks($tasks);

    expect($blocked)->toBe(1);
});
