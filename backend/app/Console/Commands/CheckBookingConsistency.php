<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ask the money columns whether they still add up.
 *
 * Every guard on the split is now at write time: the column defaults are gone,
 * so a booking cannot be created without an explicit rate, commission and
 * share. That closes the hole, but it also removed the only signal that a
 * broken row exists — and, as the admin team pointed out, no read-time test can
 * tell a legitimate zero commission from one that was never written. If a row
 * does break, nothing would say so.
 *
 * So this asks the data directly instead of waiting for a symptom:
 *
 *     commission_amount + partner_share === subtotal
 *
 * That covers a CLASS of faults rather than the one we happened to find — an
 * unfrozen commission, a share computed against `total_amount` instead of the
 * subtotal (which quietly takes 15% too much, because it charges on the VAT), a
 * partial write, or something not yet imagined. Had it existed earlier it would
 * have caught the six `total_amount` sites without anyone going looking.
 *
 * Exits non-zero when anything is wrong, so CI can gate on it.
 *
 *   php artisan bookings:check-consistency
 *   php artisan bookings:check-consistency --fix-dry-run
 */
class CheckBookingConsistency extends Command
{
    protected $signature = 'bookings:check-consistency
        {--tolerance=0.01 : Allowed rounding drift, in SAR}
        {--limit=50 : Maximum offending rows to print}';

    protected $description = 'Verify commission_amount + partner_share === subtotal on every booking';

    public function handle(): int
    {
        // The difference is rounded to 2dp before comparing: a drift of exactly
        // one halala arrives from floating point as 0.010000000000005, which
        // would fail a bare `> 0.01` and report healthy rows as broken.
        //
        // Inlined as a float literal, not a binding. PDO binds a float as a
        // STRING, and SQLite sorts every number below every string — so
        // `ABS(...) > ?` silently matched nothing under the test driver while
        // working on MySQL. A check that quietly passes is worse than no check,
        // and this one exists to run in CI.
        $tolerance = sprintf('%.6F', (float) $this->option('tolerance'));
        $limit     = (int) $this->option('limit');

        // Rows with no subtotal predate the price breakdown entirely; they have
        // no split to check and flagging them would be noise, not a finding.
        $scoped = Booking::query()->whereNotNull('subtotal')->where('subtotal', '>', 0);

        $total = (clone $scoped)->count();

        $broken = (clone $scoped)
            ->whereRaw("ROUND(ABS((commission_amount + partner_share) - subtotal), 2) > {$tolerance}")
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'subtotal', 'commission_rate', 'commission_amount', 'partner_share', 'status']);

        $brokenCount = (clone $scoped)
            ->whereRaw("ROUND(ABS((commission_amount + partner_share) - subtotal), 2) > {$tolerance}")
            ->count();

        // A rate and an amount that disagree is a separate fault: neither is
        // null, so the invariant above can still hold while the pair is wrong.
        $rateMismatch = (clone $scoped)
            ->whereRaw("ROUND(ABS(ROUND(subtotal * commission_rate, 2) - commission_amount), 2) > {$tolerance}")
            ->count();

        $this->line("checked {$total} booking(s) with a subtotal");

        if ($brokenCount === 0 && $rateMismatch === 0) {
            $this->info('✓ every row adds up: commission + partner share === subtotal');

            return self::SUCCESS;
        }

        if ($brokenCount > 0) {
            $this->error("✗ {$brokenCount} row(s) break commission + partner_share === subtotal");
            $this->table(
                ['id', 'subtotal', 'rate', 'commission', 'share', 'sum', 'diff', 'status'],
                $broken->map(fn (Booking $b) => [
                    $b->id,
                    number_format((float) $b->subtotal, 2),
                    (float) $b->commission_rate,
                    number_format((float) $b->commission_amount, 2),
                    number_format((float) $b->partner_share, 2),
                    number_format((float) $b->commission_amount + (float) $b->partner_share, 2),
                    number_format(((float) $b->commission_amount + (float) $b->partner_share) - (float) $b->subtotal, 2),
                    $b->status,
                ])->all(),
            );
        }

        if ($rateMismatch > 0) {
            $this->warn("⚠ {$rateMismatch} row(s) where commission_amount does not equal subtotal × commission_rate");
        }

        $this->newLine();
        $this->line('Repair a row that simply never froze with: php artisan bookings:freeze-commission');
        $this->line('Anything else needs a look before it is touched — the numbers are money.');

        return self::FAILURE;
    }
}
