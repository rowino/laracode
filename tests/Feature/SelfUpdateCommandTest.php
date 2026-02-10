<?php

declare(strict_types=1);

use App\Commands\SelfUpdateCommand;

it('shows source mode message when not running as PHAR', function () {
    $this->artisan('self-update')
        ->expectsOutput('You are running LaraCode from source.')
        ->expectsOutputToContain('composer update')
        ->assertSuccessful();
});

it('shows source mode message with --check option', function () {
    $this->artisan('self-update', ['--check' => true])
        ->expectsOutput('You are running LaraCode from source.')
        ->assertSuccessful();
});

it('shows source mode message with --rollback option', function () {
    $this->artisan('self-update', ['--rollback' => true])
        ->expectsOutput('You are running LaraCode from source.')
        ->assertSuccessful();
});

it('has correct signature with all options', function () {
    $command = new SelfUpdateCommand;

    expect($command->getName())->toBe('self-update')
        ->and($command->getDescription())->toBe('Update LaraCode to the latest version');

    $definition = $command->getDefinition();

    expect($definition->hasOption('stable'))->toBeTrue()
        ->and($definition->hasOption('unstable'))->toBeTrue()
        ->and($definition->hasOption('rollback'))->toBeTrue()
        ->and($definition->hasOption('check'))->toBeTrue();
});

it('gets current version from config', function () {
    $command = new SelfUpdateCommand;
    $method = new ReflectionMethod($command, 'getCurrentVersion');

    expect($method->invoke($command))->toBe(config('app.version'));
});

it('detects non-PHAR environment correctly', function () {
    $command = new SelfUpdateCommand;
    $method = new ReflectionMethod($command, 'isPhar');

    expect($method->invoke($command))->toBeFalse();
});
