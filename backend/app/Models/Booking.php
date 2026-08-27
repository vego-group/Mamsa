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
     * SQL for a booking's effective commission: the frozen amount when it was
     * captured, otherwise 2% of the subtotal (historical bookings predate the
     * frozen column, so `SUM(commission_amount)` alone reads ~0).
     */
    public static function commissionExpr(string $table = 'bookings'): string
    {
        return "(CASE WHEN {$table}.commission_amount > 0 THEN {$table}.commission_amount"
            ." ELSE ROUND(COALESCE({$table}.subtotal, 0) * ".self::LEGACY_COMMISSION_RATE.", 2) END)";
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
