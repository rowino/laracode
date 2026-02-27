<?php

declare(strict_types=1);

use App\Tui\SessionRegistry;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/laracode-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->registryPath = $this->tempDir.'/sessions.json';
    $this->registry = new SessionRegistry($this->registryPath);
});

afterEach(function () {
    if (file_exists($this->registryPath)) {
        unlink($this->registryPath);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

describe('register', function () {
    it('creates registry file and adds session entry', function () {
        $this->registry->register('/path/to/tasks.json', getmypid(), 'normal', 'claude', '/project');

        expect(file_exists($this->registryPath))->toBeTrue();

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'])->toHaveCount(1)
            ->and($data['sessions'][0]['tasksPath'])->toBe('/path/to/tasks.json')
            ->and($data['sessions'][0]['pid'])->toBe(getmypid())
            ->and($data['sessions'][0]['mode'])->toBe('normal')
            ->and($data['sessions'][0]['agent'])->toBe('claude')
            ->and($data['sessions'][0]['projectPath'])->toBe('/project')
            ->and($data['sessions'][0]['startedAt'])->toBeString();
    });

    it('adds multiple sessions', function () {
        $this->registry->register('/path/a/tasks.json', getmypid(), 'normal', 'claude', '/project-a');
        $this->registry->register('/path/b/tasks.json', getmypid(), 'yolo', 'claude', '/project-b');

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'])->toHaveCount(2)
            ->and($data['sessions'][0]['tasksPath'])->toBe('/path/a/tasks.json')
            ->and($data['sessions'][1]['tasksPath'])->toBe('/path/b/tasks.json');
    });

    it('replaces existing entry with same tasksPath', function () {
        $this->registry->register('/path/tasks.json', 100, 'normal', 'claude', '/project');
        $this->registry->register('/path/tasks.json', 200, 'yolo', 'claude', '/project-new');

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'])->toHaveCount(1)
            ->and($data['sessions'][0]['pid'])->toBe(200)
            ->and($data['sessions'][0]['mode'])->toBe('yolo')
            ->and($data['sessions'][0]['projectPath'])->toBe('/project-new');
    });
});

describe('register status', function () {
    it('includes status running in session entry', function () {
        $this->registry->register('/path/to/tasks.json', getmypid(), 'normal', 'claude', '/project');

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'][0]['status'])->toBe('running');
    });
});

describe('markCompleted', function () {
    it('sets status to completed and adds completedAt timestamp', function () {
        $this->registry->register('/path/tasks.json', getmypid(), 'normal', 'claude', '/project');

        $this->registry->markCompleted('/path/tasks.json');

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'][0]['status'])->toBe('completed')
            ->and($data['sessions'][0]['completedAt'])->toBeString();
    });

    it('does nothing when tasksPath not found', function () {
        $this->registry->register('/path/tasks.json', getmypid(), 'normal', 'claude', '/project');

        $this->registry->markCompleted('/nonexistent/tasks.json');

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'])->toHaveCount(1)
            ->and($data['sessions'][0]['status'])->toBe('running');
    });
});

describe('getSessions', function () {
    it('returns sessions with live PIDs as running', function () {
        $this->registry->register('/path/tasks.json', getmypid(), 'normal', 'claude', '/project');

        $sessions = $this->registry->getSessions();

        expect($sessions)->toHaveCount(1)
            ->and($sessions[0]['status'])->toBe('running');
    });

    it('returns completed sessions even with dead PIDs', function () {
        $this->registry->register('/path/tasks.json', 999999, 'normal', 'claude', '/project');
        $this->registry->markCompleted('/path/tasks.json');

        $sessions = $this->registry->getSessions();

        expect($sessions)->toHaveCount(1)
            ->and($sessions[0]['status'])->toBe('completed');
    });

    it('returns crashed sessions with dead PID and status running', function () {
        $this->registry->register('/path/tasks.json', 999999, 'normal', 'claude', '/project');

        $sessions = $this->registry->getSessions();

        expect($sessions)->toHaveCount(1)
            ->and($sessions[0]['status'])->toBe('crashed');
    });

    it('returns mix of running completed and crashed', function () {
        $this->registry->register('/path/live.json', getmypid(), 'normal', 'claude', '/live');
        $this->registry->register('/path/completed.json', 999998, 'normal', 'claude', '/completed');
        $this->registry->markCompleted('/path/completed.json');
        $this->registry->register('/path/crashed.json', 999999, 'normal', 'claude', '/crashed');

        $sessions = $this->registry->getSessions();
        $statuses = array_column($sessions, 'status');
        sort($statuses);

        expect($sessions)->toHaveCount(3)
            ->and($statuses)->toBe(['completed', 'crashed', 'running']);
    });
});

describe('deregister', function () {
    it('removes correct session by tasksPath', function () {
        $this->registry->register('/path/a/tasks.json', getmypid(), 'normal', 'claude', '/project-a');
        $this->registry->register('/path/b/tasks.json', getmypid(), 'yolo', 'claude', '/project-b');

        $this->registry->deregister('/path/a/tasks.json');

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'])->toHaveCount(1)
            ->and($data['sessions'][0]['tasksPath'])->toBe('/path/b/tasks.json');
    });

    it('does nothing when tasksPath not found', function () {
        $this->registry->register('/path/tasks.json', getmypid(), 'normal', 'claude', '/project');

        $this->registry->deregister('/nonexistent/tasks.json');

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'])->toHaveCount(1);
    });

    it('handles deregister on empty registry', function () {
        $this->registry->deregister('/path/tasks.json');

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'])->toHaveCount(0);
    });
});

describe('getActiveSessions', function () {
    it('returns sessions with live PIDs', function () {
        $this->registry->register('/path/tasks.json', getmypid(), 'normal', 'claude', '/project');

        $active = $this->registry->getActiveSessions();

        expect($active)->toHaveCount(1)
            ->and($active[0]['pid'])->toBe(getmypid());
    });

    it('filters out dead PIDs', function () {
        $this->registry->register('/path/live.json', getmypid(), 'normal', 'claude', '/project-live');
        $this->registry->register('/path/dead.json', 999999, 'yolo', 'claude', '/project-dead');

        $active = $this->registry->getActiveSessions();

        expect($active)->toHaveCount(1)
            ->and($active[0]['tasksPath'])->toBe('/path/live.json');
    });

    it('returns empty array when no sessions exist', function () {
        expect($this->registry->getActiveSessions())->toBe([]);
    });

    it('returns empty array when all PIDs are dead', function () {
        $this->registry->register('/path/a.json', 999998, 'normal', 'claude', '/a');
        $this->registry->register('/path/b.json', 999999, 'normal', 'claude', '/b');

        expect($this->registry->getActiveSessions())->toBe([]);
    });
});

describe('cleanup', function () {
    it('removes all dead PID entries', function () {
        $this->registry->register('/path/live.json', getmypid(), 'normal', 'claude', '/project-live');
        $this->registry->register('/path/dead1.json', 999998, 'normal', 'claude', '/dead1');
        $this->registry->register('/path/dead2.json', 999999, 'yolo', 'claude', '/dead2');

        $this->registry->cleanup();

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'])->toHaveCount(1)
            ->and($data['sessions'][0]['tasksPath'])->toBe('/path/live.json');
    });

    it('results in empty sessions when all PIDs are dead', function () {
        $this->registry->register('/path/a.json', 999998, 'normal', 'claude', '/a');
        $this->registry->register('/path/b.json', 999999, 'normal', 'claude', '/b');

        $this->registry->cleanup();

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'])->toHaveCount(0);
    });

    it('preserves completed sessions with dead PIDs', function () {
        $this->registry->register('/path/completed.json', 999998, 'normal', 'claude', '/completed');
        $this->registry->markCompleted('/path/completed.json');
        $this->registry->register('/path/crashed.json', 999999, 'normal', 'claude', '/crashed');

        $this->registry->cleanup();

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'])->toHaveCount(1)
            ->and($data['sessions'][0]['tasksPath'])->toBe('/path/completed.json')
            ->and($data['sessions'][0]['status'])->toBe('completed');
    });
});

describe('edge cases', function () {
    it('handles missing registry file on read', function () {
        expect($this->registry->getActiveSessions())->toBe([]);
    });

    it('handles corrupt JSON in registry', function () {
        file_put_contents($this->registryPath, 'not valid json {{{');

        expect($this->registry->getActiveSessions())->toBe([]);
    });

    it('handles empty registry file', function () {
        file_put_contents($this->registryPath, '');

        expect($this->registry->getActiveSessions())->toBe([]);
    });

    it('recovers from corrupt JSON on write operations', function () {
        file_put_contents($this->registryPath, 'corrupt data');

        $this->registry->register('/path/tasks.json', getmypid(), 'normal', 'claude', '/project');

        $data = json_decode(file_get_contents($this->registryPath), true);

        expect($data['sessions'])->toHaveCount(1)
            ->and($data['sessions'][0]['tasksPath'])->toBe('/path/tasks.json');
    });

    it('creates directory if missing', function () {
        $nestedDir = $this->tempDir.'/nested/deep';
        $nestedPath = $nestedDir.'/sessions.json';
        $registry = new SessionRegistry($nestedPath);

        expect(is_dir($nestedDir))->toBeTrue();

        // cleanup
        if (file_exists($nestedPath)) {
            unlink($nestedPath);
        }
        rmdir($nestedDir);
        rmdir($this->tempDir.'/nested');
    });

    it('exposes registry path via getRegistryPath', function () {
        expect($this->registry->getRegistryPath())->toBe($this->registryPath);
    });
});
