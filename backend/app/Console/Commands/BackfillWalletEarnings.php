<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\PartnerWalletService;
use Illuminate\Console\Command;

/**
 * Credit stays that finished BEFORE the wallet existed.
 *
 * Without this a partner with completed bookings opens the wallet to a zero
 * balance while the reports screen shows the revenue those same stays earned —
 * two screens disagreeing about whether the partner has been paid.
 *
 * Safe to re-run: recordEarning() is idempotent per booking. Entries are dated
 * at the stay's checkout so the ledger reads in the order the money was
 * actually earned, and balance_after accumulates correctly.
 */
class BackfillWalletEarnings extends Command
{
    protected $signature = 'wallet:backfill-earnings {--dry-run : Report what would be credited and write nothing}';

    protected $description = 'Write earning ledger rows for bookings completed before the wallet shipped';

    public function handle(PartnerWalletService $wallet): int
    {
        $dry     = (bool) $this->option('dry-run');
        $credited = 0;
        $total    = 0.0;

        Booking::query()
            ->where('status', Booking::STATUS_COMPLETED)
            ->where('partner_share', '>', 0)
            ->with('unit')
            // Oldest first: the running balance_after must accumulate in the
            // order the stays ended, not the order the rows were found.
            ->orderBy('end_date')->orderBy('id')
            ->chunkById(200, function ($bookings) use ($wallet, $dry, &$credited, &$total) {
                foreach ($bookings as $booking) {
                    if ($wallet->alreadyEarned($booking)) {
                        continue;
                    }

                    $this->line(sprintf(
                        '  %s  %s  %.2f SAR',
                        $booking->end_date?->toDateString() ?? '—',
                        $booking->code ?: $booking->id,
                        (float) $booking->partner_share,
                    ));

                    if (! $dry) {
                        $wallet->recordEarning($booking, $booking->end_date);
                    }

                    $credited++;
                    $total += (float) $booking->partner_share;
                }
            });

        $this->info(sprintf(
            '%s %d booking(s), %.2f SAR.',
            $dry ? 'Would credit' : 'Credited',
            $credited,
            $total,
        ));

        return self::SUCCESS;
    }
}
