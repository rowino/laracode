<?php

declare(strict_types=1);

use App\Editors\CursorEditor;
use App\Editors\EditorInterface;
use App\Editors\PhpStormEditor;
use App\Editors\SublimeEditor;
use App\Editors\VsCodeEditor;
use App\Editors\WindsurfEditor;
use App\Editors\ZedEditor;

$editors = [
    ['class' => VsCodeEditor::class, 'name' => 'vscode', 'executable' => 'code'],
    ['class' => CursorEditor::class, 'name' => 'cursor', 'executable' => 'cursor'],
    ['class' => PhpStormEditor::class, 'name' => 'phpstorm', 'executable' => 'phpstorm'],
    ['class' => ZedEditor::class, 'name' => 'zed', 'executable' => 'zed'],
    ['class' => SublimeEditor::class, 'name' => 'sublime', 'executable' => 'subl'],
    ['class' => WindsurfEditor::class, 'name' => 'windsurf', 'executable' => 'windsurf'],
];

describe('Editor implementations', function () use ($editors) {
    foreach ($editors as $editor) {
        describe($editor['class'], function () use ($editor) {
            it('implements EditorInterface', function () use ($editor) {
                expect(new $editor['class'])->toBeInstanceOf(EditorInterface::class);
            });

            it("returns '{$editor['name']}' as name", function () use ($editor) {
                expect((new $editor['class'])->name())->toBe($editor['name']);
            });

            it("returns '{$editor['executable']}' as executable", function () use ($editor) {
                expect((new $editor['class'])->executable())->toBe($editor['executable']);
            });
        });
    }
});
