<?php

declare(strict_types=1);

use App\Scripts\ConditionEvaluator;
use App\Scripts\Interpolator;

beforeEach(function () {
    $this->evaluator = new ConditionEvaluator(new Interpolator);
});

describe('equals comparison', function () {
    it('returns true for matching values', function () {
        expect($this->evaluator->evaluate('{{TYPE}} == production', ['TYPE' => 'production']))
            ->toBeTrue();
    });

    it('returns false for non-matching values', function () {
        expect($this->evaluator->evaluate('{{TYPE}} == production', ['TYPE' => 'development']))
            ->toBeFalse();
    });

    it('handles quoted strings', function () {
        expect($this->evaluator->evaluate('"{{TYPE}}" == "production"', ['TYPE' => 'production']))
            ->toBeTrue();
    });
});

describe('not-equals comparison', function () {
    it('returns true for different values', function () {
        expect($this->evaluator->evaluate('{{TYPE}} != production', ['TYPE' => 'development']))
            ->toBeTrue();
    });

    it('returns false for matching values', function () {
        expect($this->evaluator->evaluate('{{TYPE}} != production', ['TYPE' => 'production']))
            ->toBeFalse();
    });
});

describe('truthy/falsy', function () {
    it('handles boolean true', function () {
        expect($this->evaluator->evaluate('true', []))
            ->toBeTrue();
    });

    it('handles boolean false', function () {
        expect($this->evaluator->evaluate('false', []))
            ->toBeFalse();
    });

    it('handles numeric 1 as true', function () {
        expect($this->evaluator->evaluate('1', []))
            ->toBeTrue();
    });

    it('handles numeric 0 as false', function () {
        expect($this->evaluator->evaluate('0', []))
            ->toBeFalse();
    });

    it('handles empty string as false', function () {
        expect($this->evaluator->evaluate('', []))
            ->toBeFalse();
    });

    it('handles variable reference in boolean context', function () {
        expect($this->evaluator->evaluate('ENABLED', ['ENABLED' => true]))
            ->toBeTrue();
    });

    it('handles interpolated variable as boolean true', function () {
        expect($this->evaluator->evaluate('{{ENABLED}}', ['ENABLED' => true]))
            ->toBeTrue();
    });

    it('handles interpolated variable as boolean false', function () {
        expect($this->evaluator->evaluate('{{ENABLED}}', ['ENABLED' => false]))
            ->toBeFalse();
    });

    it('handles missing variable reference as false', function () {
        expect($this->evaluator->evaluate('MISSING', []))
            ->toBeFalse();
    });
});
