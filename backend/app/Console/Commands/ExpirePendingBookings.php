<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Booking state machine (SRS 5.2): Pending → Cancelled when payment never came.
 * An unpaid pending booking blocks its dates for everyone (availability counts
 * pending + confirmed), so abandoned checkouts must be released automatically.
 */
class ExpirePendingBookings extends Command
{
    protected $signature = 'bookings:expire-pending {--minutes=60 : Age before an unpaid pending booking expires}';

    protected $description = 'Cancel pending bookings that were never paid, releasing their dates';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $count = Booking::query()
            ->where('status', Booking::STATUS_PENDING)
            ->where('created_at', '<=', $cutoff)
            // Never expire a booking whose money side is settled or still moving:
            // paid = webhook/verify will confirm it; a Moyasar-attached payment
            // touched in the last 15 min = a 3-DS redirect may still land.
            ->whereDoesntHave('payment', function ($q) {
                $q->where('payment_status', 'paid')
                    ->orWhere(function ($q) {
                        $q->whereNotNull('moyasar_id')
                            ->where('updated_at', '>', now()->subMinutes(15));
                    });
            })
            ->update([
                'status'              => Booking::STATUS_CANCELLED,
                'cancelled_at'        => now(),
                'cancelled_by'        => 'system',
                'cancellation_reason' => 'انتهت مهلة إتمام الدفع',
            ]);

        $this->info("Expired {$count} unpaid pending booking(s).");

        return self::SUCCESS;
    }
}
