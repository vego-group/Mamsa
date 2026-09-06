<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A guest's complaint against a completed stay — complaints/refunds spec v1.0.
 *
 * The complaint's status is its own lifecycle and never touches the booking's:
 * the booking stays `completed` from submission to resolution (R6).
 *
 * `internal_note` is admin-only. It is listed in `$hidden` so that serialising
 * the model directly — the easy mistake, in a quick partner or guest endpoint —
 * cannot leak it. Admin resources that need it opt back in via makeVisible().
 */
class BookingComplaint extends Model
{
    public const STATUS_SUBMITTED         = 'submitted';
    public const STATUS_UNDER_REVIEW      = 'under_review';
    public const STATUS_APPROVED          = 'approved';
    public const STATUS_RESOLVED_REFUNDED = 'resolved_refunded';
    public const STATUS_RESOLVED_REJECTED = 'resolved_rejected';

    /** Terminal states — a decision has been recorded and cannot be retaken. */
    public const RESOLVED_STATUSES = [self::STATUS_RESOLVED_REFUNDED, self::STATUS_RESOLVED_REJECTED];

    protected $fillable = [
        'booking_id',
        'user_id',
        'status',
        'description',
        'contacted_partner',
        'reviewed_by',
        'reviewed_at',
        'approved_refund_halalas',
        'approved_by',
        'approved_at',
        'internal_note',
        'guest_message',
    ];

    protected $casts = [
        'contacted_partner'       => 'boolean',
        'reviewed_at'             => 'datetime',
        'approved_at'             => 'datetime',
        'approved_refund_halalas' => 'integer',
    ];

    /**
     * Admin-only field, hidden by default (T13). Opt in explicitly with
     * ->makeVisible('internal_note') on an admin surface — never on a guest or
     * partner one.
     */
    protected $hidden = ['internal_note'];

    /**
     * Whether a superadmin may still change the approved amount.
     *
     * Exact-equality execution ({@see mayExecute()}) means a wrong approved
     * figure would otherwise dead-end the complaint: finance can execute that
     * number or nothing, and nobody could correct it. So the amount stays
     * editable — but only while no money is in flight.
     *
     * `pending` blocks amendment just as hard as `succeeded` does (T19). A
     * pending refund has already been accepted by the gateway and is awaiting
     * settlement; changing the approved figure underneath it would leave the
     * ledger debit that the webhook is about to post disagreeing with the
     * decision on record. Only `failed` rows are ignored, which is what lets
     * finance retry after a gateway rejection.
     *
     * Every amendment is written to the audit trail by the caller:
     *   AuditLog::record($complaint, 'complaint.approval_amended',
     *       ['approved_refund_halalas' => $old],
     *       ['approved_refund_halalas' => $new], $actorId)
     * A silent overwrite would erase the only evidence of what was originally
     * decided and by whom.
     */
    public function canAmendApproval(): bool
    {
        return $this->status === self::STATUS_APPROVED && ! $this->hasRefundInFlight();
    }

    /**
     * Whether this complaint may still be rejected.
     *
     * Rejection is allowed from `approved`, not only from `under_review`: an
     * amount can be approved and then evidence arrive from the partner showing
     * the complaint was unfounded. Without this path the case would be stuck —
     * unexecutable because nobody wants to pay it, and unclosable because the
     * only exit was from an earlier state.
     *
     * It stops the moment money is involved, on the same test as amendment.
     * Rejecting a complaint whose refund is already settling would be
     * overwritten anyway: settle() sets `resolved_refunded`, so the record would
     * end up contradicting both the decision and the message sent to the guest.
     */
    public function canReject(): bool
    {
        return ! in_array($this->status, self::RESOLVED_STATUSES, true)
            && ! $this->hasRefundInFlight();
    }

    /**
     * A refund that has been accepted by the gateway, or has settled.
     *
     * `failed` is excluded — it returned nothing and must not freeze the
     * complaint. This is the single definition of "money is involved", shared by
     * every gate that needs it, so the gates cannot drift apart.
     */
    public function hasRefundInFlight(): bool
    {
        return $this->refunds()
            ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_SUCCEEDED])
            ->exists();
    }

    /**
     * Where a complaint returns to when a refund attempt fails.
     *
     * `approved`, not `under_review` (v1.2 §1): the approval still stands — a
     * gateway rejection says nothing about whether the guest was owed money.
     * Sending it back to review would force a second approval cycle for a
     * decision nobody has changed, and would drop the approved amount that
     * finance is entitled to retry.
     */
    public const STATUS_AFTER_FAILED_REFUND = self::STATUS_APPROVED;

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** The complainant. Always equal to booking.user_id — validated at write. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The superadmin who fixed the amount finance is allowed to execute. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Whether an execute request may proceed at this exact amount.
     *
     * Exact integer equality, not a ceiling: finance executes the approved
     * number or nothing. A "<=" test would let finance refund less than was
     * approved without anyone approving the smaller figure — a different
     * decision, taken by someone without the authority to take it.
     */
    public function mayExecute(int $amountHalalas): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->approved_refund_halalas !== null
            && $this->approved_refund_halalas === $amountHalalas;
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(BookingComplaintAttachment::class, 'complaint_id');
    }

    /**
     * Refunds executed for this complaint. Plural and on the shared `refunds`
     * table: a first attempt can fail at the gateway and be retried, so a
     * complaint may own several rows of which at most one is `succeeded`.
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'complaint_id');
    }
}
