<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'booking_id',
        'amount',
        'refunded_amount',
        'payment_method',
        'payment_status',
        'paid_at',
        'moyasar_id',
        'moyasar_reference',
        'moyasar_response',
    ];

    protected $casts = [
        'amount'           => 'float',
        'refunded_amount'  => 'float',
        'paid_at'          => 'datetime',
        'moyasar_response' => 'array',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** Amount still refundable against this payment (SAR). */
    public function refundableAmount(): float
    {
        return round($this->amount - $this->refunded_amount, 2);
    }
}
