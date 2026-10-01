<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\DashboardUpload;
use App\Models\PartnerDetail;
use App\Models\PartnerLedgerEntry;
use App\Models\PartnerWallet;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\Refund;
use App\Models\Unit;
use App\Models\UnitImage;
use App\Models\User;
use App\Support\Documents\DocumentStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Remove everything the pre-launch live test wrote to PRODUCTION, and prove it.
 *
 * The rule this command exists to enforce: **every row is deleted by an id
 * recorded when it was created, never by a name match or a date range.** A
 * `LIKE '%PRELAUNCH%'` can find a real listing somebody named badly; a recorded
 * id cannot. So the command reads a manifest written by the test as it ran, and
 * refuses to do anything without one.
 *
 * The name check still happens — but as an ASSERTION, not a selector. If the
 * unit the manifest points at is not the test unit, the manifest and the
 * database disagree about reality, and the only safe move is to stop.
 *
 * **The dry run is the real run, rolled back.** An earlier version listed the
 * tables it would touch in a separate method, and that list drifted: three
 * tables the purge deleted from were missing from it, so the thing under review
 * was not the thing that would happen. There is now one code path, and
 * `--dry-run` differs only in a rollback and in not touching the disk.
 *
 * Usage:
 *   php artisan prelaunch:cleanup --dry-run     # rolled back, nothing removed
 *   php artisan prelaunch:cleanup               # the real thing
 */
class PrelaunchCleanup extends Command
{
    protected $signature = 'prelaunch:cleanup
        {--dry-run : Run the whole purge in a transaction and roll it back}
        {--manifest=prelaunch/manifest.json : Path under storage/app}';

    protected $description = 'Delete the pre-launch live test rows and files, by recorded id, and verify the counts return to baseline';

    /** The marker the test unit must carry. Checked, never searched by. */
    private const UNIT_MARKER = 'PRELAUNCH-TEST';

    /** Every table whose count must return to baseline. */
    private const COUNTED = [
        'units', 'bookings', 'payments', 'refunds',
        'permits', 'users', 'notifications', 'dashboard_uploads',
    ];

    /** @var array<string, int> rows removed, per table */
    private array $deleted = [];

    /** @var array<int, array{path: string, why: string}> files the purge found orphaned */
    private array $files = [];

    /** @var array<int, string> lines that are not row counts (the wallet) */
    private array $notes = [];

    /** @var array<string, string> path of each upload, read before its row goes */
    private array $uploadPaths = [];

    public function handle(): int
    {
        // Artisan resolves a command ONCE per process, so a second invocation
        // reuses this object. Without the reset a dry run followed by the real
        // run reports every number doubled — which is precisely the state the
        // dry run exists to let someone trust. Found by the parity test.
        $this->deleted = [];
        $this->files = [];
        $this->notes = [];
        $this->uploadPaths = [];

        $manifest = $this->manifest();

        if ($manifest === null) {
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        $this->line('');
        $this->info($dry ? '=== DRY RUN — every change is rolled back ===' : '=== DELETING ===');
        $this->line('manifest written at: '.($manifest['created_at'] ?? '—'));
        $this->line('');

        if (! $this->assertSafe($manifest)) {
            return self::FAILURE;
        }

        if ($dry) {
            DB::beginTransaction();

            try {
                $this->purge($manifest);
                $after = $this->counts();
            } finally {
                DB::rollBack();
            }

            $this->report();
            $this->reportFiles(apply: false);
            $this->verify($manifest, $after, dry: true);

            $this->line('');
            $this->info('Dry run only — the transaction was rolled back and no file was touched.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->purge($manifest));

        // Files go AFTER the rows commit: an upload is safe to remove only once
        // nothing points at it, and our own rows were the things pointing.
        $this->report();
        $this->reportFiles(apply: true);

        return $this->verify($manifest, $this->counts()) ? self::SUCCESS : self::FAILURE;
    }

    /* ---------- the manifest ---------- */

    /** @return array<string, mixed>|null */
    private function manifest(): ?array
    {
        $path = storage_path('app/'.$this->option('manifest'));

        if (! is_file($path)) {
            $this->error("No manifest at {$path}.");
            $this->line('This command deletes by recorded id and will not guess. Nothing done.');

            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! isset($data['unit_id'], $data['baseline'])) {
            $this->error('Manifest is unreadable or missing unit_id / baseline. Nothing done.');

            return null;
        }

        return $data;
    }

    /* ---------- the guards ---------- */

    /** @param array<string, mixed> $m */
    private function assertSafe(array $m): bool
    {
        $unit = Unit::find($m['unit_id']);

        if (! $unit) {
            $this->warn("Unit #{$m['unit_id']} is already gone — continuing with the rest.");
        } elseif (! str_contains((string) $unit->unit_name, self::UNIT_MARKER)) {
            $this->error("REFUSING: unit #{$unit->id} is \"{$unit->unit_name}\" — it does not carry ".self::UNIT_MARKER.'.');
            $this->line('The manifest and the database disagree about which unit this is. Stop and look.');

            return false;
        }

        // permits are removed at UNIT scope. A group here would mean the test
        // somehow produced a building, and a group-scoped permit covers doors
        // that are not ours. Stop rather than guess which rows are safe.
        if ($unit && $unit->unit_group_id) {
            $group = Permit::where('scope_type', Permit::SCOPE_GROUP)
                ->where('scope_id', (string) $unit->unit_group_id)->count();

            $this->error("REFUSING: unit #{$unit->id} belongs to group {$unit->unit_group_id}"
                .($group > 0 ? ", and {$group} group-scoped permit row(s) exist." : '.'));
            $this->line('The test creates no buildings. A group here means something else happened.');

            return false;
        }

        if (! empty($m['booking_id'])) {
            $booking = Booking::find($m['booking_id']);

            if ($booking && (int) $booking->unit_id !== (int) $m['unit_id']) {
                $this->error("REFUSING: booking #{$booking->id} belongs to unit #{$booking->unit_id}, not #{$m['unit_id']}.");

                return false;
            }
        }

        $protected = array_map('intval', $m['baseline']['user_ids'] ?? []);

        foreach ($this->usersToDelete($m) as $id) {
            if (in_array((int) $id, $protected, true)) {
                $this->error("REFUSING: user #{$id} existed before the test and is not ours to delete.");

                return false;
            }

            $other = Booking::where('user_id', $id)
                ->when(! empty($m['booking_id']), fn ($q) => $q->where('id', '!=', $m['booking_id']))
                ->count();

            if ($other > 0) {
                $this->error("REFUSING: user #{$id} has {$other} booking(s) beyond the test booking.");

                return false;
            }
        }

        $this->info('✓ guards passed');

        return true;
    }

    /**
     * Users this run may remove: the ones the manifest says it created. A
     * partner account kept on purpose is simply not in this list.
     *
     * @param  array<string, mixed>  $m
     * @return array<int, int>
     */
    private function usersToDelete(array $m): array
    {
        $ids = [];

        foreach (['guest_user_id', 'partner_user_id'] as $key) {
            if (! empty($m[$key]) && ($m['created'][$key] ?? false)) {
                $ids[] = (int) $m[$key];
            }
        }

        if (($m['keep_partner_suspended'] ?? false) === true) {
            $ids = array_values(array_filter($ids, fn (int $id) => $id !== (int) ($m['partner_user_id'] ?? 0)));
        }

        return $ids;
    }

    /* ---------- the purge ---------- */

    /** @param array<string, mixed> $m */
    private function purge(array $m): void
    {
        $booking = $m['booking_id'] ?? null;
        $unit = (int) $m['unit_id'];
        $users = $this->usersToDelete($m);

        // Paths are read BEFORE their rows go: afterwards there is nothing to
        // read them from.
        $imagePaths = $this->imagePaths($unit);
        $uploadIds = array_values((array) ($m['upload_ids'] ?? []));
        $this->uploadPaths = $uploadIds === []
            ? []
            : DashboardUpload::whereIn('id', $uploadIds)->pluck('path', 'id')->all();

        if ($booking) {
            $this->drop('refunds', Refund::where('booking_id', $booking)->delete());
            $this->drop('payments', Payment::where('booking_id', $booking)->delete());
            $this->drop('partner_ledger_entries', $this->ledgerEntries($booking)->delete());
            $this->drop('notifications', $this->notifications($booking)->delete());
            $this->drop('bookings', Booking::where('id', $booking)->delete());
        }

        // Covers the UNIT as well as the booking: a review decision or an admin
        // edit on the test listing writes an audit row against the unit, and
        // deleting only the booking's rows would leave it behind.
        $this->drop('audit_logs', $this->auditLogs($booking, $unit)->delete());

        $this->restoreWallet($m);

        $this->drop('unit_images', UnitImage::where('unit_id', $unit)->delete());
        $this->drop('permits', Permit::where('scope_type', Permit::SCOPE_UNIT)->where('scope_id', (string) $unit)->delete());
        $this->drop('units', Unit::where('id', $unit)->delete());

        if ($uploadIds !== []) {
            $this->drop('dashboard_uploads', DashboardUpload::whereIn('id', $uploadIds)->delete());
        }

        if ($users !== []) {
            $this->drop('personal_access_tokens', DB::table('personal_access_tokens')->whereIn('tokenable_id', $users)->delete());
            $this->drop('refresh_tokens', DB::table('refresh_tokens')->whereIn('user_id', $users)->delete());
            $this->drop('partner_details', PartnerDetail::whereIn('user_id', $users)->delete());
            $this->drop('model_has_roles', DB::table('model_has_roles')->whereIn('model_id', $users)->delete());
            $this->drop('users', User::whereIn('id', $users)->delete());
        }

        if (($m['keep_partner_suspended'] ?? false) === true && ! empty($m['partner_user_id'])) {
            User::where('id', $m['partner_user_id'])->update(['is_active' => false]);
            $this->notes[] = 'partner account #'.$m['partner_user_id'].' kept and SUSPENDED (is_active = false)';
        }

        // The reference checks run HERE, with our rows already gone: anything
        // still pointing at these bytes belongs to somebody else, and stays.
        $this->collectFiles($imagePaths, $uploadIds);
    }

    /* ---------- files on disk ---------- */

    /**
     * Every path the test unit's photos occupy, originals and derivatives.
     *
     * Deleting the rows and leaving the bytes is the exact gap the 22/09
     * production cleanup had to go back and close. The row is the pointer; the
     * file is the thing that takes space and keeps being reachable by URL.
     *
     * @return array<int, string>
     */
    private function imagePaths(int $unitId): array
    {
        $paths = [];

        foreach (UnitImage::where('unit_id', $unitId)->get() as $image) {
            if (filled($image->path)) {
                $paths[] = (string) $image->path;
            }

            foreach ((array) ($image->getRawOriginal('variants') ? json_decode((string) $image->getRawOriginal('variants'), true) : []) as $variant) {
                if (is_string($variant) && filled($variant)) {
                    $paths[] = $variant;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<int, string>  $imagePaths
     * @param  array<int, string>  $uploadIds
     */
    private function collectFiles(array $imagePaths, array $uploadIds): void
    {
        $public = Storage::disk('public');

        foreach ($imagePaths as $path) {
            // Another listing may share the file. Checked now, after our rows
            // are gone, so "still referenced" means referenced by somebody else.
            $stillUsed = UnitImage::where('path', $path)
                ->orWhere('variants', 'like', '%'.str_replace('/', '\\/', $path).'%')
                ->exists();

            if (! $stillUsed && $public->exists($path)) {
                $this->files[] = ['path' => $path, 'why' => 'unit photo'];
            }
        }

        foreach ($uploadIds as $id) {
            // Read from the paths captured before the rows were deleted — by
            // now the row is gone, so the model can no longer tell us.
            $path = $this->uploadPaths[$id] ?? null;

            if (blank($path) || DocumentStorage::referencedAnywhere((string) $id)) {
                continue;
            }

            // The document may be on the public disk or in the vault — licence
            // PDFs were moved there, and a public-only check reports "nothing
            // to delete" for every one of them.
            [$disk] = DocumentStorage::locate((string) $path);

            if ($disk !== null) {
                $this->files[] = ['path' => (string) $path, 'why' => 'upload '.$id];
            }
        }
    }

    private function reportFiles(bool $apply): void
    {
        $this->line('');

        if ($this->files === []) {
            $this->line('files: none orphaned');

            return;
        }

        $this->info(($apply ? 'files deleted: ' : 'files that WOULD be deleted: ').count($this->files));

        foreach ($this->files as $file) {
            $this->line(sprintf('  %-60s %s', $file['path'], $file['why']));

            if ($apply) {
                DocumentStorage::delete($file['path']);
                Storage::disk('public')->delete($file['path']);
            }
        }
    }

    /* ---------- helpers ---------- */

    /**
     * The ledger references a booking through `ref_type` + `ref_id`, not a
     * `booking_id` column. This method existed as `where('booking_id', …)` and
     * every test passed: SQLite treats a double-quoted identifier it cannot
     * resolve as a STRING LITERAL, so the clause compared 'ref_id' to a number,
     * matched nothing and raised nothing. MySQL rejected it outright on the
     * first staging dry run.
     */
    private function ledgerEntries(int|string $bookingId): Builder
    {
        return PartnerLedgerEntry::query()
            ->where('ref_type', 'booking')
            ->where('ref_id', (string) $bookingId);
    }

    private function auditLogs(int|string|null $bookingId, int $unitId): Builder
    {
        return AuditLog::query()->where(function (Builder $q) use ($bookingId, $unitId) {
            $q->where(fn (Builder $w) => $w->where('auditable_type', Unit::class)->where('auditable_id', $unitId));

            if ($bookingId) {
                $q->orWhere(fn (Builder $w) => $w->where('auditable_type', Booking::class)->where('auditable_id', $bookingId));
            }
        });
    }

    private function notifications(int|string $bookingId): \Illuminate\Database\Query\Builder
    {
        return DB::table('notifications')->whereJsonContains('data->booking_id', (int) $bookingId);
    }

    /** @param array<string, mixed> $m */
    private function restoreWallet(array $m): void
    {
        $before = $m['wallet'] ?? null;

        if (! is_array($before) || ! isset($before['user_id'])) {
            return;
        }

        // The column is `partner_user_id`, not `user_id`. The manifest key stays
        // `user_id` because it means "whose wallet"; the query has to speak the
        // schema's language. Audited against MySQL's information_schema after
        // SQLite swallowed two of these silently.
        $wallet = PartnerWallet::where('partner_user_id', $before['user_id'])->first();

        if (! $wallet) {
            return;
        }

        if ($before['existed'] ?? false) {
            $wallet->update([
                'available_balance' => $before['available_balance'],
                'pending_balance' => $before['pending_balance'],
            ]);
            $this->notes[] = sprintf(
                'partner_wallets: row KEPT (it existed before the test), balance restored to available=%s pending=%s',
                $before['available_balance'], $before['pending_balance'],
            );
        } else {
            $this->drop('partner_wallets', (int) $wallet->delete());
            $this->notes[] = 'partner_wallets: row DELETED (the test created it)';
        }
    }

    private function drop(string $table, int|bool $count): void
    {
        $n = (int) $count;

        if ($n > 0) {
            $this->deleted[$table] = ($this->deleted[$table] ?? 0) + $n;
        }
    }

    /* ---------- reporting ---------- */

    private function report(): void
    {
        if ($this->deleted === [] && $this->notes === []) {
            $this->line('  (nothing to remove)');

            return;
        }

        foreach ($this->deleted as $table => $n) {
            $this->line(sprintf('  %-26s %d', $table, $n));
        }

        if ($this->deleted !== []) {
            $this->line(sprintf('  %-26s %d', 'TOTAL ROWS', array_sum($this->deleted)));
        }

        foreach ($this->notes as $note) {
            $this->line('  · '.$note);
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'units' => Unit::count(),
            'bookings' => Booking::count(),
            'payments' => Payment::count(),
            'refunds' => Refund::count(),
            'permits' => Permit::count(),
            'users' => User::count(),
            'notifications' => DB::table('notifications')->count(),
            'dashboard_uploads' => DashboardUpload::count(),
        ];
    }

    /**
     * Every counted table must come back to exactly what it was. Anything else
     * is a failure — a leftover row on production is what this exists to make
     * impossible.
     *
     * @param  array<string, mixed>  $m
     * @param  array<string, int>  $after
     */
    private function verify(array $m, array $after, bool $dry = false): bool
    {
        $this->line('');
        $this->info($dry ? '=== counts the real run WOULD leave ===' : '=== counts after cleanup ===');

        $ok = true;

        foreach (self::COUNTED as $table) {
            $count = $after[$table] ?? 0;
            $want = $m['baseline'][$table] ?? null;

            if ($want === null) {
                $this->line(sprintf('  %-18s %d  (no baseline recorded)', $table, $count));

                continue;
            }

            // A partner account kept on purpose is a declared, expected drift.
            $want = (int) $want;
            if ($table === 'users' && ($m['keep_partner_suspended'] ?? false) === true && ! empty($m['partner_user_id'])) {
                $want++;
            }

            $match = $count === $want;
            $ok = $ok && $match;

            $this->line(sprintf('  %-18s %d  (expected %d)  %s', $table, $count, $want, $match ? '✓' : '✗ MISMATCH'));
        }

        $this->line('');

        if ($ok) {
            $this->info('✓ every count is back to baseline.');
        } elseif (! $dry) {
            $this->error('✗ a count did not return to baseline. STOP and report before doing anything else.');
        } else {
            $this->error('✗ a count would NOT return to baseline. Fix this before running for real.');
        }

        return $ok;
    }
}
