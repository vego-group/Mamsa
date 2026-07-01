<?php

namespace App\Support;

/**
 * Media helpers. Central place to resolve the bundled default/placeholder image
 * so every resource, seeder and fallback points at the same asset.
 */
class Media
{
    /** Hard fallback so a missing/empty config value can never yield an empty path. */
    public const DEFAULT_IMAGE_PATH = 'defaults/unit-default.avif';

    /** Relative path of the default image on the public storage disk. */
    public static function defaultImagePath(): string
    {
        $path = trim((string) config('app.default_image_path'));

        return $path !== '' ? $path : self::DEFAULT_IMAGE_PATH;
    }

    /** Absolute, servable URL of the default image (respects APP_URL). */
    public static function defaultImageUrl(): string
    {
        return asset('storage/' . self::defaultImagePath());
    }
}
