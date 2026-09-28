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
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
 * unit the manifest points at is not the test unit, that means the manifest and
 * the database disagree about reality, and the only safe move is to stop and
 * let a human look.
 *
 * Usage:
 *   php artisan prelaunch:cleanup --dry-run     # counts only, deletes nothing
 *   php artisan prelaunch:cleanup               # the real thing
 */
class PrelaunchCleanup extends Command
{
    protected $signature = 'prelaunch:cleanup
        {--dry-run : Report what would be deleted and delete nothing}
        {--manifest=prelaunch/manifest.json : Path under storage/app}';

    protected $description = 'Delete the pre-launch live test rows, by recorded id, and verify the counts return to baseline';

    /** The marker the test unit must carry. Checked, never searched by. */
    private const UNIT_MARKER = 'PRELAUNCH-TEST';

    /** @var array<string, int> */
    private array $deleted = [];

    public function handle(): int
    {
        $manifest = $this->manifest();

        if ($manifest === null) {
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        $this->line('');
        $this->info($dry ? '=== DRY RUN — nothing will be deleted ===' : '=== DELETING ===');
        $this->line('manifest written at: '.($manifest['created_at'] ?? '—'));
        $this->line('');

        if (! $this->assertSafe($manifest)) {
            return self::FAILURE;
        }

        if ($dry) {
            $this->report($this->plan($manifest));

            $this->line('');
            $this->info('Dry run only. Re-run without --dry-run to delete.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->purge($manifest));

        $this->report($this->deleted);

        return $this->verify($manifest) ? self::SUCCESS : self::FAILURE;
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

    /**
     * Everything that must be true before a single row is removed.
     *
     * @param  array<string, mixed>  $m
     */
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

        // A booking that belongs to a different unit means the manifest is
        // stale or was written for another run. Deleting on it would remove a
        // real booking.
        if (! empty($m['booking_id'])) {
            $booking = Booking::find($m['booking_id']);

            if ($booking && (int) $booking->unit_id !== (int) $m['unit_id']) {
                $this->error("REFUSING: booking #{$booking->id} belongs to unit #{$booking->unit_id}, not #{$m['unit_id']}.");

                return false;
            }
        }

        // No user may be deleted unless the manifest says the test CREATED it,
        // and it is not one of the accounts that existed before the test.
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

        // The partner account may be kept, suspended, for later use.
        if (($m['keep_partner_suspended'] ?? false) === true) {
            $ids = array_values(array_filter($ids, fn (int $id) => $id !== (int) ($m['partner_user_id'] ?? 0)));
        }

        return $ids;
    }

    /* ---------- counting and deleting ---------- */

    /**
     * What a real run would remove, per table.
     *
     * @param  array<string, mixed>  $m
     * @return array<string, int>
     */
    private function plan(array $m): array
    {
        $booking = $m['booking_id'] ?? null;
        $unit = $m['unit_id'];
        $users = $this->usersToDelete($m);

        return array_filter([
            'refunds' => $booking ? Refund::where('booking_id', $booking)->count() : 0,
            'payments' => $booking ? Payment::where('booking_id', $booking)->count() : 0,
            'partner_ledger_entries' => $booking ? PartnerLedgerEntry::where('booking_id', $booking)->count() : 0,
            'audit_logs' => $booking ? $this->auditLogs($booking)->count() : 0,
            'notifications' => $booking ? $this->notifications($booking)->count() : 0,
            'bookings' => $booking ? Booking::where('id', $booking)->count() : 0,
            'unit_images' => UnitImage::where('unit_id', $unit)->count(),
            'permits' => Permit::where('scope_type', Permit::SCOPE_UNIT)->where('scope_id', (string) $unit)->count(),
            'units' => Unit::where('id', $unit)->count(),
            'dashboard_uploads' => count($m['upload_ids'] ?? []),
            'personal_access_tokens' => $users ? DB::table('personal_access_tokens')->whereIn('tokenable_id', $users)->count() : 0,
            'partner_details' => $users ? PartnerDetail::whereIn('user_id', $users)->count() : 0,
            'users' => count($users),
        ], fn (int $n) => $n > 0);
    }

    /** @param array<string, mixed> $m */
    private function purge(array $m): void
    {
        $booking = $m['booking_id'] ?? null;
        $unit = (int) $m['unit_id'];
        $users = $this->usersToDelete($m);

        if ($booking) {
            $this->drop('refunds', Refund::where('booking_id', $booking)->delete());
            $this->drop('payments', Payment::where('booking_id', $booking)->delete());
            $this->drop('partner_ledger_entries', PartnerLedgerEntry::where('booking_id', $booking)->delete());
            $this->drop('audit_logs', $this->auditLogs($booking)->delete());
            $this->drop('notifications', $this->notifications($booking)->delete());
            $this->drop('bookings', Booking::where('id', $booking)->delete());
        }

        // The wallet row is RESTORED, not deleted: it may have existed before
        // the test, and a partner's wallet is not ours to remove.
        $this->restoreWallet($m);

        $this->drop('unit_images', UnitImage::where('unit_id', $unit)->delete());
        $this->drop('permits', Permit::where('scope_type', Permit::SCOPE_UNIT)->where('scope_id', (string) $unit)->delete());
        $this->drop('units', Unit::where('id', $unit)->delete());

        if ($ids = $m['upload_ids'] ?? []) {
            $this->drop('dashboard_uploads', DashboardUpload::whereIn('id', $ids)->delete());
        }

        if ($users) {
            $this->drop('personal_access_tokens', DB::table('personal_access_tokens')->whereIn('tokenable_id', $users)->delete());
            $this->drop('refresh_tokens', DB::table('refresh_tokens')->whereIn('user_id', $users)->delete());
            $this->drop('partner_details', PartnerDetail::whereIn('user_id', $users)->delete());
            $this->drop('model_has_roles', DB::table('model_has_roles')->whereIn('model_id', $users)->delete());
            $this->drop('users', User::whereIn('id', $users)->delete());
        }

        // A partner account kept on purpose is suspended, never left able to
        // log in: is_active is what AuthController checks first.
        if (($m['keep_partner_suspended'] ?? false) === true && ! empty($m['partner_user_id'])) {
            User::where('id', $m['partner_user_id'])->update(['is_active' => false]);
            $this->line('  partner account #'.$m['partner_user_id'].' kept and SUSPENDED (is_active = false)');
        }
    }

    /**
     * `audit_logs` is polymorphic — there is no booking_id column, so the rows
     * are found by the morph pair. Still an id match, not a name match.
     */
    private function auditLogs(int|string $bookingId): Builder
    {
        return AuditLog::query()
            ->where('auditable_type', Booking::class)
            ->where('auditable_id', $bookingId);
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

        $wallet = PartnerWallet::where('user_id', $before['user_id'])->first();

        if (! $wallet) {
            return;
        }

        if ($before['existed'] ?? false) {
            $wallet->update([
                'available_balance' => $before['available_balance'],
                'pending_balance' => $before['pending_balance'],
            ]);
            $this->line('  partner_wallets: balance restored, row kept (it existed before the test)');
        } else {
            $this->drop('partner_wallets', (int) $wallet->delete());
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

    /** @param array<string, int> $rows */
    private function report(array $rows): void
    {
        if ($rows === []) {
            $this->line('  (nothing to remove)');

            return;
        }

        foreach ($rows as $table => $n) {
            $this->line(sprintf('  %-26s %d', $table, $n));
        }

        $this->line(sprintf('  %-26s %d', 'TOTAL', array_sum($rows)));
    }

    /**
     * The counts must come back to exactly what they were. Anything else is
     * reported as a failure — a leftover row on production is the thing this
     * whole command exists to make impossible.
     *
     * @param  array<string, mixed>  $m
     */
    private function verify(array $m): bool
    {
        $this->line('');
        $this->info('=== counts after cleanup ===');

        $now = [
            'units' => Unit::count(),
            'bookings' => Booking::count(),
            'payments' => Payment::count(),
            'refunds' => Refund::count(),
            'permits' => Permit::count(),
            'users' => User::count(),
        ];

        $ok = true;

        foreach ($now as $table => $count) {
            $want = $m['baseline'][$table] ?? null;

            if ($want === null) {
                $this->line(sprintf('  %-10s %d  (no baseline recorded)', $table, $count));

                continue;
            }

            // A partner account kept on purpose is a declared, expected drift.
            $want = (int) $want;
            if ($table === 'users' && ($m['keep_partner_suspended'] ?? false) === true && ! empty($m['partner_user_id'])) {
                $want++;
            }

            $match = $count === $want;
            $ok = $ok && $match;

            $this->line(sprintf('  %-10s %d  (expected %d)  %s', $table, $count, $want, $match ? '✓' : '✗ MISMATCH'));
        }

        $this->line('');

        if ($ok) {
            $this->info('✓ every count is back to baseline.');
        } else {
            $this->error('✗ a count did not return to baseline. STOP and report before doing anything else.');
        }

        return $ok;
    }
}
