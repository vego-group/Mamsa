<?php

declare(strict_types=1);

namespace App\Support\Permits;

use App\Models\Permit;
use App\Models\Unit;
use App\Support\Sql;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A permit runs out, and the listing stops selling — before the date, not after.
 *
 * The old shape of this problem was a reviewer reading an expiry date once, on
 * the day they approved the listing, and nothing looking again. The listing
 * kept selling stays that began after its permit had lapsed, and the first
 * anyone knew was a guest standing at a door.
 *
 * So the date is a CAP on the calendar, not an alarm: a booking may not check
 * out after the permit expires, search does not offer a stay it could not
 * legally host, the calendar returns the days past the date as closed, and a
 * listing whose permit has already lapsed is simply not on the storefront.
 *
 * Two decisions worth stating, because they read as surprises otherwise:
 *
 *  - Expiry is COMPUTED, never written. No status column flips at midnight, no
 *    job has to run for the rule to hold, and `approval_status` never changes:
 *    a lapsed permit is not a rejected listing, and renewing brings the listing
 *    straight back with nothing to undo.
 *  - `NULL` means no cap at all. Every permit that predates the column has no
 *    date, and inventing one would take real listings off the market on a
 *    guess. They stay sellable until somebody types the date off the document.
 */
final class PermitExpiry
{
    public const CODE = 'BOOKING_EXCEEDS_PERMIT_VALIDITY';

    /** The day this listing's permit runs out, or null when nothing caps it. */
    public static function on(Unit $unit): ?Carbon
    {
        $permit = Permit::currentFor($unit);

        return $permit?->expires_at ? Carbon::parse($permit->expires_at)->startOfDay() : null;
    }

    /**
     * May a stay ending on `$checkout` be sold by this listing?
     *
     * The check-out day itself is allowed: a permit valid through the 30th
     * covers a guest who leaves on the 30th. Their last NIGHT is the 29th, so
     * nothing is being sold beyond the date.
     */
    public static function coversStay(Unit $unit, string|CarbonInterface $checkout): bool
    {
        $expiry = self::on($unit);

        return $expiry === null || Carbon::parse($checkout)->startOfDay()->lessThanOrEqualTo($expiry);
    }

    /**
     * Can ANY apartment of this listing host a stay ending on `$checkout`?
     *
     * The id a guest sends is whichever apartment the collapsed card happened
     * to show, and in a building each door may carry its own permit. Asking
     * only about that one row would refuse a whole building because the
     * representative's permit lapsed, while its neighbours were free and
     * licensed. The allocator decides WHICH door; this decides whether to look.
     */
    public static function anyCovers(Unit $unit, string|CarbonInterface $checkout): bool
    {
        if (! $unit->unit_group_id) {
            return self::coversStay($unit, $checkout);
        }

        return Unit::where('unit_group_id', $unit->unit_group_id)
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->get()
            ->contains(fn (Unit $member) => self::coversStay($member, $checkout));
    }

    /** Has this listing's permit already run out? */
    public static function lapsed(Unit $unit): bool
    {
        $expiry = self::on($unit);

        return $expiry !== null && $expiry->lessThan(now()->startOfDay());
    }

    /**
     * What the console shows beside the date, so a reviewer does not have to
     * work it out: valid · expiring (inside the warning window) · expired ·
     * unknown (no date recorded).
     */
    public static function status(Unit $unit): string
    {
        $expiry = self::on($unit);

        if ($expiry === null) {
            return 'unknown';
        }

        if ($expiry->lessThan(now()->startOfDay())) {
            return 'expired';
        }

        return $expiry->lessThanOrEqualTo(now()->startOfDay()->addDays(self::warningDays()))
            ? 'expiring'
            : 'valid';
    }

    /** Days before expiry that count as "expiring" on the consoles. */
    public static function warningDays(): int
    {
        return (int) config('permits.warning_days', 30);
    }

    /* ---------- query side ---------- */

    /**
     * Restrict a unit query to listings whose permit still covers a stay ending
     * on `$checkout` (or, with no date, that have not already lapsed).
     *
     * The awkward part is that a unit may be covered by a permit scoped to
     * itself OR to its group, and its own wins when both exist ({@see
     * Permit::currentFor()}). A plain `whereDoesntHave` over both scopes would
     * exclude a unit whose GROUP permit has lapsed even when its own private
     * permit is perfectly valid — the group permit is not the one it trades
     * under. So the question is asked exactly as the reader asks it: the unit's
     * own permit decides when it has one, and the group's only when it does
     * not.
     *
     * @param  string|null  $checkout  the stay's last day; today when absent
     */
    public static function covering(Builder $units, ?string $checkout = null): void
    {
        $limit = $checkout ? Carbon::parse($checkout)->toDateString() : now()->startOfDay()->toDateString();

        // `scope_id` is text (it holds either a unit id or a group ULID) and
        // `units.id` is numeric — sqlite will not compare the two, so the cast
        // is what makes this the same query on both drivers. See Sql::asInt().
        $own = fn ($q) => $q->from('permits as p_own')
            ->whereRaw(Sql::asInt('p_own.scope_id').' = units.id')
            ->where('p_own.scope_type', Permit::SCOPE_UNIT)
            ->where('p_own.status', Permit::STATUS_CURRENT);

        $units->where(function (Builder $w) use ($limit, $own) {
            // Its own permit decides.
            $w->whereExists(fn ($q) => $own($q->select(DB::raw(1)))
                ->where(fn ($e) => $e->whereNull('p_own.expires_at')->orWhereDate('p_own.expires_at', '>=', $limit)));

            // Or it has none, and the group's does — or there is none at all,
            // which caps nothing.
            $w->orWhere(function (Builder $g) use ($limit, $own) {
                $g->whereNotExists(fn ($q) => $own($q->select(DB::raw(1))))
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('permits as p_grp')
                        ->whereColumn('p_grp.scope_id', 'units.unit_group_id')
                        ->where('p_grp.scope_type', Permit::SCOPE_GROUP)
                        ->where('p_grp.status', Permit::STATUS_CURRENT)
                        ->whereNotNull('p_grp.expires_at')
                        ->whereDate('p_grp.expires_at', '<', $limit));
            });
        });
    }

    /**
     * The closed range a lapsed or soon-lapsing permit puts on a calendar,
     * within the window asked for — or null when the permit covers all of it.
     *
     * Returned as a normal blocked range with a `reason`, so the picker in the
     * guest app closes those days with no change of its own.
     *
     * @return array{start: string, end: string, reason: string}|null
     */
    public static function blockedRange(Unit $unit, string $from, string $to): ?array
    {
        $expiry = self::on($unit);

        if ($expiry === null) {
            return null;
        }

        // The first night that may no longer be sold is the expiry day itself:
        // a stay starting then would check out after it.
        $firstClosed = $expiry->copy()->startOfDay();
        $windowStart = Carbon::parse($from)->startOfDay();
        $windowEnd = Carbon::parse($to)->startOfDay();

        if ($firstClosed->greaterThan($windowEnd)) {
            return null;
        }

        return [
            'start' => $firstClosed->lessThan($windowStart) ? $windowStart->toDateString() : $firstClosed->toDateString(),
            'end' => $windowEnd->toDateString(),
            'reason' => 'permit_expiry',
        ];
    }
}
