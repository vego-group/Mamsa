<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Exceptions\RefundInFlightException;
use App\Models\AuditLog;
use App\Models\BookingComplaint;
use App\Models\BookingComplaintAttachment;
use App\Models\PartnerWallet;
use App\Models\Refund;
use App\Notifications\ComplaintApproved;
use App\Notifications\ComplaintRejected;
use App\Notifications\ComplaintUnderReview;
use App\Services\ComplaintRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guest complaints, admin side — complaints/refunds spec §5.2 as amended by
 * v1.2 §D5.
 *
 * The lifecycle is deliberately four acts by two roles:
 *
 *   review   superadmin   submitted → under_review
 *   approve  superadmin   under_review → approved, and FIXES the amount
 *   execute  finance      pays exactly that amount, cannot change it
 *   reject   superadmin   → resolved_rejected
 *
 * Splitting approve from execute is the whole control. A single role that both
 * decides what is owed and moves the money is `wallets.adjust` wearing a
 * different name, and finance deliberately does not hold that.
 */
class ComplaintsController extends Controller
{
    public function __construct(private readonly ComplaintRefundService $refunds) {}

    /** GET /admin/complaints */
    public function index(Request $request): JsonResponse
    {
        $args = $this->listArgs($request);

        $query = BookingComplaint::query()
            ->with(['booking:id,code,unit_id,user_id', 'booking.unit:id,unit_name,user_id', 'booking.unit.owner:id,name', 'user:id,name,phone'])
            ->withCount('attachments');

        if ($status = $this->cleanParam($request->query('status'))) {
            $query->where('status', $status);
        }

        // Search spans the booking code, the guest and the unit — the three
        // things an admin has in front of them when someone calls about a case.
        if ($args['search'] !== null) {
            $term  = $args['search'];
            $phone = $this->phoneTerm($term);

            $query->where(function ($w) use ($term, $phone) {
                $w->whereHas('booking', fn ($b) => $b->where('code', 'like', "%{$term}%"))
                    ->orWhereHas('booking.unit', fn ($u) => $u->where('unit_name', 'like', "%{$term}%"))
                    ->orWhereHas('user', function ($u) use ($term, $phone) {
                        $u->where('name', 'like', "%{$term}%");
                        if ($phone) {
                            $u->orWhere('phone', 'like', "%{$phone}%");
                        }
                    });
            });
        }

        $paginator = $query->orderByDesc('id')->paginate($args['pageSize'], ['*'], 'page', $args['page']);

        return $this->items($paginator, fn (BookingComplaint $c) => [
            'id'             => $c->id,
            'status'         => $c->status,
            'bookingCode'    => $c->booking?->code,
            'unitName'       => $c->booking?->unit?->unit_name,
            'guestName'      => $c->user?->name,
            'partnerName'    => $c->booking?->unit?->owner?->name,
            'hasAttachments' => $c->attachments_count > 0,
            'createdAt'      => $c->created_at?->toIso8601ZuluString(),
        ]);
    }

    /** GET /admin/complaints/{id} — everything needed to decide, in one call. */
    public function show(string $id): JsonResponse
    {
        $complaint = $this->find($id);
        $booking   = $complaint->booking?->loadMissing('unit.owner', 'user', 'payment');

        // Two different facts, reported separately because they mean different
        // things to whoever reads the screen: `already` is money returned,
        // `pending` is money the gateway has accepted and not yet settled.
        // The ceiling subtracts both — but a screen that folded them into one
        // number could not explain why the maximum is lower than the refunded
        // total suggests.
        $alreadyHalalas = (int) round(
            (float) Refund::where('booking_id', $complaint->booking_id)
                ->where('status', Refund::STATUS_SUCCEEDED)
                ->sum('amount') * 100
        );

        $pendingHalalas = (int) round(
            (float) Refund::where('booking_id', $complaint->booking_id)
                ->where('status', Refund::STATUS_PENDING)
                ->sum('amount') * 100
        );

        $grossHalalas = (int) round((float) ($booking?->total_amount ?? 0) * 100);
        $split        = $booking?->splitRefund((float) ($booking->total_amount ?? 0)) ?? [];

        $partnerId = $booking?->unit?->user_id;
        $wallet    = $partnerId ? PartnerWallet::where('partner_user_id', $partnerId)->first() : null;

        return response()->json([
            'complaint' => [
                'id'                    => $complaint->id,
                'status'                => $complaint->status,
                'description'           => $complaint->description,
                'contactedPartner'      => (bool) $complaint->contacted_partner,
                // Admin surface: the note is opted back in explicitly. It is
                // hidden on the model so no other surface can leak it (T13).
                'internalNote'          => $complaint->internal_note,
                'guestMessage'          => $complaint->guest_message,
                'reviewedAt'            => $complaint->reviewed_at?->toIso8601ZuluString(),
                'approvedRefundHalalas' => $complaint->approved_refund_halalas,
                'approvedAt'            => $complaint->approved_at?->toIso8601ZuluString(),
                'canAmendApproval'      => $complaint->canAmendApproval(),
                'createdAt'             => $complaint->created_at?->toIso8601ZuluString(),
            ],
            'attachments' => $complaint->attachments->map(fn (BookingComplaintAttachment $a) => [
                'url'  => BookingComplaintAttachment::signedUrl($a),
                'mime' => $a->mime,
            ])->values(),
            'booking' => [
                'code'                  => $booking?->code,
                'checkIn'               => $booking?->start_date,
                'checkOut'              => $booking?->end_date,
                'grossHalalas'          => $grossHalalas,
                'vatHalalas'            => (int) round(($split['vat'] ?? 0) * 100),
                'commissionHalalas'     => (int) round(($split['commission_amount'] ?? 0) * 100),
                'partnerShareHalalas'   => (int) round(($split['partner_share'] ?? 0) * 100),
                'alreadyRefundedHalalas' => $alreadyHalalas,
                'pendingRefundHalalas'   => $pendingHalalas,
                'maxRefundableHalalas'  => max(0, $grossHalalas - $alreadyHalalas - $pendingHalalas),
                'mamsaOwned'            => (bool) $booking?->unit?->mamsa_owned,
            ],
            // Both phone numbers: the admin calls each side before deciding.
            'guest' => [
                'name'  => $booking?->user?->name,
                'phone' => $booking?->user?->phone,
            ],
            'partner' => [
                'name'                    => $booking?->unit?->owner?->name,
                'phone'                   => $booking?->unit?->owner?->phone,
                'availableBalanceHalalas' => (int) round((float) ($wallet?->available_balance ?? 0) * 100),
            ],
            'unit' => [
                'id'   => $booking?->unit?->id,
                'name' => $booking?->unit?->unit_name,
            ],
            'refunds' => $complaint->refunds()->latest('id')->get()->map(fn (Refund $r) => [
                'id'              => $r->id,
                'status'          => $r->status,
                'amountHalalas'   => (int) round((float) $r->amount * 100),
                'partnerHalalas'  => (int) round((float) ($r->amount_partner ?? 0) * 100),
                'failureReason'   => $r->failure_reason,
                'moyasarRefundId' => $r->moyasar_refund_id,
                'createdAt'       => $r->created_at?->toIso8601ZuluString(),
            ])->values(),
        ]);
    }

    /** PATCH /admin/complaints/{id}/status — superadmin. submitted → under_review. */
    public function status(string $id): JsonResponse
    {
        $complaint = $this->find($id);

        if ($complaint->status !== BookingComplaint::STATUS_SUBMITTED) {
            $this->fail('CONFLICT', 'لا يمكن نقل الشكوى إلى المراجعة من حالتها الحالية', 409);
        }

        $complaint->update([
            'status'      => BookingComplaint::STATUS_UNDER_REVIEW,
            'reviewed_by' => request()->user()?->id,
            'reviewed_at' => now(),
        ]);

        $this->tell($complaint, new ComplaintUnderReview($complaint->fresh()));

        return $this->ok();
    }

    /**
     * POST /admin/complaints/{id}/approve — superadmin.
     *
     * Fixes the amount finance may execute. This is the decision; executing it
     * is a separate act by a separate role.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $complaint = $this->find($id);

        if ($complaint->status !== BookingComplaint::STATUS_UNDER_REVIEW) {
            $this->fail('CONFLICT', 'لا يمكن اعتماد مبلغ إلا لشكوى تحت المراجعة', 409);
        }

        $data = $this->validate($request, [
            'amountHalalas' => ['required', 'integer', 'min:1'],
            'guestMessage'  => ['sometimes', 'nullable', 'string', 'max:1000'],
            'internalNote'  => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], [
            'amountHalalas.required' => 'مبلغ الاسترداد مطلوب',
            'amountHalalas.integer'  => 'المبلغ يجب أن يكون بالهللات كعدد صحيح',
            'amountHalalas.min'      => 'المبلغ يجب أن يكون أكبر من صفر',
        ]);

        $this->assertWithinRefundable($complaint, (int) $data['amountHalalas']);

        $complaint->update([
            'status'                  => BookingComplaint::STATUS_APPROVED,
            'approved_refund_halalas' => (int) $data['amountHalalas'],
            'approved_by'             => $request->user()?->id,
            'approved_at'             => now(),
            'guest_message'           => $data['guestMessage'] ?? $complaint->guest_message,
            'internal_note'           => $data['internalNote'] ?? $complaint->internal_note,
        ]);

        AuditLog::record($complaint, 'complaint.approved', null, [
            'approved_refund_halalas' => (int) $data['amountHalalas'],
        ], $request->user()?->id);

        $this->tell($complaint, new ComplaintApproved($complaint->fresh()));

        return $this->ok();
    }

    /**
     * PATCH /admin/complaints/{id}/approval — superadmin corrects the amount.
     *
     * Exists because execution demands EXACT equality: without a correction
     * path, one mistyped approval would dead-end the complaint with no way back
     * (v1.2 §1). Refused the moment money is in flight, and never a silent
     * overwrite — the old value goes to the audit trail.
     */
    public function amendApproval(Request $request, string $id): JsonResponse
    {
        $complaint = $this->find($id);

        if (! $complaint->canAmendApproval()) {
            $this->fail(
                'CONFLICT',
                'لا يمكن تعديل المبلغ المعتمد بعد بدء تنفيذ الاسترداد',
                409
            );
        }

        $data = $this->validate($request, [
            'amountHalalas' => ['required', 'integer', 'min:1'],
        ], [
            'amountHalalas.required' => 'مبلغ الاسترداد مطلوب',
            'amountHalalas.min'      => 'المبلغ يجب أن يكون أكبر من صفر',
        ]);

        $this->assertWithinRefundable($complaint, (int) $data['amountHalalas']);

        $old = $complaint->approved_refund_halalas;

        $complaint->update([
            'approved_refund_halalas' => (int) $data['amountHalalas'],
            'approved_by'             => $request->user()?->id,
            'approved_at'             => now(),
        ]);

        AuditLog::record(
            $complaint,
            'complaint.approval_amended',
            ['approved_refund_halalas' => $old],
            ['approved_refund_halalas' => (int) $data['amountHalalas']],
            $request->user()?->id,
        );

        return $this->ok();
    }

    /**
     * POST /admin/complaints/{id}/refund — finance executes the approved amount.
     *
     * The body still carries the amount, and it is checked for EXACT equality
     * against what was approved rather than trusted (T17). A ceiling test would
     * let finance pay less than was approved — a different decision, taken by
     * someone without the authority to take it.
     */
    public function refund(Request $request, string $id): JsonResponse
    {
        $complaint = $this->find($id);

        $data = $this->validate($request, [
            'amountHalalas'  => ['required', 'integer', 'min:1'],
            'idempotencyKey' => ['required', 'string', 'min:8', 'max:64'],
        ], [
            'amountHalalas.required'  => 'المبلغ مطلوب',
            'idempotencyKey.required' => 'مفتاح الحماية من التكرار مطلوب',
        ]);

        // The idempotency check comes FIRST, before the status and amount
        // gates. A successful execution moves the complaint to
        // `resolved_refunded`, so a retried request — the double-submitted
        // form, the client that timed out and resent — would otherwise be
        // refused with 409 for the crime of having already worked. Spec §5.2
        // is explicit: a replayed key returns the original outcome with 200.
        if ($prior = Refund::where('idempotency_key', $data['idempotencyKey'])->first()) {
            return response()->json([
                'ok'       => true,
                'refundId' => $prior->id,
                'status'   => $prior->status,
                'replayed' => true,
            ]);
        }

        if ($complaint->status !== BookingComplaint::STATUS_APPROVED) {
            $this->fail('CONFLICT', 'لا يمكن التنفيذ إلا لشكوى معتمدة', 409);
        }

        if (! $complaint->mayExecute((int) $data['amountHalalas'])) {
            $this->fail(
                'AMOUNT_NOT_APPROVED',
                'المبلغ المطلوب لا يطابق المبلغ المعتمد',
                422,
                ['amountHalalas' => 'المبلغ المعتمد هو '.$complaint->approved_refund_halalas.' هللة'],
            );
        }

        try {
            $refund = $this->refunds->execute(
                $complaint,
                (int) $data['amountHalalas'],
                $request->user(),
                $data['idempotencyKey'],
            );
        } catch (RefundInFlightException $e) {
            // Its own code, not REFUND_FAILED: nothing failed. A refund is
            // already on its way and the screen should say so rather than
            // invite a retry.
            $this->fail('REFUND_IN_FLIGHT', $e->getMessage(), 409, [
                'refundId' => (string) $e->refundId,
            ]);
        } catch (\RuntimeException $e) {
            $this->fail('REFUND_FAILED', $e->getMessage(), 422);
        }

        // Stamped when the same person approved and executed. Legitimate —
        // superadmin holds both permissions as an emergency exit — but it is
        // the case an audit wants to find, and finding it should not require
        // joining two columns by eye.
        AuditLog::record($complaint, 'complaint.refund_executed', null, [
            'refund_id'     => $refund->id,
            'amountHalalas' => (int) $data['amountHalalas'],
            'single_actor'  => (int) $complaint->approved_by === (int) $request->user()?->id,
        ], $request->user()?->id);

        return response()->json([
            'ok'       => true,
            'refundId' => $refund->id,
            // pending = accepted by the gateway, awaiting settlement. The screen
            // must show this as its own state, not as success (G1).
            'status'   => $refund->status,
        ]);
    }

    /** POST /admin/complaints/{id}/reject — superadmin. */
    public function reject(Request $request, string $id): JsonResponse
    {
        $complaint = $this->find($id);

        if (in_array($complaint->status, BookingComplaint::RESOLVED_STATUSES, true)) {
            $this->fail('CONFLICT', 'تم البت في هذه الشكوى بالفعل', 409);
        }

        $data = $this->validate($request, [
            'guestMessage' => ['required', 'string', 'min:3', 'max:1000'],
            'internalNote' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], [
            'guestMessage.required' => 'رسالة الضيف مطلوبة',
        ]);

        $complaint->update([
            'status'        => BookingComplaint::STATUS_RESOLVED_REJECTED,
            'guest_message' => $data['guestMessage'],
            'internal_note' => $data['internalNote'] ?? $complaint->internal_note,
            'reviewed_by'   => $complaint->reviewed_by ?? $request->user()?->id,
            'reviewed_at'   => $complaint->reviewed_at ?? now(),
        ]);

        AuditLog::record($complaint, 'complaint.rejected', null, [
            'guest_message' => $data['guestMessage'],
        ], $request->user()?->id);

        $this->tell($complaint, new ComplaintRejected($complaint->fresh()));

        return $this->ok();
    }

    /* ---------- helpers ---------- */

    private function find(string $id): BookingComplaint
    {
        $complaint = BookingComplaint::with(['attachments', 'booking'])->find($id);

        if (! $complaint) {
            $this->fail('NOT_FOUND', 'الشكوى غير موجودة', 404);
        }

        return $complaint;
    }

    /** Approving more than the booking can still return is refused up front. */
    private function assertWithinRefundable(BookingComplaint $complaint, int $amountHalalas): void
    {
        $booking = $complaint->booking;

        // succeeded AND pending: approving more than what is left after money
        // already in flight would only be discovered at execution, after the
        // amount had been promised to the guest.
        $committedHalalas = (int) round(
            (float) Refund::where('booking_id', $complaint->booking_id)
                ->whereIn('status', [Refund::STATUS_SUCCEEDED, Refund::STATUS_PENDING])
                ->sum('amount') * 100
        );

        $refundable = (int) round((float) ($booking?->total_amount ?? 0) * 100) - $committedHalalas;

        if ($amountHalalas > $refundable) {
            $this->fail(
                'AMOUNT_EXCEEDS_REFUNDABLE',
                'المبلغ أكبر من المتاح للاسترداد على هذا الحجز',
                422,
                ['amountHalalas' => 'المتاح: '.max(0, $refundable).' هللة'],
            );
        }
    }

    /** Notify the guest, never letting a delivery failure undo the decision. */
    private function tell(BookingComplaint $complaint, object $notification): void
    {
        try {
            $complaint->loadMissing('booking.user')->booking?->user?->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
