<?php

declare(strict_types=1);

use App\Scripts\Runners\RunnerInterface;
use App\Scripts\Runners\RunnerRegistry;

beforeEach(function () {
    $this->registry = new RunnerRegistry;
});

describe('register and get', function () {
    it('registers and retrieves a runner', function () {
        $runner = Mockery::mock(RunnerInterface::class);
        $this->registry->register('shell', $runner);

        expect($this->registry->get('shell'))->toBe($runner);
    });

    it('throws on unknown runner', function () {
        expect(fn () => $this->registry->get('unknown'))
            ->toThrow(InvalidArgumentException::class, 'Unknown runner: unknown');
    });

    it('overwrites existing runner with same name', function () {
        $first = Mockery::mock(RunnerInterface::class);
        $second = Mockery::mock(RunnerInterface::class);

        $this->registry->register('shell', $first);
        $this->registry->register('shell', $second);

        expect($this->registry->get('shell'))->toBe($second);
    });

    it('supports fluent registration', function () {
        $runner = Mockery::mock(RunnerInterface::class);

        $result = $this->registry->register('shell', $runner);

        expect($result)->toBe($this->registry);
    });
});

describe('has', function () {
    it('returns true for registered runner', function () {
        $runner = Mockery::mock(RunnerInterface::class);
        $this->registry->register('shell', $runner);

        expect($this->registry->has('shell'))->toBeTrue();
    });

    it('returns false for unregistered runner', function () {
        expect($this->registry->has('missing'))->toBeFalse();
    });
});

describe('names', function () {
    it('returns empty array when no runners registered', function () {
        expect($this->registry->names())->toBe([]);
    });

    it('returns all registered runner names', function () {
        $this->registry->register('shell', Mockery::mock(RunnerInterface::class));
        $this->registry->register('ai', Mockery::mock(RunnerInterface::class));

        expect($this->registry->names())->toBe(['shell', 'ai']);
    });
});
