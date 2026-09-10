<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\PaymentAfterCancellation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A payment that lands after the platform gave up on the booking.
 *
 * The hold expires, `bookings:expire-pending` cancels the booking, and the
 * cancellation RELEASES its nights. If the gateway then confirms the money —
 * a slow 3-DS challenge, a delayed webhook, a retry — the booking must not be
 * resurrected without checking whether the nights are still there. Before this
 * suite existed, it was: the only guard was "already confirmed?", so a paid
 * webhook flipped a cancelled booking straight back to confirmed and the same
 * nights could be sold twice with nobody told.
 */
class LatePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        config()->set('moyasar.webhook_secret', '');
        config()->set('complaints.alert_recipients', ['ops@mamsaa.com']);
    }

    /* ---------- the nights are still free: the guest keeps the stay ---------- */

    public function test_a_late_payment_restores_a_timed_out_booking_when_the_nights_are_free(): void
    {
        [$booking, $payment] = $this->timedOutBooking();

        $this->fakeGateway($payment);

        $this->webhook($payment)->assertOk();

        $booking->refresh();

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);
        $this->assertNull($booking->cancelled_at, 'a confirmed booking must not still carry a cancellation');
        $this->assertNull($booking->cancelled_by);
        $this->assertNull($booking->cancellation_reason);
        $this->assertSame(0, Refund::count(), 'nothing to refund — the guest got their stay');
    }

    /* ---------- the nights are gone: refund, do not double-book ---------- */

    public function test_a_late_payment_does_not_resurrect_a_booking_whose_nights_were_taken(): void
    {
        Notification::fake();

        [$booking, $payment] = $this->timedOutBooking();

        // Somebody else took the nights the cancellation released.
        $rival = $this->bookingOn($booking->unit, 10, 15, Booking::STATUS_CONFIRMED);

        $this->fakeGateway($payment);

        $this->webhook($payment)->assertOk();

        $this->assertSame(
            Booking::STATUS_CANCELLED,
            $booking->refresh()->status,
            'confirming this would sell booking #'.$rival->id."'s nights twice",
        );

        $refund = Refund::where('booking_id', $booking->id)->first();
        $this->assertNotNull($refund, 'the guest paid for nights they cannot have');
        $this->assertSame(Refund::STATUS_PENDING, $refund->status);
        $this->assertEqualsWithDelta((float) $booking->total_amount, (float) $refund->amount, 0.01);

        Notification::assertSentOnDemand(PaymentAfterCancellation::class);
    }

    public function test_the_refund_for_lost_nights_happens_once_however_many_callbacks_arrive(): void
    {
        Notification::fake();

        [$booking, $payment] = $this->timedOutBooking();
        $this->bookingOn($booking->unit, 10, 15, Booking::STATUS_CONFIRMED);

        $this->fakeGateway($payment);

        $this->webhook($payment)->assertOk();
        // A duplicate webhook, and the browser redirect behind it.
        $payment->forceFill(['payment_status' => 'pending', 'paid_at' => null])->save();
        $this->webhook($payment)->assertOk();

        $this->assertSame(1, Refund::where('booking_id', $booking->id)->count());
    }

    /* ---------- a deliberate cancellation is a decision, not a race ---------- */

    public function test_a_late_payment_never_reverses_a_cancellation_the_guest_made(): void
    {
        Notification::fake();

        [$booking, $payment] = $this->timedOutBooking();

        // Same state, but the guest chose it. The nights are free — and it
        // still must not come back.
        $booking->forceFill(['cancelled_by' => 'customer'])->save();

        $this->fakeGateway($payment);

        $this->webhook($payment)->assertOk();

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->refresh()->status);
        $this->assertSame(1, Refund::where('booking_id', $booking->id)->count());
    }

    /* ---------- the ordinary path is untouched ---------- */

    public function test_a_payment_on_a_pending_booking_still_confirms_it(): void
    {
        $unit    = $this->unit();
        $booking = $this->bookingOn($unit, 10, 15, Booking::STATUS_PENDING);
        $payment = $this->payment($booking);

        $this->fakeGateway($payment);

        $this->webhook($payment)->assertOk();

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->refresh()->status);
        $this->assertSame(0, Refund::count());
    }

    public function test_a_failed_gateway_refund_is_recorded_and_still_alerts(): void
    {
        Notification::fake();

        [$booking, $payment] = $this->timedOutBooking();
        $this->bookingOn($booking->unit, 10, 15, Booking::STATUS_CONFIRMED);

        $this->fakeGateway($payment, refund: 'rejected');

        $this->webhook($payment)->assertOk();

        $refund = Refund::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(Refund::STATUS_FAILED, $refund->status);
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->refresh()->status);

        // The money did not move, so the guest must not be told it did.
        $this->assertSame(0.0, (float) $payment->refresh()->refunded_amount);
        Notification::assertSentOnDemand(PaymentAfterCancellation::class);
    }

    /* ---------- fixtures ---------- */

    /** @return array{0: Booking, 1: Payment} */
    private function timedOutBooking(): array
    {
        $unit    = $this->unit();
        $booking = $this->bookingOn($unit, 10, 15, Booking::STATUS_PENDING);

        // Exactly what bookings:expire-pending writes.
        $booking->forceFill([
            'status'              => Booking::STATUS_CANCELLED,
            'cancelled_at'        => now(),
            'cancelled_by'        => 'system',
            'cancellation_reason' => 'انتهت مهلة إتمام الدفع',
        ])->save();

        return [$booking, $this->payment($booking)];
    }

    private function unit(): Unit
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $owner->units()->create([
            'unit_name'       => 'وحدة الدفع المتأخر',
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => 500,
            'capacity'        => 4,
            'bedrooms'        => 1,
            'approval_status' => 'approved',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ]);
    }

    private function bookingOn(Unit $unit, int $from, int $to, string $status): Booking
    {
        $guest = User::factory()->create();
        $guest->assignRole('User');

        return Booking::create([
            'unit_id'      => $unit->id,
            'user_id'      => $guest->id,
            'start_date'   => now()->addDays($from)->format('Y-m-d'),
            'end_date'     => now()->addDays($to)->format('Y-m-d'),
            'guests'       => 2,
            'status'       => $status,
            'total_amount' => 1000,
        ]);
    }

    private function payment(Booking $booking): Payment
    {
        return Payment::create([
            'booking_id'     => $booking->id,
            'amount'         => $booking->total_amount,
            'payment_method' => 'creditcard',
            'payment_status' => 'pending',
            'moyasar_id'     => 'pay_'.fake()->unique()->numerify('##########'),
        ]);
    }

    /**
     * Stub the whole gateway in ONE call.
     *
     * Two separate Http::fake() calls would be a trap: the second replaces the
     * stub set the first installed, so the payment lookup would fall through to
     * a stray request and the test would fail for a reason that has nothing to
     * do with what it is checking.
     */
    private function fakeGateway(Payment $payment, string $refund = 'accepted'): void
    {
        Http::fake([
            '*/payments/'.$payment->moyasar_id.'/refund' => $refund === 'accepted'
                ? Http::response(['id' => 'rf_'.fake()->numerify('########'), 'status' => 'refunded'])
                : Http::response(['message' => 'refund rejected'], 500),

            '*/payments/'.$payment->moyasar_id => Http::response([
                'id'       => $payment->moyasar_id,
                'status'   => 'paid',
                'amount'   => (int) round((float) $payment->amount * 100),
                'currency' => 'SAR',
            ]),
        ]);
    }

    private function webhook(Payment $payment): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/payments/callback', ['id' => $payment->moyasar_id]);
    }
}
