<?php

declare(strict_types=1);

use App\Scripts\Interpolator;

beforeEach(function () {
    $this->interpolator = new Interpolator;
});

describe('interpolate', function () {
    it('replaces {{VAR}} with values', function () {
        expect($this->interpolator->interpolate('Hello {{NAME}}!', ['NAME' => 'World']))
            ->toBe('Hello World!');
    });

    it('replaces multiple variables', function () {
        expect($this->interpolator->interpolate(
            '{{GREETING}} {{NAME}}, welcome to {{PLACE}}',
            ['GREETING' => 'Hello', 'NAME' => 'John', 'PLACE' => 'Paris']
        ))->toBe('Hello John, welcome to Paris');
    });

    it('keeps placeholder for missing variables', function () {
        expect($this->interpolator->interpolate('Hello {{NAME}}!', []))
            ->toBe('Hello {{NAME}}!');
    });

    it('handles partial missing variables', function () {
        expect($this->interpolator->interpolate('{{GREETING}} {{NAME}}!', ['GREETING' => 'Hello']))
            ->toBe('Hello {{NAME}}!');
    });

    it('converts non-string scalars to strings', function () {
        expect($this->interpolator->interpolate(
            'Count: {{NUM}}, Active: {{BOOL}}',
            ['NUM' => 42, 'BOOL' => true]
        ))->toBe('Count: 42, Active: 1');
    });

    it('handles arrays as empty strings', function () {
        expect($this->interpolator->interpolate('Value: {{ARR}}', ['ARR' => ['a', 'b']]))
            ->toBe('Value: ');
    });

    it('applies filter to variable', function () {
        expect($this->interpolator->interpolate('{{NAME|upper}}', ['NAME' => 'hello']))
            ->toBe('HELLO');
    });

    it('applies snake filter', function () {
        expect($this->interpolator->interpolate('{{NAME|snake}}', ['NAME' => 'featureAuth']))
            ->toBe('feature_auth');
    });

    it('applies slug filter', function () {
        expect($this->interpolator->interpolate('{{NAME|slug}}', ['NAME' => 'Feature Auth Module']))
            ->toBe('feature-auth-module');
    });

    it('applies lower filter', function () {
        expect($this->interpolator->interpolate('{{NAME|lower}}', ['NAME' => 'HELLO']))
            ->toBe('hello');
    });

    it('handles nested interpolation in single pass', function () {
        expect($this->interpolator->interpolate(
            '{{A}} and {{B|upper}}',
            ['A' => 'first', 'B' => 'second']
        ))->toBe('first and SECOND');
    });

    it('returns template unchanged when no placeholders', function () {
        expect($this->interpolator->interpolate('no placeholders here', ['KEY' => 'val']))
            ->toBe('no placeholders here');
    });

    it('handles empty template', function () {
        expect($this->interpolator->interpolate('', ['KEY' => 'val']))
            ->toBe('');
    });
});

describe('applyFilter', function () {
    it('converts to snake_case', function () {
        expect($this->interpolator->applyFilter('featureAuth', 'snake'))
            ->toBe('feature_auth');
    });

    it('handles spaces and special chars in snake', function () {
        expect($this->interpolator->applyFilter('Feature Auth Module', 'snake'))
            ->toBe('feature_auth_module');
    });

    it('handles already snake_case', function () {
        expect($this->interpolator->applyFilter('already_snake_case', 'snake'))
            ->toBe('already_snake_case');
    });

    it('converts to slug', function () {
        expect($this->interpolator->applyFilter('Feature Auth Module', 'slug'))
            ->toBe('feature-auth-module');
    });

    it('handles special characters in slug', function () {
        expect($this->interpolator->applyFilter('Feature/Auth!Module@Test', 'slug'))
            ->toBe('feature-auth-module-test');
    });

    it('converts to uppercase', function () {
        expect($this->interpolator->applyFilter('hello world', 'upper'))
            ->toBe('HELLO WORLD');
    });

    it('converts to lowercase', function () {
        expect($this->interpolator->applyFilter('HELLO WORLD', 'lower'))
            ->toBe('hello world');
    });

    it('returns unchanged for unknown filter', function () {
        expect($this->interpolator->applyFilter('hello', 'unknown'))
            ->toBe('hello');
    });
});

describe('toSnakeCase', function () {
    it('converts camelCase', function () {
        expect($this->interpolator->toSnakeCase('camelCase'))
            ->toBe('camel_case');
    });

    it('trims leading/trailing underscores', function () {
        expect($this->interpolator->toSnakeCase('_leading_'))
            ->toBe('leading');
    });
});

describe('toSlug', function () {
    it('converts spaces to hyphens', function () {
        expect($this->interpolator->toSlug('hello world'))
            ->toBe('hello-world');
    });

    it('trims leading/trailing hyphens', function () {
        expect($this->interpolator->toSlug('-leading-'))
            ->toBe('leading');
    });
});
