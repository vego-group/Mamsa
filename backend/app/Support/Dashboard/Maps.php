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
