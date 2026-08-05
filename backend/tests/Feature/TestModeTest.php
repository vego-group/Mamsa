<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PartnerDetail;
use App\Models\User;
use App\Services\OtpService;
use App\Services\Sms\SmsProvider;
use App\Support\TestMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Scoped test mode: fixed OTP + simulated payments for an explicit phone
 * allowlist. The security contract is that a NON-allowlisted phone is never
 * affected (always a random SMS OTP / live charge), and that both master
 * switches disable the bypass regardless of the allowlist.
 */
class TestModeTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PHONE = '+966555000009';

    private const REAL_PHONE = '+966501234567';

    private const CODE = '654321';

    private function enableOtp(): void
    {
        config()->set('test_mode.otp', true);
        config()->set('test_mode.payments', true);
        config()->set('test_mode.code', self::CODE);
        config()->set('test_mode.phones', [self::TEST_PHONE]);
    }

    /** Bind an SMS spy and resolve a fresh OtpService that uses it. */
    private function otpWithSpy(): array
    {
        $spy = Mockery::spy(SmsProvider::class);
        $this->app->instance(SmsProvider::class, $spy);

        return [$this->app->make(OtpService::class), $spy];
    }

    /* ---------- TestMode gate ---------- */

    public function test_allowlist_matches_regardless_of_phone_format(): void
    {
        $this->enableOtp();

        $this->assertTrue(TestMode::isTestPhone(self::TEST_PHONE));
        $this->assertTrue(TestMode::isTestPhone('0555000009'));   // 05… form
        $this->assertTrue(TestMode::isTestPhone('555000009'));    // 5… form
        $this->assertFalse(TestMode::isTestPhone(self::REAL_PHONE));
        $this->assertFalse(TestMode::isTestPhone(null));
        $this->assertFalse(TestMode::isTestPhone('not-a-phone'));
    }

    public function test_switches_gate_the_bypass(): void
    {
        $this->enableOtp();
        $this->assertTrue(TestMode::otpBypass(self::TEST_PHONE));
        $this->assertTrue(TestMode::paymentBypass(self::TEST_PHONE));

        // Master switches off → no bypass even for an allowlisted phone.
        config()->set('test_mode.otp', false);
        config()->set('test_mode.payments', false);
        $this->assertFalse(TestMode::otpBypass(self::TEST_PHONE));
        $this->assertFalse(TestMode::paymentBypass(self::TEST_PHONE));
    }

    public function test_empty_code_disables_otp_bypass(): void
    {
        $this->enableOtp();
        config()->set('test_mode.code', '');

        $this->assertNull(TestMode::code());
        $this->assertFalse(TestMode::otpBypass(self::TEST_PHONE));
        // Payments do not depend on the code.
        $this->assertTrue(TestMode::paymentBypass(self::TEST_PHONE));
    }

    public function test_real_phone_is_never_bypassed(): void
    {
        $this->enableOtp();
        $this->assertFalse(TestMode::otpBypass(self::REAL_PHONE));
        $this->assertFalse(TestMode::paymentBypass(self::REAL_PHONE));
    }

    /* ---------- OtpService integration ---------- */

    public function test_test_phone_gets_fixed_code_and_no_sms(): void
    {
        $this->enableOtp();
        [$otp, $spy] = $this->otpWithSpy();

        $code = $otp->request(self::TEST_PHONE);

        $this->assertSame(self::CODE, $code);
        $spy->shouldNotHaveReceived('send');

        // …and the fixed code actually verifies.
        $otp->verify(self::TEST_PHONE, self::CODE);
        $this->assertTrue(true); // verify() throws on failure
    }

    public function test_real_phone_gets_random_code_and_sms(): void
    {
        $this->enableOtp();
        [$otp, $spy] = $this->otpWithSpy();

        $code = $otp->request(self::REAL_PHONE);

        $this->assertNotSame(self::CODE, $code);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $spy->shouldHaveReceived('send')->once();
    }

    public function test_switch_off_sends_real_sms_even_to_allowlisted_phone(): void
    {
        $this->enableOtp();
        config()->set('test_mode.otp', false);
        [$otp, $spy] = $this->otpWithSpy();

        $code = $otp->request(self::TEST_PHONE);

        $this->assertNotSame(self::CODE, $code);
        $spy->shouldHaveReceived('send')->once();
    }

    /* ---------- provisioning command ---------- */

    public function test_sync_command_creates_the_three_accounts_with_roles(): void
    {
        config()->set('test_mode.accounts', [
            'user' => '+966555000001',
            'partner' => '+966555000002',
            'superadmin' => '+966555000003',
        ]);

        Artisan::call('test-accounts:sync');

        $super = User::where('phone', '+966555000003')->first();
        $this->assertNotNull($super);
        $this->assertTrue($super->hasRole('SuperAdmin'));
        $this->assertTrue($super->is_active);

        $partner = User::where('phone', '+966555000002')->with('partnerDetail')->first();
        $this->assertNotNull($partner);
        $this->assertTrue($partner->isPartner());
        $this->assertSame(PartnerDetail::STATUS_APPROVED, $partner->partnerDetail->status);

        $user = User::where('phone', '+966555000001')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('User'));
    }

    public function test_sync_command_is_idempotent_and_only_adds_roles(): void
    {
        Role::findOrCreate('User', 'web');
        Role::findOrCreate('SuperAdmin', 'web');

        // A pre-existing real account on the super-admin number.
        $existing = User::create(['phone' => '+966555000003', 'name' => 'حساب حقيقي', 'is_active' => true]);
        $existing->assignRole('User');

        config()->set('test_mode.accounts', [
            'user' => '+966555000001',
            'partner' => '+966555000002',
            'superadmin' => '+966555000003',
        ]);

        Artisan::call('test-accounts:sync');
        Artisan::call('test-accounts:sync'); // twice → no duplicates

        $existing->refresh();
        $this->assertSame('حساب حقيقي', $existing->name);      // name preserved
        $this->assertTrue($existing->hasRole('User'));          // prior role kept
        $this->assertTrue($existing->hasRole('SuperAdmin'));    // new role added
        $this->assertSame(1, User::where('phone', '+966555000003')->count());
        $this->assertSame(1, User::where('phone', '+966555000002')->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
