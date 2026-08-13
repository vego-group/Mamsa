<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel\Concerns;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Maps the app's internal values to the exact spec enum strings / codes / money
 * / dates (BACKEND_SPEC §4, §7). Kept in one place so list rows and detail
 * payloads stay identical.
 */
trait MapsSpec
{
    /** Human-readable entity code, e.g. USR-0007, PTR-001, BKG-0231. */
    protected function code(string $prefix, int|string $id, int $pad = 3): string
    {
        return sprintf('%s-%0'.$pad.'d', $prefix, (int) $id);
    }

    protected function iso(mixed $date): ?string
    {
        return $date ? $date->toIso8601String() : null;
    }

    protected function money(mixed $v): float
    {
        return round((float) $v, 2);
    }

    /** Commission is a frozen 2% of total; partner keeps the rest (§7). A stored
     *  0/null means it was never frozen (historical booking) → compute 2%. */
    protected function commissionOf(float $total, ?float $stored = null): float
    {
        return $this->money(($stored !== null && $stored > 0) ? $stored : round($total * 0.02, 2));
    }

    /**
     * SUM of effective commission over a bookings query (frozen amount where
     * captured, else 2% of subtotal) — see Booking::commissionExpr(). The query
     * must be over the `bookings` table (no alias).
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     */
    protected function commissionSum($query): float
    {
        return (float) $query->sum(DB::raw(Booking::commissionExpr()));
    }

    /** Percentage change vs a previous value (§5.3 deltas); negative allowed. */
    protected function pctDelta(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /* ---------- enums ---------- */

    // bookingStatus() shim REMOVED 2026-08-13: bookings.status now stores the
    // spec literals natively (pending_payment|confirmed|completed|cancelled),
    // so no translation is needed — read $booking->status directly.

    protected function paymentStatus(?string $s): string
    {
        return match ($s) {
            'paid', 'captured', 'succeeded' => 'paid',
            'refunded'                      => 'refunded',
            'failed'                        => 'failed',
            default                         => 'pending',
        };
    }

    /** UnitStatus from the internal approval_status (§4: approved == published). */
    protected function unitStatus(?string $approval): string
    {
        return match ($approval) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'draft'    => 'draft',
            default    => 'pending_review', // internal 'pending'
        };
    }

    protected function unitType(?string $t): string
    {
        $allowed = ['apartment', 'villa', 'chalet', 'studio', 'hotel_room'];

        return match ($t) {
            'hotel'  => 'hotel_room',
            null     => 'apartment',
            default  => in_array($t, $allowed, true) ? $t : 'apartment',
        };
    }

    /** AccountStatus (§4). An invited-but-not-activated user is pending_activation. */
    protected function accountStatus(User $u): string
    {
        if ($u->is_active) {
            return 'active';
        }

        return $u->invited_at !== null ? 'pending_activation' : 'disabled';
    }

    /** PartnerStatus (§4): verified badge is independent of this. */
    protected function partnerStatus(User $u, ?PartnerDetail $d): string
    {
        return match (true) {
            $d?->status === PartnerDetail::STATUS_PENDING  => 'pending',
            $d?->status === PartnerDetail::STATUS_REJECTED => 'rejected',
            ! $u->is_active                                => 'suspended',
            default                                        => 'active',
        };
    }

    protected function refundStatus(float $refunded, float $total): string
    {
        if ($refunded <= 0) {
            return 'none';
        }

        return $refunded + 0.01 >= $total ? 'refunded' : 'partial';
    }

    /* ---------- driver-aware SQL (works on MySQL prod + sqlite tests) ---------- */

    /** SUM of nights between two date columns. */
    protected function nightsSql(string $end = 'end_date', string $start = 'start_date'): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "COALESCE(SUM(julianday({$end}) - julianday({$start})), 0)"
            : "COALESCE(SUM(DATEDIFF({$end}, {$start})), 0)";
    }

    /** 'YYYY-MM' bucket for a datetime column. */
    protected function ymSql(string $col): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$col})"
            : "DATE_FORMAT({$col}, '%Y-%m')";
    }

    /** AVG hours between two datetime columns. */
    protected function avgHoursSql(string $start, string $end): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "AVG((julianday({$end}) - julianday({$start})) * 24)"
            : "AVG(TIMESTAMPDIFF(HOUR, {$start}, {$end}))";
    }
}
