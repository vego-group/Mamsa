<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\PartnerLedgerEntry;
use App\Services\PartnerWalletService;
use Illuminate\Console\Command;

/**
 * Freeze Mamsa's 2% onto bookings that never captured it.
 *
 * Bookings taken before `commission_amount` was populated carry no commission
 * at all, and the partner_share backfill therefore computed
 * `subtotal - 0 = subtotal` — crediting the partner the FULL net base with no
 * commission deducted. Every report has meanwhile *imputed* 2% at query time,
 * so the reported commission and the money actually owed disagreed, and the
 * four report tiles could not be made to reconcile.
 *
 * Freezing the same 2% the reports already imputed makes the stored data say
 * what every surface has been showing, and lets all of them read one source.
 *
 * Deliberately skips bookings already attached to a payout: that money has
 * moved, the payout's amount is frozen against those rows, and rewriting their
 * share would falsify a completed transfer.
 *
 * Where an earning already reached the ledger, a compensating adjustment is
 * posted so the wallet balance stays exactly the sum of its rows.
 */
class FreezeLegacyCommission extends Command
{
    protected $signature = 'bookings:freeze-commission {--dry-run : Report what would change and write nothing}';

    protected $description = 'Capture the 2% commission on bookings that predate the frozen column';

    public function handle(PartnerWalletService $wallet): int
    {
        $dry = (bool) $this->option('dry-run');

        $query = Booking::query()
            ->whereIn('status', Booking::REVENUE_STATUSES)
            ->where('subtotal', '>', 0)
            ->whereNull('payout_id')                       // never rewrite paid money
            ->where(fn ($q) => $q->whereNull('commission_amount')->orWhere('commission_amount', 0));

        $count      = 0;
        $commission = 0.0;
        $adjusted   = 0;

        $query->with('unit')->chunkById(200, function ($bookings) use ($wallet, $dry, &$count, &$commission, &$adjusted) {
            foreach ($bookings as $booking) {
                $newCommission = round((float) $booking->subtotal * Booking::COMMISSION_RATE, 2);
                $newShare      = round((float) $booking->subtotal - $newCommission, 2);
                $delta         = round($newShare - (float) $booking->partner_share, 2);

                $count++;
                $commission += $newCommission;

                // Already credited? The difference has to be posted so the
                // ledger and the balance cannot drift apart. Counted in the dry
                // run too — predicting the wallet movement is the point of it.
                $earned = PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_EARNING)
                    ->where('ref_type', 'booking')->where('ref_id', (string) $booking->id)->exists();

                $needsAdjustment = $earned && abs($delta) >= 0.01 && $booking->unit?->user_id;

                if ($needsAdjustment) {
                    $adjusted++;
                }

                if ($dry) {
                    continue;
                }

                $booking->forceFill([
                    'commission_amount' => $newCommission,
                    'partner_share'     => $newShare,
                ])->saveQuietly();

                if ($needsAdjustment) {
                    $wallet->post(
                        partnerUserId: $booking->unit->user_id,
                        type: PartnerLedgerEntry::TYPE_ADJUSTMENT,
                        amount: $delta,
                        refType: 'booking',
                        refId: (string) $booking->id,
                        refCode: $booking->code ?: (string) $booking->id,
                        description: 'تصحيح عمولة الحجز '.($booking->code ?: $booking->id),
                    );
                }
            }
        });

        $this->info(sprintf(
            '%s %d booking(s); commission captured %.2f SAR; %d wallet adjustment(s).',
            $dry ? 'Would update' : 'Updated', $count, $commission, $adjusted,
        ));

        return self::SUCCESS;
    }
}
