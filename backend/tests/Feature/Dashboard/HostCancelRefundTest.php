<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Booking;
use App\Models\PartnerLedgerEntry;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PartnerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The guest paid, the unit was double-booked elsewhere, and the PARTNER
 * cancelled.
 *
 * The guest did nothing wrong, so no cancellation policy applies to them: they
 * get 100% of what they paid back. The partner forfeits their share and Mamsa
 * forfeits its commission — the platform absorbs the loss, not the guest.
 */
class HostCancelRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private User $guest;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        // The idempotency guard lives in the cache; isolate it between tests.
        Cache::flush();

        foreach (['Individual', 'Company', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
        $this->partner->partnerDetail()->create(['type' => 'individual', 'national_id' => '1012345678']);

        $this->guest = User::factory()->create(['name' => 'ضيف الاختبار']);
        $this->guest->assignRole('User');

        $this->unit = $this->partner->units()->create([
            'unit_name' => 'شاليه الواحة', 'unit_type' => 'chalet',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 1000, 'capacity' => 4, 'bedrooms' => 2, 'beds' => 3, 'bathrooms' => 1,
            'area' => 90, 'city' => 'جدة', 'district' => 'الشاطئ', 'lat' => 21.5, 'lng' => 39.1,
            'approval_status' => 'approved', 'status' => 'available',
            'checkin_time' => '15:00:00',
            'calendar_token' => str()->random(60),
        ]);
    }

    /**
     * A paid, confirmed, future stay — the only state a host may cancel.
     *
     * @param  array<string, mixed>  $money
     */
    private function paidBooking(array $money = []): Booking
    {
        $money = array_merge([
            'subtotal' => 3000.00, 'taxes' => 450.00, 'commission_amount' => 60.00,
            'partner_share' => 2940.00, 'total_amount' => 3450.00,
        ], $money);

        $booking = Booking::create(array_merge([
            'unit_id'    => $this->unit->id,
            'user_id'    => $this->guest->id,
            'code'       => 'BK-'.fake()->unique()->numerify('####'),
            'start_date' => now()->addDays(10),
            'end_date'   => now()->addDays(13),
            'guests'     => 2,
            'status'     => Booking::STATUS_CONFIRMED,
        ], $money));

        Payment::create([
            'booking_id'      => $booking->id,
            'user_id'         => $this->guest->id,
            'amount'          => $money['total_amount'],
            'payment_method'  => 'creditcard',
            'payment_status'  => 'paid',
            'refunded_amount' => 0,
            'paid_at'         => now()->subDay(),
        ]);

        return $booking->fresh(['payment']);
    }

    private function hostCancel(Booking $booking, string $reason = 'الوحدة محجوزة في منصة أخرى'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->partner, 'dashboard')
            ->postJson("/bookings/{$booking->id}/host-cancel", ['reason' => $reason]);
    }

    /* ---- the scenario ---- */

    public function test_the_guest_is_refunded_every_riyal_they_paid(): void
    {
        $booking = $this->paidBooking();

        $this->hostCancel($booking)->assertOk();

        $booking->refresh()->load('refunds', 'payment');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertSame('partner', $booking->cancelled_by);

        $refund = $booking->refunds->first();

        $this->assertNotNull($refund, 'a paid booking cancelled by the host must produce a refund');
        $this->assertEqualsWithDelta(3450.00, (float) $refund->amount, 0.01,
            'the FULL gross the guest paid — not the base, not the base plus VAT');
        $this->assertSame(100, (int) $refund->refund_percent);

        $this->assertEqualsWithDelta(3450.00, (float) $booking->payment->refunded_amount, 0.01);
    }

    /**
     * No cancellation policy is consulted. A guest cancelling 10 days out might
     * forfeit a tier percentage; a guest whose host cancelled forfeits nothing,
     * because they did not cancel.
     */
    public function test_no_policy_tier_is_applied_to_the_guest(): void
    {
        $booking = $this->paidBooking();

        $this->hostCancel($booking)->assertOk();

        $refund = $booking->refresh()->refunds->first();

        $this->assertEqualsWithDelta(
            (float) $booking->total_amount, (float) $refund->amount, 0.01,
            'the refund must equal the booking total exactly',
        );
        $this->assertSame('إلغاء المضيف', $refund->tier_label);
    }

    /**
     * A legacy booking also carries the abolished service and cleaning fees.
     * "Full" means the whole gross, fees included — the guest paid those too.
     */
    public function test_a_legacy_booking_with_fees_is_refunded_in_full(): void
    {
        $booking = $this->paidBooking([
            'subtotal' => 1000.00, 'taxes' => 150.00,
            'service_fee' => 100.00, 'cleaning_fee' => 50.00,
            'commission_amount' => 20.00, 'partner_share' => 980.00,
            'total_amount' => 1300.00,
        ]);

        $this->hostCancel($booking)->assertOk();

        $this->assertEqualsWithDelta(
            1300.00, (float) $booking->refresh()->refunds->first()->amount, 0.01,
            'subtotal + VAT alone would short the guest by the 150.00 of fees',
        );
    }

    /** The guest sees the money coming back, not just a cancelled booking. */
    public function test_the_guest_gets_a_wallet_record_of_the_refund(): void
    {
        $booking = $this->paidBooking();

        $this->hostCancel($booking)->assertOk();

        $tx = WalletTransaction::where('user_id', $this->guest->id)
            ->where('booking_id', $booking->id)->first();

        $this->assertNotNull($tx);
        $this->assertSame(WalletTransaction::TYPE_REFUND, $tx->type);
        $this->assertEqualsWithDelta(3450.00, (float) $tx->amount, 0.01);
    }

    /* ---- the partner side of the same event ---- */

    /**
     * The partner cancelled, so they earn nothing — and Mamsa takes no
     * commission on a stay that never happened.
     */
    public function test_the_partner_earns_nothing_and_mamsa_takes_no_commission(): void
    {
        $booking = $this->paidBooking();
        $wallet  = app(PartnerWalletService::class);

        $this->assertEqualsWithDelta(2940.00, $wallet->pendingBalance($this->partner->id), 0.01,
            'a confirmed stay is pending income before it is cancelled');

        $this->hostCancel($booking)->assertOk();

        $this->assertEqualsWithDelta(0.0, $wallet->pendingBalance($this->partner->id), 0.01,
            'a cancelled stay must leave the pending balance');

        $this->assertFalse(
            PartnerLedgerEntry::where('partner_user_id', $this->partner->id)
                ->where('ref_id', (string) $booking->id)->exists(),
            'no earning may ever reach the ledger for a cancelled stay',
        );
    }

    /** §6.1.4 — the freed dates are blocked, not instantly resold. */
    public function test_the_freed_dates_are_blocked(): void
    {
        $booking = $this->paidBooking();

        $this->hostCancel($booking)->assertOk();

        $this->assertTrue(
            $this->unit->blockedDates()
                ->whereDate('start_date', $booking->start_date->toDateString())->exists(),
        );
    }

    /* ---- guards ---- */

    /** A double-click must not refund the guest twice. */
    public function test_a_repeated_cancel_does_not_refund_twice(): void
    {
        $booking = $this->paidBooking();

        $this->hostCancel($booking)->assertOk();
        $this->hostCancel($booking);   // already cancelled

        $booking->refresh()->load('refunds', 'payment');

        $this->assertCount(1, $booking->refunds, 'exactly one refund row');
        $this->assertEqualsWithDelta(3450.00, (float) $booking->payment->refunded_amount, 0.01,
            'the payment must never be refunded beyond what was paid');
    }

    /**
     * "Full" means everything still owed, never more than was paid.
     *
     * If part of the payment had already been returned, refunding the whole
     * total again would push `refunded_amount` past `amount` — the platform
     * paying out more than the guest ever handed over. The admin retry path
     * already caps with min(amount, refundableAmount()); this one did not.
     */
    public function test_an_existing_partial_refund_is_not_paid_out_twice(): void
    {
        $booking = $this->paidBooking();

        // 450.00 already returned for some earlier adjustment.
        $booking->payment->update(['refunded_amount' => 450.00]);

        $this->hostCancel($booking)->assertOk();

        $payment = $booking->refresh()->payment->fresh();

        $this->assertEqualsWithDelta(3450.00, (float) $payment->refunded_amount, 0.01,
            'the guest gets back exactly what they paid — 450 already returned + 3000 now');
        $this->assertLessThanOrEqual(
            (float) $payment->amount, (float) $payment->refunded_amount,
            'refunded_amount must never exceed the amount captured',
        );
    }

    /* ---- the REAL gateway path ---- */

    /**
     * The bug a live staging run found and every automated test missed.
     *
     * With no secret key configured the gateway is skipped and the refund is
     * written as `succeeded`. Only a REAL gateway result took the other branch,
     * which wrote `processing` — not a member of the refunds enum
     * (pending|succeeded|failed). MySQL truncated it, the insert threw, and the
     * whole cancellation rolled back AFTER Moyasar had already refunded the
     * guest: the money moved and the system did not know.
     */
    public function test_a_real_gateway_refund_is_recorded_with_a_valid_status(): void
    {
        config(['moyasar.secret_key' => 'sk_test_fake']);   // → not test mode

        $this->mock(\App\Services\MoyasarService::class, function ($mock) {
            $mock->shouldReceive('refund')->once()->andReturn([
                'id' => 'ref_live_abc123', 'status' => 'refunded', 'refunded' => 345000,
            ]);
        });

        $booking = $this->paidBooking();
        $booking->payment->update(['moyasar_id' => 'pay_abc123']);

        $this->hostCancel($booking)->assertOk();

        $refund = $booking->refresh()->refunds->first();

        $this->assertNotNull($refund, 'the refund row must survive the insert');
        $this->assertContains($refund->status, ['pending', 'succeeded', 'failed'],
            'status must be a member of the refunds enum');
        $this->assertSame('pending', $refund->status, 'a gateway refund settles via webhook');
        $this->assertSame('ref_live_abc123', $refund->moyasar_refund_id);
        $this->assertEqualsWithDelta(3450.00, (float) $refund->amount, 0.01);

        // And the cancellation itself must have committed, not rolled back.
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertEqualsWithDelta(3450.00, (float) $booking->payment->fresh()->refunded_amount, 0.01);
    }

    /* ---- the same rule through the /api/v1 partner surface ---- */

    /**
     * The Vue partner app had no cancel control because there was no endpoint
     * behind it. It shares HostCancelBookingAction with the dashboard — two
     * refund paths would eventually disagree about how much a guest gets back.
     */
    public function test_the_v1_partner_endpoint_refunds_the_guest_in_full(): void
    {
        $booking = $this->paidBooking();

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/bookings/{$booking->id}/cancel", [
                'reason' => 'الوحدة محجوزة في منصة أخرى',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $booking->refresh()->load('refunds', 'payment');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertSame('partner', $booking->cancelled_by);
        $this->assertEqualsWithDelta(3450.00, (float) $booking->refunds->first()->amount, 0.01);
        $this->assertEqualsWithDelta(3450.00, (float) $booking->payment->refunded_amount, 0.01);
    }

    public function test_the_v1_endpoint_requires_a_reason(): void
    {
        $booking = $this->paidBooking();

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/bookings/{$booking->id}/cancel", ['reason' => ''])
            ->assertStatus(422);

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->refresh()->status);
    }

    /** A partner must not be able to cancel — or probe — someone else's booking. */
    public function test_the_v1_endpoint_hides_another_partners_booking(): void
    {
        $booking = $this->paidBooking();

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Individual');
        $other->partnerDetail()->create(['type' => 'individual', 'national_id' => '1099999999']);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/partner/bookings/{$booking->id}/cancel", ['reason' => 'محاولة'])
            ->assertStatus(404);

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->refresh()->status);
    }

    public function test_another_partner_cannot_cancel_this_booking(): void
    {
        $booking = $this->paidBooking();

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Individual');
        $other->partnerDetail()->create(['type' => 'individual', 'national_id' => '1099999999']);

        $this->actingAs($other, 'dashboard')
            ->postJson("/bookings/{$booking->id}/host-cancel", ['reason' => 'محاولة غير مصرح بها'])
            ->assertStatus(404);

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->refresh()->status);
    }

    /** Once the stay has started the money is no longer simply returnable. */
    public function test_a_stay_whose_checkin_has_passed_cannot_be_host_cancelled(): void
    {
        $booking = $this->paidBooking();
        $booking->forceFill([
            'start_date' => now()->subDays(2),
            'end_date'   => now()->addDay(),
        ])->saveQuietly();

        $this->hostCancel($booking)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CHECKIN_PASSED');

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->refresh()->status);
    }
}
