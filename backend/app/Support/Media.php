<?php

namespace App\Support;

/**
 * Media helpers. Central place to resolve the bundled default/placeholder image
 * so every resource, seeder and fallback points at the same asset.
 */
class Media
{
    /** Relative path of the default image on the public storage disk. */
    public static function defaultImagePath(): string
    {
        return (string) config('app.default_image_path');
    }

    /** Absolute, servable URL of the default image (respects APP_URL). */
    public static function defaultImageUrl(): string
    {
        return asset('storage/' . self::defaultImagePath());
    }
}
