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
        $count = 0;

        // Saved one at a time rather than by a mass UPDATE: completion is what
        // credits the partner's wallet, and a mass update fires no model events,
        // so every finished stay would silently go unpaid.
        Booking::query()
            ->where('status', Booking::STATUS_CONFIRMED)
            // end_date strictly before today = the guest has checked out.
            ->whereDate('end_date', '<', now()->toDateString())
            ->with('unit')
            ->chunkById(200, function ($bookings) use (&$count) {
                foreach ($bookings as $booking) {
                    $booking->update(['status' => Booking::STATUS_COMPLETED]);
                    $count++;
                }
            });

        $this->info("Marked {$count} booking(s) as completed.");

        return self::SUCCESS;
    }
}
