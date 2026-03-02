<?php

declare(strict_types=1);

use App\Tui\MarkdownRenderer;

describe('MarkdownRenderer', function () {
    it('renders headings as bold cyan', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind('## Overview'))
            ->toBe('<span class="font-bold text-cyan">Overview</span>')
            ->and($renderer->toTermwind('### Details'))
            ->toBe('<span class="font-bold text-cyan">Details</span>');
    });

    it('renders unordered list items with tick marker', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind('- First item'))
            ->toBe('<div class="ml-2"><span class="text-green">✓</span> First item</div>')
            ->and($renderer->toTermwind('* Second item'))
            ->toBe('<div class="ml-2"><span class="text-green">✓</span> Second item</div>');
    });

    it('renders ordered list items with yellow numbers', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind('1. First step'))
            ->toBe('<div class="ml-2"><span class="text-yellow">1.</span> First step</div>')
            ->and($renderer->toTermwind('12. Twelfth step'))
            ->toBe('<div class="ml-2"><span class="text-yellow">12.</span> Twelfth step</div>');
    });

    it('renders bold text', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind('This is **bold** text'))
            ->toBe('<div>This is <span class="font-bold">bold</span> text</div>');
    });

    it('renders inline code in yellow', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind('Use `composer test` to run'))
            ->toBe('<div>Use <span class="text-yellow">composer test</span> to run</div>');
    });

    it('renders blockquotes with pipe marker', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind('> Important note'))
            ->toBe('<div class="ml-2"><span class="text-gray">│</span> <span class="text-gray italic">Important note</span></div>');
    });

    it('renders blank lines as spacing divs', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind(''))
            ->toBe('<div></div>');
    });

    it('renders plain text in divs', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind('Just some text'))
            ->toBe('<div>Just some text</div>');
    });

    it('handles combined markdown', function () {
        $markdown = <<<'MD'
        ## Summary

        - Created **EditorInterface**
        - Added `6` implementations

        1. Install deps
        2. Run tests
        MD;

        $html = (new MarkdownRenderer)->toTermwind($markdown);

        expect($html)
            ->toContain('<span class="font-bold text-cyan">Summary</span>')
            ->toContain('<span class="text-green">✓</span> Created <span class="font-bold">EditorInterface</span>')
            ->toContain('<span class="text-green">✓</span> Added <span class="text-yellow">6</span> implementations')
            ->toContain('<span class="text-yellow">1.</span> Install deps')
            ->toContain('<span class="text-yellow">2.</span> Run tests')
            ->toContain('<div></div>');
    });

    it('handles empty string', function () {
        expect((new MarkdownRenderer)->toTermwind(''))
            ->toBe('<div></div>');
    });

    it('handles whitespace-only input', function () {
        expect((new MarkdownRenderer)->toTermwind("  \n  \n  "))
            ->toBe("<div></div>\n<div></div>\n<div></div>");
    });

    it('escapes HTML entities to prevent XSS', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind('<script>alert("xss")</script>'))
            ->not->toContain('<script>')
            ->toContain('\\&lt;script&gt;');
    });

    it('applies inline transforms inside list items', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind('- Use **bold** and `code` here'))
            ->toContain('<span class="font-bold">bold</span>')
            ->toContain('<span class="text-yellow">code</span>');
    });

    it('applies inline transforms inside headings', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind('## The `config` file'))
            ->toContain('<span class="text-yellow">config</span>')
            ->toContain('font-bold text-cyan');
    });

    it('handles empty blockquote', function () {
        $renderer = new MarkdownRenderer;

        expect($renderer->toTermwind('> '))
            ->toContain('text-gray italic');
    });
});
