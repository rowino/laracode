<?php

declare(strict_types=1);

namespace App\Scripts;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;

/**
 * Usage: $runner = new PromptRunner(new Interpolator); $responses = $runner->runPrompts($prompts, $variables);
 */
class PromptRunner
{
    private bool $autoMode = false;

    public function __construct(
        private readonly Interpolator $interpolator,
    ) {}

    public function setAutoMode(bool $auto): self
    {
        $this->autoMode = $auto;

        return $this;
    }

    /**
     * @param  list<array{id: string, type: string, label: string, default?: mixed, options?: list<string|array{label: string, value: string}>, required?: bool, promptEveryRun?: bool}>  $prompts
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function runPrompts(array $prompts, array $variables): array
    {
        $responses = [];

        foreach ($prompts as $prompt) {
            $id = $prompt['id'];
            $type = $prompt['type'];
            $label = $this->interpolator->interpolate($prompt['label'], $variables);
            $default = $prompt['default'] ?? null;
            $options = $prompt['options'] ?? [];
            $required = $prompt['required'] ?? true;

            if ($this->autoMode && $default !== null) {
                $responses[$id] = is_string($default) ? trim($default) : $default;
                $variables[$id] = $responses[$id];

                continue;
            }

            $defaultString = is_string($default) ? $default : '';
            /** @var list<string> $defaultArray */
            $defaultArray = is_array($default) ? array_values(array_filter($default, 'is_string')) : [];

            $value = match ($type) {
                'text' => $this->promptText($label, $defaultString, $required),
                'confirm' => $this->promptConfirm($label, (bool) ($default ?? false)),
                'select' => $this->promptSelect($label, $options, $defaultString),
                'multiselect' => $this->promptMultiselect($label, $options, $defaultArray),
                'suggest' => $this->promptSuggest($label, $options, $defaultString),
                default => $default,
            };

            if (is_string($value)) {
                $value = trim($value);
            }

            $responses[$id] = $value;
            $variables[$id] = $value;
        }

        return $responses;
    }

    protected function promptText(string $label, string $default, bool $required): string
    {
        return text(
            label: $label,
            default: $default,
            required: $required,
        );
    }

    protected function promptConfirm(string $label, bool $default): bool
    {
        return confirm(
            label: $label,
            default: $default,
        );
    }

    /**
     * @param  list<string|array{label: string, value: string}>  $options
     */
    protected function promptSelect(string $label, array $options, string $default): string
    {
        if (empty($options)) {
            return $default;
        }

        $normalizedOptions = $this->normalizeOptions($options);
        $optionValues = array_keys($normalizedOptions);
        $defaultValue = $default !== '' && isset($normalizedOptions[$default]) ? $default : $optionValues[0];

        return (string) select(
            label: $label,
            options: $normalizedOptions,
            default: $defaultValue,
        );
    }

    /**
     * @param  list<string|array{label: string, value: string}>  $options
     * @param  list<string>  $default
     * @return list<string>
     */
    protected function promptMultiselect(string $label, array $options, array $default): array
    {
        if (empty($options)) {
            return $default;
        }

        $normalizedOptions = $this->normalizeOptions($options);
        $validDefault = array_values(array_filter($default, fn ($val) => isset($normalizedOptions[$val])));

        $result = multiselect(
            label: $label,
            options: $normalizedOptions,
            default: $validDefault,
        );

        return array_values(array_map('strval', $result));
    }

    /**
     * @param  list<string|array{label: string, value: string}>  $options
     */
    protected function promptSuggest(string $label, array $options, string $default): string
    {
        $flatOptions = [];
        foreach ($options as $option) {
            if (is_array($option)) {
                $flatOptions[] = $option['value'];
            } else {
                $flatOptions[] = $option;
            }
        }

        return suggest(
            label: $label,
            options: $flatOptions,
            default: $default,
        );
    }

    /**
     * @param  list<string|array{label: string, value: string}>  $options
     * @return array<string, string>
     */
    public function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $option) {
            if (is_array($option)) {
                $normalized[$option['value']] = $option['label'];
            } else {
                $normalized[$option] = $option;
            }
        }

        return $normalized;
    }
}
