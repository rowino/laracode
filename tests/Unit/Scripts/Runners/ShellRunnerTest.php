<?php

declare(strict_types=1);

use App\Scripts\Interpolator;
use App\Scripts\Runners\ShellRunner;

beforeEach(function () {
    $this->runner = new ShellRunner(new Interpolator);
});

describe('execute', function () {
    it('runs a successful command', function () {
        $result = $this->runner->execute(
            ['id' => 'echo-test', 'run' => 'echo hello'],
            [],
            sys_get_temp_dir()
        );

        expect($result->id)->toBe('echo-test')
            ->and($result->success)->toBeTrue()
            ->and(trim($result->output))->toBe('hello')
            ->and($result->error)->toBe('');
    });

    it('returns failure for non-zero exit code', function () {
        $result = $this->runner->execute(
            ['id' => 'fail-test', 'run' => 'exit 1'],
            [],
            sys_get_temp_dir()
        );

        expect($result->id)->toBe('fail-test')
            ->and($result->success)->toBeFalse();
    });

    it('captures stderr', function () {
        $result = $this->runner->execute(
            ['id' => 'stderr-test', 'run' => 'echo error >&2'],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue()
            ->and(trim($result->error))->toBe('error');
    });

    it('interpolates variables in command', function () {
        $result = $this->runner->execute(
            ['id' => 'interp-test', 'run' => 'echo {{GREETING}}'],
            ['GREETING' => 'world'],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue()
            ->and(trim($result->output))->toBe('world');
    });

    it('uses working directory', function () {
        $tmpDir = sys_get_temp_dir();

        $result = $this->runner->execute(
            ['id' => 'pwd-test', 'run' => 'pwd'],
            [],
            $tmpDir
        );

        expect($result->success)->toBeTrue()
            ->and(trim($result->output))->toBe(realpath($tmpDir));
    });

    it('falls back to "command" key when "run" is absent', function () {
        $result = $this->runner->execute(
            ['id' => 'cmd-test', 'command' => 'echo fallback'],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue()
            ->and(trim($result->output))->toBe('fallback');
    });

    it('returns failure when no command specified', function () {
        $result = $this->runner->execute(
            ['id' => 'empty-test'],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeFalse()
            ->and($result->error)->toBe('No command specified');
    });

    it('defaults id to "step" when missing', function () {
        $result = $this->runner->execute(
            ['run' => 'echo ok'],
            [],
            sys_get_temp_dir()
        );

        expect($result->id)->toBe('step')
            ->and($result->success)->toBeTrue();
    });

    it('escapes shell metacharacters in variable values', function () {
        $result = $this->runner->execute(
            ['id' => 'inject-test', 'run' => 'echo {{INPUT}}'],
            ['INPUT' => 'safe; echo hacked'],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue()
            ->and(trim($result->output))->toBe('safe; echo hacked')
            ->and($result->output)->not->toContain('hacked'."\n".'hacked');
    });

    it('prevents command substitution in variables', function () {
        $result = $this->runner->execute(
            ['id' => 'subst-test', 'run' => 'echo {{INPUT}}'],
            ['INPUT' => '$(echo pwned)'],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue()
            ->and(trim($result->output))->toBe('$(echo pwned)');
    });

    it('passes variables as environment variables', function () {
        $result = $this->runner->execute(
            ['id' => 'env-test', 'run' => 'echo $MY_VAR'],
            ['MY_VAR' => 'from-env'],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue()
            ->and(trim($result->output))->toBe('from-env');
    });

    it('allows raw filter to bypass escaping', function () {
        $result = $this->runner->execute(
            ['id' => 'raw-test', 'run' => 'for x in {{ITEMS|raw}}; do echo $x; done'],
            ['ITEMS' => 'a b c'],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue()
            ->and(trim($result->output))->toBe("a\nb\nc");
    });
});

describe('output callback', function () {
    it('invokes callback with command type before execution', function () {
        $messages = [];
        $this->runner->setOutputCallback(function (string $output, string $type) use (&$messages) {
            $messages[] = ['output' => $output, 'type' => $type];
        });

        $this->runner->execute(
            ['id' => 'cb-test', 'run' => 'echo hello'],
            [],
            sys_get_temp_dir()
        );

        expect($messages[0]['type'])->toBe('command')
            ->and($messages[0]['output'])->toBe('→ echo hello');
    });

    it('invokes callback with stdout', function () {
        $messages = [];
        $this->runner->setOutputCallback(function (string $output, string $type) use (&$messages) {
            $messages[] = ['output' => $output, 'type' => $type];
        });

        $this->runner->execute(
            ['id' => 'cb-test', 'run' => 'echo hello'],
            [],
            sys_get_temp_dir()
        );

        $stdoutMessages = array_filter($messages, fn ($m) => $m['type'] === 'stdout');
        expect($stdoutMessages)->not->toBeEmpty();
    });

    it('invokes callback with stderr', function () {
        $messages = [];
        $this->runner->setOutputCallback(function (string $output, string $type) use (&$messages) {
            $messages[] = ['output' => $output, 'type' => $type];
        });

        $this->runner->execute(
            ['id' => 'cb-test', 'run' => 'echo error >&2'],
            [],
            sys_get_temp_dir()
        );

        $stderrMessages = array_filter($messages, fn ($m) => $m['type'] === 'stderr');
        expect($stderrMessages)->not->toBeEmpty();
    });

    it('does not invoke callback when none is set', function () {
        $result = $this->runner->execute(
            ['id' => 'no-cb', 'run' => 'echo hello'],
            [],
            sys_get_temp_dir()
        );

        expect($result->success)->toBeTrue();
    });
});
