<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\BankDetail;
use App\Models\Booking;
use App\Models\PartnerLedgerEntry;
use App\Models\Payout;
use App\Models\Unit;
use App\Models\User;
use App\Services\PartnerWalletService;
use App\Support\Iban;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Partner wallet, ledger, payouts and bank details — wallet contract §1–6. */
class WalletTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Company', 'User', 'SuperAdmin'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');

        $this->unit = $this->partner->units()->create([
            'unit_name' => 'استوديو مرسى', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 350, 'capacity' => 4, 'bedrooms' => 2, 'beds' => 3, 'bathrooms' => 1,
            'area' => 90, 'city' => 'جدة', 'district' => 'الشاطئ', 'lat' => 21.5, 'lng' => 39.1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);
    }

    /** A booking priced VAT-inclusive: gross 3450 → netBase 3000, commission 60, share 2940. */
    private function booking(string $status = Booking::STATUS_CONFIRMED, array $over = []): Booking
    {
        return Booking::create(array_merge([
            'unit_id'           => $this->unit->id,
            'user_id'           => User::factory()->create()->id,
            'code'              => 'BK-'.fake()->unique()->numerify('####'),
            'start_date'        => now()->subDays(5),
            'end_date'          => now()->subDays(2),
            'guests'            => 2,
            'subtotal'          => 3000.00,
            'taxes'             => 450.00,
            'commission_amount' => 60.00,
            'partner_share'     => 2940.00,
            'total_amount'      => 3450.00,
            'status'            => $status,
        ], $over));
    }

    /* ---- §5 earning lifecycle ---- */

    public function test_a_finished_stay_credits_the_partner_wallet(): void
    {
        $booking = $this->booking();

        $this->assertSame(0, PartnerLedgerEntry::count(), 'a confirmed stay has not earned yet');

        $booking->update(['status' => Booking::STATUS_COMPLETED]);

        $entry = PartnerLedgerEntry::firstOrFail();
        $this->assertSame('earning', $entry->type);
        $this->assertEqualsWithDelta(2940.00, $entry->amount, 0.001);
        $this->assertEqualsWithDelta(2940.00, $entry->balance_after, 0.001);
    }

    public function test_a_booking_cannot_be_credited_twice(): void
    {
        $booking = $this->booking();
        $booking->update(['status' => Booking::STATUS_COMPLETED]);

        // Re-running the sweep, or an admin re-saving the same status.
        app(PartnerWalletService::class)->recordEarning($booking->fresh());
        $booking->fresh()->update(['status' => Booking::STATUS_COMPLETED]);

        $this->assertSame(1, PartnerLedgerEntry::where('type', 'earning')->count());
    }

    public function test_the_daily_sweep_credits_completed_stays(): void
    {
        $this->booking(Booking::STATUS_CONFIRMED, ['end_date' => now()->subDay()]);

        $this->artisan('bookings:complete')->assertSuccessful();

        // A mass UPDATE would fire no model events and pay nobody.
        $this->assertSame(1, PartnerLedgerEntry::where('type', 'earning')->count());
    }

    /* ---- §1 summary ---- */

    public function test_summary_separates_pending_from_available(): void
    {
        $this->booking();                                   // confirmed → pending
        $this->booking()->update(['status' => Booking::STATUS_COMPLETED]); // → available

        $body = $this->actingAs($this->partner, 'dashboard')
            ->getJson('/wallet')->assertOk()->json();

        $this->assertEqualsWithDelta(2940.00, $body['availableBalance'], 0.001);
        $this->assertEqualsWithDelta(2940.00, $body['pendingBalance'], 0.001);
        $this->assertEqualsWithDelta(2940.00, $body['lifetimeEarnings'], 0.001);
        $this->assertSame('SAR', $body['currency']);
    }

    public function test_ineligibility_reasons_are_ordered_so_a_blocker_outranks_the_threshold(): void
    {
        $body = fn () => $this->actingAs($this->partner, 'dashboard')->getJson('/wallet')->json();

        // No bank account at all.
        $this->assertFalse($body()['payoutEligible']);
        $this->assertSame('bank_missing', $body()['ineligibleReason']);

        BankDetail::create([
            'partner_user_id' => $this->partner->id,
            'iban' => 'SA0380000000608010167519', 'account_holder_name' => 'شريك',
        ]);
        $this->assertSame('bank_unverified', $body()['ineligibleReason']);

        BankDetail::where('partner_user_id', $this->partner->id)
            ->update(['verified' => true, 'verified_at' => now()]);
        $this->assertSame('below_minimum', $body()['ineligibleReason']);

        // Suspension outranks the balance threshold: telling a suspended
        // partner to keep earning is advice that cannot help them.
        $this->partner->update(['is_active' => false]);
        $this->assertSame('partner_suspended', $body()['ineligibleReason']);
    }

    public function test_a_suspended_partner_still_sees_their_balance(): void
    {
        $this->partner->update(['is_active' => false]);

        $this->actingAs($this->partner, 'dashboard')->getJson('/wallet')
            ->assertOk()   // §7: 200 + reason, not 403
            ->assertJsonPath('ineligibleReason', 'partner_suspended');
    }

    /* ---- §2 ledger ---- */

    public function test_the_newest_ledger_balance_equals_the_available_balance(): void
    {
        $this->booking()->update(['status' => Booking::STATUS_COMPLETED]);
        $this->booking()->update(['status' => Booking::STATUS_COMPLETED]);

        $ledger  = $this->actingAs($this->partner, 'dashboard')->getJson('/wallet/ledger')->assertOk()->json();
        $summary = $this->actingAs($this->partner, 'dashboard')->getJson('/wallet')->assertOk()->json();

        $this->assertCount(2, $ledger);
        $this->assertEqualsWithDelta(
            $summary['availableBalance'], $ledger[0]['balanceAfter'], 0.001,
            'contract §5 invariant 1',
        );
    }

    public function test_the_ledger_rejects_an_out_of_range_limit(): void
    {
        $this->actingAs($this->partner, 'dashboard')->getJson('/wallet/ledger?limit=500')
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION');
    }

    public function test_a_partner_never_sees_another_partners_ledger(): void
    {
        $this->booking()->update(['status' => Booking::STATUS_COMPLETED]);

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Individual');

        $this->actingAs($other, 'dashboard')->getJson('/wallet/ledger')
            ->assertOk()->assertJsonCount(0);
    }

    /* ---- §3 / §4 payouts ---- */

    public function test_payout_detail_bookings_sum_to_the_transfer_amount(): void
    {
        $payout = Payout::create([
            'partner_user_id' => $this->partner->id, 'reference' => 'PO-2026-06',
            'period_month' => '2026-05', 'amount' => 5880.00, 'bookings_count' => 2,
            'iban_masked' => '••••7519', 'bank_name' => 'مصرف الراجحي',
            'status' => 'paid', 'paid_at' => now()->subDay(),
        ]);

        foreach ([1, 2] as $i) {
            $this->booking(Booking::STATUS_COMPLETED)->update(['payout_id' => $payout->id]);
        }

        $body = $this->actingAs($this->partner, 'dashboard')
            ->getJson("/payouts/po_{$payout->id}")->assertOk()->json();

        $this->assertCount(2, $body['bookings']);
        $this->assertEqualsWithDelta(
            $body['amount'], collect($body['bookings'])->sum('partnerShare'), 0.001,
            'contract §5 invariant 4',
        );
    }

    public function test_another_partners_payout_is_a_404_not_a_403(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Individual');

        $payout = Payout::create([
            'partner_user_id' => $other->id, 'reference' => 'PO-2026-07',
            'period_month' => '2026-06', 'amount' => 100.00, 'bookings_count' => 0,
            'iban_masked' => '••••1234', 'status' => 'paid', 'paid_at' => now(),
        ]);

        // 403 would confirm the id exists.
        $this->actingAs($this->partner, 'dashboard')->getJson("/payouts/po_{$payout->id}")
            ->assertStatus(404)->assertJsonPath('error.code', 'PAYOUT_NOT_FOUND');
    }

    public function test_a_reversed_payout_is_excluded_from_lifetime_paid_out(): void
    {
        foreach ([['paid', 'PO-A'], ['reversed', 'PO-B']] as [$status, $ref]) {
            Payout::create([
                'partner_user_id' => $this->partner->id, 'reference' => $ref,
                'period_month' => '2026-05', 'amount' => 1000.00, 'bookings_count' => 1,
                'iban_masked' => '••••7519', 'status' => $status, 'paid_at' => now()->subDays(2),
            ]);
        }

        $body = $this->actingAs($this->partner, 'dashboard')->getJson('/wallet')->assertOk()->json();

        $this->assertEqualsWithDelta(1000.00, $body['lifetimePaidOut'], 0.001, 'contract §5 invariant 3');
    }

    /* ---- §6 bank details ---- */

    public function test_a_partner_with_no_account_gets_200_and_a_null_body(): void
    {
        $response = $this->actingAs($this->partner, 'dashboard')->getJson('/me/bank-details');

        $response->assertOk();  // a 404 would render a full error state on the account page

        // The literal bytes matter: `{}` would read as "an account with blank
        // fields" rather than "no account yet".
        $this->assertSame('null', $response->getContent());
    }

    public function test_saving_an_account_derives_the_bank_and_starts_unverified(): void
    {
        $body = $this->actingAs($this->partner, 'dashboard')->putJson('/me/bank-details', [
            'iban' => 'SA03 8000 0000 6080 1016 7519',   // spaced — the server normalizes
            'accountHolderName' => 'شركة ممسى للضيافة',
        ])->assertOk()->json();

        $this->assertSame('SA0380000000608010167519', $body['iban']);
        $this->assertSame('مصرف الراجحي', $body['bankName']);
        $this->assertFalse($body['verified']);
    }

    public function test_changing_the_iban_resets_verification(): void
    {
        BankDetail::create([
            'partner_user_id' => $this->partner->id,
            'iban' => 'SA0380000000608010167519', 'account_holder_name' => 'شريك',
            'verified' => true, 'verified_at' => now(),
        ]);

        $body = $this->actingAs($this->partner, 'dashboard')->putJson('/me/bank-details', [
            'iban' => 'SA4420000001234567891234', 'accountHolderName' => 'شريك',
        ])->assertOk()->json();

        $this->assertFalse($body['verified'], 'finance verifies one specific account number');
        $this->assertNull($body['verifiedAt']);
    }

    public function test_re_saving_the_same_iban_keeps_verification(): void
    {
        BankDetail::create([
            'partner_user_id' => $this->partner->id,
            'iban' => 'SA0380000000608010167519', 'account_holder_name' => 'شريك',
            'verified' => true, 'verified_at' => now(),
        ]);

        $body = $this->actingAs($this->partner, 'dashboard')->putJson('/me/bank-details', [
            'iban' => 'SA0380000000608010167519', 'accountHolderName' => 'اسم جديد',
        ])->assertOk()->json();

        $this->assertTrue($body['verified'], 'only the account NUMBER changing invalidates it');
    }

    public function test_a_bad_checksum_is_rejected(): void
    {
        // Correct shape, one digit off — the case a shape-only check would pass.
        $this->actingAs($this->partner, 'dashboard')->putJson('/me/bank-details', [
            'iban' => 'SA0380000000608010167518', 'accountHolderName' => 'شريك',
        ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_IBAN');
    }

    public function test_the_kyc_iban_is_kept_in_step(): void
    {
        $this->actingAs($this->partner, 'dashboard')->putJson('/me/bank-details', [
            'iban' => 'SA0380000000608010167519', 'accountHolderName' => 'شريك',
        ])->assertOk();

        // The admin KYC screen and documentsComplete() read this column.
        $this->assertSame('SA0380000000608010167519', $this->partner->fresh()->partnerDetail->iban);
    }

    /* ---- IBAN support ---- */

    public function test_iban_checksum_validation(): void
    {
        $this->assertTrue(Iban::isValid('SA0380000000608010167519'));
        $this->assertTrue(Iban::isValid('sa03 8000 0000 6080 1016 7519'));
        $this->assertFalse(Iban::isValid('SA0380000000608010167518'), 'one digit off');
        $this->assertFalse(Iban::isValid('SA038000000060801016751'), 'too short');
        $this->assertFalse(Iban::isValid('GB82WEST12345698765432'), 'not Saudi');
        $this->assertSame('••••7519', Iban::mask('SA0380000000608010167519'));
        $this->assertNull(Iban::bankName('SA4499000001234567891234'), 'unknown code is null, never a guess');
    }
}
