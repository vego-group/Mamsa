<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\PartnerLedgerEntry;
use App\Models\User;
use App\Notifications\LedgerCheckFailed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
        {--limit=50 : Maximum offending rows to print}
        {--alert : Notify super admins when something is found. For the scheduler.}';

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
        //
        // They are COUNTED and reported all the same. A silent skip is the same
        // shape as the bug this command exists to catch: "67/67 pass" says
        // nothing about how many rows were never looked at, so a migration that
        // emptied half the subtotals would report a clean bill of health on the
        // remaining half.
        $scoped = Booking::query()->whereNotNull('subtotal')->where('subtotal', '>', 0);

        $all     = Booking::query()->count();
        $total   = (clone $scoped)->count();
        $skipped = $all - $total;

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

        $this->line("checked {$total} / {$all} booking(s)   skipped {$skipped}");

        if ($skipped > 0) {
            $this->warn("⚠ {$skipped} booking(s) skipped for having no subtotal — they carry no split to verify.");
            $this->warn('  If that number is unexpected, the rows are worth a look before trusting the result below.');
        }

        // Ledger COVERAGE — a separate invariant from the arithmetic above.
        //
        // A row can add up perfectly and still never have been credited. That is
        // not hypothetical: staging bookings 66 and 67 sat `completed` with a
        // correct 1,296.00 share and no earning entry for three days, because
        // they were created already-completed while partner_share still defaulted
        // to 0. recordEarning() returns null on a zero share — correctly, but
        // SILENTLY — and the observer only fires on creation or a status change,
        // so when the share was filled in later by a saveQuietly() backfill,
        // nothing ever went back to post the entry it now owed.
        //
        // Nothing checked for it. The arithmetic check passed, the totals looked
        // clean, and one partner was quietly short 2,592.00 SAR.
        [$uncredited, $uncreditedRows, $zeroShare] = $this->ledgerCoverage($limit);

        if ($brokenCount === 0 && $rateMismatch === 0 && $uncredited === 0) {
            $this->info('✓ every checked row adds up: commission + partner share === subtotal');
            $this->info('✓ every completed booking with a share has an earning entry');

            if ($zeroShare > 0) {
                $this->warn("⚠ {$zeroShare} completed booking(s) carry no share to credit — correctly uncredited, but worth knowing.");
            }

            return self::SUCCESS;
        }

        if ($uncredited > 0) {
            $this->error("✗ {$uncredited} completed booking(s) owe a share that never reached the ledger");
            $this->table(
                ['booking', 'share', 'partner', 'status'],
                $uncreditedRows->map(fn (Booking $b) => [
                    $b->id,
                    number_format((float) $b->partner_share, 2),
                    $b->unit?->user_id ?? '—',
                    $b->status,
                ])->all(),
            );
            $this->line('  Repair with: php artisan wallet:backfill-earnings');
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

        // Scheduled runs alert; a human running this at a terminal is already
        // looking at the output and does not need an email about it.
        if ($this->option('alert')) {
            $this->alertAdmins($uncredited, $brokenCount, $rateMismatch);
        }

        $this->newLine();
        $this->line('Repair a row that simply never froze with: php artisan bookings:freeze-commission');
        $this->line('Anything else needs a look before it is touched — the numbers are money.');

        return self::FAILURE;
    }

    /**
     * Tell the super admins. A finding that reaches only a log file is read a
     * week later, which for a money fault is the same as not finding it.
     *
     * Failure to notify must NOT change the command's exit status: the check
     * did its job, and a broken mail transport is a separate problem from a
     * broken ledger.
     */
    private function alertAdmins(int $uncredited, int $brokenSplit, int $rateMismatch): void
    {
        try {
            $admins = User::role('SuperAdmin')->where('is_active', true)->get();

            if ($admins->isEmpty()) {
                $this->warn('⚠ --alert was passed but no active super admin exists to notify.');

                return;
            }

            Notification::send($admins, new LedgerCheckFailed($uncredited, $brokenSplit, $rateMismatch));
            $this->line("  alerted {$admins->count()} super admin(s)");
        } catch (\Throwable $e) {
            report($e);
            $this->warn('⚠ could not send the alert: '.$e->getMessage());
        }
    }

    /**
     * Completed bookings whose partner share never became a ledger entry.
     *
     * A share of zero is EXCLUDED from the fault and counted separately: those
     * are correctly uncredited (there is nothing to credit), but a growing
     * number of them is its own smell, so the count is reported rather than
     * dropped.
     *
     * @return array{0:int,1:\Illuminate\Support\Collection<int,Booking>,2:int}
     */
    private function ledgerCoverage(int $limit): array
    {
        $owed = Booking::query()
            ->where('status', Booking::STATUS_COMPLETED)
            ->where('partner_share', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('partner_ledger_entries')
                    ->where('type', PartnerLedgerEntry::TYPE_EARNING)
                    ->where('ref_type', 'booking')
                    ->whereColumn('ref_id', 'bookings.id');
            });

        $zeroShare = Booking::query()
            ->where('status', Booking::STATUS_COMPLETED)
            ->where(fn ($q) => $q->whereNull('partner_share')->orWhere('partner_share', '<=', 0))
            ->count();

        return [
            (clone $owed)->count(),
            (clone $owed)->with('unit')->orderBy('id')->limit($limit)->get(),
            $zeroShare,
        ];
    }
}
