<?php

declare(strict_types=1);

namespace App\Tui\Terminal;

readonly class FocusResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}

    public static function success(): self
    {
        return new self(true, 'Focused terminal pane');
    }

    public static function notFound(): self
    {
        return new self(false, 'Could not find terminal pane for this session');
    }

    public static function unsupported(): self
    {
        return new self(false, 'No supported terminal multiplexer detected');
    }

    public static function error(string $message): self
    {
        return new self(false, $message);
    }
}
