<?php

declare(strict_types=1);

use App\Tui\Components\KeyHelp;

describe('KeyHelp', function () {
    it('renders list view hints with navigate, details, and quit', function () {
        $html = (new KeyHelp)->render('list');

        expect($html)->toContain('Navigate')
            ->and($html)->toContain('Details')
            ->and($html)->toContain('Quit')
            ->and($html)->toContain('↑↓')
            ->and($html)->toContain('Enter')
            ->and($html)->toContain('q');
    });

    it('renders detail view hints with back and quit', function () {
        $html = (new KeyHelp)->render('detail');

        expect($html)->toContain('Back')
            ->and($html)->toContain('Quit')
            ->and($html)->toContain('Esc')
            ->and($html)->toContain('q');
    });

    it('renders key names with text-cyan-400 highlight', function () {
        $html = (new KeyHelp)->render('list');

        expect($html)->toContain('text-cyan-400');
    });

    it('renders with text-gray bottom bar styling and no background', function () {
        $html = (new KeyHelp)->render('list');

        expect($html)->toContain('text-gray')
            ->and($html)->not->toContain('bg-');
    });

    it('defaults to list hints for unknown view', function () {
        $html = (new KeyHelp)->render('something-else');

        expect($html)->toContain('Navigate')
            ->and($html)->toContain('Details');
    });

    it('detail view does not contain list-specific hints', function () {
        $html = (new KeyHelp)->render('detail');

        expect($html)->not->toContain('Navigate')
            ->and($html)->not->toContain('Details');
    });
});
