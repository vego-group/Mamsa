<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\PartnerLedgerEntry;
use App\Models\PartnerWallet;
use App\Models\Unit;
use App\Models\User;
use App\Services\OtpService;
use App\Support\TestMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Security invariants — the properties that must hold regardless of feature work.
 *
 * Each test here pins something that was either verified by hand during an audit
 * (and must not silently regress) or is a control the product depends on:
 *   - the three auth surfaces cannot be crossed,
 *   - one partner can never read another's data,
 *   - internal margin never reaches a guest,
 *   - a fixed OTP is never honoured for an ordinary user,
 *   - fixture endpoints can never be served from production,
 *   - the payout amount can never be supplied by the client,
 *   - the money ledger is append-only.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // OTP codes live in the cache — isolate between tests.

        foreach (['Individual', 'Company', 'Admin', 'SuperAdmin', 'User', 'finance'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    /* ===================== helpers ===================== */

    private function partner(string $type = 'individual'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($type === 'company' ? 'Company' : 'Individual');
        $user->partnerDetail()->create(['type' => $type, 'status' => PartnerDetail::STATUS_APPROVED]);

        return $user;
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('SuperAdmin');

        return $user;
    }

    private function unit(User $owner): Unit
    {
        return $owner->units()->create([
            'unit_name'       => 'وحدة اختبار',
            'unit_type'       => 'apartment',
            'code'            => 'SEC'.fake()->unique()->numerify('#####'),
            'price'           => 300,
            'capacity'        => 2,
            'bedrooms'        => 1,
            'approval_status' => 'approved',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ]);
    }

    private function booking(Unit $unit, User $guest): Booking
    {
        return Booking::create([
            'unit_id'      => $unit->id,
            'user_id'      => $guest->id,
            'start_date'   => now()->addDays(5)->toDateString(),
            'end_date'     => now()->addDays(7)->toDateString(),
            'guests'       => 1,
            'nightly_rate' => 300,
            'subtotal'     => 600,
            'taxes'        => 90,
            'total_amount' => 690,
            'status'       => Booking::STATUS_CONFIRMED,
        ]);
    }

    /* ========== 1. the three auth surfaces cannot be crossed ========== */

    public function test_partner_session_cannot_reach_admin_endpoints(): void
    {
        $partner = $this->partner();

        // Authenticated on the partner guard only.
        foreach (['/admin/me', '/admin/partners', '/admin/bookings', '/admin/users'] as $path) {
            $this->actingAs($partner, 'dashboard')->getJson($path)
                ->assertUnauthorized(); // 401 — a dashboard session is not an admin session
        }
    }

    public function test_admin_session_cannot_reach_partner_endpoints(): void
    {
        $admin = $this->admin();

        foreach (['/me', '/units', '/bookings'] as $path) {
            $this->actingAs($admin, 'admin-panel')->getJson($path)
                ->assertUnauthorized(); // 401 — an admin session is not a partner session
        }
    }

    public function test_unauthenticated_requests_are_rejected_on_every_surface(): void
    {
        $this->getJson('/admin/me')->assertUnauthorized();       // admin BFF
        $this->getJson('/me')->assertUnauthorized();             // partner dashboard
        $this->getJson('/api/v1/user/bookings')->assertUnauthorized(); // guest API
    }

    /* ========== 1b. per-endpoint authorisation on the admin BFF ========== */

    private function financeAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('finance');

        return $user;
    }

    public function test_finance_admin_is_refused_the_endpoints_outside_its_permissions(): void
    {
        $finance = $this->financeAdmin();

        // §4.3: finance has no users/units/approvals/dashboard reach.
        foreach (['/admin/users', '/admin/units', '/admin/approvals', '/admin/dashboard/summary'] as $path) {
            $this->actingAs($finance, 'admin-panel')->getJson($path)
                ->assertForbidden()
                ->assertJsonPath('code', 'INSUFFICIENT_PERMISSION');
        }
    }

    public function test_finance_admin_keeps_the_endpoints_inside_its_permissions(): void
    {
        $finance = $this->financeAdmin();

        // Reading these is the whole point of the role — it must not be over-blocked.
        foreach (['/admin/partners', '/admin/bookings', '/admin/payouts/eligible'] as $path) {
            $this->actingAs($finance, 'admin-panel')->getJson($path)->assertOk();
        }
    }

    public function test_finance_admin_cannot_perform_superadmin_only_mutations(): void
    {
        $finance = $this->financeAdmin();
        $partner = $this->partner();

        $this->actingAs($finance, 'admin-panel')
            ->postJson("/admin/partners/{$partner->id}/suspend", ['reason' => 'تجربة'])
            ->assertForbidden()
            ->assertJsonPath('code', 'INSUFFICIENT_PERMISSION');
    }

    public function test_superadmin_retains_full_reach(): void
    {
        $admin = $this->admin();

        foreach (['/admin/users', '/admin/units', '/admin/approvals', '/admin/dashboard/summary'] as $path) {
            $this->actingAs($admin, 'admin-panel')->getJson($path)->assertOk();
        }
    }

    public function test_admin_me_stays_reachable_for_every_admin_role(): void
    {
        // /admin/me must never be permission-gated: the client needs it to learn
        // which permissions it has before it can gate anything.
        // Both users are created up-front: Spatie resolves a role's guard from the
        // ACTIVE session, so assigning a role while acting as another guard fails.
        $finance = $this->financeAdmin();
        $super   = $this->admin();

        $this->actingAs($finance, 'admin-panel')->getJson('/admin/me')
            ->assertOk()->assertJsonPath('role', 'finance');

        $this->actingAs($super, 'admin-panel')->getJson('/admin/me')
            ->assertOk()->assertJsonPath('role', 'superadmin');
    }

    /* ========== 2. cross-tenant isolation ========== */

    public function test_partner_cannot_read_another_partners_booking(): void
    {
        $a = $this->partner();
        $b = $this->partner();
        $guest = User::factory()->create();

        $booking = $this->booking($this->unit($b), $guest);

        // 404, not 403 — the response must not confirm that the booking exists.
        $this->actingAs($a, 'dashboard')->getJson("/bookings/{$booking->id}")
            ->assertNotFound();
    }

    public function test_partner_booking_list_contains_only_own_bookings(): void
    {
        $a = $this->partner();
        $b = $this->partner();
        $guest = User::factory()->create();

        $mine = $this->booking($this->unit($a), $guest);
        $theirs = $this->booking($this->unit($b), $guest);

        $body = $this->actingAs($a, 'dashboard')->getJson('/bookings')
            ->assertOk()->getContent();

        $this->assertStringContainsString((string) $mine->id, $body);
        $this->assertStringNotContainsString('"id":"b_'.$theirs->id.'"', $body);
        $this->assertStringNotContainsString('"bookingId":'.$theirs->id, $body);
    }

    /* ========== 3. internal margin never reaches a guest ========== */

    public function test_guest_booking_response_never_exposes_commission_or_partner_share(): void
    {
        $partner = $this->partner();
        $unit    = $this->unit($partner);
        $guest   = User::factory()->create();
        $guest->assignRole('User');

        $booking = $this->booking($unit, $guest);

        $body = $this->actingAs($guest)->getJson("/api/v1/bookings/{$booking->id}")
            ->assertOk()->getContent();

        // Mamsa's cut is an internal settlement — a guest must never see it.
        $this->assertStringNotContainsString('commission', $body);
        $this->assertStringNotContainsString('partner_share', $body);
        $this->assertStringNotContainsString('partnerShare', $body);
    }

    /* ========== 4. a fixed OTP is never honoured for an ordinary user ========== */

    public function test_fixed_otp_code_is_ignored_in_production(): void
    {
        // OTP_FIXED_CODE exists for staging/local only. If this guard is ever
        // removed, every account on production becomes openable with a constant.
        config(['otp.fixed_code' => '424242']);
        config(['test_mode.otp' => false]);
        $this->app['env'] = 'production';

        $code = app(OtpService::class)->request('512345678', 'login');

        $this->assertNotSame('424242', $code, 'A fixed OTP was honoured in production.');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_test_mode_bypass_never_applies_to_a_non_allowlisted_phone(): void
    {
        // The scoped bypass has no production guard of its own — the allowlist IS
        // the control. A real user must never fall into it, in any environment.
        config([
            'test_mode.otp'    => true,
            'test_mode.code'   => '424242',
            'test_mode.phones' => ['+966555000003'],
        ]);
        $this->app['env'] = 'production';

        $this->assertTrue(TestMode::otpBypass('+966555000003'), 'allowlisted phone should bypass');
        $this->assertFalse(TestMode::otpBypass('+966512345678'), 'a real user must never be bypassed');
    }

    public function test_blank_fixed_code_disables_the_bypass_entirely(): void
    {
        config([
            'test_mode.otp'    => true,
            'test_mode.code'   => '',              // the kill switch used during incidents
            'test_mode.phones' => ['+966555000003'],
        ]);

        $this->assertFalse(TestMode::otpBypass('+966555000003'));
    }

    /* ========== 5. fixture endpoints can never be served from production ========== */

    public function test_stub_routes_are_guarded_by_a_production_check(): void
    {
        // The stubs return fixture money data. They are registered only outside
        // production; this pins the guard itself, because a route list built in
        // the test environment cannot observe production's boot.
        //
        // Keyed on whether a file still WIRES a stub, so replacing stubs with
        // real controllers cannot leave a rule asserting a guard that no longer
        // needs to exist — and adding a stub back re-arms the check.
        foreach (['routes/admin-panel.php', 'routes/dashboard.php'] as $file) {
            $source = file_get_contents(base_path($file));

            if (! str_contains($source, 'Stub\\')) {
                continue;
            }

            $this->assertStringContainsString(
                '! app()->isProduction()',
                $source,
                "{$file} must gate its stub routes behind a production check."
            );
        }

        // The admin-panel fixtures really are reachable outside production.
        $this->assertTrue(Route::has('ap.payouts.eligible'));

        // The partner wallet is no longer a stub: it is database-backed and
        // must be registered in EVERY environment, production included.
        $this->assertTrue(Route::has('pd.wallet'));
        $this->assertStringNotContainsString(
            'Stub\\',
            file_get_contents(base_path('routes/dashboard.php')),
            'the partner dashboard must serve no fixture money data',
        );
    }

    /* ========== 6. the payout amount can never come from the client ========== */

    public function test_payout_record_ignores_client_supplied_amount_and_iban(): void
    {
        $admin   = $this->admin();
        $partner = $this->partner();

        \App\Models\BankDetail::create([
            'partner_user_id'     => $partner->id,
            'iban'                => 'SA2480000000000000000000',
            'account_holder_name' => 'شريك',
            'bank_name'           => 'مصرف الراجحي',
            'verified'            => true,
            'verified_at'         => now(),
        ]);

        $unit = $this->unit($partner);

        \App\Models\Booking::create([
            'unit_id'           => $unit->id,
            'user_id'           => User::factory()->create()->id,
            'code'              => 'BK-SEC-1',
            'start_date'        => now()->subDays(5),
            'end_date'          => now()->subDays(2),
            'guests'            => 2,
            'subtotal'          => 3000.00,
            'taxes'             => 450.00,
            'commission_amount' => 60.00,
            'partner_share'     => 2940.00,
            'total_amount'      => 3450.00,
            'status'            => \App\Models\Booking::STATUS_COMPLETED,
        ]);

        // The core control of the payout feature: the accountant records a
        // transfer, they never state its size or destination.
        $this->actingAs($admin, 'admin-panel')
            ->postJson('/admin/payouts/record', [
                'partnerId'     => 'prt_'.$partner->id,
                'bankReference' => 'FT-SEC-0001',
                'amount'        => 999999.99,
                'iban'          => 'SA0000000000000000000000',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            // Nothing the client sent may be echoed back as authoritative.
            ->assertJsonMissing(['amount' => 999999.99])
            ->assertJsonMissing(['iban' => 'SA0000000000000000000000']);

        // And the recorded transfer is what the SERVER computed, not what was
        // asked for — the assertion the fixture version could never make.
        $payout = \App\Models\Payout::firstOrFail();
        $this->assertEqualsWithDelta(2940.00, $payout->amount, 0.001);
        $this->assertSame('••••0000', $payout->iban_masked);
    }

    /* ========== 7. the money ledger is append-only ========== */

    public function test_partner_ledger_entry_cannot_be_updated_or_deleted(): void
    {
        $partner = $this->partner();

        $entry = PartnerLedgerEntry::create([
            'partner_user_id' => $partner->id,
            'type'            => PartnerLedgerEntry::TYPE_EARNING,
            'amount'          => 100.00,
            'balance_after'   => 100.00,
            'ref_type'        => 'booking',
            'ref_id'          => '1',
            'created_at'      => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $entry->update(['amount' => 5000.00]); // a correction must be a NEW row
    }

    public function test_partner_ledger_entry_delete_is_blocked(): void
    {
        $partner = $this->partner();

        $entry = PartnerLedgerEntry::create([
            'partner_user_id' => $partner->id,
            'type'            => PartnerLedgerEntry::TYPE_PAYOUT,
            'amount'          => -100.00,
            'balance_after'   => 0.00,
            'ref_type'        => 'payout',
            'ref_id'          => '1',
            'created_at'      => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $entry->delete();
    }

    public function test_wallet_available_balance_accepts_a_negative_value(): void
    {
        // A refund reversal may drive the balance negative; clamping to zero
        // would silently forgive money owed back to the platform.
        $partner = $this->partner();

        $wallet = PartnerWallet::create([
            'partner_user_id'   => $partner->id,
            'available_balance' => -150.00,
        ]);

        $this->assertSame(-150.00, (float) $wallet->fresh()->available_balance);
    }
}
