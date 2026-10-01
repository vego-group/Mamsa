<?php

declare(strict_types=1);

namespace App\Support\Units;

use App\Models\Unit;
use Collator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * A door's name inside a building — `apartment_no` — and the ONE order doors
 * are listed in. Decided with the owner 2026-10-01; the frontend implements
 * the same order on its side, so every screen shows a building the same way.
 *
 * A door number is a name ("402", "7", "الدور الثالث"), not an index — but a
 * name made only of digits is still compared as a number.
 */
final class DoorName
{
    /** Arabic-Indic and Extended Arabic-Indic (Persian) digits → ASCII. */
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * How a door name is stored and compared: trimmed, with Arabic and Persian
     * digits written as ASCII. "٧" and "7" are the same door — on a screen they
     * look identical, so letting both exist would give a building two doors
     * nobody can tell apart. Letters are untouched: "الدور الثالث" stays as typed.
     *
     * Same digit mapping as PermitNumber::normalize(); unlike it, inner spaces
     * and case are kept, because a door name is shown to people as written.
     */
    public static function normalize(?string $name): string
    {
        return trim(strtr((string) $name, self::DIGITS));
    }

    /**
     * Doors in the one agreed order:
     *
     *   1. no name (null or empty) first
     *   2. names made only of digits, ascending by their VALUE — "2" before "10"
     *   3. every other name, by Arabic collation (localeCompare('ar') on the client)
     *   4. a tie on all of the above — "01" and "1", or identical names — by id
     *
     * Replaces orderBy('apartment_no'), which sorted as TEXT: "10" before "2".
     *
     * @param  Collection<int, Unit>  $doors
     * @return Collection<int, Unit>
     */
    public static function order(Collection $doors): Collection
    {
        $collator = self::collator();

        return $doors->sort(function (Unit $a, Unit $b) use ($collator): int {
            $x = self::normalize($a->apartment_no);
            $y = self::normalize($b->apartment_no);

            $rank = fn (string $n): int => $n === '' ? 0 : (ctype_digit($n) ? 1 : 2);

            $byRank = $rank($x) <=> $rank($y);
            if ($byRank !== 0) {
                return $byRank;
            }

            $byName = match ($rank($x)) {
                0 => 0,
                1 => self::compareNumeric($x, $y),
                default => $collator->compare($x, $y) ?: 0,
            };

            return $byName !== 0 ? $byName : ($a->id <=> $b->id);
        })->values();
    }

    /**
     * Arabic collation — which puts Arabic-script names before Latin, exactly
     * like the browser's localeCompare('ar').
     *
     * ICU without Arabic locale data does not fail: it silently falls back to
     * `root`, which puts LATIN first, and the backend and the frontend would
     * list one building in two orders again. Verified 2026-10-01: staging and
     * production load `ar` (ICU 64.2); the local Docker image falls back to
     * `root`. So the fallback is logged, once per process, instead of passing
     * unseen.
     */
    private static function collator(): Collator
    {
        static $collator = null;

        if ($collator === null) {
            $collator = new Collator('ar');

            if ($collator->getLocale(\Locale::ACTUAL_LOCALE) !== 'ar') {
                Log::warning('DoorName: ICU has no Arabic collation; door names sort with root rules (Latin before Arabic), unlike the frontend', [
                    'actual_locale' => $collator->getLocale(\Locale::ACTUAL_LOCALE),
                    'icu' => \defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : null,
                ]);
            }
        }

        return $collator;
    }

    /** True when names sort exactly as the frontend does (Arabic locale data present). */
    public static function hasArabicCollation(): bool
    {
        return self::collator()->getLocale(\Locale::ACTUAL_LOCALE) === 'ar';
    }

    /**
     * Digit strings compared by value without converting to int — a door name
     * may be up to 20 digits, past PHP_INT_MAX.
     */
    private static function compareNumeric(string $x, string $y): int
    {
        $x = ltrim($x, '0') ?: '0';
        $y = ltrim($y, '0') ?: '0';

        return strlen($x) <=> strlen($y) ?: strcmp($x, $y) <=> 0;
    }
}
