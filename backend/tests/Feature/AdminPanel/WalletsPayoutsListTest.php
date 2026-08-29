<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\BankDetail;
use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\PartnerLedgerEntry;
use App\Models\Payout;
use App\Models\Unit;
use App\Models\User;
use App\Services\PartnerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The three admin surfaces added for the wallets/payouts screens:
 * GET /admin/wallets/stats, GET /admin/payouts, POST /admin/partners/:id/reactivate.
 */
class WalletsPayoutsListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $partner;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Company', 'User', 'SuperAdmin'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'مدير النظام']);
        $this->admin->assignRole('SuperAdmin');

        $this->partner = $this->partner('شريك الاختبار');
        $this->unit    = $this->unitFor($this->partner);
    }

    private function partner(string $name): User
    {
        $p = User::factory()->create(['is_active' => true, 'name' => $name]);
        $p->assignRole('Individual');
        $p->partnerDetail()->create([
            'type' => 'individual', 'national_id' => fake()->numerify('10########'),
            'status' => PartnerDetail::STATUS_APPROVED,
        ]);

        return $p;
    }

    private function unitFor(User $p): Unit
    {
        return $p->units()->create([
            'unit_name' => 'استوديو', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 350, 'capacity' => 4, 'bedrooms' => 2, 'beds' => 3, 'bathrooms' => 1,
            'area' => 90, 'city' => 'جدة', 'district' => 'الشاطئ', 'lat' => 21.5, 'lng' => 39.1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);
    }

    private function verifiedBank(User $p): BankDetail
    {
        return BankDetail::create([
            'partner_user_id' => $p->id,
            'iban' => 'SA2480000000000000000000', 'account_holder_name' => $p->name,
            'bank_name' => 'مصرف الراجحي', 'verified' => true, 'verified_at' => now(),
        ]);
    }

    /** A completed stay worth 2940 to the partner. */
    private function earned(Unit $unit, int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            Booking::create([
                'unit_id' => $unit->id, 'user_id' => User::factory()->create()->id,
                'code' => 'BK-'.fake()->unique()->numerify('####'),
                'start_date' => now()->subDays(5), 'end_date' => now()->subDays(2), 'guests' => 2,
                'subtotal' => 3000.00, 'taxes' => 450.00, 'commission_amount' => 60.00,
                'partner_share' => 2940.00, 'total_amount' => 3450.00,
                'status' => Booking::STATUS_COMPLETED,
            ]);
        }
    }

    private function stats(): array
    {
        return $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/wallets/stats')->assertOk()->json();
    }

    /* ---- GET /admin/wallets/stats ---- */

    /**
     * The bug that hid this endpoint for months: `wallets/stats` matched
     * `wallets/{partnerId}` with the id "stats" and answered NOT_FOUND *after*
     * authentication — alive to an unauthenticated probe, dead for a signed-in
     * admin, and silent in the console either way.
     */
    public function test_stats_is_its_own_route_and_not_a_partner_named_stats(): void
    {
        $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/wallets/stats')
            ->assertOk()
            ->assertJsonStructure(['totalAvailable', 'eligibleCount', 'eligibleAmount', 'partnersCount']);
    }

    /**
     * The whole value of the tiles is that they cannot disagree with the run
     * beneath them, so they are asserted against it rather than against a
     * hand-computed figure.
     */
    public function test_the_eligible_tiles_equal_the_payout_run(): void
    {
        $this->verifiedBank($this->partner);
        $this->earned($this->unit, 2);

        $stats = $this->stats();
        $rows  = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts/eligible')->assertOk()->json();

        $this->assertSame(count($rows), $stats['eligibleCount']);
        $this->assertEqualsWithDelta(
            collect($rows)->sum('amount'), $stats['eligibleAmount'], 0.01,
            'a tile must never promise money the run does not list',
        );
        $this->assertEqualsWithDelta(5880.00, $stats['eligibleAmount'], 0.01);
    }

    /**
     * A partner eligible on balance but with no unpaid stay left to attach the
     * money to is dropped by /admin/payouts/eligible. The tile has to drop them
     * too, or the count offers a row the run will not show.
     */
    public function test_a_partner_with_balance_but_nothing_payable_is_not_counted_eligible(): void
    {
        $this->verifiedBank($this->partner);

        app(PartnerWalletService::class)->post(
            partnerUserId: $this->partner->id,
            type: PartnerLedgerEntry::TYPE_ADJUSTMENT,
            amount: 3000.00,
            refType: 'manual',
            description: 'تسوية يدوية',
        );

        $stats = $this->stats();

        $this->assertSame(0, $stats['eligibleCount']);
        $this->assertSame(1, $stats['nothingPayableCount']);
        $this->assertEmpty($this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts/eligible')->json());
    }

    /** Every partner lands in exactly one bucket, so the row adds up on screen. */
    public function test_the_buckets_sum_to_the_partner_count(): void
    {
        $this->verifiedBank($this->partner);
        $this->earned($this->unit);              // eligible

        $noBank = $this->partner('بدون حساب');
        $this->earned($this->unitFor($noBank));  // bank_missing

        $unverified = $this->partner('غير موثق');
        BankDetail::create([
            'partner_user_id' => $unverified->id, 'iban' => 'SA4420000001234567891234',
            'account_holder_name' => 'غير موثق', 'bank_name' => 'الراجحي', 'verified' => false,
        ]);
        $this->earned($this->unitFor($unverified));  // bank_unverified

        $suspended = $this->partner('موقوف');
        $suspended->update(['is_active' => false]);  // partner_suspended

        $stats = $this->stats();

        $buckets = $stats['eligibleCount'] + $stats['belowMinimumCount'] + $stats['bankUnverifiedCount']
            + $stats['bankMissingCount'] + $stats['negativeBalanceCount'] + $stats['alreadyPaidCount']
            + $stats['suspendedCount'] + $stats['nothingPayableCount'];

        $this->assertSame(4, $stats['partnersCount']);
        $this->assertSame($stats['partnersCount'], $buckets, 'a count row that does not add up is a count row nobody trusts');
        $this->assertSame(1, $stats['bankMissingCount']);
        $this->assertSame(1, $stats['bankUnverifiedCount']);
        $this->assertSame(1, $stats['suspendedCount']);
        $this->assertSame(1, $stats['eligibleCount']);
    }

    /* ---- GET /admin/payouts ---- */

    private function payout(User $p, string $month, float $amount, string $status = Payout::STATUS_PAID): Payout
    {
        return Payout::create([
            'partner_user_id' => $p->id,
            'reference'       => 'PO-'.$month.'-'.fake()->unique()->numerify('####'),
            'period_month'    => $month, 'amount' => $amount, 'bookings_count' => 2,
            'currency' => 'SAR', 'iban_masked' => '••••0000', 'bank_name' => 'مصرف الراجحي',
            'status' => $status, 'paid_at' => $month.'-15 10:00:00',
            'bank_reference' => 'FT'.fake()->unique()->numerify('#########'),
        ]);
    }

    public function test_payouts_are_listed_for_one_month_with_the_partner_name(): void
    {
        $this->payout($this->partner, '2026-07', 5880.00);
        $this->payout($this->partner, '2026-06', 1000.00);

        $body = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts?periodMonth=2026-07')->assertOk()->json();

        $this->assertSame(1, $body['total']);
        $this->assertSame('شريك الاختبار', $body['items'][0]['partnerName']);
        $this->assertSame('prt_'.$this->partner->id, $body['items'][0]['partnerId']);
        $this->assertEqualsWithDelta(5880.00, $body['items'][0]['amount'], 0.01);
        $this->assertSame('••••0000', $body['items'][0]['ibanMasked']);
    }

    /**
     * The month total is the reason this endpoint exists — and reversed money
     * came back, so counting it would overstate the month an accountant is
     * closing.
     */
    public function test_the_month_total_excludes_reversed_transfers(): void
    {
        $this->payout($this->partner, '2026-07', 5880.00);
        $this->payout($this->partner, '2026-07', 2000.00, Payout::STATUS_REVERSED);

        $body = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts?periodMonth=2026-07')->assertOk()->json();

        $this->assertSame(2, $body['total'], 'the reversed row is still listed');
        $this->assertEqualsWithDelta(5880.00, $body['totalAmount'], 0.01);
    }

    /** The total covers the whole filter, not the page the client happens to be on. */
    public function test_the_total_covers_the_filter_not_the_page(): void
    {
        foreach (range(1, 3) as $i) {
            $this->payout($this->partner, '2026-07', 1000.00);
        }

        $body = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts?periodMonth=2026-07&pageSize=1')->assertOk()->json();

        $this->assertCount(1, $body['items']);
        $this->assertSame(3, $body['total']);
        $this->assertEqualsWithDelta(3000.00, $body['totalAmount'], 0.01);
    }

    /**
     * A malformed month matches nothing, and an empty list reads as "we paid
     * nobody in July" — a wrong answer to a reconciliation question is worse
     * than an error.
     */
    public function test_a_malformed_period_month_is_rejected_rather_than_answered_empty(): void
    {
        $this->payout($this->partner, '2026-07', 5880.00);

        $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts?periodMonth=2026-7')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_the_list_row_and_the_wallet_preview_share_one_shape(): void
    {
        $this->payout($this->partner, '2026-07', 5880.00);

        $list = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts?periodMonth=2026-07')->assertOk()->json('items.0');

        $detail = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/wallets/prt_'.$this->partner->id)->assertOk()->json('recentPayouts.0');

        $this->assertSame($list, $detail);
    }

    /* ---- POST /admin/partners/:id/reactivate ---- */

    public function test_reactivating_clears_the_suspension_reason_and_restores_eligibility(): void
    {
        $this->verifiedBank($this->partner);
        $this->earned($this->unit);

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/partners/'.$this->partner->id.'/suspend', ['reason' => 'شكاوى متكررة'])
            ->assertOk();

        $this->assertSame('شكاوى متكررة', $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/partners/'.$this->partner->id)->json('suspensionReason'));

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/partners/'.$this->partner->id.'/reactivate')->assertOk();

        $this->partner->refresh();
        $this->assertTrue((bool) $this->partner->is_active);
        $this->assertNull($this->partner->partnerDetail->fresh()->suspension_reason);
        $this->assertNull($this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/partners/'.$this->partner->id)->json('suspensionReason'));

        // The partner is payable again the moment the flag flips.
        $this->assertSame(1, $this->stats()['eligibleCount']);
    }

    public function test_an_active_partner_cannot_be_reactivated(): void
    {
        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/partners/'.$this->partner->id.'/reactivate')
            ->assertStatus(409);
    }

    /**
     * An invited partner who never completed KYC is inactive too — reactivating
     * them here would put them live without a review.
     */
    public function test_a_pending_partner_cannot_be_activated_through_reactivate(): void
    {
        $pending = $this->partner('قيد المراجعة');
        $pending->update(['is_active' => false]);
        $pending->partnerDetail->update(['status' => PartnerDetail::STATUS_PENDING]);

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/partners/'.$pending->id.'/reactivate')
            ->assertStatus(409);
    }

    /* ---- one money basis on the bookings screen ---- */

    /**
     * The detail row and the stats total above it must agree on one number.
     *
     * They once disagreed by imputing from different bases — 2% of gross on the
     * row, 2% of the subtotal in the total, 23.00 against 20.00 for one stay.
     * Neither imputes now: both read the amount frozen on the booking, so they
     * cannot drift apart at all, and a booking that owes no commission reports
     * none instead of having a plausible figure invented for it.
     */
    public function test_the_detail_row_reports_the_frozen_commission_verbatim(): void
    {
        $booking = Booking::create([
            'unit_id' => $this->unit->id, 'user_id' => User::factory()->create()->id,
            'code' => 'BK-'.fake()->unique()->numerify('####'),
            'start_date' => now()->subDays(5), 'end_date' => now()->subDays(2), 'guests' => 2,
            // The real pre-conversion shape: commission never captured, so the
            // share was backfilled as `subtotal − 0`.
            'subtotal' => 1000.00, 'taxes' => 150.00, 'commission_amount' => 0,
            'partner_share' => 1000.00, 'total_amount' => 1150.00,
            'status' => Booking::STATUS_COMPLETED,
        ]);

        $row = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/bookings/'.$booking->id)->assertOk()->json();

        $this->assertEqualsWithDelta(0.00, $row['commission'], 0.01, 'a zero is a zero, not a cue to guess');

        // And a frozen amount comes back exactly, from whatever rate it was
        // taken under — no recomputation against today's rate.
        $booking->forceFill(['commission_rate' => 0.10, 'commission_amount' => 100.00])->save();

        $again = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/bookings/'.$booking->id)->assertOk()->json();

        $this->assertEqualsWithDelta(100.00, $again['commission'], 0.01);
        $this->assertEqualsWithDelta(0.10, $again['commissionRate'], 0.0001);
    }
}
