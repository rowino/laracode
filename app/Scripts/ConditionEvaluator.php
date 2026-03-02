<?php

declare(strict_types=1);

namespace App\Scripts;

/**
 * Usage: $evaluator->evaluate('{{TYPE}} == production', ['TYPE' => 'production']) => true
 */
class ConditionEvaluator
{
    public function __construct(
        private readonly Interpolator $interpolator,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     */
    public function evaluate(string $condition, array $variables): bool
    {
        $condition = trim($condition);

        $interpolated = $this->interpolator->interpolate($condition, $variables);

        if (preg_match('/^(.+?)\s*(==|!=)\s*(.+)$/', $interpolated, $matches)) {
            $left = trim($matches[1], " \t\n\r\0\x0B\"'");
            $operator = $matches[2];
            $right = trim($matches[3], " \t\n\r\0\x0B\"'");

            if ($operator === '==') {
                return $left === $right;
            }

            return $left !== $right;
        }

        $interpolated = trim($interpolated, " \t\n\r\0\x0B\"'");

        if ($interpolated === 'true' || $interpolated === '1') {
            return true;
        }

        if ($interpolated === 'false' || $interpolated === '0' || $interpolated === '') {
            return false;
        }

        return (bool) ($variables[$interpolated] ?? false);
    }
}
