<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Mamsa's cut is an internal settlement between the platform and the
        // partner — never a guest-facing figure (contract §1.7, §7). This
        // resource is shared by the guest, partner and admin endpoints, so the
        // field is gated here rather than at the route.
        $viewer  = $request->user();
        $isAdmin = (bool) $viewer?->isAdmin();
        $isOwner = $viewer !== null && (int) ($this->unit?->user_id ?? 0) === (int) $viewer->id;

        return [
            'id'           => $this->id,
            // Human-friendly confirmation code derived deterministically from the id.
            // Stable per booking, no extra column required (used in UI + SMS).
            'reference'    => $this->reference(),
            'unit'         => $this->whenLoaded('unit', fn () => new UnitResource($this->unit)),
            // Always-present scalar (column) so the partner dashboard need not
            // rely on the eager-loaded `user` object.
            'user_id'      => $this->user_id,
            'guest_name'   => $this->whenLoaded('user', fn () => $this->user?->name),
            'user'         => $this->whenLoaded('user', fn () => [
                'id'    => $this->user?->id,
                'name'  => $this->user?->name,
                'phone' => $this->user?->phone,
            ]),
            'start_date'   => $this->start_date?->toDateString(),
            'end_date'     => $this->end_date?->toDateString(),
            'nights'       => $this->nights,
            // Total guest count (unchanged) + the adults/children split.
            'guests'       => $this->guests,
            'guests_detail' => [
                'adults'   => max(0, (int) $this->guests - (int) $this->children),
                'children' => (int) $this->children,
            ],
            'total_amount' => $this->total_amount,
            // Mamsa's frozen 2% cut — surfaced for the admin bookings table and
            // the partner's own earnings view. Withheld from guests.
            $this->mergeWhen($isAdmin || $isOwner, fn () => [
                'commission_amount' => (float) $this->commission_amount,
                // The rate this booking was created under, frozen alongside the
                // amount. Read it rather than a local constant: the rate has
                // changed once and will again, and a client multiplying by a
                // hardcoded figure is wrong the day it does.
                'commission_rate'   => (float) $this->commission_rate,
            ]),
            // Itemised price summary (ملخص السعر). Falls back gracefully for
            // legacy rows that predate the breakdown columns.
            'pricing'      => $this->pricingBlock(),
            'status'       => $this->status,
            'status_label' => $this->statusLabel(),
            // FR-036 — the cancellation policy frozen at payment time. Refund
            // math reads ONLY this snapshot, so any UI (cancel dialog, policy
            // card) must render from it too — never from the unit's live
            // policy. Null until the booking is paid (no snapshot exists yet);
            // in that window the unit's current policy is what will be frozen.
            'policy_snapshot' => $this->when((bool) $this->cancellation_snapshot, fn () => [
                'template'   => $this->cancellation_snapshot['policy_key'] ?? null,
                'name'       => $this->cancellation_snapshot['policy_name'] ?? null,
                'checkin_at' => $this->cancellation_snapshot['checkin_at'] ?? null,
                'tiers'      => $this->cancellation_snapshot['tiers'] ?? [],
            ], null),
            'notes'        => $this->notes,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            // Cancellation card data (الحجز ملغي): only present once cancelled.
            'cancellation' => $this->when($this->status === 'cancelled', fn () => [
                'reason'           => $this->cancellation_reason,
                'cancelled_by'     => $this->cancelled_by,
                'cancelled_by_label' => $this->cancelledByLabel(),
                'cancelled_at'     => $this->cancelled_at?->toISOString(),
                'refunded_amount'  => $this->whenLoaded('payment', fn () => $this->payment?->refunded_amount),
            ]),
            'payment'      => $this->whenLoaded('payment', fn () => [
                'id'              => $this->payment?->id,
                'payment_method'  => $this->payment?->payment_method,
                'payment_status'  => $this->payment?->payment_status,
                'amount'          => $this->payment?->amount,
                'refunded_amount' => $this->payment?->refunded_amount,
                'paid_at'         => $this->payment?->paid_at?->toISOString(),
            ]),
            'review'       => $this->whenLoaded('review', fn () => $this->review ? [
                'id'              => $this->review->id,
                'rating'          => $this->review->rating,
                'comment'         => $this->review->comment,
                'created_at'      => $this->review->created_at?->toISOString(),
                // No avatar storage yet — null keeps the UI's initials fallback.
                'user_avatar_url' => null,
            ] : null),
            'created_at'   => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Fees were abolished 2026-07-18 — the standing shape is subtotal + VAT.
     * Fee lines appear ONLY on historical bookings that actually charged them
     * (62 prod rows, Jun 30 – Jul 6): omitting them there would make the
     * lines stop summing to total_amount, which is frozen financial record.
     *
     * @return array<string, mixed>
     */
    private function pricingBlock(): array
    {
        $pricing = [
            'nightly_rate' => (float) ($this->nightly_rate ?? ($this->nights ? round($this->total_amount / $this->nights, 2) : 0)),
            'nights'       => $this->nights,
            // Contract §1.7 names. These are the SAME frozen numbers as the
            // legacy keys below — subtotal IS the net base, taxes IS the VAT,
            // total IS the gross — exposed under the contract's vocabulary so
            // clients need not know the historical column names.
            // snake_case here because /api/v1 is snake_case (§9.4).
            'gross'        => (float) $this->total_amount,
            'net_base'     => (float) ($this->subtotal ?? $this->total_amount),
            'vat'          => (float) $this->taxes,
            'vat_rate'     => round((float) ($this->tax_percent ?? 0) / 100, 4),

            'subtotal'     => (float) ($this->subtotal ?? $this->total_amount),
            'taxes'        => (float) $this->taxes,
            // Frozen applied rate; derived (fee ÷ base, the fee-era formula)
            // only for rows the migration backfill couldn't reach.
            'tax_percent'  => (float) ($this->tax_percent ?? (($base = $this->subtotal + $this->cleaning_fee + $this->service_fee) > 0 ? round($this->taxes / $base * 100, 2) : 0)),
            'total'        => (float) $this->total_amount,
        ];

        if ($this->service_fee > 0) {
            $pricing['service_fee']         = (float) $this->service_fee;
            $pricing['service_fee_percent'] = (float) ($this->service_fee_percent ?? ($this->subtotal > 0 ? round($this->service_fee / $this->subtotal * 100, 2) : 0));
        }

        if ($this->cleaning_fee > 0) {
            $pricing['cleaning_fee'] = (float) $this->cleaning_fee;
        }

        return $pricing;
    }

    private function statusLabel(): string
    {
        return match ($this->status) {
            'pending_payment' => 'بانتظار الدفع',
            'confirmed' => 'مؤكد',
            'completed' => 'منتهي',
            'cancelled' => 'ملغى',
            default     => (string) ($this->status ?? ''),
        };
    }

    /** Arabic label for who cancelled — drives "تم الإلغاء بواسطة" on the card. */
    private function cancelledByLabel(): ?string
    {
        return match ($this->cancelled_by) {
            'customer' => 'العميل',
            'admin'    => 'الإدارة',
            'system'   => 'النظام',
            default    => null,
        };
    }

    /**
     * Deterministic 8-char confirmation code (e.g. "MM0001A3").
     * base36 keeps it short and unambiguous while staying stable per booking.
     */
    private function reference(): string
    {
        return 'MM' . strtoupper(str_pad(base_convert((string) $this->id, 10, 36), 6, '0', STR_PAD_LEFT));
    }
}
