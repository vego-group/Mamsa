<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Booking state machine (SRS 5.2): Confirmed → Completed once the stay ends.
 * Runs daily; marks every confirmed booking whose checkout date has passed.
 */
class CompletePastBookings extends Command
{
    protected $signature = 'bookings:complete';

    protected $description = 'Mark confirmed bookings whose stay has ended as completed';

    public function handle(): int
    {
        // end_date strictly before today = the guest has checked out.
        $count = Booking::query()
            ->where('status', Booking::STATUS_CONFIRMED)
            ->whereDate('end_date', '<', now()->toDateString())
            ->update(['status' => Booking::STATUS_COMPLETED]);

        $this->info("Marked {$count} booking(s) as completed.");

        return self::SUCCESS;
    }
}
