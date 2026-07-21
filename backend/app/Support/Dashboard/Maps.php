<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

/**
 * Value maps between the partner-dashboard contract (English slugs) and the
 * platform's stored Arabic values, plus Saudi geo validation. The DB keeps
 * Arabic (what the public site renders); the dashboard speaks slugs.
 */
class Maps
{
    /** Saudi Arabia bounding box (contract §10.4 — reject coordinates outside). */
    public const LAT_MIN = 16.0;
    public const LAT_MAX = 32.5;
    public const LNG_MIN = 34.0;
    public const LNG_MAX = 56.0;

    /** Agreed city enum → stored Arabic name. */
    public const CITIES = [
        'riyadh'   => 'الرياض',
        'jeddah'   => 'جدة',
        'makkah'   => 'مكة المكرمة',
        'madinah'  => 'المدينة المنورة',
        'dammam'   => 'الدمام',
        'khobar'   => 'الخبر',
        'dhahran'  => 'الظهران',
        'taif'     => 'الطائف',
        'abha'     => 'أبها',
        'khamis_mushait' => 'خميس مشيط',
        'tabuk'    => 'تبوك',
        'buraydah' => 'بريدة',
        'hail'     => 'حائل',
        'jubail'   => 'الجبيل',
        'yanbu'    => 'ينبع',
        'najran'   => 'نجران',
        'jazan'    => 'جازان',
        'alula'    => 'العلا',
        'baha'     => 'الباحة',
        'hofuf'    => 'الهفوف',
    ];

    /** Contract amenity keys → stored Arabic feature names (public site renders these). */
    public const AMENITIES = [
        'wifi'            => 'واي فاي',
        'ac'              => 'تكييف',
        'kitchen'         => 'مطبخ',
        'parking'         => 'موقف سيارات',
        'pool'            => 'مسبح',
        'security'        => 'حراسة أمنية',
        'self_checkin'    => 'تسجيل دخول ذاتي',
        'family_friendly' => 'مناسب للعائلات',
        // Extended vocabulary (frontend field-gap task 2026-07-21) so the
        // storefront can pick a stable icon per amenity instead of matching
        // Arabic text.
        'smart_tv'        => 'شاشة ذكية',
        'garden'          => 'حديقة',
        'bbq'             => 'شواء',
        'elevator'        => 'مصعد',
        'washer'          => 'غسالة',
        'private_beach'   => 'شاطئ خاص',
        'event_hall'      => 'قاعة مناسبات',
    ];

    /**
     * Spelling variants → canonical Arabic label in AMENITIES, so a unit
     * tagged "مكيف" still resolves to the `ac` slug. Add aliases here rather
     * than duplicating slugs.
     */
    public const AMENITY_VARIANTS = [
        'مكيف'        => 'تكييف',
        'واي-فاي'     => 'واي فاي',
        'حراسة'       => 'حراسة أمنية',
        'شاشة'        => 'شاشة ذكية',
        'تلفزيون ذكي' => 'شاشة ذكية',
    ];

    public static function cityToArabic(string $slug): ?string
    {
        return self::CITIES[$slug] ?? null;
    }

    public static function cityToSlug(?string $arabic): ?string
    {
        if ($arabic === null) {
            return null;
        }

        $slug = array_search(trim($arabic), self::CITIES, true);

        return $slug === false ? $arabic : $slug; // unmapped legacy value passes through raw
    }

    public static function amenityToArabic(string $key): ?string
    {
        return self::AMENITIES[$key] ?? null;
    }

    /**
     * Every stored Arabic label that maps to a slug — the canonical AMENITIES
     * label plus each spelling variant. Used to filter units by slug while
     * still matching the messy stored spellings (تكييف / مكيف …).
     *
     * @return list<string>
     */
    public static function labelsForSlug(string $slug): array
    {
        $canonical = self::AMENITIES[$slug] ?? null;
        if ($canonical === null) {
            return [];
        }

        $labels = [$canonical];
        foreach (self::AMENITY_VARIANTS as $variant => $canon) {
            if ($canon === $canonical) {
                $labels[] = $variant;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Resolve a units filter value (slug OR raw label) to the set of stored
     * labels to match. A known slug expands to all its spellings; anything
     * else falls back to the raw value (backward-compatible label filtering).
     *
     * @return list<string>
     */
    public static function filterLabels(string $value): array
    {
        $value = trim($value);
        $slug  = isset(self::AMENITIES[$value]) ? $value : self::amenityKey($value);

        return $slug !== null ? self::labelsForSlug($slug) : [$value];
    }

    /** Resolve one Arabic amenity name to its stable slug (variants included). */
    public static function amenityKey(string $arabic): ?string
    {
        $name    = trim($arabic);
        $reverse = array_flip(self::AMENITIES);

        if (isset($reverse[$name])) {
            return $reverse[$name];
        }

        $canonical = self::AMENITY_VARIANTS[$name] ?? null;

        return $canonical !== null ? ($reverse[$canonical] ?? null) : null;
    }

    /**
     * Map amenity names to the public `{ key, label }` shape. `key` is the
     * stable slug (null for anything outside the vocabulary → generic icon);
     * `label` is the display text as stored.
     *
     * @param  iterable<int, string>  $arabicNames
     * @return list<array{key: ?string, label: string}>
     */
    public static function amenityPairs(iterable $arabicNames): array
    {
        $pairs = [];
        foreach ($arabicNames as $name) {
            $pairs[] = ['key' => self::amenityKey($name), 'label' => trim((string) $name)];
        }

        return $pairs;
    }

    /** @param  iterable<int, string>  $arabicNames  @return list<string> */
    public static function amenitiesToKeys(iterable $arabicNames): array
    {
        $reverse = array_flip(self::AMENITIES);

        $keys = [];
        foreach ($arabicNames as $name) {
            if (isset($reverse[trim($name)])) {
                $keys[] = $reverse[trim($name)];
            }
        }

        return $keys;
    }

    public static function insideSaudi(float $lat, float $lng): bool
    {
        return $lat >= self::LAT_MIN && $lat <= self::LAT_MAX
            && $lng >= self::LNG_MIN && $lng <= self::LNG_MAX;
    }
}
