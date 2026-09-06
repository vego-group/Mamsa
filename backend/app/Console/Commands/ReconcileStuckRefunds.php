<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Refund;
use App\Notifications\ComplaintRefundStuck;
use App\Services\ComplaintRefundService;
use App\Services\MoyasarService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Chase refunds the gateway accepted but never confirmed — G1.
 *
 * A stuck refund is invisible by design: nothing failed, so nothing is logged
 * as an error and no screen turns red. The guest is waiting on money, the
 * partner has not been debited, and the complaint sits half-closed. Left alone
 * it stays that way indefinitely, because the only thing that would have moved
 * it — the webhook — is precisely what did not arrive.
 *
 * Two thresholds, because they answer different questions:
 *   reconcile_after_hours  when to ASK Moyasar what really happened
 *   alert_after_hours      when to tell a human that asking did not resolve it
 */
class ReconcileStuckRefunds extends Command
{
    protected $signature = 'complaints:reconcile-refunds
        {--alert : Notify the configured recipients about anything past the alert threshold.}';

    protected $description = 'Reconcile refunds stuck in pending against Moyasar, and alert on the old ones.';

    public function handle(MoyasarService $moyasar, ComplaintRefundService $complaints): int
    {
        $reconcileAfter = (int) config('complaints.reconcile_after_hours');
        $alertAfter     = (int) config('complaints.alert_after_hours');

        $stuck = Refund::with('booking')
            ->where('status', Refund::STATUS_PENDING)
            ->where('created_at', '<=', Carbon::now()->subHours($reconcileAfter))
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No refunds pending beyond '.$reconcileAfter.'h.');

            return self::SUCCESS;
        }

        $this->line($stuck->count().' refund(s) pending beyond '.$reconcileAfter.'h.');

        foreach ($stuck as $refund) {
            $this->reconcile($refund, $moyasar, $complaints);
        }

        // Re-read: anything reconcile() settled is no longer stuck, and alerting
        // on it would send a human to look at a refund that just resolved.
        $stillStuck = Refund::with('booking')
            ->where('status', Refund::STATUS_PENDING)
            ->where('created_at', '<=', Carbon::now()->subHours($alertAfter))
            ->get();

        if ($stillStuck->isNotEmpty()) {
            $this->warn($stillStuck->count().' refund(s) stuck beyond '.$alertAfter.'h.');

            if ($this->option('alert')) {
                $complaints->raise(new ComplaintRefundStuck($stillStuck, $alertAfter));
                $this->line('  alert sent');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Ask the gateway what happened to one refund.
     *
     * Settlement goes through the same service the webhook uses, so a refund
     * recovered here debits the partner exactly as one confirmed normally
     * would — there is no second, subtly different settlement path.
     */
    private function reconcile(Refund $refund, MoyasarService $moyasar, ComplaintRefundService $complaints): void
    {
        $moyasarId = $refund->payment?->moyasar_id;

        if (blank(config('moyasar.secret_key')) || blank($moyasarId)) {
            $this->line("  refund #{$refund->id}: no gateway to ask, skipped");

            return;
        }

        try {
            $payment = $moyasar->fetchPayment($moyasarId);
        } catch (\Throwable $e) {
            report($e);
            $this->warn("  refund #{$refund->id}: could not read the gateway — ".$e->getMessage());

            return;
        }

        // Moyasar reports the refunded total on the PAYMENT, not per refund, so
        // "has this settled" is answered by the payment carrying at least this
        // much refunded. Comparing in halalas keeps it an integer test.
        $refundedHalalas = (int) ($payment['refunded'] ?? 0);
        $thisHalalas     = (int) round((float) $refund->amount * 100);

        if ($refundedHalalas >= $thisHalalas) {
            $refund->update([
                'status'           => Refund::STATUS_SUCCEEDED,
                'moyasar_response' => $payment,
            ]);

            if ($refund->complaint_id) {
                $complaints->settle($refund->fresh());
            }

            $this->info("  refund #{$refund->id}: settled by reconciliation");

            return;
        }

        $this->line("  refund #{$refund->id}: still not refunded at the gateway");
    }
}
