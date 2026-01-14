<?php

declare(strict_types=1);

use App\Services\TaskSelector;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-e2e-test-'.uniqid();
    mkdir($this->testPath.'/.laracode/specs/test-feature', 0755, true);
    mkdir($this->testPath.'/.claude/commands', 0755, true);
    $this->tasksPath = $this->testPath.'/.laracode/specs/test-feature/tasks.json';
    $this->cliffNotesPath = $this->testPath.'/.laracode/specs/test-feature/cliff-notes.md';
    $this->selector = new TaskSelector;
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

it('generates tasks with proper schema structure', function () {
    $tasks = [
        'title' => 'E2E Test Feature',
        'branch' => 'feature/e2e-test',
        'created' => date('c'),
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Setup configuration',
                'description' => 'Create initial config files',
                'steps' => ['Create config file', 'Add default values'],
                'status' => 'pending',
                'dependencies' => [],
                'priority' => 1,
                'acceptance' => ['Config file exists', 'Values are set'],
            ],
            [
                'id' => 2,
                'title' => 'Create model',
                'description' => 'Create the main model',
                'steps' => ['Create model class', 'Add properties'],
                'status' => 'pending',
                'dependencies' => [1],
                'priority' => 10,
                'acceptance' => ['Model class exists'],
            ],
        ],
        'sources' => ['spec.md'],
    ];

    file_put_contents($this->tasksPath, json_encode($tasks, JSON_PRETTY_PRINT));

    $content = json_decode(file_get_contents($this->tasksPath), true);

    expect($content)
        ->toHaveKey('title')
        ->toHaveKey('branch')
        ->toHaveKey('created')
        ->toHaveKey('tasks')
        ->toHaveKey('sources')
        ->and($content['tasks'])->toHaveCount(2)
        ->and($content['tasks'][0])->toHaveKey('id')
        ->and($content['tasks'][0])->toHaveKey('title')
        ->and($content['tasks'][0])->toHaveKey('dependencies')
        ->and($content['tasks'][0])->toHaveKey('priority')
        ->and($content['tasks'][0])->toHaveKey('acceptance');
});

it('selects tasks in correct dependency order', function () {
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'priority' => 10, 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'priority' => 5, 'dependencies' => [1]],
        ['id' => 3, 'status' => 'pending', 'priority' => 1, 'dependencies' => [2]],
    ];

    // First selection - only task 1 available (priority 10, no deps)
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(1);

    // Mark task 1 complete
    $tasks[0]['status'] = 'completed';

    // Second selection - task 2 available (priority 5, dep on 1 satisfied)
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(2);

    // Mark task 2 complete
    $tasks[1]['status'] = 'completed';

    // Third selection - task 3 available (priority 1, dep on 2 satisfied)
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(3);
});

it('correctly skips blocked tasks when selecting next', function () {
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'priority' => 100, 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'priority' => 1, 'dependencies' => [1]],
        ['id' => 3, 'status' => 'pending', 'priority' => 50, 'dependencies' => []],
    ];

    // Task 2 has highest priority (1) but is blocked by task 1
    // Task 3 has priority 50 and no deps, should be selected over task 1 (priority 100)
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(3);

    // Mark task 3 complete, task 1 should be next
    $tasks[2]['status'] = 'completed';
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(1);

    // Mark task 1 complete, task 2 is now unblocked
    $tasks[0]['status'] = 'completed';
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(2);
});

it('handles complex dependency chains correctly through multiple iterations', function () {
    // Simulate a real feature with migrations → models → services → tests
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'priority' => 1, 'dependencies' => [], 'title' => 'Create migration'],
        ['id' => 2, 'status' => 'pending', 'priority' => 5, 'dependencies' => [1], 'title' => 'Create model'],
        ['id' => 3, 'status' => 'pending', 'priority' => 10, 'dependencies' => [2], 'title' => 'Create service'],
        ['id' => 4, 'status' => 'pending', 'priority' => 15, 'dependencies' => [3], 'title' => 'Create controller'],
        ['id' => 5, 'status' => 'pending', 'priority' => 85, 'dependencies' => [2, 3, 4], 'title' => 'Write tests'],
        ['id' => 6, 'status' => 'pending', 'priority' => 96, 'dependencies' => [1, 2, 3, 4, 5], 'title' => 'Update docs'],
    ];

    $executionOrder = [];

    // Simulate build loop
    while ($this->selector->hasPendingTasks($tasks)) {
        $next = $this->selector->selectNextTask($tasks);

        if ($next === null) {
            break; // Deadlock
        }

        $executionOrder[] = $next['id'];

        // Mark as completed
        foreach ($tasks as &$task) {
            if ($task['id'] === $next['id']) {
                $task['status'] = 'completed';
                break;
            }
        }
    }

    expect($executionOrder)->toBe([1, 2, 3, 4, 5, 6])
        ->and($this->selector->hasPendingTasks($tasks))->toBeFalse();
});

it('marks all tasks completed at end of full workflow', function () {
    $tasks = [
        'title' => 'Full Workflow Test',
        'tasks' => [
            ['id' => 1, 'status' => 'pending', 'priority' => 1, 'dependencies' => []],
            ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => [1]],
            ['id' => 3, 'status' => 'pending', 'priority' => 20, 'dependencies' => [1, 2]],
        ],
    ];

    file_put_contents($this->tasksPath, json_encode($tasks, JSON_PRETTY_PRINT));

    // Simulate execution of all tasks
    $taskList = &$tasks['tasks'];
    while ($this->selector->hasPendingTasks($taskList)) {
        $next = $this->selector->selectNextTask($taskList);

        if ($next === null) {
            break;
        }

        foreach ($taskList as &$task) {
            if ($task['id'] === $next['id']) {
                $task['status'] = 'completed';
                break;
            }
        }
    }

    // Write back to file
    file_put_contents($this->tasksPath, json_encode($tasks, JSON_PRETTY_PRINT));

    // Verify all completed
    $content = json_decode(file_get_contents($this->tasksPath), true);
    $allCompleted = array_reduce($content['tasks'], fn ($carry, $t) => $carry && $t['status'] === 'completed', true);

    expect($allCompleted)->toBeTrue();
});

it('accumulates cliff notes properly during workflow', function () {
    // Initialize empty cliff notes
    file_put_contents($this->cliffNotesPath, "# Cliff Notes - Test Feature\n\n");

    $tasks = [
        ['id' => 1, 'status' => 'pending', 'priority' => 1, 'dependencies' => [], 'title' => 'First Task'],
        ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => [1], 'title' => 'Second Task'],
        ['id' => 3, 'status' => 'pending', 'priority' => 20, 'dependencies' => [1, 2], 'title' => 'Third Task'],
    ];

    // Simulate execution with cliff notes accumulation
    while ($this->selector->hasPendingTasks($tasks)) {
        $next = $this->selector->selectNextTask($tasks);

        if ($next === null) {
            break;
        }

        // Mark as completed
        foreach ($tasks as &$task) {
            if ($task['id'] === $next['id']) {
                $task['status'] = 'completed';
                break;
            }
        }

        // Append cliff note entry
        $cliffNote = "\n---\n";
        $cliffNote .= "## Task #{$next['id']}: {$next['title']}\n";
        $cliffNote .= "**Status**: Completed\n";
        $cliffNote .= "- Implementation completed\n\n";
        $cliffNote .= "### Next Steps\n";

        // Find unblocked tasks
        $completedIds = $this->selector->getCompletedTaskIds($tasks);
        $available = $this->selector->getAvailableTasks($tasks, $completedIds);
        if (! empty($available)) {
            $cliffNote .= "- Task #{$available[0]['id']} is now available\n";
        }

        file_put_contents($this->cliffNotesPath, $cliffNote, FILE_APPEND);
    }

    $cliffNotesContent = file_get_contents($this->cliffNotesPath);

    expect($cliffNotesContent)
        ->toContain('Task #1')
        ->toContain('Task #2')
        ->toContain('Task #3')
        ->toContain('Next Steps')
        ->toContain('Completed');
});

it('detects and handles deadlock scenario', function () {
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'priority' => 1, 'dependencies' => [2]],
        ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => [1]],
    ];

    expect($this->selector->isDeadlocked($tasks))->toBeTrue()
        ->and($this->selector->selectNextTask($tasks))->toBeNull();
});

it('processes tasks with multiple parallel dependencies', function () {
    // Tasks 2 and 3 both depend on task 1 (parallel execution possibility)
    // Task 4 depends on both 2 and 3
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'priority' => 1, 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => [1]],
        ['id' => 3, 'status' => 'pending', 'priority' => 10, 'dependencies' => [1]],
        ['id' => 4, 'status' => 'pending', 'priority' => 20, 'dependencies' => [2, 3]],
    ];

    // First: task 1
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(1);
    $tasks[0]['status'] = 'completed';

    // After task 1: both task 2 and 3 are available
    $completedIds = $this->selector->getCompletedTaskIds($tasks);
    $available = $this->selector->getAvailableTasks($tasks, $completedIds);
    expect($available)->toHaveCount(2);

    // Task 2 selected (same priority, lower id)
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(2);
    $tasks[1]['status'] = 'completed';

    // Task 3 still available, task 4 blocked
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(3);
    $tasks[2]['status'] = 'completed';

    // Now task 4 is available
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(4);
});

it('verifies build command stats calculation with new schema', function () {
    $tasks = [
        'title' => 'Stats Test Feature',
        'branch' => 'feature/stats-test',
        'created' => '2026-01-12T10:30:00Z',
        'tasks' => [
            ['id' => 1, 'status' => 'completed', 'priority' => 1, 'dependencies' => []],
            ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => [1]],
            ['id' => 3, 'status' => 'pending', 'priority' => 20, 'dependencies' => [4]],
            ['id' => 4, 'status' => 'pending', 'priority' => 5, 'dependencies' => []],
        ],
    ];

    file_put_contents($this->tasksPath, json_encode($tasks, JSON_PRETTY_PRINT));

    // Test stats calculation
    $total = count($tasks['tasks']);
    $completed = count(array_filter($tasks['tasks'], fn ($t) => $t['status'] === 'completed'));
    $pending = count(array_filter($tasks['tasks'], fn ($t) => $t['status'] === 'pending'));
    $blocked = $this->selector->countBlockedTasks($tasks['tasks']);

    expect($total)->toBe(4)
        ->and($completed)->toBe(1)
        ->and($pending)->toBe(3)
        ->and($blocked)->toBe(1); // Task 3 is blocked by task 4
});

it('simulates full build command workflow with file updates', function () {
    $tasks = [
        'title' => 'Full Build Simulation',
        'branch' => 'feature/full-build',
        'created' => date('c'),
        'tasks' => [
            ['id' => 1, 'status' => 'pending', 'priority' => 1, 'dependencies' => [], 'title' => 'Setup'],
            ['id' => 2, 'status' => 'pending', 'priority' => 10, 'dependencies' => [1], 'title' => 'Implement'],
            ['id' => 3, 'status' => 'pending', 'priority' => 85, 'dependencies' => [2], 'title' => 'Test'],
        ],
        'sources' => ['spec.md'],
    ];

    file_put_contents($this->tasksPath, json_encode($tasks, JSON_PRETTY_PRINT));
    file_put_contents($this->cliffNotesPath, "# Cliff Notes\n");

    $maxIterations = 10;
    $iteration = 0;

    while ($iteration < $maxIterations) {
        // Read tasks from file (simulating BuildCommand behavior)
        $content = json_decode(file_get_contents($this->tasksPath), true);
        $taskList = $content['tasks'];

        // Check for pending tasks
        if (! $this->selector->hasPendingTasks($taskList)) {
            break;
        }

        $next = $this->selector->selectNextTask($taskList);
        if ($next === null) {
            break; // Deadlock
        }

        // Mark as in_progress
        foreach ($content['tasks'] as &$task) {
            if ($task['id'] === $next['id']) {
                $task['status'] = 'in_progress';
                break;
            }
        }
        file_put_contents($this->tasksPath, json_encode($content, JSON_PRETTY_PRINT));

        // "Execute" task (simulated)
        // ...

        // Mark as completed
        foreach ($content['tasks'] as &$task) {
            if ($task['id'] === $next['id']) {
                $task['status'] = 'completed';
                break;
            }
        }
        file_put_contents($this->tasksPath, json_encode($content, JSON_PRETTY_PRINT));

        // Append to cliff notes
        $note = "\n---\n## Task #{$next['id']}: {$next['title']}\n**Status**: Completed\n";
        file_put_contents($this->cliffNotesPath, $note, FILE_APPEND);

        $iteration++;
    }

    // Verify final state
    $finalContent = json_decode(file_get_contents($this->tasksPath), true);
    $allCompleted = array_reduce(
        $finalContent['tasks'],
        fn ($carry, $t) => $carry && $t['status'] === 'completed',
        true
    );

    $cliffNotes = file_get_contents($this->cliffNotesPath);

    expect($allCompleted)->toBeTrue()
        ->and($iteration)->toBe(3)
        ->and($cliffNotes)->toContain('Task #1')
        ->and($cliffNotes)->toContain('Task #2')
        ->and($cliffNotes)->toContain('Task #3');
});

it('handles tasks without optional fields gracefully', function () {
    $tasks = [
        'title' => 'Minimal Schema Test',
        'tasks' => [
            ['id' => 1, 'status' => 'pending'],
            ['id' => 2, 'status' => 'pending'],
        ],
    ];

    file_put_contents($this->tasksPath, json_encode($tasks, JSON_PRETTY_PRINT));

    // Both tasks should be available (no dependencies)
    $completedIds = $this->selector->getCompletedTaskIds($tasks['tasks']);
    $available = $this->selector->getAvailableTasks($tasks['tasks'], $completedIds);

    expect($available)->toHaveCount(2);

    // Selection should work (falls back to id ordering)
    $next = $this->selector->selectNextTask($tasks['tasks']);
    expect($next['id'])->toBe(1);
});

it('verifies task execution respects priority within dependency constraints', function () {
    // Higher priority tasks should execute first WHEN dependencies allow
    $tasks = [
        ['id' => 1, 'status' => 'pending', 'priority' => 100, 'dependencies' => []],
        ['id' => 2, 'status' => 'pending', 'priority' => 1, 'dependencies' => []],  // Highest priority
        ['id' => 3, 'status' => 'pending', 'priority' => 50, 'dependencies' => [2]],
    ];

    // Task 2 should be selected first (priority 1, no deps)
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(2);

    $tasks[1]['status'] = 'completed';

    // Task 3 now unblocked and has priority 50, task 1 has priority 100
    // Task 3 should be next
    $next = $this->selector->selectNextTask($tasks);
    expect($next['id'])->toBe(3);
});
