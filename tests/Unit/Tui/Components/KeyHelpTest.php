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

    it('shows dismiss hint in list view when canDismiss is true', function () {
        $html = (new KeyHelp)->render('list', canDismiss: true);

        expect($html)->toContain('d')
            ->and($html)->toContain('Dismiss');
    });

    it('hides dismiss hint in list view when canDismiss is false', function () {
        $html = (new KeyHelp)->render('list', canDismiss: false);

        expect($html)->not->toContain('Dismiss');
    });

    it('shows focus hint in list view when canFocus is true', function () {
        $html = (new KeyHelp)->render('list', canFocus: true);

        expect($html)->toContain('f')
            ->and($html)->toContain('Focus');
    });

    it('hides focus hint in list view when canFocus is false', function () {
        $html = (new KeyHelp)->render('list', canFocus: false);

        expect($html)->not->toContain('Focus');
    });

    it('shows dismiss hint in detail view when canDismiss is true', function () {
        $html = (new KeyHelp)->render('detail', canDismiss: true);

        expect($html)->toContain('d')
            ->and($html)->toContain('Dismiss');
    });

    it('hides dismiss hint in detail view when canDismiss is false', function () {
        $html = (new KeyHelp)->render('detail');

        expect($html)->not->toContain('Dismiss');
    });

    it('does not show focus hint in detail view even when canFocus is true', function () {
        $html = (new KeyHelp)->render('detail', canFocus: true);

        expect($html)->not->toContain('Focus');
    });

    it('preserves default behavior with no extra params', function () {
        $html = (new KeyHelp)->render('list');

        expect($html)->not->toContain('Dismiss')
            ->and($html)->not->toContain('Focus');
    });
});
