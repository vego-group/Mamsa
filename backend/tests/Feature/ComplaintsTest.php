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
use Illuminate\Support\Facades\Notification;
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

    private function makeUnit(User $owner, bool $mamsaOwned = false): Unit
    {
        return $owner->units()->create([
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
        // 48h + a minute after checkout, in Riyadh time.
        $booking = $this->stay();
        $booking->update(['end_date' => now('Asia/Riyadh')->subHours(48)->subMinute()]);

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
}


