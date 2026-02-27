<?php

declare(strict_types=1);

use App\Tui\Components\SessionList;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/laracode-sessionlist-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->component = new SessionList;
});

afterEach(function () {
    $files = glob($this->tempDir.'/*');
    foreach ($files as $file) {
        unlink($file);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

describe('empty sessions', function () {
    it('renders no active sessions message when list is empty', function () {
        $html = $this->component->render([], 0);

        expect($html)->toContain('No active sessions')
            ->and($html)->toContain('text-gray');
    });
});

describe('session rendering', function () {
    it('renders 4 div lines when active task present', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'main',
            'tasks' => [
                ['id' => 1, 'status' => 'in_progress', 'title' => 'Working'],
            ],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        // 1 wrapper div + 4 line divs
        expect(substr_count($html, '<div class='))->toBe(5);
    });

    it('renders 3 div lines when idle', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'main',
            'tasks' => [],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        // 1 wrapper div + 3 line divs (no line 4 when idle)
        expect(substr_count($html, '<div class='))->toBe(4);
    });

    it('renders full title on line 1 with selector', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'My Feature Implementation',
            'branch' => 'main',
            'tasks' => [],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('▸')
            ->and($html)->toContain('My Feature Implementation');
    });

    it('renders full untruncated title even when very long', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        $longTitle = str_repeat('A', 80);
        file_put_contents($tasksPath, json_encode([
            'title' => $longTitle,
            'branch' => 'main',
            'tasks' => [],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain($longTitle);
    });

    it('renders left-truncated path with branch on line 2', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'feature/cool',
            'tasks' => [],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/home/user/projects/myproject'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('/home/user/projects/myproject')
            ->and($html)->toContain('(feature/cool)');
    });

    it('left-truncates long paths with ellipsis prefix', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'main',
            'tasks' => [],
        ]));

        $longPath = '/home/user/very/deeply/nested/directory/structure/that/goes/on/and/on/project';

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => $longPath],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('…')
            ->and($html)->not->toContain($longPath)
            ->and($html)->toContain('project');
    });

    it('renders mode and agent on line 3', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'main',
            'tasks' => [],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('Mode:')
            ->and($html)->toMatch('/text-cyan-400[^>]*>normal</')
            ->and($html)->toContain('Agent:')
            ->and($html)->toMatch('/text-cyan-400[^>]*>claude</');
    });

    it('renders progress and elapsed time on line 3', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'main',
            'tasks' => [
                ['id' => 1, 'status' => 'completed', 'title' => 'Done'],
                ['id' => 2, 'status' => 'completed', 'title' => 'Done too'],
                ['id' => 3, 'status' => 'pending', 'title' => 'Not yet'],
                ['id' => 4, 'status' => 'pending', 'title' => 'Also not yet'],
            ],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('Tasks:')
            ->and($html)->toMatch('/text-yellow-400[^>]*>2\/4/')
            ->and($html)->toContain('Status:')
            ->and($html)->toContain('Elapsed:')
            ->and($html)->toMatch('/text-yellow-400[^>]*>\d+s</');
    });

    it('renders full active task name on line 4', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        $longTask = str_repeat('B', 60);
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'main',
            'tasks' => [
                ['id' => 1, 'status' => 'in_progress', 'title' => $longTask],
            ],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain($longTask)
            ->and($html)->toContain('text-cyan-400');
    });

    it('renders status label active when task in_progress', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'main',
            'tasks' => [
                ['id' => 1, 'status' => 'in_progress', 'title' => 'Working'],
            ],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toMatch('/text-cyan-400[^>]*>active</');
    });

    it('renders status label complete when all done', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'main',
            'tasks' => [
                ['id' => 1, 'status' => 'completed', 'title' => 'Done'],
                ['id' => 2, 'status' => 'completed', 'title' => 'Also done'],
            ],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toMatch('/text-green-400[^>]*>complete</');
    });

    it('renders status label running when no tasks in_progress', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'main',
            'tasks' => [
                ['id' => 1, 'status' => 'completed', 'title' => 'Done'],
                ['id' => 2, 'status' => 'pending', 'title' => 'Waiting'],
            ],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toMatch('/text-cyan-400[^>]*>running</');
    });

    it('highlights all 3 lines of selected row with text-white', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Selected',
            'branch' => 'main',
            'tasks' => [],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('▸')
            ->and($html)->toMatch('/font-bold[^>]*>Selected/')
            ->and(substr_count($html, 'text-white'))->toBe(3)
            ->and($html)->not->toContain('bg-');
    });

    it('does not highlight non-selected rows', function () {
        $tasksPath1 = $this->tempDir.'/tasks1.json';
        $tasksPath2 = $this->tempDir.'/tasks2.json';
        file_put_contents($tasksPath1, json_encode(['title' => 'First', 'branch' => 'main', 'tasks' => []]));
        file_put_contents($tasksPath2, json_encode(['title' => 'Second', 'branch' => 'dev', 'tasks' => []]));

        $sessions = [
            ['tasksPath' => $tasksPath1, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/a'],
            ['tasksPath' => $tasksPath2, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/b'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('First')
            ->and($html)->toContain('Second')
            ->and(substr_count($html, 'text-white'))->toBe(3);
    });

    it('hides unknown branch', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'unknown',
            'tasks' => [],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->not->toContain('unknown');
    });

    it('hides empty branch', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => '',
            'tasks' => [],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->not->toMatch('/text-cyan-400[^>]*>\(/');
    });

    it('renders branch in parentheses in cyan on line 2', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'feature/xyz',
            'tasks' => [],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toMatch('/text-cyan-400[^>]*>\(feature\/xyz\)/');
    });

    it('adds mb-1 spacing on last line of each card', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'main',
            'tasks' => [],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('mb-1')
            ->and($html)->not->toContain('mb-2');
    });

    it('does not render percentage label', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Test',
            'branch' => 'main',
            'tasks' => [
                ['id' => 1, 'status' => 'completed', 'title' => 'Done'],
                ['id' => 2, 'status' => 'pending', 'title' => 'Not yet'],
            ],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->not->toContain('50%')
            ->and($html)->toContain('1/2');
    });
});

describe('registry status rendering', function () {
    it('renders green complete label when registry status is completed', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Finished Feature',
            'branch' => 'main',
            'tasks' => [
                ['id' => 1, 'status' => 'completed', 'title' => 'Done'],
                ['id' => 2, 'status' => 'pending', 'title' => 'Skipped'],
            ],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project', 'status' => 'completed'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toMatch('/text-green-400[^>]*>complete</');
    });

    it('renders red crashed label when PID is dead and status is running', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Crashed Session',
            'branch' => 'main',
            'tasks' => [
                ['id' => 1, 'status' => 'in_progress', 'title' => 'Was working'],
            ],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => 999999999, 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project', 'status' => 'running'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toMatch('/text-red-400[^>]*>crashed</');
    });

    it('uses task-based logic when PID is alive and status is running', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Active Session',
            'branch' => 'main',
            'tasks' => [
                ['id' => 1, 'status' => 'in_progress', 'title' => 'Working'],
            ],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project', 'status' => 'running'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toMatch('/text-cyan-400[^>]*>active</');
    });
});

describe('edge cases', function () {
    it('handles missing tasks file gracefully', function () {
        $sessions = [
            ['tasksPath' => '/nonexistent/tasks.json', 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('Untitled')
            ->and($html)->not->toContain('unknown');
    });

    it('handles corrupt JSON in tasks file', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, 'not valid json');

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('Untitled');
    });

    it('handles empty tasks file', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, '');

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('Untitled');
    });

    it('renders 0/0 progress when tasks array is empty', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Empty',
            'branch' => 'main',
            'tasks' => [],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('Tasks:')
            ->and($html)->toContain('0/0');
    });

    it('renders all completed progress', function () {
        $tasksPath = $this->tempDir.'/tasks.json';
        file_put_contents($tasksPath, json_encode([
            'title' => 'Done',
            'branch' => 'main',
            'tasks' => [
                ['id' => 1, 'status' => 'completed', 'title' => 'A'],
                ['id' => 2, 'status' => 'completed', 'title' => 'B'],
            ],
        ]));

        $sessions = [
            ['tasksPath' => $tasksPath, 'pid' => getmypid(), 'startedAt' => date('c'), 'mode' => 'normal', 'agent' => 'claude', 'projectPath' => '/project'],
        ];

        $html = $this->component->render($sessions, 0);

        expect($html)->toContain('Tasks:')
            ->and($html)->toMatch('/text-green-400[^>]*>2\/2/');
    });
});
