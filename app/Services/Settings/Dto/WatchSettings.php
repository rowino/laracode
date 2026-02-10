<?php

declare(strict_types=1);

namespace App\Services\Settings\Dto;

readonly class WatchSettings
{
    /**
     * @param  array<string>  $paths
     * @param  array<string>  $excludePatterns
     */
    public function __construct(
        public array $paths = [],
        public string $searchWord = '',
        public string $stopWord = '',
        public string $mode = 'interactive',
        public array $excludePatterns = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            paths: isset($data['paths']) && is_array($data['paths'])
                ? array_values(array_filter($data['paths'], 'is_string'))
                : [],
            searchWord: isset($data['searchWord']) && is_string($data['searchWord'])
                ? $data['searchWord']
                : '',
            stopWord: isset($data['stopWord']) && is_string($data['stopWord'])
                ? $data['stopWord']
                : '',
            mode: isset($data['mode']) && is_string($data['mode'])
                ? $data['mode']
                : 'interactive',
            excludePatterns: isset($data['excludePatterns']) && is_array($data['excludePatterns'])
                ? array_values(array_filter($data['excludePatterns'], 'is_string'))
                : [],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function withOverrides(array $overrides): self
    {
        return new self(
            paths: isset($overrides['paths']) && is_array($overrides['paths']) && ! empty($overrides['paths'])
                ? $overrides['paths']
                : $this->paths,
            searchWord: isset($overrides['searchWord']) && is_string($overrides['searchWord'])
                ? $overrides['searchWord']
                : $this->searchWord,
            stopWord: isset($overrides['stopWord']) && is_string($overrides['stopWord'])
                ? $overrides['stopWord']
                : $this->stopWord,
            mode: isset($overrides['mode']) && is_string($overrides['mode'])
                ? $overrides['mode']
                : $this->mode,
            excludePatterns: array_values(array_unique(array_merge(
                $this->excludePatterns,
                isset($overrides['excludePatterns']) && is_array($overrides['excludePatterns'])
                    ? $overrides['excludePatterns']
                    : []
            ))),
        );
    }

    public function isConfigured(): bool
    {
        return $this->searchWord !== '' && $this->stopWord !== '';
    }
}
