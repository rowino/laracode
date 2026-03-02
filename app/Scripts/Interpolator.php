<?php

declare(strict_types=1);

namespace App\Scripts;

/**
 * Usage: $interpolator->interpolate('Hello {{NAME|upper}}!', ['NAME' => 'world']) => 'Hello WORLD!'
 */
class Interpolator
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function interpolate(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{(\w+)(?:\|(\w+))?\}\}/',
            function (array $matches) use ($variables): string {
                $key = $matches[1];
                $filter = $matches[2] ?? null;

                if (! array_key_exists($key, $variables)) {
                    return $matches[0];
                }

                $rawValue = $variables[$key];
                $value = is_scalar($rawValue) ? (string) $rawValue : '';

                if ($filter !== null) {
                    $value = $this->applyFilter($value, $filter);
                }

                return $value;
            },
            $template
        ) ?? $template;
    }

    public function applyFilter(string $value, string $filter): string
    {
        return match ($filter) {
            'snake' => $this->toSnakeCase($value),
            'slug' => $this->toSlug($value),
            'upper' => strtoupper($value),
            'lower' => strtolower($value),
            default => $value,
        };
    }

    public function toSnakeCase(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?? $value;
        $value = preg_replace('/([a-z])([A-Z])/', '$1_$2', $value) ?? $value;
        $value = strtolower($value);

        return trim($value, '_');
    }

    public function toSlug(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? $value;
        $value = strtolower($value);

        return trim($value, '-');
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function interpolateForShell(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{(\w+)(?:\|(\w+))?\}\}/',
            function (array $matches) use ($variables): string {
                $key = $matches[1];
                $filter = $matches[2] ?? null;

                if (! array_key_exists($key, $variables)) {
                    return $matches[0];
                }

                $rawValue = $variables[$key];
                $value = is_scalar($rawValue) ? (string) $rawValue : '';

                if ($filter !== null) {
                    if ($filter === 'raw') {
                        return $value;
                    }
                    $value = $this->applyFilter($value, $filter);
                }

                return escapeshellarg($value);
            },
            $template
        ) ?? $template;
    }
}
