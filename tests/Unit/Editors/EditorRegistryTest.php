<?php

declare(strict_types=1);

use App\Editors\EditorInterface;
use App\Editors\EditorRegistry;

it('registers and retrieves an editor by name', function () {
    $registry = new EditorRegistry;
    $editor = Mockery::mock(EditorInterface::class);
    $editor->shouldReceive('name')->andReturn('vscode');

    $registry->register($editor);

    expect($registry->get('vscode'))->toBe($editor);
});

it('throws exception for unregistered editor', function () {
    $registry = new EditorRegistry;
    $registry->get('nonexistent');
})->throws(InvalidArgumentException::class, "Editor 'nonexistent' is not registered.");

it('checks if editor exists with has()', function () {
    $registry = new EditorRegistry;
    $editor = Mockery::mock(EditorInterface::class);
    $editor->shouldReceive('name')->andReturn('cursor');

    $registry->register($editor);

    expect($registry->has('cursor'))->toBeTrue()
        ->and($registry->has('nonexistent'))->toBeFalse();
});

it('returns all registered editors', function () {
    $registry = new EditorRegistry;
    $vscode = Mockery::mock(EditorInterface::class);
    $vscode->shouldReceive('name')->andReturn('vscode');

    $cursor = Mockery::mock(EditorInterface::class);
    $cursor->shouldReceive('name')->andReturn('cursor');

    $registry->register($vscode)->register($cursor);

    expect($registry->all())
        ->toHaveCount(2)
        ->toHaveKeys(['vscode', 'cursor']);
});

it('returns registered editor names', function () {
    $registry = new EditorRegistry;
    $vscode = Mockery::mock(EditorInterface::class);
    $vscode->shouldReceive('name')->andReturn('vscode');

    $zed = Mockery::mock(EditorInterface::class);
    $zed->shouldReceive('name')->andReturn('zed');

    $registry->register($vscode)->register($zed);

    expect($registry->names())->toBe(['vscode', 'zed']);
});

it('returns empty array when no editors registered', function () {
    $registry = new EditorRegistry;

    expect($registry->all())->toBe([])
        ->and($registry->names())->toBe([]);
});

it('register returns self for fluent chaining', function () {
    $registry = new EditorRegistry;
    $editor = Mockery::mock(EditorInterface::class);
    $editor->shouldReceive('name')->andReturn('vscode');

    expect($registry->register($editor))->toBe($registry);
});
