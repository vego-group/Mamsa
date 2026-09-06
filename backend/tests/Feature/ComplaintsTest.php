<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingComplaint;
use App\Models\PartnerLedgerEntry;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Unit;
use App\Models\User;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Guest complaints and the discretionary refund — spec v1.3, tests T1–T19. */
class ComplaintsTest extends TestCase
{
    use RefreshDatabase;

    private User $guest;
    private User $partner;
    private User $superadmin;
    private User $finance;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // The throttle on the filing route is backed by the array cache, which
        // lives for the whole process — without this, tests accumulate hits
        // against a shared key and whichever one runs sixth gets a 429.
        cache()->flush();

        // Freeze the clock at midday Riyadh.
        //
        // The complaint window is computed from calendar days in Riyadh, so a
        // test that builds dates from the real `now()` passes or fails
        // depending on the hour it runs. That is how a genuine three-hour
        // timezone bug hid here for a day: the tests only entered the affected
        // band shortly after Riyadh midnight, and the suite happened not to run
        // then. A fixed clock makes the boundary the subject of the test rather
        // than the weather.
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Riyadh'));

        foreach (['Individual', 'User', 'SuperAdmin', 'finance'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->guest = User::factory()->create(['is_active' => true]);
        $this->guest->assignRole('User');

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
        $this->partner->partnerDetail()->create(['type' => 'individual', 'national_id' => '1012345678']);

        $this->superadmin = User::factory()->create(['is_active' => true]);
        $this->superadmin->assignRole('SuperAdmin');

        $this->finance = User::factory()->create(['is_active' => true]);
        $this->finance->assignRole('finance');

        $this->unit = $this->makeUnit($this->partner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeUnit(User $owner, bool $mamsaOwned = false, ?string $checkoutTime = null): Unit
    {
        return $owner->units()->create([
            'checkout_time' => $checkoutTime,
            'unit_name' => 'استوديو', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 350, 'capacity' => 4, 'bedrooms' => 2, 'beds' => 3, 'bathrooms' => 1,
            'area' => 90, 'city' => 'جدة', 'district' => 'الشاطئ', 'lat' => 21.5, 'lng' => 39.1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
            'mamsa_owned' => $mamsaOwned,
        ]);
    }

    /** A completed 1,000.00 stay, checked out yesterday — inside the window. */
    private function stay(?Unit $unit = null, float $rate = 0.10, int $checkoutDaysAgo = 1): Booking
    {
        $unit  = $unit ?? $this->unit;
        $split = Pricing::split(1000.00, $rate);

        $booking = Booking::create([
            'unit_id' => $unit->id, 'user_id' => $this->guest->id,
            'code' => 'BK-'.fake()->unique()->numerify('#####'),
            'start_date' => now()->subDays($checkoutDaysAgo + 3),
            'end_date'   => now()->subDays($checkoutDaysAgo),
            'guests' => 2,
            'subtotal' => $split['net_base'], 'taxes' => $split['vat'],
            'commission_rate' => $rate, 'commission_amount' => $split['commission_amount'],
            'partner_share' => $split['partner_share'], 'total_amount' => 1000.00,
            'status' => Booking::STATUS_COMPLETED,
        ]);

        Payment::create([
            'booking_id' => $booking->id, 'user_id' => $this->guest->id,
            'amount' => 1000.00, 'status' => 'paid', 'method' => 'creditcard',
        ]);

        return $booking->fresh();
    }

    private function complaint(Booking $booking, string $status = BookingComplaint::STATUS_UNDER_REVIEW): BookingComplaint
    {
        return BookingComplaint::create([
            'booking_id' => $booking->id, 'user_id' => $booking->user_id,
            'status' => $status, 'description' => str_repeat('م', 40), 'contacted_partner' => true,
        ]);
    }

    private function approved(Booking $booking, int $halalas = 50000): BookingComplaint
    {
        $c = $this->complaint($booking);
        $c->update([
            'status' => BookingComplaint::STATUS_APPROVED,
            'approved_refund_halalas' => $halalas,
            'approved_by' => $this->superadmin->id, 'approved_at' => now(),
        ]);

        return $c->fresh();
    }

    /* ================= the split (T1, T2, T14) ================= */

    public function test_t1_the_reference_split_matches_the_spec(): void
    {
        $booking = $this->stay();
        $split   = $booking->splitRefund(500.00);

        $this->assertEqualsWithDelta(65.22, $split['vat'], 0.001);
        $this->assertEqualsWithDelta(43.48, $split['commission_amount'], 0.001);
        $this->assertEqualsWithDelta(391.30, $split['partner_share'], 0.001);
    }

    /**
     * T2 — the rate comes off the BOOKING, not config.
     *
     * This is the test the whole B1 objection exists for: a stay taken at 2%
     * must refund at 2% even while the live rate is 10%. Reading config here
     * would restate history and debit the partner a share that was never his.
     */
    public function test_t2_a_legacy_booking_refunds_at_its_frozen_rate(): void
    {
        config(['booking.commission_rate' => 0.10]);

        $legacy = $this->stay(rate: 0.02);
        $split  = $legacy->splitRefund(500.00);

        $this->assertEqualsWithDelta(8.70, $split['commission_amount'], 0.001, 'commission must be 2%, not 10%');
        $this->assertEqualsWithDelta(426.08, $split['partner_share'], 0.001);
        $this->assertEqualsWithDelta(0.02, $split['commission_rate'], 0.0001);
    }

    /** T14 — the invariant holds on the INTEGERS, which is what the API speaks. */
    public function test_t14_the_invariant_holds_in_halalas(): void
    {
        $booking = $this->stay();

        foreach ([1, 99, 12345, 33333, 50000, 100000] as $gross) {
            $split = $booking->splitRefund($gross / 100);

            $vat  = (int) round($split['vat'] * 100);
            $comm = (int) round($split['commission_amount'] * 100);
            $part = $gross - $vat - $comm;

            $this->assertSame($gross, $vat + $comm + $part, "invariant broke at {$gross} halalas");
        }
    }

    /* ================= filing (T6, T7, T8) ================= */

    public function test_t6_a_complaint_before_check_in_is_refused(): void
    {
        $booking = $this->stay();
        $booking->update([
            'start_date' => now()->addDays(3),
            'end_date'   => now()->addDays(6),
        ]);

        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", [
                'description' => str_repeat('م', 40), 'contacted_partner' => true,
            ])->assertStatus(422);
    }

    public function test_t7_a_complaint_past_the_window_is_refused(): void
    {
        // A minute past the deadline: check-out is 12:00 (the default for a
        // unit with none recorded), so the window shuts at 12:00 two days later.
        $booking = $this->stay();
        $booking->update(['end_date' => Carbon::parse('2026-09-13', 'Asia/Riyadh')]);
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:01:00', 'Asia/Riyadh'));

        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", [
                'description' => str_repeat('م', 40), 'contacted_partner' => true,
            ])->assertStatus(422);
    }

    public function test_t8_a_second_complaint_on_the_same_booking_is_refused(): void
    {
        $booking = $this->stay();
        $this->complaint($booking);

        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", [
                'description' => str_repeat('م', 40), 'contacted_partner' => true,
            ])->assertStatus(409);
    }

    public function test_a_guest_cannot_complain_about_someone_elses_booking(): void
    {
        $booking = $this->stay();
        $other   = User::factory()->create();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", [
                'description' => str_repeat('م', 40), 'contacted_partner' => true,
            ])->assertStatus(403);
    }

    public function test_a_complaint_can_be_filed_inside_the_window(): void
    {
        $booking = $this->stay();

        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", [
                'description' => str_repeat('م', 40), 'contacted_partner' => true,
            ])->assertStatus(201)->assertJsonPath('status', 'submitted');

        $this->assertSame(1, BookingComplaint::where('booking_id', $booking->id)->count());
    }

    /* ================= permissions (T12) ================= */

    /**
     * T12 (as amended by v1.4 §4) — finance cannot decide the amount.
     *
     * The half that asserted superadmin cannot execute is gone: superadmin now
     * holds `complaints.execute_refund` too. That removes no security property
     * — superadmin is already the highest authority and could grant itself the
     * role — while removing a real deadlock, where one absent finance account
     * halts every refund. The property that matters is the one still asserted
     * here: finance cannot set a figure, only carry out an approved one.
     */
    public function test_t12_finance_cannot_approve_an_amount(): void
    {
        $complaint = $this->complaint($this->stay());

        $this->actingAs($this->finance, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/approve", ['amountHalalas' => 50000])
            ->assertStatus(403);

        $this->actingAs($this->finance, 'admin-panel')
            ->patchJson("/admin/complaints/{$complaint->id}/approval", ['amountHalalas' => 50000])
            ->assertStatus(403);

        // And superadmin executing is permitted, but leaves a mark: approver
        // and executor being one person is the case an audit wants to find.
        $approved = $this->approved($this->stay());

        $this->actingAs($this->superadmin, 'admin-panel')
            ->postJson("/admin/complaints/{$approved->id}/refund", [
                'amountHalalas' => 50000, 'idempotencyKey' => (string) str()->uuid(),
            ])->assertOk();

        $log = AuditLog::where('action', 'complaint.refund_executed')->latest('id')->firstOrFail();
        $this->assertTrue($log->after['single_actor'], 'a one-person approval+execution must be flagged');
    }

    /* ================= execution (T3, T4, T17, T19) ================= */

    public function test_t17_executing_an_amount_other_than_the_approved_one_is_refused(): void
    {
        $complaint = $this->approved($this->stay(), 50000);

        $this->actingAs($this->finance, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/refund", [
                'amountHalalas' => 49900, 'idempotencyKey' => (string) str()->uuid(),
            ])->assertStatus(422)->assertJsonPath('code', 'AMOUNT_NOT_APPROVED');

        $this->assertSame(0, Refund::count());
    }

    public function test_t3_approving_more_than_the_booking_is_worth_is_refused(): void
    {
        $complaint = $this->complaint($this->stay());

        $this->actingAs($this->superadmin, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/approve", ['amountHalalas' => 100001])
            ->assertStatus(422);

        $this->assertSame(0, Refund::count());
        $this->assertSame(0, PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_REFUND_REVERSAL)->count());
    }

    public function test_t4_the_same_idempotency_key_executes_once(): void
    {
        $complaint = $this->approved($this->stay(), 50000);
        $key       = (string) str()->uuid();

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->finance, 'admin-panel')
                ->postJson("/admin/complaints/{$complaint->id}/refund", [
                    'amountHalalas' => 50000, 'idempotencyKey' => $key,
                ])->assertOk();
        }

        $this->assertSame(1, Refund::count(), 'the replay must not create a second refund');
        $this->assertSame(
            1,
            PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_REFUND_REVERSAL)->count(),
            'the replay must not debit the partner twice'
        );
    }

    public function test_t19_the_approved_amount_cannot_be_amended_once_money_is_in_flight(): void
    {
        $complaint = $this->approved($this->stay(), 50000);

        // Amendable while nothing has been executed.
        $this->actingAs($this->superadmin, 'admin-panel')
            ->patchJson("/admin/complaints/{$complaint->id}/approval", ['amountHalalas' => 40000])
            ->assertOk();

        $this->assertSame(40000, $complaint->fresh()->approved_refund_halalas);

        // The old value is on the record, not silently replaced.
        $this->assertDatabaseHas('audit_logs', ['action' => 'complaint.approval_amended']);
        $log = AuditLog::where('action', 'complaint.approval_amended')->latest('id')->first();
        $this->assertSame(50000, $log->before['approved_refund_halalas']);

        // A refund in flight closes the door.
        Refund::create([
            'booking_id' => $complaint->booking_id,
            'payment_id' => Payment::where('booking_id', $complaint->booking_id)->value('id'),
            'type' => Refund::TYPE_REFUND,
            'amount' => 400.00, 'refund_percent' => 40, 'status' => Refund::STATUS_PENDING,
            'complaint_id' => $complaint->id, 'reason' => Refund::REASON_COMPLAINT,
        ]);

        $this->actingAs($this->superadmin, 'admin-panel')
            ->patchJson("/admin/complaints/{$complaint->id}/approval", ['amountHalalas' => 30000])
            ->assertStatus(409);

        $this->assertSame(40000, $complaint->fresh()->approved_refund_halalas);
    }

    /* ================= settlement (T15) ================= */

    public function test_a_settled_refund_debits_the_partner_share_only(): void
    {
        $complaint = $this->approved($this->stay(), 50000);

        $this->actingAs($this->finance, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/refund", [
                'amountHalalas' => 50000, 'idempotencyKey' => (string) str()->uuid(),
            ])->assertOk();

        $entry = PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_REFUND_REVERSAL)->firstOrFail();

        // 391.30, not the 500.00 the guest received: VAT goes back to ZATCA and
        // the commission to Mamsa, neither of which the partner ever held.
        $this->assertEqualsWithDelta(-391.30, (float) $entry->amount, 0.001);
        $this->assertSame('refund', $entry->ref_type);
        $this->assertSame(BookingComplaint::STATUS_RESOLVED_REFUNDED, $complaint->fresh()->status);
    }

    /**
     * T15 — a Mamsa-owned unit debits nobody.
     *
     * `units.user_id` on one of those is the ADMIN who created the listing, so
     * a missing guard would take money out of an employee's wallet.
     */
    public function test_t15_a_mamsa_owned_unit_posts_no_ledger_entry(): void
    {
        $owned     = $this->makeUnit($this->superadmin, mamsaOwned: true);
        $booking   = $this->stay($owned, rate: 1.0);
        $complaint = $this->approved($booking, 50000);

        $this->actingAs($this->finance, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/refund", [
                'amountHalalas' => 50000, 'idempotencyKey' => (string) str()->uuid(),
            ])->assertOk();

        $this->assertSame(0, PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_REFUND_REVERSAL)->count());
        $this->assertSame(BookingComplaint::STATUS_RESOLVED_REFUNDED, $complaint->fresh()->status);
    }

    /* ================= disclosure (T13) ================= */

    public function test_t13_the_internal_note_never_reaches_the_guest_or_the_partner(): void
    {
        $booking   = $this->stay();
        $complaint = $this->complaint($booking);
        $complaint->update(['internal_note' => 'NOTE-FOR-ADMINS-ONLY', 'guest_message' => 'رسالة الضيف']);

        $guest = $this->actingAs($this->guest, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}/complaint")->assertOk();
        $this->assertStringNotContainsString('NOTE-FOR-ADMINS-ONLY', $guest->getContent());

        $partner = $this->actingAs($this->partner, 'dashboard')
            ->getJson("/me/complaints/{$complaint->id}")->assertOk();
        $this->assertStringNotContainsString('NOTE-FOR-ADMINS-ONLY', $partner->getContent());

        // The partner must not be handed a direct line to the complainant.
        $this->assertStringNotContainsString((string) $this->guest->phone, $partner->getContent());

        // The admin, by contrast, sees it — that is the whole point of the field.
        $admin = $this->actingAs($this->superadmin, 'admin-panel')
            ->getJson("/admin/complaints/{$complaint->id}")->assertOk();
        $this->assertStringContainsString('NOTE-FOR-ADMINS-ONLY', $admin->getContent());
    }
    /* ================= settlement attribution (T21) ================= */

    /**
     * T21 — one settlement event settles only the refund it covers.
     *
     * Two refunds can be pending on one payment: a cancellation refund and a
     * complaint refund. Moyasar has no refund object and its event carries no
     * per-refund id, so "flip every pending row" would settle both from a
     * single event — writing a ledger debit for money that never left, which is
     * precisely the harm the webhook-driven design exists to prevent.
     *
     * Attribution comes from the payment's cumulative `refunded` total.
     */
    public function test_t21_one_event_settles_only_the_refund_it_covers(): void
    {
        config(['moyasar.secret_key' => 'sk_test_fake', 'moyasar.webhook_secret' => 'whsec_test']);

        $booking = $this->stay();
        $booking->payment->update(['moyasar_id' => 'pay_t21']);

        // An older cancellation refund, still pending, worth 200.00.
        $cancellation = Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
            'type' => Refund::TYPE_REFUND, 'amount' => 200.00, 'refund_percent' => 20,
            'status' => Refund::STATUS_PENDING, 'reason' => Refund::REASON_OTHER,
        ]);

        // A complaint refund, also pending, worth 500.00.
        $complaint = $this->approved($booking, 50000);
        $complaintRefund = Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
            'complaint_id' => $complaint->id, 'reason' => Refund::REASON_COMPLAINT,
            'type' => Refund::TYPE_REFUND, 'amount' => 500.00, 'refund_percent' => 50,
            'status' => Refund::STATUS_PENDING,
        ]);

        // The gateway reports 200.00 refunded in total — the cancellation only.
        $this->postJson('/webhooks/moyasar', [
            'type' => 'payment_refunded', 'secret_token' => 'whsec_test',
            'data' => [
                'id' => 'pay_t21', 'refunded' => 20000,
                // A payment taken after the stamp went live carries it, and the
                // handler refuses one that does not.
                'metadata' => ['env' => config('app.env')],
            ],
        ])->assertOk();

        $this->assertSame(Refund::STATUS_SUCCEEDED, $cancellation->fresh()->status);
        $this->assertSame(
            Refund::STATUS_PENDING,
            $complaintRefund->fresh()->status,
            'the complaint refund was not covered by this event and must stay pending'
        );

        $this->assertSame(
            0,
            PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_REFUND_REVERSAL)->count(),
            'no partner may be debited for a refund the gateway has not settled'
        );

        $this->assertSame(BookingComplaint::STATUS_APPROVED, $complaint->fresh()->status);
    }

    /**
     * A total that does not decompose into whole pending rows settles nothing.
     *
     * The safe state under ambiguity is `pending`: a missing ledger entry is
     * added once a human looks, a wrong one in an append-only table is
     * corrected by a second entry and visible forever.
     */
    public function test_an_unattributable_total_settles_nothing(): void
    {
        config(['moyasar.secret_key' => 'sk_test_fake', 'moyasar.webhook_secret' => 'whsec_test']);

        $booking = $this->stay();
        $booking->payment->update(['moyasar_id' => 'pay_amb']);

        $complaint = $this->approved($booking, 50000);

        Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
            'complaint_id' => $complaint->id, 'reason' => Refund::REASON_COMPLAINT,
            'type' => Refund::TYPE_REFUND, 'amount' => 500.00, 'refund_percent' => 50,
            'status' => Refund::STATUS_PENDING,
        ]);

        // 300.00 matches no pending row and no combination of them.
        $this->postJson('/webhooks/moyasar', [
            'type' => 'payment_refunded', 'secret_token' => 'whsec_test',
            'data' => [
                'id' => 'pay_amb', 'refunded' => 30000,
                'metadata' => ['env' => config('app.env')],
            ],
        ])->assertOk();

        $this->assertSame(1, Refund::where('status', Refund::STATUS_PENDING)->count());
        $this->assertSame(0, PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_REFUND_REVERSAL)->count());

        // With no configured recipient list the alert falls back to every
        // active SuperAdmin — degrading to today's behaviour rather than to
        // silence, which is the whole point of the fallback.
        Notification::assertSentTo($this->superadmin, \App\Notifications\RefundSettlementAmbiguous::class);
    }
    /**
     * T22 — a total that more than one subset explains settles nothing.
     *
     * Rows of 100, 200 and 300 with a remainder of 300: {300} and {100,200}
     * both sum to it. Walking oldest-first would take {100,200} and settle two
     * refunds, when the one that actually cleared may have been the 300. The
     * rule is therefore uniqueness, not merely a match — finding *an* answer is
     * not the same as knowing it is *the* answer.
     */
    public function test_t22_an_ambiguous_subset_settles_nothing(): void
    {
        config(['moyasar.secret_key' => 'sk_test_fake', 'moyasar.webhook_secret' => 'whsec_test']);

        $booking = $this->stay();
        $booking->payment->update(['moyasar_id' => 'pay_t22']);

        foreach ([100.00, 200.00, 300.00] as $amount) {
            Refund::create([
                'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
                'type' => Refund::TYPE_REFUND, 'amount' => $amount,
                'refund_percent' => $amount / 10, 'status' => Refund::STATUS_PENDING,
                'reason' => Refund::REASON_OTHER,
            ]);
        }

        // 300.00 is explained by {300} and by {100,200}.
        $this->postJson('/webhooks/moyasar', [
            'type' => 'payment_refunded', 'secret_token' => 'whsec_test',
            'data' => [
                'id' => 'pay_t22', 'refunded' => 30000,
                'metadata' => ['env' => config('app.env')],
            ],
        ])->assertOk();

        $this->assertSame(3, Refund::where('status', Refund::STATUS_PENDING)->count(),
            'every row must stay pending while the event cannot be attributed');
        $this->assertSame(0, PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_REFUND_REVERSAL)->count());

        Notification::assertSentTo($this->superadmin, \App\Notifications\RefundSettlementAmbiguous::class);
    }

    /** The unambiguous case still settles — uniqueness must not mean paralysis. */
    public function test_a_single_matching_subset_still_settles(): void
    {
        config(['moyasar.secret_key' => 'sk_test_fake', 'moyasar.webhook_secret' => 'whsec_test']);

        $booking = $this->stay();
        $booking->payment->update(['moyasar_id' => 'pay_uniq']);

        $two = Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
            'type' => Refund::TYPE_REFUND, 'amount' => 200.00, 'refund_percent' => 20,
            'status' => Refund::STATUS_PENDING, 'reason' => Refund::REASON_OTHER,
        ]);
        $five = Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
            'type' => Refund::TYPE_REFUND, 'amount' => 500.00, 'refund_percent' => 50,
            'status' => Refund::STATUS_PENDING, 'reason' => Refund::REASON_OTHER,
        ]);

        // {200}=200 ✓  {500}=500 ✗  {200,500}=700 ✗  → exactly one subset.
        $this->postJson('/webhooks/moyasar', [
            'type' => 'payment_refunded', 'secret_token' => 'whsec_test',
            'data' => [
                'id' => 'pay_uniq', 'refunded' => 20000,
                'metadata' => ['env' => config('app.env')],
            ],
        ])->assertOk();

        $this->assertSame(Refund::STATUS_SUCCEEDED, $two->fresh()->status);
        $this->assertSame(Refund::STATUS_PENDING, $five->fresh()->status);
    }
    /**
     * A second execute with a NEW idempotency key, while the first is still in
     * flight, is refused.
     *
     * This is the gap the idempotency key does not cover: that catches the same
     * request twice, and this is a different request minutes later. It is easy
     * to reach because a successful execute leaves the refund `pending` and the
     * complaint `approved` — the complaint is only closed on settlement — so
     * the screen still offers the button while money is on its way.
     *
     * Without the guard, the amount check would pass too: the ceiling used to
     * count only `succeeded`, so the pending 500.00 was invisible to it.
     */
    public function test_a_second_execute_while_one_is_in_flight_is_refused(): void
    {
        config(['moyasar.secret_key' => 'sk_test_fake']);

        $booking = $this->stay();
        $booking->payment->update(['moyasar_id' => 'pay_inflight']);

        $this->mock(\App\Services\MoyasarService::class, function ($m) {
            // Accepted by the gateway, not settled — the refund stays pending.
            $m->shouldReceive('refund')->once()->andReturn(['id' => 'pay_inflight', 'status' => 'paid']);
        });

        $complaint = $this->approved($booking, 50000);

        $this->actingAs($this->finance, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/refund", [
                'amountHalalas' => 50000, 'idempotencyKey' => (string) str()->uuid(),
            ])->assertOk()->assertJsonPath('status', 'pending');

        $this->assertSame(BookingComplaint::STATUS_APPROVED, $complaint->fresh()->status,
            'the complaint stays approved until settlement — this is what exposes the button');

        // A genuinely new attempt, new key.
        $this->actingAs($this->finance, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/refund", [
                'amountHalalas' => 50000, 'idempotencyKey' => (string) str()->uuid(),
            ])->assertStatus(409)->assertJsonPath('code', 'REFUND_IN_FLIGHT');

        $this->assertSame(1, Refund::count(), 'no second refund row may be created');
        $this->assertSame(
            0,
            PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_REFUND_REVERSAL)->count(),
            'and nothing may reach the ledger — the first has not settled either'
        );
    }

    /** Money in flight lowers the ceiling, so it cannot be approved twice over. */
    public function test_a_pending_refund_counts_against_what_can_be_approved(): void
    {
        $booking = $this->stay();

        Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
            'type' => Refund::TYPE_REFUND, 'amount' => 600.00, 'refund_percent' => 60,
            'status' => Refund::STATUS_PENDING, 'reason' => Refund::REASON_OTHER,
        ]);

        $complaint = $this->complaint($booking);

        // 1000 gross − 600 pending = 400 left. 500 must be refused.
        $this->actingAs($this->superadmin, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/approve", ['amountHalalas' => 50000])
            ->assertStatus(422)->assertJsonPath('code', 'AMOUNT_EXCEEDS_REFUNDABLE');

        $this->actingAs($this->superadmin, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/approve", ['amountHalalas' => 40000])
            ->assertOk();
    }

    /** The detail payload separates settled money from money in flight. */
    public function test_the_detail_reports_pending_and_settled_separately(): void
    {
        $booking = $this->stay();

        Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
            'type' => Refund::TYPE_REFUND, 'amount' => 200.00, 'refund_percent' => 20,
            'status' => Refund::STATUS_PENDING, 'reason' => Refund::REASON_OTHER,
        ]);

        $complaint = $this->complaint($booking);

        $body = $this->actingAs($this->superadmin, 'admin-panel')
            ->getJson("/admin/complaints/{$complaint->id}")->assertOk()->json('booking');

        $this->assertSame(0, $body['alreadyRefundedHalalas']);
        $this->assertSame(20000, $body['pendingRefundHalalas']);
        $this->assertSame(80000, $body['maxRefundableHalalas']);
    }
    /* ================= rejection from approved ================= */

    /**
     * A complaint can be rejected after an amount was approved, as long as no
     * money has moved.
     *
     * The case is real: an amount is approved, then the partner produces
     * evidence the complaint was unfounded. Without this path the record has no
     * exit — nobody will execute it, and the only other close was from an
     * earlier state.
     */
    public function test_a_complaint_can_be_rejected_after_approval(): void
    {
        $complaint = $this->approved($this->stay(), 50000);

        $this->assertTrue($complaint->canReject());

        $this->actingAs($this->superadmin, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/reject", [
                'guestMessage' => 'تبيّن أن الشكوى غير صحيحة بعد مراجعة أدلة الشريك.',
            ])->assertOk();

        $this->assertSame(BookingComplaint::STATUS_RESOLVED_REJECTED, $complaint->fresh()->status);
        $this->assertSame(0, Refund::count());

        // The approved figure SURVIVES the rejection. It is part of the record
        // of what was decided: clearing it would hide that the complaint ever
        // reached approval, and the audit trail would show a rejection with no
        // sign of the amount that was once on the table.
        $this->assertSame(50000, $complaint->fresh()->approved_refund_halalas);
    }

    /**
     * But not once a refund is in flight.
     *
     * settle() would set `resolved_refunded` over the top, leaving a record that
     * contradicts both the decision and the message already sent to the guest.
     */
    public function test_a_complaint_cannot_be_rejected_once_a_refund_is_in_flight(): void
    {
        $complaint = $this->approved($this->stay(), 50000);

        Refund::create([
            'booking_id' => $complaint->booking_id,
            'payment_id' => Payment::where('booking_id', $complaint->booking_id)->value('id'),
            'complaint_id' => $complaint->id, 'reason' => Refund::REASON_COMPLAINT,
            'type' => Refund::TYPE_REFUND, 'amount' => 500.00, 'refund_percent' => 50,
            'status' => Refund::STATUS_PENDING,
        ]);

        $this->assertFalse($complaint->fresh()->canReject());

        $this->actingAs($this->superadmin, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/reject", [
                'guestMessage' => 'محاولة رفض بعد بدء التنفيذ',
            ])->assertStatus(409)->assertJsonPath('code', 'REFUND_IN_FLIGHT');

        $this->assertSame(BookingComplaint::STATUS_APPROVED, $complaint->fresh()->status);
    }

    /** The detail payload tells the screen whether rejection is still open. */
    public function test_the_detail_exposes_whether_rejection_is_still_possible(): void
    {
        $complaint = $this->approved($this->stay(), 50000);

        $body = $this->actingAs($this->superadmin, 'admin-panel')
            ->getJson("/admin/complaints/{$complaint->id}")->assertOk()->json('complaint');

        $this->assertTrue($body['canReject']);
        $this->assertTrue($body['canAmendApproval']);
    }
    /* ================= T23: settlement is the sink, and it is guarded ================= */

    /**
     * T23 — a settlement arriving on a rejected complaint writes the ledger but
     * not the status, and raises an alert.
     *
     * The guards on reject, amend and execute are entrances; this is the sink.
     * Guarding only the entrances leaves the next path anyone adds — an
     * automated rejection, an admin tool, a cleanup job — free to reopen the
     * hole with settle() carrying it out silently.
     *
     * The split is deliberate. The ledger records what HAPPENED and must reflect
     * money that moved; the status records what was DECIDED and must not be
     * overwritten. Suppressing both would be worse than either: money gone, and
     * nothing in the ledger saying so.
     *
     * The state is forced directly here, because every ordinary route into it is
     * now blocked — which is the point of the guards.
     */
    public function test_t23_settlement_on_a_rejected_complaint_writes_the_ledger_not_the_status(): void
    {
        $booking   = $this->stay();
        $complaint = $this->approved($booking, 50000);

        $refund = Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
            'complaint_id' => $complaint->id, 'reason' => Refund::REASON_COMPLAINT,
            'type' => Refund::TYPE_REFUND, 'amount' => 500.00, 'refund_percent' => 50,
            'amount_vat' => 65.22, 'amount_commission' => 43.48, 'amount_partner' => 391.30,
            'status' => Refund::STATUS_SUCCEEDED,
        ]);

        // Force the state the guards exist to prevent.
        $complaint->forceFill(['status' => BookingComplaint::STATUS_RESOLVED_REJECTED])->save();

        app(\App\Services\ComplaintRefundService::class)->settle($refund->fresh());

        // The money moved, so the ledger says so.
        $entry = PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_REFUND_REVERSAL)->first();
        $this->assertNotNull($entry, 'the ledger must record money that actually moved');
        $this->assertEqualsWithDelta(-391.30, (float) $entry->amount, 0.001);

        // The decision stands.
        $this->assertSame(
            BookingComplaint::STATUS_RESOLVED_REJECTED,
            $complaint->fresh()->status,
            'a recorded decision must not be overwritten by settlement'
        );

        Notification::assertSentTo(
            $this->superadmin,
            \App\Notifications\SettlementOnUnexpectedState::class
        );
    }

    /** The normal path is untouched: approved settles to resolved_refunded, silently. */
    public function test_settlement_from_approved_still_closes_the_complaint(): void
    {
        $complaint = $this->approved($this->stay(), 50000);

        $this->actingAs($this->finance, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/refund", [
                'amountHalalas' => 50000, 'idempotencyKey' => (string) str()->uuid(),
            ])->assertOk();

        $this->assertSame(BookingComplaint::STATUS_RESOLVED_REFUNDED, $complaint->fresh()->status);
        Notification::assertNotSentTo(
            $this->superadmin,
            \App\Notifications\SettlementOnUnexpectedState::class
        );
    }
    /**
     * A retry with the SAME key, while the first attempt is still pending,
     * returns the original outcome — not REFUND_IN_FLIGHT.
     *
     * This pins an ORDERING, which is why it exists as its own test: the key
     * check must run before the in-flight guard. Both orders are financially
     * safe — neither creates a second refund — so nothing would fail loudly if
     * they were swapped. What would change is what the admin is told: a retry
     * after a dropped response would read "someone has already started a
     * refund", which is their own first attempt, and sends them to support
     * instead of onward.
     *
     * T4 covers the same key twice on the settled path. This covers it while a
     * refund is genuinely in flight, which is the case the two checks contest.
     */
    public function test_a_retry_with_the_same_key_beats_the_in_flight_guard(): void
    {
        config(['moyasar.secret_key' => 'sk_test_fake']);

        $booking = $this->stay();
        $booking->payment->update(['moyasar_id' => 'pay_retry']);

        $this->mock(\App\Services\MoyasarService::class, function ($m) {
            // Once only: the retry must never reach the gateway again.
            $m->shouldReceive('refund')->once()->andReturn(['id' => 'pay_retry', 'status' => 'paid']);
        });

        $complaint = $this->approved($booking, 50000);
        $key       = (string) str()->uuid();

        $first = $this->actingAs($this->finance, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/refund", [
                'amountHalalas' => 50000, 'idempotencyKey' => $key,
            ])->assertOk()->json();

        $this->assertSame('pending', $first['status']);

        // The response was lost; the client resends with the SAME key.
        $retry = $this->actingAs($this->finance, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/refund", [
                'amountHalalas' => 50000, 'idempotencyKey' => $key,
            ])->assertOk()->json();

        $this->assertTrue($retry['replayed'], 'the retry must be reported as a replay');
        $this->assertSame($first['refundId'], $retry['refundId'], 'and must name the original refund');
        $this->assertSame(1, Refund::count());

        // And a genuinely different request is still refused.
        $this->actingAs($this->finance, 'admin-panel')
            ->postJson("/admin/complaints/{$complaint->id}/refund", [
                'amountHalalas' => 50000, 'idempotencyKey' => (string) str()->uuid(),
            ])->assertStatus(409)->assertJsonPath('code', 'REFUND_IN_FLIGHT');

        $this->assertSame(1, Refund::count());
    }
    /**
     * T24 — a SECOND settlement on an already-refunded complaint alerts.
     *
     * The exemption this replaces was keyed on the complaint's status, which
     * made `resolved_refunded` silent. But every caller settles a row it has
     * just moved out of `pending`, and a settled row is never moved again — so
     * arriving here with the complaint already closed means a second refund
     * settled against it. That is a second ledger entry and a second partner
     * debit, and it was the one case the exemption let through in silence.
     */
    public function test_t24_a_second_settlement_on_a_closed_complaint_alerts(): void
    {
        $booking   = $this->stay();
        $complaint = $this->approved($booking, 50000);

        // The first refund, already settled and closed.
        Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
            'complaint_id' => $complaint->id, 'reason' => Refund::REASON_COMPLAINT,
            'type' => Refund::TYPE_REFUND, 'amount' => 200.00, 'refund_percent' => 20,
            'amount_vat' => 26.09, 'amount_commission' => 17.39, 'amount_partner' => 156.52,
            'status' => Refund::STATUS_SUCCEEDED,
        ]);
        $complaint->forceFill(['status' => BookingComplaint::STATUS_RESOLVED_REFUNDED])->save();

        // A second refund on the same complaint reaches settlement.
        $second = Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
            'complaint_id' => $complaint->id, 'reason' => Refund::REASON_COMPLAINT,
            'type' => Refund::TYPE_REFUND, 'amount' => 500.00, 'refund_percent' => 50,
            'amount_vat' => 65.22, 'amount_commission' => 43.48, 'amount_partner' => 391.30,
            'status' => Refund::STATUS_SUCCEEDED,
        ]);

        app(\App\Services\ComplaintRefundService::class)->settle($second->fresh());

        // The money moved, so the entry is written for THIS refund.
        $entry = PartnerLedgerEntry::where('ref_type', 'refund')->where('ref_id', (string) $second->id)->first();
        $this->assertNotNull($entry);
        $this->assertEqualsWithDelta(-391.30, (float) $entry->amount, 0.001);

        // And a human is told, which the status-keyed exemption would not have done.
        Notification::assertSentTo(
            $this->superadmin,
            \App\Notifications\SettlementOnUnexpectedState::class
        );
    }

    /** settle() is safe to call twice for one refund: the webhook and the job can race. */
    public function test_settling_the_same_refund_twice_posts_one_ledger_entry(): void
    {
        $booking   = $this->stay();
        $complaint = $this->approved($booking, 50000);

        $refund = Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $booking->payment->id,
            'complaint_id' => $complaint->id, 'reason' => Refund::REASON_COMPLAINT,
            'type' => Refund::TYPE_REFUND, 'amount' => 500.00, 'refund_percent' => 50,
            'amount_vat' => 65.22, 'amount_commission' => 43.48, 'amount_partner' => 391.30,
            'status' => Refund::STATUS_SUCCEEDED,
        ]);

        $service = app(\App\Services\ComplaintRefundService::class);
        $service->settle($refund->fresh());
        $service->settle($refund->fresh());   // the reconciliation job, racing a late webhook

        $this->assertSame(
            1,
            PartnerLedgerEntry::where('ref_type', 'refund')->where('ref_id', (string) $refund->id)->count(),
            'one refund, one debit — the ledger is append-only and cannot be corrected by editing'
        );
    }
    /* ================= guest-surface error codes ================= */

    /**
     * Every refusal on the guest surface carries a stable `code`.
     *
     * Without one a client has to branch on Arabic prose to tell "outside the
     * window" from "already complained" — and the first person to improve the
     * wording breaks the app silently. The message is unchanged for anything
     * already rendering it; the code is additive.
     */
    public function test_guest_refusals_carry_a_machine_readable_code(): void
    {
        $booking = $this->stay();
        $body    = ['description' => str_repeat('م', 40), 'contacted_partner' => true];

        // not the complainant
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", $body)
            ->assertStatus(403)->assertJsonPath('code', 'NOT_YOUR_BOOKING');

        // before check-in
        $early = $this->stay();
        $early->update(['start_date' => now()->addDays(3), 'end_date' => now()->addDays(6)]);
        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$early->id}/complaint", $body)
            ->assertStatus(422)->assertJsonPath('code', 'WINDOW_NOT_OPEN');

        // past the window — 12:00 check-out on the 13th shuts at 12:00 on the 15th
        $late = $this->stay();
        $late->update(['end_date' => Carbon::parse('2026-09-13', 'Asia/Riyadh')]);
        $wasNow = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:01:00', 'Asia/Riyadh'));
        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$late->id}/complaint", $body)
            ->assertStatus(422)->assertJsonPath('code', 'WINDOW_CLOSED');
        Carbon::setTestNow($wasNow);

        // not a completed stay
        $open = $this->stay();
        $open->update(['status' => Booking::STATUS_CONFIRMED]);
        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$open->id}/complaint", $body)
            ->assertStatus(422)->assertJsonPath('code', 'BOOKING_NOT_COMPLETED');

        // duplicate
        $this->complaint($booking);
        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", $body)
            ->assertStatus(409)->assertJsonPath('code', 'COMPLAINT_ALREADY_EXISTS');

        // nothing filed yet
        $none = $this->stay();
        $this->actingAs($this->guest, 'sanctum')
            ->getJson("/api/v1/bookings/{$none->id}/complaint")
            ->assertStatus(404)->assertJsonPath('code', 'NO_COMPLAINT');
    }
    /**
     * `/me/complaints` returns every complaint, unpaginated.
     *
     * The partner dashboard depends on this. It links a ledger row to a
     * complaint by matching the row's `ref_code` (a booking code) against the
     * complaint list it already holds — no extra request, no guessing. That
     * only works while the list is complete.
     *
     * Adding pagination would break the link SILENTLY: rows past the first page
     * would simply stop being clickable, with no error anywhere. This test is
     * here so that change fails loudly instead, and so whoever makes it knows to
     * tell the frontend — at which point the answer is to add `complaintId` to
     * the ledger payload, which was deliberately not added now.
     */
    public function test_partner_complaints_are_returned_unpaginated(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->complaint($this->stay());
        }

        $body = $this->actingAs($this->partner, 'dashboard')
            ->getJson('/me/complaints')->assertOk()->json();

        $this->assertArrayHasKey('items', $body);
        $this->assertCount(3, $body['items'], 'every complaint must be present');

        foreach (['total', 'page', 'pageSize', 'links', 'meta', 'next_cursor'] as $paginationKey) {
            $this->assertArrayNotHasKey(
                $paginationKey,
                $body,
                "the partner list must stay unpaginated — the dashboard's ledger→complaint link depends on it"
            );
        }
    }
    /**
     * Every column named in an eager-load select must actually exist.
     *
     * This test exists because the suite CANNOT catch such a mistake by running
     * the query. Laravel quotes identifiers as "code", and SQLite has a
     * documented compatibility quirk: a double-quoted name that matches no
     * column is treated as a string LITERAL rather than rejected. So
     * `with('booking:id,code')` runs green on SQLite forever and returns a 500
     * on MySQL the moment a real session touches it — which is exactly what
     * happened to /me/complaints on staging.
     *
     * `bookings` has never had a `code` column, in any environment. Asking the
     * schema is engine-independent, so this catches the class the query never
     * could.
     */
    public function test_eager_load_selects_name_only_real_columns(): void
    {
        $files = [
            app_path('Http/Controllers/Dashboard/ComplaintController.php'),
            app_path('Http/Controllers/AdminPanel/ComplaintsController.php'),
            app_path('Http/Controllers/Api/V1/ComplaintController.php'),
        ];

        // 'booking:id,unit_id' → table `bookings`; 'booking.unit:id,name' → `units`
        $tableFor = [
            'booking'      => 'bookings',
            'booking.unit' => 'units',
            'user'         => 'users',
            'attachments'  => 'booking_complaint_attachments',
        ];

        $checked = 0;

        foreach ($files as $file) {
            // Comments are stripped first: this file's own docblocks quote the
            // very pattern being searched for, and a guard that trips on prose
            // about itself is a guard nobody keeps.
            $source = (string) file_get_contents($file);
            $source = preg_replace('#/\*.*?\*/#s', '', $source);
            $source = preg_replace('#//.*$#m', '', (string) $source);

            preg_match_all("/'([a-z.]+):([a-z_,]+)'/i", (string) $source, $m, PREG_SET_ORDER);

            foreach ($m as [$whole, $relation, $columns]) {
                if (! isset($tableFor[$relation])) {
                    continue;   // a relation this map does not cover
                }

                foreach (explode(',', $columns) as $column) {
                    $this->assertTrue(
                        Schema::hasColumn($tableFor[$relation], $column),
                        "{$whole} in ".basename($file)." selects `{$column}`, which does not exist on `{$tableFor[$relation]}`. "
                        .'SQLite will not fail on this — MySQL will, with a 500.'
                    );
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'the scan matched nothing — the pattern has drifted');
    }
    /**
     * The window boundary is in RIYADH time, not the app timezone.
     *
     * The app runs in UTC and the dates are cast to `date`, so they arrive as
     * UTC Carbons — and Carbon::parse() silently ignores its timezone argument
     * when given a Carbon instead of a string. That computed the whole window
     * three hours off: a guest could file three hours late, and could not file
     * during the first three hours of their check-in day.
     *
     * The clock is pinned inside the three-hour band where the two
     * interpretations disagree, so a regression cannot hide by running at a
     * convenient hour — which is exactly how the original bug survived.
     */
    public function test_the_window_closes_on_riyadh_time_not_utc(): void
    {
        // Check-out 12:00 on the 15th → the window shuts 12:00 Riyadh on the
        // 17th. Computed in UTC instead, it would shut at 12:00 UTC — three
        // hours later, i.e. 15:00 Riyadh. 13:30 Riyadh sits between the two:
        // outside under the correct rule, inside under the broken one.
        Carbon::setTestNow(Carbon::parse('2026-09-17 13:30:00', 'Asia/Riyadh'));

        $booking = $this->stay();
        $booking->update([
            'start_date' => Carbon::parse('2026-09-12', 'Asia/Riyadh'),
            'end_date'   => Carbon::parse('2026-09-15', 'Asia/Riyadh'),
        ]);

        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", [
                'description' => str_repeat('م', 40), 'contacted_partner' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'WINDOW_CLOSED');

        // And 90 minutes earlier — 11:00 Riyadh on the 17th — it is still open.
        Carbon::setTestNow(Carbon::parse('2026-09-17 11:00:00', 'Asia/Riyadh'));

        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", [
                'description' => str_repeat('م', 40), 'contacted_partner' => true,
            ])
            ->assertStatus(201);
    }
    /* ================= the window measures from check-out, not midnight ================= */

    /**
     * The 48 hours run from the unit's check-out time.
     *
     * Measuring from midnight of the check-out day gave a 12:00 check-out only
     * 36 hours, and the shortfall grew with every later time — R4 promises 48.
     * The platform records check-out per unit; the window now uses it.
     */
    public function test_the_window_runs_from_the_units_checkout_time(): void
    {
        $unit    = $this->makeUnit($this->partner, checkoutTime: '12:00');
        $booking = $this->stay($unit);
        $booking->update([
            'start_date' => Carbon::parse('2026-09-12', 'Asia/Riyadh'),
            'end_date'   => Carbon::parse('2026-09-15', 'Asia/Riyadh'),
        ]);

        $body = ['description' => str_repeat('م', 40), 'contacted_partner' => true];

        // 47h59 after a 12:00 check-out — still inside. Under the old rule this
        // was 11:59 PAST the deadline.
        Carbon::setTestNow(Carbon::parse('2026-09-17 11:59:00', 'Asia/Riyadh'));
        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", $body)
            ->assertStatus(201);

        BookingComplaint::query()->delete();

        // 48h01 — outside.
        Carbon::setTestNow(Carbon::parse('2026-09-17 12:01:00', 'Asia/Riyadh'));
        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", $body)
            ->assertStatus(422)
            ->assertJsonPath('code', 'WINDOW_CLOSED');
    }

    /**
     * A unit with NO recorded check-out time gets the 12:00 default, not
     * midnight.
     *
     * Five of the thirty-two units carry NULL. Treating that as 00:00 would
     * charge the guest twelve hours of their deadline for a gap in OUR data —
     * so a guest on such a unit gets exactly what a guest on a 12:00 unit gets.
     */
    public function test_a_unit_with_no_checkout_time_falls_back_to_noon(): void
    {
        $unit = $this->makeUnit($this->partner);          // checkout_time = null
        $this->assertNull($unit->checkout_time, 'the fixture must actually have none');

        $booking = $this->stay($unit);
        $booking->update([
            'start_date' => Carbon::parse('2026-09-12', 'Asia/Riyadh'),
            'end_date'   => Carbon::parse('2026-09-15', 'Asia/Riyadh'),
        ]);

        $body = ['description' => str_repeat('م', 40), 'contacted_partner' => true];

        // Identical boundaries to the 12:00 unit above.
        Carbon::setTestNow(Carbon::parse('2026-09-17 11:59:00', 'Asia/Riyadh'));
        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", $body)
            ->assertStatus(201);

        BookingComplaint::query()->delete();

        Carbon::setTestNow(Carbon::parse('2026-09-17 12:01:00', 'Asia/Riyadh'));
        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", $body)
            ->assertStatus(422)
            ->assertJsonPath('code', 'WINDOW_CLOSED');
    }

    /** A later check-out moves the deadline with it. */
    public function test_a_later_checkout_extends_the_deadline(): void
    {
        $unit    = $this->makeUnit($this->partner, checkoutTime: '13:00');
        $booking = $this->stay($unit);
        $booking->update([
            'start_date' => Carbon::parse('2026-09-12', 'Asia/Riyadh'),
            'end_date'   => Carbon::parse('2026-09-15', 'Asia/Riyadh'),
        ]);

        // 12:30 on day+2: past a 12:00 unit's deadline, inside a 13:00 one's.
        Carbon::setTestNow(Carbon::parse('2026-09-17 12:30:00', 'Asia/Riyadh'));

        $this->actingAs($this->guest, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/complaint", [
                'description' => str_repeat('م', 40), 'contacted_partner' => true,
            ])->assertStatus(201);
    }
}












