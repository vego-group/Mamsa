<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Payout;
use App\Services\PartnerWalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A transfer that bounced — contract §3.
 *
 * The payout record survives as `reversed` (a partner must still be able to see
 * that the attempt happened, and the bank reference behind it); the money
 * returns to the balance as an adjustment credit.
 *
 * The covered bookings are DETACHED so the same earnings can be paid again in
 * the next run. Without that the credit would sit in the balance with no
 * bookings to back it, and the next payout — whose amount is the sum of unpaid
 * bookings — could never move it.
 *
 * No admin endpoint: reversal is rare, irreversible, and not in the frontend
 * contract. It stays a deliberate operator action.
 */
class ReversePayout extends Command
{
    protected $signature = 'payouts:reverse {reference : the payout reference, e.g. PO-2026-08-0001}
                            {--reason= : Arabic, shown to the partner}';

    protected $description = 'Reverse a bounced transfer and return the money to the partner balance';

    public function handle(PartnerWalletService $wallet): int
    {
        $payout = Payout::where('reference', $this->argument('reference'))->first();

        if (! $payout) {
            $this->error('No payout with that reference.');

            return self::FAILURE;
        }

        if ($payout->status === Payout::STATUS_REVERSED) {
            $this->warn('Already reversed — nothing to do.');

            return self::SUCCESS;
        }

        $reason = $this->option('reason') ?: 'تم إرجاع الحوالة من البنك';

        $this->line(sprintf(
            'Reversing %s — %.2f SAR across %d booking(s) for partner %d.',
            $payout->reference, $payout->amount, $payout->bookings_count, $payout->partner_user_id,
        ));

        if (! $this->confirm('Proceed?', false)) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($payout, $wallet, $reason) {
            $payout->update([
                'status'          => Payout::STATUS_REVERSED,
                'reversed_at'     => now(),
                'reversal_reason' => $reason,
            ]);

            Booking::where('payout_id', $payout->id)->update(['payout_id' => null]);

            $wallet->recordPayoutReversal($payout, $reason);
        });

        $this->info('Reversed. The balance is restored and those stays are payable again.');

        return self::SUCCESS;
    }
}
