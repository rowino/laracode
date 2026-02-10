<?php

declare(strict_types=1);

use App\Agents\AgentInterface;
use App\Agents\AgentRegistry;
use App\Services\Settings\SettingsLoader;
use App\Services\Settings\SettingsService;

function createMockAgent(string $name): AgentInterface
{
    $mock = Mockery::mock(AgentInterface::class);
    $mock->allows('name')->andReturns($name);

    return $mock;
}

beforeEach(function () {
    $this->loader = new SettingsLoader;
    $this->settings = new SettingsService($this->loader);
    $this->registry = new AgentRegistry($this->settings);
});

describe('register', function () {
    it('registers an agent', function () {
        $agent = createMockAgent('claude');

        $result = $this->registry->register($agent);

        expect($result)->toBe($this->registry)
            ->and($this->registry->has('claude'))->toBeTrue();
    });

    it('allows registering multiple agents', function () {
        $this->registry->register(createMockAgent('claude'));
        $this->registry->register(createMockAgent('opencode'));

        expect($this->registry->has('claude'))->toBeTrue()
            ->and($this->registry->has('opencode'))->toBeTrue();
    });
});

describe('get', function () {
    it('returns registered agent by name', function () {
        $agent = createMockAgent('claude');
        $this->registry->register($agent);

        $result = $this->registry->get('claude');

        expect($result)->toBe($agent);
    });

    it('throws exception for unregistered agent', function () {
        $this->registry->get('nonexistent');
    })->throws(InvalidArgumentException::class, "Agent 'nonexistent' is not registered.");
});

describe('has', function () {
    it('returns true for registered agent', function () {
        $this->registry->register(createMockAgent('claude'));

        expect($this->registry->has('claude'))->toBeTrue();
    });

    it('returns false for unregistered agent', function () {
        expect($this->registry->has('nonexistent'))->toBeFalse();
    });
});

describe('all', function () {
    it('returns all registered agents', function () {
        $claude = createMockAgent('claude');
        $opencode = createMockAgent('opencode');

        $this->registry->register($claude);
        $this->registry->register($opencode);

        $all = $this->registry->all();

        expect($all)->toHaveKey('claude')
            ->and($all)->toHaveKey('opencode')
            ->and($all['claude'])->toBe($claude)
            ->and($all['opencode'])->toBe($opencode);
    });

    it('returns empty array when no agents registered', function () {
        expect($this->registry->all())->toBe([]);
    });
});

describe('names', function () {
    it('returns array of registered agent names', function () {
        $this->registry->register(createMockAgent('claude'));
        $this->registry->register(createMockAgent('opencode'));

        $names = $this->registry->names();

        expect($names)->toBe(['claude', 'opencode']);
    });

    it('returns empty array when no agents registered', function () {
        expect($this->registry->names())->toBe([]);
    });
});

describe('getDefault', function () {
    it('returns first registered agent when no default set', function () {
        $claude = createMockAgent('claude');
        $opencode = createMockAgent('opencode');

        $this->registry->register($claude);
        $this->registry->register($opencode);

        expect($this->registry->getDefault())->toBe($claude);
    });

    it('returns explicitly set default agent', function () {
        $claude = createMockAgent('claude');
        $opencode = createMockAgent('opencode');

        $this->registry->register($claude);
        $this->registry->register($opencode);
        $this->registry->setDefault('opencode');

        expect($this->registry->getDefault())->toBe($opencode);
    });

    it('throws exception when no agents registered', function () {
        $this->registry->getDefault();
    })->throws(InvalidArgumentException::class, 'No agents registered.');
});

describe('getDefaultName', function () {
    it('returns first agent name when no default configured', function () {
        $this->registry->register(createMockAgent('claude'));
        $this->registry->register(createMockAgent('opencode'));

        expect($this->registry->getDefaultName())->toBe('claude');
    });

    it('returns explicitly set default name', function () {
        $this->registry->register(createMockAgent('claude'));
        $this->registry->register(createMockAgent('opencode'));
        $this->registry->setDefault('opencode');

        expect($this->registry->getDefaultName())->toBe('opencode');
    });
});

describe('setDefault', function () {
    it('sets the default agent', function () {
        $this->registry->register(createMockAgent('claude'));
        $this->registry->register(createMockAgent('opencode'));

        $result = $this->registry->setDefault('opencode');

        expect($result)->toBe($this->registry)
            ->and($this->registry->getDefaultName())->toBe('opencode');
    });

    it('throws exception for unregistered agent', function () {
        $this->registry->register(createMockAgent('claude'));

        $this->registry->setDefault('nonexistent');
    })->throws(InvalidArgumentException::class, "Agent 'nonexistent' is not registered.");
});

describe('settings integration', function () {
    it('uses default from settings when available', function () {
        $tempDir = sys_get_temp_dir().'/agent_registry_test_'.uniqid();
        mkdir($tempDir.'/.laracode', 0755, true);
        file_put_contents($tempDir.'/.laracode/settings.json', json_encode([
            'agents' => ['default' => 'opencode'],
        ]));

        $loader = new SettingsLoader;
        $settings = new SettingsService($loader);
        $settings->setProjectPath($tempDir);
        $registry = new AgentRegistry($settings);

        $registry->register(createMockAgent('claude'));
        $registry->register(createMockAgent('opencode'));

        expect($registry->getDefaultName())->toBe('opencode');

        @unlink($tempDir.'/.laracode/settings.json');
        @rmdir($tempDir.'/.laracode');
        @rmdir($tempDir);
    });

    it('falls back to first agent when settings default not registered', function () {
        $tempDir = sys_get_temp_dir().'/agent_registry_test_'.uniqid();
        mkdir($tempDir.'/.laracode', 0755, true);
        file_put_contents($tempDir.'/.laracode/settings.json', json_encode([
            'agents' => ['default' => 'nonexistent'],
        ]));

        $loader = new SettingsLoader;
        $settings = new SettingsService($loader);
        $settings->setProjectPath($tempDir);
        $registry = new AgentRegistry($settings);

        $registry->register(createMockAgent('claude'));
        $registry->register(createMockAgent('opencode'));

        expect($registry->getDefaultName())->toBe('claude');

        @unlink($tempDir.'/.laracode/settings.json');
        @rmdir($tempDir.'/.laracode');
        @rmdir($tempDir);
    });
});
