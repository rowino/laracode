<?php

declare(strict_types=1);

namespace App\Services\Settings;

class SettingsPath
{
    public static function user(): string
    {
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            return '';
        }

        return $home.'/.laracode/settings.json';
    }

    public static function project(?string $basePath = null): string
    {
        $basePath ??= self::cwd();

        return $basePath.'/.laracode/settings.json';
    }

    public static function local(?string $basePath = null): string
    {
        $basePath ??= self::cwd();

        return $basePath.'/.laracode/settings.local.json';
    }

    private static function cwd(): string
    {
        $cwd = getcwd();

        return $cwd !== false ? $cwd : '.';
    }
}
