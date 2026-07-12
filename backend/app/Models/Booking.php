<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'unit_id',
        'user_id',
        'start_date',
        'end_date',
        'guests',
        'nightly_rate',
        'subtotal',
        'service_fee',
        'cleaning_fee',
        'taxes',
        'commission_rate',
        'commission_amount',
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
        'cleaning_fee'          => 'float',
        'taxes'                 => 'float',
        'commission_rate'       => 'float',
        'commission_amount'     => 'float',
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
