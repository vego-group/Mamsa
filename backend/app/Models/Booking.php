<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    /** Unpaid booking awaiting payment (renamed from 'pending' 2026-08-13). */
    public const STATUS_PENDING   = 'pending_payment';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Bookings that represent realized revenue: a paid, upcoming stay
     * (confirmed) OR a paid, finished stay (completed). Reports/dashboards must
     * sum over BOTH — counting only `confirmed` drops every finished stay.
     */
    public const REVENUE_STATUSES = [self::STATUS_CONFIRMED, self::STATUS_COMPLETED];

    /**
     * The rate to IMPUTE for bookings that predate the frozen columns — not the
     * rate charged today.
     *
     * Those rows were taken when the commission was 2%, so reconstructing them
     * at the current rate would restate history: a report would claim Mamsa
     * earned five times what it actually invoiced, and a partner's past
     * earnings would shrink retroactively.
     *
     * The live rate lives in config('booking.commission_rate') and is read only
     * by App\Support\Pricing, at the moment a booking is created. The two are
     * separate on purpose and must not be merged.
     *
     * As of 2026-08-28 this is used ONLY by the `bookings:freeze-commission`
     * repair command and the migration that backfilled the last implicit rows —
     * both places where reconstructing the historical rate is the intent. No
     * read path imputes any more: a zero commission is now read as zero,
     * because the write side can no longer produce an unfrozen row.
     */
    public const LEGACY_COMMISSION_RATE = 0.02;

    /** @param \Illuminate\Database\Eloquent\Builder $q */
    public function scopeRevenue($q)
    {
        // Qualify the column — this scope is used in queries joined to `units`,
        // which also has a `status` column (would otherwise be ambiguous).
        return $q->whereIn('bookings.status', self::REVENUE_STATUSES);
    }

    /**
     * SQL for a booking's commission: simply the frozen amount.
     *
     * This used to impute the legacy rate whenever `commission_amount` was not
     * greater than zero, and that test could not tell a booking with a
     * LEGITIMATE zero commission — a promotional partner, a Mamsa-owned unit's
     * counterpart — from one that was never frozen. It would have replaced a
     * correct zero with 2% of the subtotal: a wrong number that looks right,
     * which is worse than the silent zero it was guarding against.
     *
     * `IS NOT NULL` is not the fix either: both columns are NOT NULL, so that
     * test is always true and the fallback becomes unreachable — the same
     * outcome by a longer route.
     *
     * The ambiguity is not resolvable at read time, so it is removed at write
     * time instead: the column defaults are dropped (see the 2026_08_28
     * migration), so a row cannot be created without an explicit rate and
     * amount, and an unfrozen row can no longer exist. Every row here is frozen
     * by construction.
     */
    public static function commissionExpr(string $table = 'bookings'): string
    {
        // No COALESCE: the column is NOT NULL and no caller LEFT JOINs bookings,
        // so wrapping it would only suggest a nullability that does not exist.
        return "{$table}.commission_amount";
    }

    protected $fillable = [
        'unit_id',
        'user_id',
        'start_date',
        'end_date',
        'guests',
        'children',
        'nightly_rate',
        'subtotal',
        'service_fee',
        'service_fee_percent',
        'tax_percent',
        'cleaning_fee',
        'taxes',
        'commission_rate',
        'commission_amount',
        'partner_share',
        'payout_id',
        'total_amount',
        'status',
        'cancellation_snapshot',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by',
        'notes',
    ];

    protected $casts = [
        'start_date'            => 'date',
        'end_date'              => 'date',
        'nightly_rate'          => 'float',
        'subtotal'              => 'float',
        'service_fee'           => 'float',
        'service_fee_percent'   => 'float',
        'tax_percent'           => 'float',
        'cleaning_fee'          => 'float',
        'taxes'                 => 'float',
        'commission_rate'       => 'float',
        'commission_amount'     => 'float',
        'partner_share'         => 'float',
        'total_amount'          => 'float',
        'cancellation_snapshot' => 'array',
        'cancelled_at'          => 'datetime',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function getNightsAttribute(): int
    {
        return $this->start_date->diffInDays($this->end_date);
    }
}
