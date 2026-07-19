<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Notifications\CheckinReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Email task doc §3 — day-before check-in reminder (address + check-in time).
 * Scheduled daily at 10:00 Asia/Riyadh. Idempotent: each booking is marked
 * (checkin_reminder_sent_at) the moment its send succeeds, and the query
 * only ever selects unmarked rows — double runs send nothing twice.
 */
class SendCheckinReminders extends Command
{
    protected $signature = 'bookings:checkin-reminders';

    protected $description = 'Email confirmed guests whose check-in is tomorrow (Asia/Riyadh)';

    public function handle(): int
    {
        // "Tomorrow" in the guests' timezone, not the server's.
        $tomorrow = Carbon::now('Asia/Riyadh')->addDay()->toDateString();

        $due = Booking::query()
            ->where('status', Booking::STATUS_CONFIRMED)
            ->whereDate('start_date', $tomorrow)
            ->whereNull('checkin_reminder_sent_at')
            ->with(['user', 'unit'])
            ->get();

        $sent = 0;

        foreach ($due as $booking) {
            try {
                $booking->user?->notify(new CheckinReminder($booking));

                // Mark only after a successful dispatch so a failed send is
                // retried on the next run instead of being silently lost.
                $booking->forceFill(['checkin_reminder_sent_at' => now()])->save();
                $sent++;
            } catch (\Throwable $e) {
                report($e);
                $this->warn("booking {$booking->id}: ".$e->getMessage());
            }
        }

        $this->info("check-in reminders: {$sent}/{$due->count()} sent for {$tomorrow}");

        return self::SUCCESS;
    }
}
