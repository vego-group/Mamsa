<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Dashboard\Maps;

/**
 * Resolve whatever a client calls a city to the Arabic string actually stored
 * in `units.city`.
 *
 * That column is free text and holds values like `مكة المكرمة` — so a client
 * hardcoding Arabic from the outside is guessing at our data, and a spelling
 * variant fails as an EMPTY LIST rather than an error. The admin panel sends
 * English names (`Riyadh`, `Makkah`) as internal keys; the partner dashboard
 * sends slugs (`riyadh`). Both resolve here, against one canonical map.
 *
 * Deliberately wraps Maps::CITIES rather than defining a second list: two city
 * tables is how `مكة` and `مكة المكرمة` end up both being "right".
 */
final class City
{
    /**
     * Spellings a client might send that are not simply the slug with spaces.
     * English exonyms, and the definite article the stored names omit.
     */
    private const ALIASES = [
        'mecca'          => 'makkah',
        'makkah_al_mukarramah' => 'makkah',
        'medina'         => 'madinah',
        'al_madinah'     => 'madinah',
        'al_khobar'      => 'khobar',
        'al_jubail'      => 'jubail',
        'al_baha'        => 'baha',
        'al_ula'         => 'alula',
        'ula'            => 'alula',
        // The canonical slug is `hofuf`; Al-Ahsa is the governorate it sits in
        // and is what people often type. Pointing `hofuf` AT `ahsa` (which is
        // not a key) made a listed city resolve to null, so `?city=Hofuf`
        // filtered on the literal string and matched nothing, silently.
        'ahsa'           => 'hofuf',
        'al_ahsa'        => 'hofuf',
        'al_hasa'        => 'hofuf',
        'qassim'         => 'buraydah',
        'jizan'          => 'jazan',
        'khamis'         => 'khamis_mushait',
    ];

    /**
     * @return string|null the stored Arabic name, or null when nothing matches
     *                     (callers should then filter on the raw input, so an
     *                     unmapped legacy value still works)
     */
    public static function toArabic(string $input): ?string
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return null;
        }

        // Already the stored Arabic name.
        if (in_array($trimmed, Maps::CITIES, true)) {
            return $trimmed;
        }

        $slug = self::slugify($trimmed);
        $slug = self::ALIASES[$slug] ?? $slug;

        return Maps::CITIES[$slug] ?? null;
    }

    /**
     * Apply a city filter to a query column, accepting any spelling.
     *
     * Falls back to the raw value when unresolvable so an unmapped city still
     * filters rather than silently matching nothing.
     */
    public static function filter(mixed $query, string $column, string $input): void
    {
        $query->where($column, self::toArabic($input) ?? trim($input));
    }

    /** `Khamis Mushait` / `khamis-mushait` / `KHAMIS_MUSHAIT` → `khamis_mushait`. */
    private static function slugify(string $value): string
    {
        return trim(preg_replace('/_+/', '_', (string) preg_replace(
            '/[^a-z0-9]+/', '_', mb_strtolower($value),
        )) ?? '', '_');
    }

    /**
     * The full vocabulary, for `GET /admin/cities`.
     *
     * @return list<array{key: string, en: string, ar: string}>
     */
    public static function all(): array
    {
        return array_values(array_map(fn (string $slug, string $ar) => [
            'key' => $slug,
            'en'  => ucwords(str_replace('_', ' ', $slug)),
            'ar'  => $ar,
        ], array_keys(Maps::CITIES), Maps::CITIES));
    }
}
