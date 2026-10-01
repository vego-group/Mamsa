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

    /**
     * Column defaults are a DB-side concept: a freshly created instance holds
     * only what was passed, so a wallet created with just an id read back null
     * balances and any arithmetic on them blew up.
     */
    protected $attributes = [
        'available_balance' => 0,
        'pending_balance'   => 0,
        'lifetime_earnings' => 0,
        'lifetime_paid_out' => 0,
        'currency'          => 'SAR',
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
