<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable partner ledger row — contract v2.2 §2.3 (renamed from the contract's
 * `WalletTransaction` to avoid colliding with the live guest {@see WalletTransaction}).
 *
 * Append-only: rows are never updated or deleted. `$timestamps = false` because
 * there is no `updated_at`; `created_at` is set explicitly at insert. Model-level
 * guards below back the DB-level immutability enforced separately.
 */
class PartnerLedgerEntry extends Model
{
    public const TYPE_EARNING         = 'earning';
    public const TYPE_PAYOUT          = 'payout';
    public const TYPE_REFUND_REVERSAL = 'refund_reversal';
    public const TYPE_ADJUSTMENT      = 'adjustment';

    /** Immutable ledger: no updated_at, and rows are insert-only. */
    public $timestamps = false;

    protected $fillable = [
        'partner_user_id',
        'type',
        'amount',
        'balance_after',
        'ref_type',
        'ref_id',
        'ref_code',
        'description',
        'created_by_admin_id',
        'created_at',
    ];

    protected $casts = [
        'amount'        => 'float',
        'balance_after' => 'float',
        'created_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        // Defence-in-depth: block any update/delete at the model layer. The
        // authoritative guard is the DB trigger/grant (contract §7).
        static::updating(fn () => throw new \RuntimeException('partner_ledger_entries is append-only'));
        static::deleting(fn () => throw new \RuntimeException('partner_ledger_entries is append-only'));
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }
}
