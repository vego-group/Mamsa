<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Notifications\PaymentsStuckPending;
use App\Services\BookingPaymentSettler;
use App\Services\MoyasarService;
use App\Support\OpsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Ask Moyasar about payments whose webhook never arrived.
 *
 * Until this existed, a lost webhook was recovered only if the guest came back
 * to the page and triggered `POST /payments/verify`. A guest who paid and closed
 * the tab had no server-side path at all: the money moved, the booking stayed
 * `pending_payment`, and `bookings:expire-pending` would eventually cancel it —
 * because that job reads the LOCAL payment row, not the gateway. So a dropped
 * connection on one inbound request could cancel a paid stay, and nothing would
 * report it.
 *
 * This is the refund reconciler's shape applied to payments, deliberately: same
 * cadence idea, same alert routing, same rule that asking the gateway is cheaper
 * than assuming. The important part is what it does NOT do — it never decides a
 * booking's fate itself. Everything goes through BookingPaymentSettler, which
 * takes the unit lock, re-checks availability, restores a platform-cancelled
 * booking only when the nights are still free, refuses to reverse a guest's own
 * cancellation, and refunds when the nights are gone. A job that reimplemented
 * any of that would be a second chance to sell the same nights twice.
 */
class ReconcilePendingPayments extends Command
{
    protected $signature = 'payments:reconcile-pending
        {--alert : Notify the operations recipients about anything past the alert threshold.}
        {--limit=50 : Maximum payments to ask about in one run.}';

    protected $description = 'Settle payments whose webhook never arrived, by asking Moyasar directly';

    public function handle(MoyasarService $moyasar, BookingPaymentSettler $settler): int
    {
        $after = (int) config('moyasar.reconcile_after_minutes', 15);
        $alertAfter = (int) config('moyasar.reconcile_alert_after_hours', 6);

        $candidates = Payment::query()
            ->with('booking')
            // Only payments the gateway actually knows about. A row with no
            // moyasar_id never reached Moyasar, so there is nothing to ask.
            ->whereNotNull('moyasar_id')
            ->where('payment_status', '!=', 'paid')
            ->where('created_at', '<=', Carbon::now()->subMinutes($after))
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $settled = 0;
        $failed = 0;
        $unresolved = 0;

        foreach ($candidates as $payment) {
            // Outside any transaction: this is a network call, and holding a
            // database lock across one is how a slow gateway becomes an outage.
            try {
                $remote = $moyasar->fetchPayment((string) $payment->moyasar_id);
            } catch (\Throwable $e) {
                $unresolved++;
                $this->warn("  payment #{$payment->id}: gateway unreachable — {$e->getMessage()}");

                continue;
            }

            $status = (string) ($remote['status'] ?? '');

            if ($status === 'paid') {
                // Verified on amount and currency, not just status — the same
                // check the webhook makes, because a status alone would let a
                // mismatched or partial capture through.
                if (! $moyasar->verifyCallback((string) $payment->moyasar_id, (float) $payment->amount)) {
                    $unresolved++;
                    Log::critical('Reconcile: gateway reports paid but verification failed', [
                        'payment_id' => $payment->id,
                        'moyasar_id' => $payment->moyasar_id,
                    ]);
                    $this->error("  payment #{$payment->id}: reports paid but does not verify");

                    continue;
                }

                $payment->update([
                    'payment_status' => 'paid',
                    'paid_at' => now(),
                    'moyasar_response' => $remote,
                ]);

                // The settler owns every decision from here — including the
                // case where the nights were given away while the webhook was
                // lost, which it refunds and alerts on.
                if ($payment->booking) {
                    $settler->confirm($payment->booking);
                }

                $settled++;
                $this->info("  payment #{$payment->id}: settled from the gateway");

                continue;
            }

            if (in_array($status, ['failed', 'voided', 'refunded'], true)) {
                $payment->update(['payment_status' => 'failed', 'moyasar_response' => $remote]);
                $failed++;
                $this->line("  payment #{$payment->id}: {$status} at the gateway");

                continue;
            }

            // initiated / authorized / anything else: still in flight. Left
            // alone deliberately — a payment mid-3-DS is not a failure, and
            // marking it one would cancel a stay the guest is still paying for.
            $unresolved++;
        }

        $this->info(sprintf(
            'Asked about %d payment(s): %d settled, %d failed at the gateway, %d still unresolved.',
            $candidates->count(), $settled, $failed, $unresolved,
        ));

        // Re-read rather than reuse the list: anything settled above is no
        // longer stuck, and alerting on it would train people to ignore this.
        $stuck = Payment::query()
            ->whereNotNull('moyasar_id')
            ->where('payment_status', '!=', 'paid')
            ->where('created_at', '<=', Carbon::now()->subHours($alertAfter))
            ->get();

        if ($stuck->isNotEmpty()) {
            $this->warn($stuck->count()." payment(s) unresolved beyond {$alertAfter}h.");

            if ($this->option('alert')) {
                OpsAlert::raise(new PaymentsStuckPending($stuck, $alertAfter));
                $this->line('  alert sent');
            }
        }

        return self::SUCCESS;
    }
}
