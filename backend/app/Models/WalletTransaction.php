<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_REFUND  = 'refund';
    public const TYPE_TOPUP   = 'topup';
    public const TYPE_REWARD  = 'reward';

    protected $fillable = [
        'user_id',
        'ref_code',
        'type',
        'amount',
        'description',
        'status',
        'booking_id',
        'occurred_at',
    ];

    protected $casts = [
        'amount'      => 'float',
        'occurred_at' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
