<?php

declare(strict_types=1);

use App\Tui\SessionRegistry;

it('has show command registered', function () {
    $this->artisan('show --help')
        ->assertSuccessful()
        ->expectsOutputToContain('Monitor all active build sessions');
});

it('can be resolved from the container', function () {
    $command = app(\App\Commands\ShowCommand::class);

    expect($command)->toBeInstanceOf(\App\Commands\ShowCommand::class);
});

it('receives SessionRegistry as dependency', function () {
    $command = app(\App\Commands\ShowCommand::class);

    $property = new ReflectionProperty($command, 'registry');
    $registry = $property->getValue($command);

    expect($registry)->toBeInstanceOf(SessionRegistry::class);
});
