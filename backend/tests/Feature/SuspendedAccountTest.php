<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\OtpService;
use App\Services\RefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Suspending an account (`is_active = false`) on the /api/v1 surface.
 *
 * The partner dashboard and the admin panel have always gated their own login
 * on this flag. /api/v1 did not — so "disable user" in the admin panel blocked
 * nothing on the guest app: the person still passed the OTP (they do own the
 * phone) and still renewed whatever session they already held.
 */
class SuspendedAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('User', 'web');
    }

    private function login(User $user): \Illuminate\Testing\TestResponse
    {
        $code = app(OtpService::class)->request($user->phone, 'login');

        return $this->postJson('/api/v1/auth/verify-otp', [
            'phone' => $user->phone,
            'code'  => $code,
        ]);
    }

    public function test_a_suspended_user_cannot_log_in_with_a_valid_otp(): void
    {
        $user = User::factory()->create(['phone' => '+966512345678', 'is_active' => false]);
        $user->assignRole('User');

        $this->login($user)
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_an_active_user_still_logs_in(): void
    {
        // The control: the gate must not cost an ordinary login.
        $user = User::factory()->create(['phone' => '+966512345679', 'is_active' => true]);
        $user->assignRole('User');

        $this->login($user)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
    }

    public function test_suspending_a_user_ends_the_session_they_already_hold(): void
    {
        // Blocking only the next login would leave a rotating refresh token
        // renewing itself indefinitely — the suspension would never take hold.
        $user = User::factory()->create(['phone' => '+966512345680', 'is_active' => true]);
        $user->assignRole('User');

        $pair = app(RefreshTokenService::class)->issuePair($user);
        $this->assertSame(1, $user->tokens()->count());

        $user->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(401);

        $this->assertSame(0, $user->fresh()->tokens()->count(), 'Access tokens survived the suspension.');
    }

    public function test_reactivating_restores_access(): void
    {
        // Suspension is reversible: this is the whole point of using the flag
        // rather than deleting the account.
        $user = User::factory()->create(['phone' => '+966512345681', 'is_active' => false]);
        $user->assignRole('User');

        $this->login($user)->assertStatus(403);

        $user->update(['is_active' => true]);

        $this->login($user)->assertOk();
    }
}
