<?php

declare(strict_types=1);

namespace App\Support\Permits;

use App\Models\Permit;
use App\Support\Units\LicenseViolation;

/**
 * A permit number belongs to one listing, or to one building — never to both,
 * and never to two of either.
 *
 * The rule cannot be a UNIQUE index, and the reason is the whole difficulty: in
 * single-permit mode the SAME number legitimately sits on one row per door, so
 * a building of eight apartments holds eight copies of it on `units` and one
 * row in `permits`. What must be unique is therefore not "the number" but "the
 * SCOPE the number is claimed by": one `permits` row per number, whatever that
 * row covers. That is expressible as a database constraint only on the permits
 * table — and only once the historical duplicates are resolved.
 *
 * Production has one such duplicate today: `50047139` is on unit #35 (published)
 * and unit #37 (hidden), because the same private permit was attached to two
 * listings before anyone checked. Until the owner decides which one survives,
 * that number is an exception rather than a violation — a recorded fact, not a
 * silent skip. Everything else is refused at the writer, under a lock, with the
 * daily check as the second net for anything written around it.
 */
final class PermitUniqueness
{
    /**
     * Numbers that already sit on more than one scope and are allowed to stay
     * that way until a human resolves them. Configured, not hard-coded, so
     * resolving one is an env change rather than a deploy.
     *
     * @return list<string>
     */
    public static function exceptions(): array
    {
        return array_values(array_filter(array_map(
            fn ($n) => PermitNumber::normalize((string) $n),
            (array) config('permits.uniqueness_exceptions', []),
        )));
    }

    public static function isException(?string $number): bool
    {
        $normalised = PermitNumber::normalize($number);

        return $normalised !== null && in_array($normalised, self::exceptions(), true);
    }

    /**
     * Refuse a number already claimed by a different scope.
     *
     * `$ignorePermitId` is the row being edited — a permit keeping its own
     * number must not collide with itself, and a renewal carrying the number
     * forward must not collide with the permit it will replace.
     *
     * Call this INSIDE the transaction that writes, after taking the lock:
     * two concurrent requests both reading "free" and both writing is exactly
     * the race a check outside the lock cannot see.
     */
    public static function guard(
        ?string $number,
        string $scopeType,
        string $scopeId,
        ?int $ignorePermitId = null,
    ): void {
        $normalised = PermitNumber::normalize($number);

        if ($normalised === null || self::isException($normalised)) {
            return;
        }

        $clash = Permit::query()
            ->where('number', $normalised)
            ->whereIn('status', [Permit::STATUS_CURRENT, Permit::STATUS_PENDING])
            ->when($ignorePermitId !== null, fn ($q) => $q->whereKeyNot($ignorePermitId))
            ->where(fn ($q) => $q->where('scope_type', '!=', $scopeType)->orWhere('scope_id', '!=', $scopeId))
            ->first();

        if ($clash === null) {
            return;
        }

        throw LicenseViolation::of(
            'DUPLICATE_PERMIT_NUMBER',
            'رقم التصريح مستخدم بالفعل في وحدة أخرى',
            [
                'permit_number' => $normalised,
                // Which listing holds it, so support can look without a query.
                'claimed_by_unit_id' => $clash->units()->value('id'),
            ],
        );
    }

    /**
     * Numbers claimed by more than one scope right now — what the daily check
     * reports, and what a database constraint could eventually be built on.
     *
     * Exceptions are excluded: they are known, recorded, and waiting on a
     * person, so reporting them every morning would train the reader to skip
     * the whole list.
     *
     * @return array<int, object{number: string, scopes: int}>
     */
    public static function duplicates(): array
    {
        $rows = Permit::query()
            ->selectRaw('number')
            ->selectRaw('COUNT(DISTINCT CONCAT(scope_type, ":", scope_id)) AS scopes')
            ->whereNotNull('number')
            ->whereIn('status', [Permit::STATUS_CURRENT, Permit::STATUS_PENDING])
            ->groupBy('number')
            ->havingRaw('COUNT(DISTINCT CONCAT(scope_type, ":", scope_id)) > 1')
            ->get()
            ->all();

        return array_values(array_filter($rows, fn ($r) => ! self::isException($r->number)));
    }
}
