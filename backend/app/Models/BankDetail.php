<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's payout account — wallet contract §6. One row per partner, for
 * both account types.
 */
class BankDetail extends Model
{
    protected $fillable = [
        'partner_user_id', 'iban', 'account_holder_name', 'bank_name',
        'verified', 'verified_at', 'verified_by_admin_id', 'rejection_reason',
    ];

    protected $casts = [
        'verified'    => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }

    /** Who approved this destination — a disputed transfer needs a name. */
    public function verifiedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_admin_id');
    }
}
