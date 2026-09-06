<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BookingComplaint;
use App\Models\BookingComplaintAttachment;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Complaints filed against the partner's own units — spec §5.3. Read-only.
 *
 * What the partner may see is a shorter list than what exists, and the omission
 * is the point: no `internal_note` (the admin's working note) and no guest
 * phone number. A complaint is a dispute between two parties that Mamsa
 * mediates; handing the partner a direct line to the complainant turns a
 * mediated process into an unmediated one.
 */
class ComplaintController extends Controller
{
    /** GET /me/complaints */
    public function index(Request $request): JsonResponse
    {
        $rows = $this->scope($request)
            ->with(['booking:id,code,unit_id', 'booking.unit:id,unit_name'])
            ->latest('id')
            ->get()
            ->map(fn (BookingComplaint $c) => [
                'id'          => $c->id,
                'status'      => $c->status,
                'bookingCode' => $c->booking?->code,
                'unitName'    => $c->booking?->unit?->unit_name,
                'createdAt'   => $c->created_at?->toIso8601ZuluString(),
            ]);

        return response()->json(['items' => $rows]);
    }

    /** GET /me/complaints/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $complaint = $this->scope($request)
            ->with(['attachments', 'booking:id,code,unit_id', 'booking.unit:id,unit_name'])
            ->find($id);

        if (! $complaint) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'الشكوى غير موجودة'],
            ], 404);
        }

        // Only a SETTLED refund is money that actually left the partner. A
        // pending one may still fail, and showing it as a deduction would have
        // the partner reading a debit that is not on their statement.
        $refund = $complaint->refunds()
            ->where('status', Refund::STATUS_SUCCEEDED)
            ->latest('id')
            ->first();

        return response()->json([
            'id'          => $complaint->id,
            'status'      => $complaint->status,
            'description' => $complaint->description,
            'bookingCode' => $complaint->booking?->code,
            'unitName'    => $complaint->booking?->unit?->unit_name,
            'createdAt'   => $complaint->created_at?->toIso8601ZuluString(),
            'images'      => $complaint->attachments->map(fn (BookingComplaintAttachment $a) => [
                'url'  => BookingComplaintAttachment::signedUrl($a),
                'mime' => $a->mime,
            ])->values(),
            // The partner's SHARE, not the guest's refund: VAT returns to ZATCA
            // and commission to Mamsa, so the gross would overstate what this
            // cost them.
            'deductedHalalas' => $refund
                ? (int) round((float) ($refund->amount_partner ?? 0) * 100)
                : null,
        ]);
    }

    /** Complaints on units this partner owns — never any other partner's. */
    private function scope(Request $request)
    {
        return BookingComplaint::query()
            ->whereHas('booking.unit', fn ($q) => $q->where('user_id', $request->user()->id));
    }
}
