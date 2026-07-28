<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin-panel (Next.js) BFF — OTP auth + profile contract coverage
 * (BACKEND_SPEC §3, §5.1, §5.2). Verifies the flat { message, code } envelope,
 * the admin-only OTP gate, the cookie-session guard, and camelCase profile.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // OTP codes live in the cache — isolate between tests.

        foreach (['Admin', 'SuperAdmin', 'Individual', 'Company', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        // Deterministic OTP for the flow test (honoured only outside production).
        config(['otp.fixed_code' => '123456']);
    }

    private function admin(bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $user->assignRole('SuperAdmin');

        return $user;
    }

    /* ---- envelope + auth gate ---- */

    public function test_me_requires_session_and_uses_flat_envelope(): void
    {
        $this->getJson('/admin/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['message', 'code']);
    }

    public function test_request_otp_rejects_non_admin_phone(): void
    {
        $user = User::factory()->create();
        $user->assignRole('User');

        $this->postJson('/admin/auth/request-otp', ['phone' => $user->phone])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_request_otp_rejects_unknown_phone(): void
    {
        $this->postJson('/admin/auth/request-otp', ['phone' => '+966599999999'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    /* ---- full OTP login flow ---- */

    public function test_admin_can_request_and_verify_otp(): void
    {
        $admin = $this->admin();

        $this->postJson('/admin/auth/request-otp', ['phone' => $admin->phone])
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->postJson('/admin/auth/verify-otp', ['phone' => $admin->phone, 'code' => '123456'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('admin.id', (string) $admin->id)
            ->assertJsonPath('admin.role', 'superadmin')
            ->assertJsonStructure(['ok', 'admin' => [
                'id', 'name', 'email', 'phone', 'role', 'verified',
                'memberSince', 'totalReviews', 'actionsToday', 'preferredLocale',
            ]]);

        // The verify call must have opened an admin-panel session.
        $this->assertAuthenticatedAs($admin, 'admin-panel');
    }

    public function test_verify_otp_wrong_code_returns_otp_envelope(): void
    {
        $admin = $this->admin();
        $this->postJson('/admin/auth/request-otp', ['phone' => $admin->phone])->assertOk();

        $this->postJson('/admin/auth/verify-otp', ['phone' => $admin->phone, 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID');
    }

    public function test_suspended_admin_cannot_complete_login(): void
    {
        $admin = $this->admin(active: false);
        $this->postJson('/admin/auth/request-otp', ['phone' => $admin->phone])->assertOk();

        $this->postJson('/admin/auth/verify-otp', ['phone' => $admin->phone, 'code' => '123456'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    /* ---- profile (§5.2) ---- */

    public function test_me_returns_admin_profile_shape(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin-panel')->getJson('/admin/me')
            ->assertOk()
            ->assertJsonPath('role', 'superadmin')
            ->assertJsonPath('preferredLocale', 'ar'); // default when column null
    }

    public function test_profile_update_persists_camelcase_fields(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin-panel')
            ->patchJson('/admin/profile', ['name' => 'مدير المنصة', 'preferredLocale' => 'en'])
            ->assertOk()
            ->assertJsonPath('name', 'مدير المنصة')
            ->assertJsonPath('preferredLocale', 'en');

        $this->assertSame('en', $admin->refresh()->preferred_locale);
    }

    public function test_sessions_lists_current_and_revoke_is_conflict(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin-panel')->getJson('/admin/profile/sessions')
            ->assertOk()
            ->assertJsonPath('0.current', true)
            ->assertJsonPath('0.id', 'current');

        $this->actingAs($admin, 'admin-panel')->deleteJson('/admin/profile/sessions/current')
            ->assertStatus(409)
            ->assertJsonPath('code', 'CONFLICT');
    }
}
