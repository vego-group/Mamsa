<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\PartnerLedgerEntry;
use App\Models\Payout;
use App\Services\PartnerWalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Rebuild the partner ledger on STAGING from the bookings that back it.
 *
 * Written as a command rather than run by hand for the reason the admin team
 * gave: a safety guard is right to refuse an improvised destructive statement
 * in a session, and the answer is not to find a way past it — it is to make the
 * work something the guard has no reason to object to. Reviewable, revertable,
 * repeatable, and it will be needed again the next time staging drifts.
 *
 * Every safeguard is in the code rather than in a promise:
 *
 *  - the environment is checked HERE, by name, and the command refuses to run
 *    anywhere else — including against a production database reached from a
 *    staging checkout;
 *  - `--confirm` is mandatory, and without it the command reports what it would
 *    destroy and stops;
 *  - the dump happens as part of the run, so it cannot be the step someone
 *    forgets;
 *  - a before/after summary is printed at the end.
 *
 * Earnings are re-posted from each booking's OWN frozen share, never from
 * today's rate — the point of the exercise is to make the ledger agree with the
 * bookings, not to restate them.
 *
 *   php artisan ledger:reseed-staging
 *   php artisan ledger:reseed-staging --confirm
 */
class ReseedStagingLedger extends Command
{
    protected $signature = 'ledger:reseed-staging
        {--confirm : Actually do it. Without this the command only reports.}
        {--force-env= : Override the environment guard. Requires the exact database name.}';

    protected $description = 'STAGING ONLY — wipe and rebuild partner ledger entries from booking shares';

    /**
     * Test databases, which are always safe to rebuild — a suite creates and
     * discards them, so there is no history to lose.
     *
     * Real environments come from config('database.reseed_allowlist'), set per
     * environment via RESEED_ALLOWED_DATABASES. They are deliberately NOT here:
     * a guard that needs a code change to cover a new environment is one people
     * route around, and this repository is public, so a database name does not
     * belong in it.
     */
    private const ALWAYS_SAFE = ['testing', ':memory:'];

    public function handle(PartnerWalletService $wallet): int
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (! $this->targetIsAllowed($database)) {
            $this->error('Refusing to run.');
            $this->line("  connection : ".DB::getDefaultConnection());
            $this->line("  database   : {$database}");
            $this->line("  app env    : ".app()->environment());
            $this->newLine();
            $this->line('This command destroys ledger history and is restricted to staging databases.');
            $this->line('Allowed here: '.(implode(', ', self::allowed()) ?: '(none configured)'));
            $this->line('Set RESEED_ALLOWED_DATABASES for this environment, or pass --force-env=<exact database name>.');

            return self::FAILURE;
        }

        $this->info('Target confirmed');
        $this->line("  connection : ".DB::getDefaultConnection());
        $this->line("  database   : {$database}");
        $this->line("  app env    : ".app()->environment());
        $this->newLine();

        $before = $this->snapshot();
        $this->summary('BEFORE', $before);

        if (! $this->option('confirm')) {
            $this->newLine();
            $this->warn('Dry run — nothing was touched. Re-run with --confirm to proceed.');

            return self::SUCCESS;
        }

        $dump = $this->dump();
        $this->newLine();
        $this->info("Dump written: {$dump}");

        DB::transaction(function () {
            PartnerLedgerEntry::query()->delete();
            Payout::query()->delete();
            Booking::query()->whereNotNull('payout_id')->update(['payout_id' => null]);
        });

        $this->line('Ledger, payouts and booking payout links cleared.');

        // Re-post from the frozen share on each completed booking.
        //
        // Skips are COUNTED and listed. A booking with no partner, or with no
        // share to credit, produces no entry — and a silent skip here is the
        // same fault as a silent skip in the consistency check: the totals look
        // clean while a row quietly contributed nothing.
        $posted  = 0;
        $skipped = [];

        foreach (Booking::where('status', Booking::STATUS_COMPLETED)->with('unit')->cursor() as $booking) {
            if ($wallet->recordEarning($booking)) {
                $posted++;

                continue;
            }

            $skipped[] = [
                $booking->id,
                $booking->unit?->user_id ?? '— no partner —',
                number_format((float) ($booking->subtotal ?? 0), 2),
                number_format((float) ($booking->partner_share ?? 0), 2),
            ];
        }

        $eligible = $posted + count($skipped);
        $this->line("Re-posted {$posted} / {$eligible} completed booking(s) from frozen shares.");

        if ($skipped !== []) {
            $this->newLine();
            $this->warn(count($skipped).' completed booking(s) produced no entry:');
            $this->table(['booking', 'partner', 'subtotal', 'frozen share'], $skipped);
            $this->warn('A booking with no partner or a zero share credits nothing. If any of these');
            $this->warn('are unexpected, the ledger is short by that much and the rows need a look.');
        }

        $this->newLine();
        $this->summary('AFTER', $this->snapshot());

        $this->newLine();
        $this->info('Done. A payout scenario still needs building — see ledger:seed-payout-scenario.');

        return self::SUCCESS;
    }

    private function targetIsAllowed(string $database): bool
    {
        if (app()->isProduction()) {
            return false;
        }

        $forced = (string) $this->option('force-env');

        if ($forced !== '') {
            // Still has to name the live database exactly, so a wrong guess is
            // a refusal rather than a blind override.
            return $forced === $database;
        }

        return in_array($database, self::allowed(), true);
    }

    /**
     * Test databases plus whatever this environment has configured.
     *
     * @return list<string>
     */
    private static function allowed(): array
    {
        $configured = (array) config('database.reseed_allowlist', []);

        return array_values(array_unique(array_merge(self::ALWAYS_SAFE, $configured)));
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'entries'    => PartnerLedgerEntry::count(),
            'earning'    => (float) PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_EARNING)->sum('amount'),
            'payout'     => (float) PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_PAYOUT)->sum('amount'),
            'adjustment' => (float) PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_ADJUSTMENT)->sum('amount'),
            'payouts'    => Payout::count(),
            'balance'    => (float) PartnerLedgerEntry::sum('amount'),
        ];
    }

    /** @param array<string, mixed> $s */
    private function summary(string $label, array $s): void
    {
        $this->line("{$label}");
        $this->table(
            ['entries', 'earning', 'payout', 'adjustment', 'payouts', 'net balance'],
            [[
                $s['entries'],
                number_format($s['earning'], 2),
                number_format($s['payout'], 2),
                number_format($s['adjustment'], 2),
                $s['payouts'],
                number_format($s['balance'], 2),
            ]],
        );
    }

    /** Write the three tables to storage before anything is deleted. */
    private function dump(): string
    {
        $path = 'ledger-dumps/'.now()->format('Ymd-His').'.json';

        Storage::disk('local')->put($path, (string) json_encode([
            'taken_at'                => now()->toIso8601String(),
            'database'                => DB::connection()->getDatabaseName(),
            'partner_ledger_entries'  => PartnerLedgerEntry::orderBy('id')->get()->toArray(),
            'payouts'                 => Payout::orderBy('id')->get()->toArray(),
            'booking_payout_links'    => Booking::whereNotNull('payout_id')
                ->get(['id', 'payout_id'])->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return Storage::disk('local')->path($path);
    }
}
