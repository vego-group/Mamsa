<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Permit;
use App\Models\PermitReminder;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\PermitExpiring;
use App\Support\Permits\PermitRenewal;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Warn before a permit runs out, once per threshold, ever.
 *
 * The reminder is not what protects the platform — the calendar cap does that,
 * and it holds whether or not this command ever runs. This is what stops a
 * partner being surprised by it: their availability shrinks as the date
 * approaches, and they should know why while there is still time to renew.
 *
 * "Once per threshold, ever" is the whole difficulty. The query gives the same
 * answer every time it runs on a given day, so a retry, a second cron entry or
 * a manual run would each send again. The claim is therefore the INSERT into
 * permit_reminders, under its unique key: whoever inserts sends, and a
 * duplicate insert is caught and skipped rather than raced over.
 */
class CheckPermitExpiry extends Command
{
    protected $signature = 'permits:check-expiry {--dry-run : Report who would be told, and tell nobody}';

    protected $description = 'Remind partners whose tourism permit is about to expire (60/30/14/7/1 days, and the day itself)';

    public function handle(): int
    {
        $today = now()->startOfDay();
        $sent = 0;
        $skipped = 0;

        foreach (PermitReminder::THRESHOLDS as $threshold) {
            $target = $today->copy()->addDays($threshold)->toDateString();

            $permits = Permit::query()
                ->where('status', Permit::STATUS_CURRENT)
                ->whereDate('expires_at', $target)
                ->get();

            foreach ($permits as $permit) {
                $unit = $permit->units()->first();

                if (! $unit) {
                    continue; // a permit covering nothing warns nobody
                }

                // A renewal already filed is the answer to this reminder. The
                // partner has done the thing it would ask for; saying it again
                // reads as "we did not notice".
                if (PermitRenewal::pendingFor($unit)) {
                    $skipped++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line(sprintf(
                        '  would warn: permit #%d (%s) expires %s — %d day(s) — unit %s',
                        $permit->id, $permit->number ?? '—', $target, $threshold, $unit->unit_name,
                    ));
                    $sent++;

                    continue;
                }

                // The claim. A second run today loses the insert and sends
                // nothing — which is the point.
                try {
                    PermitReminder::create([
                        'permit_id' => $permit->id,
                        'threshold' => $threshold,
                        'expires_at' => $target,
                        'sent_at' => now(),
                    ]);
                } catch (QueryException $e) {
                    $skipped++;

                    continue;
                }

                $recipients = $this->recipients($unit);

                if ($recipients->isEmpty()) {
                    continue;
                }

                try {
                    Notification::send($recipients, new PermitExpiring($permit, $threshold, (string) $unit->unit_name));
                    $sent++;
                } catch (\Throwable $e) {
                    // The row stays: a mail provider having a bad minute is not
                    // a reason to send the same warning again tomorrow, and the
                    // in-app copy has already been written for most of them.
                    report($e);
                    $this->warn("  delivery failed for permit #{$permit->id}: ".$e->getMessage());
                }
            }
        }

        $this->info(($this->option('dry-run') ? '[dry run] ' : '').
            "permit reminders: {$sent} sent, {$skipped} skipped (renewal pending or already sent)");

        return self::SUCCESS;
    }

    /**
     * Who hears about it.
     *
     * A partner's listing → the partner. A Mamsa-owned listing has no partner
     * to warn — its owner is the platform account, which has no phone and no
     * inbox by design — so the platform's admins stand in, the same way they
     * do for its bookings.
     *
     * @return Collection<int, User>
     */
    private function recipients(Unit $unit): Collection
    {
        if ($unit->mamsa_owned) {
            return User::role(['Admin', 'SuperAdmin'])->where('is_active', true)->get();
        }

        return collect(array_filter([$unit->owner]));
    }
}
