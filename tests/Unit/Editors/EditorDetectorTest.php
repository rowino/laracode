<?php

declare(strict_types=1);

use App\Editors\EditorDetector;
use App\Editors\EditorInterface;
use App\Editors\EditorRegistry;

it('detects installed editors via findExecutable', function () {
    $registry = new EditorRegistry;

    $vscode = Mockery::mock(EditorInterface::class);
    $vscode->shouldReceive('name')->andReturn('vscode');
    $vscode->shouldReceive('executable')->andReturn('code');

    $zed = Mockery::mock(EditorInterface::class);
    $zed->shouldReceive('name')->andReturn('zed');
    $zed->shouldReceive('executable')->andReturn('zed');

    $registry->register($vscode)->register($zed);

    $detector = Mockery::mock(EditorDetector::class, [$registry])->makePartial();
    $detector->shouldReceive('findExecutable')->with('code')->andReturn('/usr/local/bin/code');
    $detector->shouldReceive('findExecutable')->with('zed')->andReturn(null);

    $installed = $detector->detectInstalled();

    expect($installed)->toHaveCount(1)
        ->toHaveKey('vscode')
        ->and($installed['vscode'])->toBe($vscode);
});

it('returns empty array when no editors installed', function () {
    $registry = new EditorRegistry;

    $editor = Mockery::mock(EditorInterface::class);
    $editor->shouldReceive('name')->andReturn('vscode');
    $editor->shouldReceive('executable')->andReturn('code');

    $registry->register($editor);

    $detector = Mockery::mock(EditorDetector::class, [$registry])->makePartial();
    $detector->shouldReceive('findExecutable')->with('code')->andReturn(null);

    expect($detector->detectInstalled())->toBe([]);
});

it('detects multiple installed editors', function () {
    $registry = new EditorRegistry;

    $vscode = Mockery::mock(EditorInterface::class);
    $vscode->shouldReceive('name')->andReturn('vscode');
    $vscode->shouldReceive('executable')->andReturn('code');

    $cursor = Mockery::mock(EditorInterface::class);
    $cursor->shouldReceive('name')->andReturn('cursor');
    $cursor->shouldReceive('executable')->andReturn('cursor');

    $phpstorm = Mockery::mock(EditorInterface::class);
    $phpstorm->shouldReceive('name')->andReturn('phpstorm');
    $phpstorm->shouldReceive('executable')->andReturn('phpstorm');

    $registry->register($vscode)->register($cursor)->register($phpstorm);

    $detector = Mockery::mock(EditorDetector::class, [$registry])->makePartial();
    $detector->shouldReceive('findExecutable')->with('code')->andReturn('/usr/local/bin/code');
    $detector->shouldReceive('findExecutable')->with('cursor')->andReturn('/usr/local/bin/cursor');
    $detector->shouldReceive('findExecutable')->with('phpstorm')->andReturn(null);

    $installed = $detector->detectInstalled();

    expect($installed)->toHaveCount(2)
        ->toHaveKeys(['vscode', 'cursor']);
});

it('returns empty when registry is empty', function () {
    $registry = new EditorRegistry;

    $detector = Mockery::mock(EditorDetector::class, [$registry])->makePartial();

    expect($detector->detectInstalled())->toBe([]);
});
