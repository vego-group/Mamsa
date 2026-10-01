<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one-time code comes back in an API response only when an environment
 * has been DECIDED to allow it (OTP_DEBUG_RESPONSE=true), and never on
 * production.
 *
 * It used to be on in every non-production environment (`! isProduction()`),
 * so any new environment started open without anyone deciding. On staging that
 * opened every guest account to anyone who could reach it, including real
 * people's (2026-10-01). Off is now the default; a written rule is not a lock.
 */
class OtpDebugResponseTest extends TestCase
{
    use RefreshDatabase;

    private function requestPhoneCode(): array
    {
        return $this->postJson('/api/v1/auth/request-otp', ['phone' => '0597100993'])->assertOk()->json('data') ?? [];
    }

    private function requestEmailCode(): array
    {
        $user = User::factory()->create(['email' => null]);

        return $this->actingAs($user)->postJson('/api/v1/user/email', ['email' => 'probe@example.test'])->assertOk()->json('data') ?? [];
    }

    public function test_off_by_default_outside_production(): void
    {
        $this->app['env'] = 'staging';
        config()->set('otp.debug_response', false);

        $this->assertArrayNotHasKey('debug_otp', $this->requestPhoneCode());
        $this->assertArrayNotHasKey('debug_otp', $this->requestEmailCode());
    }

    public function test_the_config_default_is_off_when_the_variable_is_absent(): void
    {
        $saved = [getenv('OTP_DEBUG_RESPONSE'), $_ENV['OTP_DEBUG_RESPONSE'] ?? null, $_SERVER['OTP_DEBUG_RESPONSE'] ?? null];
        putenv('OTP_DEBUG_RESPONSE');
        unset($_ENV['OTP_DEBUG_RESPONSE'], $_SERVER['OTP_DEBUG_RESPONSE']);

        try {
            $this->assertFalse((require config_path('otp.php'))['debug_response']);
        } finally {
            if ($saved[0] !== false) {
                putenv('OTP_DEBUG_RESPONSE='.$saved[0]);
            }
            if ($saved[1] !== null) {
                $_ENV['OTP_DEBUG_RESPONSE'] = $saved[1];
            }
            if ($saved[2] !== null) {
                $_SERVER['OTP_DEBUG_RESPONSE'] = $saved[2];
            }
        }
    }

    public function test_an_environment_that_decides_to_allow_it_gets_the_code(): void
    {
        $this->app['env'] = 'staging';
        config()->set('otp.debug_response', true);

        $this->assertArrayHasKey('debug_otp', $this->requestPhoneCode());
        $this->assertArrayHasKey('debug_otp', $this->requestEmailCode());
    }

    public function test_production_never_returns_it_even_when_the_flag_is_on(): void
    {
        $this->app['env'] = 'production';
        config()->set('otp.debug_response', true);

        $this->assertArrayNotHasKey('debug_otp', $this->requestPhoneCode());
        $this->assertArrayNotHasKey('debug_otp', $this->requestEmailCode());
    }
}
