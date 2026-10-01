<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Booking;
use App\Services\PartnerWalletService;

/**
 * Credits the partner's wallet when a stay finishes — wallet contract §5.
 *
 * Hung off the model rather than the daily command so that every route to
 * `completed` pays out: the scheduled sweep, an admin correcting a status by
 * hand, and anything added later. {@see PartnerWalletService::recordEarning()}
 * is idempotent per booking, so overlapping paths cannot pay twice.
 */
class BookingEarningObserver
{
    public function __construct(private readonly PartnerWalletService $wallet) {}

    public function updated(Booking $booking): void
    {
        if ($booking->wasChanged('status') && $booking->status === Booking::STATUS_COMPLETED) {
            $this->credit($booking);
        }
    }

    /** A booking created already-completed (backfills, imports) still earns. */
    public function created(Booking $booking): void
    {
        if ($booking->status === Booking::STATUS_COMPLETED) {
            $this->credit($booking);
        }
    }

    /**
     * A failure here must not roll back the status change: the stay really did
     * end, and a stuck booking is worse than a missing ledger row, which the
     * idempotent reconcile picks up on the next pass.
     */
    private function credit(Booking $booking): void
    {
        try {
            $this->wallet->recordEarning($booking->loadMissing('unit'));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
