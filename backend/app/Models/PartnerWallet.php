<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Partner earnings wallet — contract v2.2 §2. Balances are backend-owned;
 * `available_balance` is signed and MAY be negative (§2.2). Distinct from the
 * guest {@see WalletTransaction} history.
 */
class PartnerWallet extends Model
{
    protected $fillable = [
        'partner_user_id',
        'available_balance',
        'pending_balance',
        'lifetime_earnings',
        'lifetime_paid_out',
        'currency',
    ];

    protected $casts = [
        'available_balance' => 'float',
        'pending_balance'   => 'float',
        'lifetime_earnings' => 'float',
        'lifetime_paid_out' => 'float',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(PartnerLedgerEntry::class, 'partner_user_id', 'partner_user_id');
    }
}
