<?php

namespace App\Enums;

enum BuildMode: string
{
    case Interactive = 'interactive';
    case Plan = 'plan';
    case Yolo = 'yolo';
    case Accept = 'accept';

    public function description(): string
    {
        return match ($this) {
            self::Interactive => 'Interactive - Asks before making changes',
            self::Plan => 'Plan - Creates plan first, then executes',
            self::Yolo => 'Yolo - Executes without confirmation',
            self::Accept => 'Accept - Auto-accepts all prompts',
        };
    }

    public function modeDescription(): string
    {
        return match ($this) {
            self::Interactive => 'The agent will prompt you for confirmation before making any changes to your codebase. Best for careful, supervised development.',
            self::Plan => 'The agent creates a detailed implementation plan first, then executes it step by step. Good for complex features.',
            self::Yolo => 'The agent executes tasks immediately without asking for confirmation. Use for trusted, well-defined tasks.',
            self::Accept => 'The agent automatically accepts all prompts and suggestions. Maximum automation with minimal interaction.',
        };
    }
}
