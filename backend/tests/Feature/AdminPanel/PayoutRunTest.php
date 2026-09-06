<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\BankDetail;
use App\Models\Booking;
use App\Models\PartnerLedgerEntry;
use App\Models\Payout;
use App\Models\PartnerWallet;
use App\Models\Unit;
use App\Models\User;
use App\Services\PayoutEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The monthly payout run — contract v2.2 §5.2. */
class PayoutRunTest extends TestCase
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

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('SuperAdmin');

        $this->partner = User::factory()->create(['is_active' => true, 'name' => 'شريك الاختبار']);
        $this->partner->assignRole('Individual');
        $this->partner->partnerDetail()->create(['type' => 'individual', 'national_id' => '1012345678']);

        $this->unit = $this->partner->units()->create([
            'unit_name' => 'استوديو', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 350, 'capacity' => 4, 'bedrooms' => 2, 'beds' => 3, 'bathrooms' => 1,
            'area' => 90, 'city' => 'جدة', 'district' => 'الشاطئ', 'lat' => 21.5, 'lng' => 39.1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);
    }

    private function verifiedBank(): BankDetail
    {
        return BankDetail::create([
            'partner_user_id' => $this->partner->id,
            'iban' => 'SA2480000000000000000000', 'account_holder_name' => 'شريك الاختبار',
            'bank_name' => 'مصرف الراجحي', 'verified' => true, 'verified_at' => now(),
        ]);
    }

    /** A completed stay worth 2940 to the partner. */
    private function earned(int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            Booking::create([
                'unit_id' => $this->unit->id, 'user_id' => User::factory()->create()->id,
                'code' => 'BK-'.fake()->unique()->numerify('####'),
                'start_date' => now()->subDays(5), 'end_date' => now()->subDays(2), 'guests' => 2,
                'subtotal' => 3000.00, 'taxes' => 450.00, 'commission_amount' => 60.00,
                'partner_share' => 2940.00, 'total_amount' => 3450.00,
                'status' => Booking::STATUS_COMPLETED,
            ]);
        }
    }

    /* ---- the run ---- */

    public function test_an_eligible_partner_is_listed_with_exactly_what_will_be_paid(): void
    {
        $this->verifiedBank();
        $this->earned(2);

        $rows = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts/eligible')->assertOk()->json();

        $row = collect($rows)->firstWhere('partnerId', 'prt_'.$this->partner->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(5880.00, $row['amount'], 0.001);
        $this->assertSame(2, $row['bookingsCount']);
        $this->assertSame('SA2480000000000000000000', $row['iban']);
    }

    public function test_each_ineligible_partner_carries_its_reason(): void
    {
        $this->earned();   // balance, but no bank account at all

        $rows = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts/ineligible')->assertOk()->json();

        $row = collect($rows)->firstWhere('partnerId', 'prt_'.$this->partner->id);

        $this->assertSame('bank_missing', $row['reason']);
        $this->assertEqualsWithDelta(2940.00, $row['availableBalance'], 0.001);
    }

    public function test_a_shortfall_is_reported_for_a_partner_below_the_minimum(): void
    {
        $this->verifiedBank();

        Booking::create([
            'unit_id' => $this->unit->id, 'user_id' => User::factory()->create()->id,
            'code' => 'BK-SMALL', 'start_date' => now()->subDays(3), 'end_date' => now()->subDay(),
            'guests' => 1, 'subtotal' => 500.00, 'taxes' => 75.00, 'commission_amount' => 10.00,
            'partner_share' => 490.00, 'total_amount' => 575.00, 'status' => Booking::STATUS_COMPLETED,
        ]);

        $rows = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts/ineligible')->assertOk()->json();
        $row = collect($rows)->firstWhere('partnerId', 'prt_'.$this->partner->id);

        $this->assertSame('below_minimum', $row['reason']);
        $this->assertEqualsWithDelta(1510.00, $row['shortfall'], 0.001, '2000 − 490');
    }

    public function test_recording_a_transfer_pays_the_balance_and_links_the_bookings(): void
    {
        $this->verifiedBank();
        $this->earned(2);

        $body = $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/payouts/record', [
                'partnerId' => 'prt_'.$this->partner->id,
                'bankReference' => 'FT-2026-0001',
            ])->assertOk()->json();

        $this->assertTrue($body['ok']);

        $payout = Payout::firstOrFail();
        $this->assertEqualsWithDelta(5880.00, $payout->amount, 0.001);
        $this->assertSame(2, (int) $payout->bookings_count);
        $this->assertSame('••••0000', $payout->iban_masked, 'never the full IBAN');
        $this->assertSame(2, Booking::where('payout_id', $payout->id)->count());

        // The debit lands in the ledger and the balance returns to zero.
        $debit = PartnerLedgerEntry::where('type', 'payout')->firstOrFail();
        $this->assertEqualsWithDelta(-5880.00, $debit->amount, 0.001);
        $this->assertEqualsWithDelta(0.00, $debit->balance_after, 0.001);
    }

    public function test_the_partner_sees_the_transfer_and_its_bookings(): void
    {
        $this->verifiedBank();
        $this->earned(2);

        $this->actingAs($this->admin, 'admin-panel')->postJson('/admin/payouts/record', [
            'partnerId' => 'prt_'.$this->partner->id, 'bankReference' => 'FT-2026-0002',
        ])->assertOk();

        $payout = Payout::firstOrFail();

        $detail = $this->actingAs($this->partner, 'dashboard')
            ->getJson("/payouts/po_{$payout->id}")->assertOk()->json();

        $this->assertEqualsWithDelta(
            $detail['amount'], collect($detail['bookings'])->sum('partnerShare'), 0.001,
            'contract §5 invariant 4 — the sheet shows this total back to the partner',
        );
        $this->assertSame('••••0000', $detail['ibanMasked']);
    }

    /* ---- the guards ---- */

    public function test_the_amount_and_iban_can_never_come_from_the_client(): void
    {
        $this->verifiedBank();
        $this->earned();

        $this->actingAs($this->admin, 'admin-panel')->postJson('/admin/payouts/record', [
            'partnerId' => 'prt_'.$this->partner->id,
            'bankReference' => 'FT-2026-0003',
            'amount' => 999999.99,
            'iban'   => 'SA0000000000000000000000',
        ])->assertOk();

        $payout = Payout::firstOrFail();
        $this->assertEqualsWithDelta(2940.00, $payout->amount, 0.001, 'the server decides what was owed');
        $this->assertSame('••••0000', $payout->iban_masked, 'and where it went');
    }

    public function test_a_repeated_bank_reference_is_refused(): void
    {
        $this->verifiedBank();
        $this->earned(2);

        $post = fn () => $this->actingAs($this->admin, 'admin-panel')->postJson('/admin/payouts/record', [
            'partnerId' => 'prt_'.$this->partner->id, 'bankReference' => 'FT-DUPLICATE',
        ]);

        $post()->assertOk();

        // A double-submitted form must not record the same transfer twice.
        $post()->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_BANK_REFERENCE');
        $this->assertSame(1, Payout::count());
    }

    public function test_a_partner_already_paid_this_month_is_refused(): void
    {
        $this->verifiedBank();
        $this->earned(2);

        $this->actingAs($this->admin, 'admin-panel')->postJson('/admin/payouts/record', [
            'partnerId' => 'prt_'.$this->partner->id, 'bankReference' => 'FT-FIRST',
        ])->assertOk();

        $this->earned(2);   // more money arrives in the same month

        $this->actingAs($this->admin, 'admin-panel')->postJson('/admin/payouts/record', [
            'partnerId' => 'prt_'.$this->partner->id, 'bankReference' => 'FT-SECOND',
        ])->assertStatus(409)->assertJsonPath('code', 'ALREADY_PAID_THIS_MONTH');
    }

    public function test_an_ineligible_partner_is_refused_at_the_moment_of_recording(): void
    {
        $this->earned();   // no bank account

        $this->actingAs($this->admin, 'admin-panel')->postJson('/admin/payouts/record', [
            'partnerId' => 'prt_'.$this->partner->id, 'bankReference' => 'FT-NOBANK',
        ])->assertStatus(409)->assertJsonPath('code', 'NOT_ELIGIBLE');

        $this->assertSame(0, Payout::count());
    }

    public function test_a_paid_booking_is_never_paid_again(): void
    {
        $this->verifiedBank();
        $this->earned(2);

        $this->actingAs($this->admin, 'admin-panel')->postJson('/admin/payouts/record', [
            'partnerId' => 'prt_'.$this->partner->id, 'bankReference' => 'FT-ONCE',
        ])->assertOk();

        // Same stays, a month later: nothing left to pay, so not in the run.
        $this->travel(1)->months();

        $rows = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts/eligible')->assertOk()->json();

        $this->assertNull(collect($rows)->firstWhere('partnerId', 'prt_'.$this->partner->id));
    }

    public function test_validation_messages_reach_the_admin_in_arabic(): void
    {
        // The panel renders `message` verbatim; a Laravel default would show
        // English on an otherwise Arabic screen.
        $body = $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/payouts/record', ['partnerId' => 'prt_'.$this->partner->id])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->json();

        $this->assertSame('رقم المرجع البنكي مطلوب', $body['message']);
    }

    /* ---- reversal ---- */

    public function test_reversing_returns_the_money_and_makes_the_stays_payable_again(): void
    {
        $this->verifiedBank();
        $this->earned(2);

        $this->actingAs($this->admin, 'admin-panel')->postJson('/admin/payouts/record', [
            'partnerId' => 'prt_'.$this->partner->id, 'bankReference' => 'FT-BOUNCE',
        ])->assertOk();

        $payout = Payout::firstOrFail();

        $this->artisan('payouts:reverse', ['reference' => $payout->reference, '--reason' => 'رفض البنك'])
            ->expectsConfirmation('Proceed?', 'yes')
            ->assertSuccessful();

        $payout->refresh();
        $this->assertSame('reversed', $payout->status);
        $this->assertNotNull($payout->reversed_at);

        // The credit comes back as an adjustment, and the record survives.
        $credit = PartnerLedgerEntry::where('type', 'adjustment')->firstOrFail();
        $this->assertEqualsWithDelta(5880.00, $credit->amount, 0.001);
        $this->assertEqualsWithDelta(5880.00, $credit->balance_after, 0.001);

        // Detached, so the same earnings can go out in the next run — otherwise
        // the credit would sit in the balance with no bookings to move it.
        $this->assertSame(0, Booking::whereNotNull('payout_id')->count());
    }

    public function test_a_reversed_transfer_leaves_lifetime_paid_out_untouched(): void
    {
        $this->verifiedBank();
        $this->earned(2);

        $this->actingAs($this->admin, 'admin-panel')->postJson('/admin/payouts/record', [
            'partnerId' => 'prt_'.$this->partner->id, 'bankReference' => 'FT-BOUNCE-2',
        ])->assertOk();

        $this->artisan('payouts:reverse', ['reference' => Payout::firstOrFail()->reference])
            ->expectsConfirmation('Proceed?', 'yes')->assertSuccessful();

        $summary = $this->actingAs($this->partner, 'dashboard')->getJson('/wallet')->assertOk()->json();

        $this->assertEqualsWithDelta(0.00, $summary['lifetimePaidOut'], 0.001, 'the money came back');
        $this->assertEqualsWithDelta(5880.00, $summary['availableBalance'], 0.001);
    }

    /* ---- bank verification ---- */

    public function test_verifying_a_bank_account_makes_the_partner_payable(): void
    {
        BankDetail::create([
            'partner_user_id' => $this->partner->id,
            'iban' => 'SA2480000000000000000000', 'account_holder_name' => 'شريك الاختبار',
            'bank_name' => 'مصرف الراجحي',   // saved by the partner, unverified
        ]);
        $this->earned(2);

        // Before: on the run, but blocked on verification.
        $rows = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts/ineligible')->assertOk()->json();
        $this->assertSame(
            'bank_unverified',
            collect($rows)->firstWhere('partnerId', 'prt_'.$this->partner->id)['reason'],
        );

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/wallets/prt_'.$this->partner->id.'/bank/verify')
            ->assertOk()->assertJsonPath('ok', true);

        // After: eligible, with the money attached.
        $eligible = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/payouts/eligible')->assertOk()->json();
        $row = collect($eligible)->firstWhere('partnerId', 'prt_'.$this->partner->id);

        $this->assertNotNull($row, 'verification is what unblocks the payout run');
        $this->assertEqualsWithDelta(5880.00, $row['amount'], 0.001);
    }

    public function test_verification_records_who_approved_the_destination(): void
    {
        BankDetail::create([
            'partner_user_id' => $this->partner->id,
            'iban' => 'SA2480000000000000000000', 'account_holder_name' => 'شريك الاختبار',
        ]);

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/wallets/prt_'.$this->partner->id.'/bank/verify')->assertOk();

        $bank = BankDetail::firstOrFail();
        $this->assertSame($this->admin->id, $bank->verified_by_admin_id);
        $this->assertNotNull($bank->verified_at);

        $detail = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/wallets/prt_'.$this->partner->id)->assertOk()->json();

        $this->assertSame($this->admin->name, $detail['bankDetails']['verifiedBy']);
    }

    public function test_rejecting_an_account_tells_the_partner_what_to_fix(): void
    {
        BankDetail::create([
            'partner_user_id' => $this->partner->id,
            'iban' => 'SA2480000000000000000000', 'account_holder_name' => 'اسم مختلف',
            'verified' => true, 'verified_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/wallets/prt_'.$this->partner->id.'/bank/reject', [
                'reason' => 'اسم صاحب الحساب لا يطابق اسم الشريك',
            ])->assertOk();

        // The partner's own screen is the only channel that explains this.
        $body = $this->actingAs($this->partner, 'dashboard')
            ->getJson('/me/bank-details')->assertOk()->json();

        $this->assertFalse($body['verified']);
        $this->assertSame('اسم صاحب الحساب لا يطابق اسم الشريك', $body['rejectionReason']);
        $this->assertNull($body['verifiedAt']);
    }

    public function test_a_rejection_requires_a_reason(): void
    {
        BankDetail::create([
            'partner_user_id' => $this->partner->id,
            'iban' => 'SA2480000000000000000000', 'account_holder_name' => 'شريك',
        ]);

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/wallets/prt_'.$this->partner->id.'/bank/reject', [])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_verifying_a_partner_with_no_account_is_a_404(): void
    {
        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/wallets/prt_'.$this->partner->id.'/bank/verify')
            ->assertStatus(404);
    }

    /**
     * Separation of duties: finance records transfers, so it must not also
     * approve where they go — one compromised finance session would otherwise
     * be able to point a payout at its own account and pay it.
     */
    public function test_finance_cannot_verify_a_bank_account(): void
    {
        Role::findOrCreate('finance', 'web');
        $finance = User::factory()->create(['is_active' => true]);
        $finance->assignRole('finance');

        BankDetail::create([
            'partner_user_id' => $this->partner->id,
            'iban' => 'SA2480000000000000000000', 'account_holder_name' => 'شريك',
        ]);

        $this->actingAs($finance, 'admin-panel')
            ->postJson('/admin/wallets/prt_'.$this->partner->id.'/bank/verify')
            ->assertForbidden()->assertJsonPath('code', 'INSUFFICIENT_PERMISSION');

        $this->assertFalse(BankDetail::firstOrFail()->verified);
    }

    /* ---- admin wallets ---- */

    public function test_the_admin_wallet_list_reflects_real_balances(): void
    {
        $this->verifiedBank();
        $this->earned(2);

        $body = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/wallets')->assertOk()->json();

        $row = collect($body['items'])->firstWhere('partnerId', 'prt_'.$this->partner->id);

        $this->assertEqualsWithDelta(5880.00, $row['availableBalance'], 0.001);
        $this->assertTrue($row['payoutEligible']);
        $this->assertNull($row['ineligibleReason']);
    }

    public function test_the_admin_wallet_detail_carries_bank_ledger_and_payouts(): void
    {
        $this->verifiedBank();
        $this->earned(2);

        $this->actingAs($this->admin, 'admin-panel')->postJson('/admin/payouts/record', [
            'partnerId' => 'prt_'.$this->partner->id, 'bankReference' => 'FT-DETAIL',
        ])->assertOk();

        $body = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/wallets/prt_'.$this->partner->id)->assertOk()->json();

        $this->assertSame('SA2480000000000000000000', $body['bankDetails']['iban']);
        $this->assertCount(3, $body['recentLedger'], '2 earnings + 1 payout');
        $this->assertCount(1, $body['recentPayouts']);
    }

    public function test_the_admin_ledger_paginates_by_cursor(): void
    {
        $this->verifiedBank();
        $this->earned(3);

        $first = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/wallets/prt_'.$this->partner->id.'/ledger?limit=2')->assertOk()->json();

        $this->assertCount(2, $first['items']);
        $this->assertTrue($first['hasMore']);
        $this->assertNotNull($first['nextCursor']);

        $second = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/wallets/prt_'.$this->partner->id.'/ledger?limit=2&before='.urlencode($first['nextCursor']))
            ->assertOk()->json();

        $this->assertFalse($second['hasMore']);
    }
    /* ---- T20: the eligibility race (v1.2 §3) ---- */

    /**
     * The balance a payout is judged against must be read INSIDE the
     * transaction that writes the payout.
     *
     * It was not: `record()` evaluated eligibility — including the
     * minimum-balance rule — and computed the payable amount before opening
     * its transaction and without any lock. A ledger debit landing in that
     * window (a refund settling, which after the complaints work is driven by
     * an inbound webhook and so can arrive at any instant) left the payout
     * recorded against a balance that had already changed. The accountant had
     * moved real money by then, so the stale figure was the one that persisted.
     *
     * Proving that by racing two requests is not possible in-process: one
     * connection cannot race itself, the same limitation
     * {@see \Tests\Feature\BookingAvailabilityTest} documents for double
     * bookings. What IS provable, and is the part the code controls, is the
     * ordering: the eligibility read must observe an open transaction. On the
     * pre-fix code it observes level 0 and this test fails.
     *
     * The row lock itself is a MySQL guarantee that SQLite ignores, so this
     * pins the ordering rather than the blocking; the lock is exercised for
     * real on staging and production, which run MySQL.
     *
     * The comparison is against a BASELINE, not against zero. RefreshDatabase
     * already holds a transaction open around every test, so `> 0` is true no
     * matter what the controller does — the first version of this test asserted
     * exactly that and passed against the unfixed code, proving nothing. What
     * distinguishes the two is whether the controller opens a FURTHER level of
     * its own before reading the balance.
     */
    public function test_eligibility_is_evaluated_inside_the_payout_transaction(): void
    {
        $this->verifiedBank();
        $this->earned(2);

        EligibilitySpy::$levelsAtReason  = [];
        EligibilitySpy::$levelsAtPayable = [];
        $this->app->instance(PayoutEligibility::class, new EligibilitySpy());

        // RefreshDatabase already has one transaction open; the controller must
        // add its own on top of it.
        $baseline = DB::transactionLevel();

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/payouts/record', [
                'partnerId'     => 'prt_'.$this->partner->id,
                'bankReference' => 'FT-2026-T20',
            ])->assertOk();

        $this->assertNotEmpty(
            EligibilitySpy::$levelsAtReason,
            'eligibility was never consulted — the test is not exercising the path it claims to'
        );

        $this->assertGreaterThan(
            $baseline,
            EligibilitySpy::$levelsAtReason[0],
            'the eligibility check ran outside the payout transaction: the balance it '
            .'judged can be changed by a concurrent ledger write before the payout is '
            ."inserted (baseline {$baseline}, observed ".EligibilitySpy::$levelsAtReason[0].')'
        );

        $this->assertGreaterThan(
            $baseline,
            EligibilitySpy::$levelsAtPayable[0],
            'the payable amount was computed outside the payout transaction'
        );
    }
}

/**
 * Records the transaction nesting level in force when eligibility is consulted.
 * A level of 0 means no transaction was open — and therefore no row lock was
 * held — at the moment the balance was judged.
 */
final class EligibilitySpy extends PayoutEligibility
{
    /** @var list<int> */
    public static array $levelsAtReason = [];

    /** @var list<int> */
    public static array $levelsAtPayable = [];

    public function reason(User $partner, ?PartnerWallet $wallet = null, ?BankDetail $bank = null, bool $adminView = false): ?string
    {
        self::$levelsAtReason[] = DB::transactionLevel();

        return parent::reason($partner, $wallet, $bank, $adminView);
    }

    /** @return array{amount: float, count: int} */
    public function payable(int $partnerUserId): array
    {
        self::$levelsAtPayable[] = DB::transactionLevel();

        return parent::payable($partnerUserId);
    }
}

