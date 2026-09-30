<?php

declare(strict_types=1);

namespace App\Support\Permits;

use Carbon\Carbon;

/**
 * What happens to the Hijri text when a permit is written.
 *
 * The Hijri text is the source the Gregorian date was converted from, so the
 * two must never disagree. One rule, used by both write paths ({@see
 * PermitWriter::apply()} and {@see PermitRenewal::open()}), because each of
 * them merges a request over an existing row and a plain merge would carry the
 * OLD Hijri text onto a NEW date — silently, and in exactly the rows it exists
 * to repair.
 *
 *   - Hijri sent             → stored exactly as sent (null included).
 *   - Date changed, no Hijri → null: the new date has no Hijri source.
 *   - Date unchanged         → kept, so a form that re-sends every field does
 *                              not wipe it.
 *   - No date at all         → null: a source for nothing is meaningless.
 */
final class PermitHijri
{
    /**
     * @param  array<string, mixed>  $before  the row being written over (may be empty)
     * @param  array<string, mixed>  $changes  what this request sets
     */
    public static function resolve(array $before, array $changes, mixed $expiresAt): ?string
    {
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }

        if (array_key_exists('expires_at_hijri', $changes)) {
            $sent = $changes['expires_at_hijri'];

            return $sent === null ? null : (string) $sent;
        }

        $old = $before['expires_at'] ?? null;

        if (array_key_exists('expires_at', $changes) && self::day($old) !== self::day($changes['expires_at'])) {
            return null;
        }

        $kept = $before['expires_at_hijri'] ?? null;

        return $kept === null ? null : (string) $kept;
    }

    private static function day(mixed $date): ?string
    {
        return $date === null || $date === '' ? null : Carbon::parse($date)->toDateString();
    }
}
