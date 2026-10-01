<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monthly transfer to a partner — wallet contract §3. Recorded after the bank
 * transfer has happened, so `paid` and `reversed` are the only states.
 */
class Payout extends Model
{
    public const STATUS_PAID     = 'paid';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'partner_user_id', 'reference', 'period_month', 'amount', 'bookings_count',
        'currency', 'iban_masked', 'bank_name', 'status', 'paid_at', 'bank_reference',
        'note', 'reversed_at', 'reversal_reason', 'created_by_admin_id',
    ];

    protected $casts = [
        'amount'      => 'float',
        'paid_at'     => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }

    /** The bookings this transfer was made of — the partner's self-service breakdown. */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
