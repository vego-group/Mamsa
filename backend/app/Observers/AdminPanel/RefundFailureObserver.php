<?php

declare(strict_types=1);

namespace App\Observers\AdminPanel;

use App\Models\Refund;
use App\Observers\AdminPanel\Concerns\NotifiesAdmins;

/**
 * A refund landing in `failed` (gateway decline / webhook) raises a "refund
 * failed" in the admin feed so it can be retried — BACKEND_SPEC §5.11.
 */
class RefundFailureObserver
{
    use NotifiesAdmins;

    public function created(Refund $refund): void
    {
        if ($refund->status === 'failed') {
            $this->announce($refund);
        }
    }

    public function updated(Refund $refund): void
    {
        if ($refund->wasChanged('status') && $refund->status === 'failed') {
            $this->announce($refund);
        }
    }

    private function announce(Refund $refund): void
    {
        $this->notifyAdmins(
            'refund',
            'فشل استرداد',
            'تعذّر تنفيذ استرداد للحجز رقم '.$refund->booking_id,
            ['cancellation_id' => $refund->booking_id],
        );
    }
}
