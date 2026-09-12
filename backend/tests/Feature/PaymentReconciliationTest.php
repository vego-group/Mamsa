<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\PaymentsStuckPending;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Recovering a payment whose webhook never arrived.
 *
 * Before this job, that was recovered only if the guest came back to the page.
 * One who paid and closed the tab had no server-side path — and
 * `bookings:expire-pending` reads the LOCAL payment row rather than the
 * gateway, so it would eventually cancel a stay that had been paid for. A
 * single dropped inbound connection could cost a booking, silently.
 */
class PaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        config()->set('complaints.alert_recipients', ['ops@mamsaa.com']);
    }

    public function test_a_payment_the_gateway_calls_paid_is_settled_and_the_booking_confirmed(): void
    {
        [$booking, $payment] = $this->pendingPayment(minutesAgo: 30);

        $this->gatewaySays($payment, 'paid');

        $this->artisan('payments:reconcile-pending')->assertExitCode(0);

        $this->assertSame('paid', $payment->fresh()->payment_status);
        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
    }

    public function test_a_payment_too_young_to_have_lost_its_webhook_is_left_alone(): void
    {
        // The webhook may simply not have arrived yet. Asking immediately would
        // race the thing it is meant to back up.
        [$booking, $payment] = $this->pendingPayment(minutesAgo: 2);

        Http::fake();

        $this->artisan('payments:reconcile-pending')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
    }

    public function test_a_payment_still_in_flight_is_not_marked_failed(): void
    {
        // A 3-DS challenge in progress is not a failure, and calling it one
        // would cancel a stay the guest is still paying for.
        [$booking, $payment] = $this->pendingPayment(minutesAgo: 30);

        $this->gatewaySays($payment, 'initiated');

        $this->artisan('payments:reconcile-pending')->assertExitCode(0);

        $this->assertSame('pending', $payment->fresh()->payment_status);
        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
    }

    public function test_a_payment_the_gateway_calls_failed_is_marked_failed(): void
    {
        [, $payment] = $this->pendingPayment(minutesAgo: 30);

        $this->gatewaySays($payment, 'failed');

        $this->artisan('payments:reconcile-pending')->assertExitCode(0);

        $this->assertSame('failed', $payment->fresh()->payment_status);
    }

    public function test_paid_at_the_gateway_but_failing_verification_is_never_settled(): void
    {
        // Status alone is not enough: a mismatched amount must not confirm a
        // stay. The webhook checks amount and currency and so does this.
        [$booking, $payment] = $this->pendingPayment(minutesAgo: 30);

        $this->gatewaySays($payment, 'paid', amountHalalas: 1);

        $this->artisan('payments:reconcile-pending')->assertExitCode(0);

        $this->assertSame('pending', $payment->fresh()->payment_status);
        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
    }

    public function test_an_unreachable_gateway_changes_nothing(): void
    {
        [$booking, $payment] = $this->pendingPayment(minutesAgo: 30);

        Http::fake(['*' => Http::response('gateway down', 500)]);

        $this->artisan('payments:reconcile-pending')->assertExitCode(0);

        $this->assertSame('pending', $payment->fresh()->payment_status);
        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
    }

    public function test_it_goes_through_the_settler_so_lost_nights_are_refunded_not_double_sold(): void
    {
        // The case the whole design exists for: the webhook was lost, the hold
        // expired, the nights went to someone else, and only then does the job
        // learn the money moved. It must NOT confirm.
        Notification::fake();

        [$booking, $payment] = $this->pendingPayment(minutesAgo: 30);

        $booking->forceFill([
            'status' => Booking::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => 'system',
            'cancellation_reason' => 'انتهت مهلة إتمام الدفع',
        ])->save();

        // Must OVERLAP the cancelled booking's nights (20→25) or nothing was
        // actually lost and the settler is right to restore it.
        $this->bookingOn($booking->unit, 20, 25, Booking::STATUS_CONFIRMED);

        $this->gatewaySays($payment, 'paid', withRefund: true);

        $this->artisan('payments:reconcile-pending')->assertExitCode(0);

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertSame(1, Refund::where('booking_id', $booking->id)->count());
    }

    public function test_it_alerts_on_a_payment_nobody_can_resolve(): void
    {
        Notification::fake();

        [, $payment] = $this->pendingPayment(minutesAgo: 60 * 8);

        $this->gatewaySays($payment, 'initiated');

        $this->artisan('payments:reconcile-pending --alert')->assertExitCode(0);

        Notification::assertSentOnDemand(PaymentsStuckPending::class);
    }

    public function test_it_does_not_alert_when_it_settled_everything(): void
    {
        // An alert on a run that fixed itself trains people to ignore the alert.
        Notification::fake();

        [, $payment] = $this->pendingPayment(minutesAgo: 60 * 8);

        $this->gatewaySays($payment, 'paid');

        $this->artisan('payments:reconcile-pending --alert')->assertExitCode(0);

        Notification::assertNotSentTo(new AnonymousNotifiable, PaymentsStuckPending::class);
    }

    public function test_a_payment_that_never_reached_the_gateway_is_skipped(): void
    {
        [, $payment] = $this->pendingPayment(minutesAgo: 30);
        $payment->forceFill(['moyasar_id' => null])->save();

        Http::fake();

        $this->artisan('payments:reconcile-pending')->assertExitCode(0);

        Http::assertNothingSent();
    }

    /* ---------- fixtures ---------- */

    /** @return array{0: Booking, 1: Payment} */
    private function pendingPayment(int $minutesAgo): array
    {
        $unit = $this->unit();
        $booking = $this->bookingOn($unit, 20, 25, Booking::STATUS_PENDING);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'payment_method' => 'creditcard',
            'payment_status' => 'pending',
            'moyasar_id' => 'pay_'.fake()->unique()->numerify('##########'),
        ]);

        $payment->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return [$booking, $payment];
    }

    private function gatewaySays(Payment $payment, string $status, ?int $amountHalalas = null, bool $withRefund = false): void
    {
        $body = [
            'id' => $payment->moyasar_id,
            'status' => $status,
            'amount' => $amountHalalas ?? (int) round((float) $payment->amount * 100),
            'currency' => 'SAR',
        ];

        $fakes = ['*/payments/'.$payment->moyasar_id => Http::response($body)];

        if ($withRefund) {
            $fakes = ['*/payments/'.$payment->moyasar_id.'/refund' => Http::response(['id' => 'rf_1', 'status' => 'refunded'])] + $fakes;
        }

        Http::fake($fakes);
    }

    private function unit(): Unit
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $owner->units()->create([
            'unit_name' => 'وحدة المطابقة',
            'unit_type' => 'apartment',
            'code' => 'RCN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 4, 'bedrooms' => 1,
            'approval_status' => 'approved', 'status' => 'available',
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
        ]);
    }

    private function bookingOn(Unit $unit, int $from, int $to, string $status): Booking
    {
        $guest = User::factory()->create();
        $guest->assignRole('User');

        return Booking::create([
            'unit_id' => $unit->id,
            'user_id' => $guest->id,
            'start_date' => now()->addDays($from)->format('Y-m-d'),
            'end_date' => now()->addDays($to)->format('Y-m-d'),
            'guests' => 2,
            'status' => $status,
            'total_amount' => 1000,
        ]);
    }
}
