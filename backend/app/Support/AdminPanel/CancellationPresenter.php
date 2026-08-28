<?php

declare(strict_types=1);

namespace App\Support\AdminPanel;

use App\Http\Controllers\AdminPanel\Concerns\MapsSpec;
use App\Models\Booking;

/**
 * Single source of truth for the admin-panel Cancellation shape
 * (BACKEND_SPEC §5.9, §6). Reused by CancellationsController and the dashboard's
 * recentHostCancellations list. impact is negative (platform loss).
 */
class CancellationPresenter
{
    use MapsSpec;

    /** @return array<string, mixed> Cancellation — expects user, unit.owner, payment, refunds loaded. */
    public function row(Booking $b): array
    {
        $refunded = (float) ($b->payment?->refunded_amount ?? 0);

        return [
            'id'           => (string) $b->id,
            'bookingId'    => (string) $b->id,
            'bookingCode'  => $this->code('BKG', $b->id, 4),
            'guestName'    => $b->user?->name ?? '',
            'cancelledBy'  => $b->cancelled_by === 'customer' ? 'guest' : 'host',
            'unitName'     => $b->unit?->unit_name ?? '',
            'partnerId'    => (string) ($b->unit?->user_id ?? ''),
            'partnerName'  => $b->unit?->owner?->name ?? '',
            'at'           => $this->iso($b->cancelled_at),
            'reason'       => $b->cancellation_reason ?? '',
            'bookingTotal' => $this->money($b->total_amount),
            'refundAmount' => $this->money($refunded),
            // Lost commission = frozen amount, else 2% of subtotal (historical bookings have no frozen value).
            'impact'       => -$this->money((float) $b->commission_amount),
            'refundStatus' => $this->refundStatusOf($b, $refunded),
            'mamsaOwned'   => (bool) ($b->unit?->mamsa_owned ?? false),
        ];
    }

    /** A failed refund row wins; otherwise derive from the refunded amount vs total. */
    public function refundStatusOf(Booking $b, float $refunded): string
    {
        if ($b->relationLoaded('refunds') && $b->refunds->contains(fn ($r) => $r->status === 'failed')) {
            return 'failed';
        }

        return $this->refundStatus($refunded, (float) $b->total_amount);
    }
}
