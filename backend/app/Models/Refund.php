<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund/void executed against Moyasar for a cancelled booking — SRS 2.3.
 */
class Refund extends Model
{
    public const TYPE_REFUND = 'refund';
    public const TYPE_VOID   = 'void';

    protected $fillable = [
        'booking_id',
        'payment_id',
        'type',
        'amount',
        'refund_percent',
        'tier_label',
        'status',
        'moyasar_refund_id',
        'moyasar_response',
    ];

    protected $casts = [
        'amount'           => 'float',
        'refund_percent'   => 'float',
        'moyasar_response' => 'array',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
