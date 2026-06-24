<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single configurable refund tier inside a policy template — SRS 2.3.1.
 */
class PolicyTier extends Model
{
    protected $fillable = [
        'cancellation_policy_id',
        'min_hours_before_checkin',
        'refund_percent',
        'label_ar',
    ];

    protected $casts = [
        'min_hours_before_checkin' => 'integer',
        'refund_percent'           => 'integer',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class, 'cancellation_policy_id');
    }
}
