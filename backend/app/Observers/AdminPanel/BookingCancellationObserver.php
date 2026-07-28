<?php

declare(strict_types=1);

namespace App\Observers\AdminPanel;

use App\Models\Booking;
use App\Observers\AdminPanel\Concerns\NotifiesAdmins;

/**
 * A host-side cancellation (cancelled_by ≠ customer) raises a "host cancellation"
 * in the admin feed — BACKEND_SPEC §5.11. Guest cancellations are ignored.
 */
class BookingCancellationObserver
{
    use NotifiesAdmins;

    public function updated(Booking $booking): void
    {
        if (! $booking->wasChanged('status') || $booking->status !== Booking::STATUS_CANCELLED) {
            return;
        }
        if ($booking->cancelled_by === null || $booking->cancelled_by === 'customer') {
            return; // guest cancellation
        }

        $this->notifyAdmins(
            'cancellation',
            'إلغاء من المضيف',
            'ألغى المضيف حجز "'.($booking->unit?->unit_name ?? '—').'"',
            ['cancellation_id' => $booking->id],
        );
    }
}
