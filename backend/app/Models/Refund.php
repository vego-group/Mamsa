<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund/void executed against Moyasar — SRS 2.3, extended 2026-09-06 to
 * carry complaint refunds too (complaints/refunds spec v1.0).
 *
 * One table for every way money goes back to a guest, so "how much of this
 * booking has already been returned" has a single answer. `reason` says which
 * path created the row; the split columns are populated only on rows written
 * after 2026-09-06 and are NULL on the historical cancellation refunds.
 */
class Refund extends Model
{
    public const TYPE_REFUND = 'refund';
    public const TYPE_VOID   = 'void';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';

    public const REASON_COMPLAINT        = 'complaint';
    public const REASON_HOST_CANCELLATION = 'host_cancellation';
    public const REASON_OTHER            = 'other';

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

        // Complaint refunds (spec v1.0 §4.3).
        'complaint_id',
        'reason',
        'amount_vat',
        'amount_commission',
        'amount_partner',
        'idempotency_key',
        'failure_reason',
        'initiated_by',
        'manual_transfer_reference',
        'credit_note_number',
        'credit_note_qr',
    ];

    protected $casts = [
        'amount'            => 'float',
        'refund_percent'    => 'float',
        'moyasar_response'  => 'array',
        'amount_vat'        => 'float',
        'amount_commission' => 'float',
        'amount_partner'    => 'float',
    ];

    /**
     * The split adds up to the refunded total — the same invariant the booking
     * itself holds. Checked before the row is written, not after: a row that
     * fails this has already told the partner a wrong number.
     */
    public function splitIsBalanced(): bool
    {
        if ($this->amount_commission === null) {
            return true; // historical row, written before the split existed
        }

        return round($this->amount_commission + $this->amount_partner + $this->amount_vat, 2)
            === round((float) $this->amount, 2);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** Null when the refund came from cancellation rather than a complaint. */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(BookingComplaint::class, 'complaint_id');
    }

    /** The admin who executed it. Null on rows written by the cancellation engine. */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
