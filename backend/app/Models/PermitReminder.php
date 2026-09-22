<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reminder that was sent — the record that stops it being sent again. */
class PermitReminder extends Model
{
    /** Days before expiry, longest first. 0 is the expiry day itself. */
    public const THRESHOLDS = [60, 30, 14, 7, 1, 0];

    /** The last ones before it lapses; these also go out by SMS. */
    public const URGENT = [7, 1, 0];

    protected $fillable = ['permit_id', 'threshold', 'expires_at', 'sent_at'];

    protected $casts = ['expires_at' => 'date', 'sent_at' => 'datetime', 'threshold' => 'integer'];

    public function permit(): BelongsTo
    {
        return $this->belongsTo(Permit::class);
    }
}
