<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin-panel super-admin management (POST/GET /admin/admins). Covers the
 * SuperAdmin-only gate, granting to a brand-new phone, promoting an existing
 * account, the already-super-admin conflict, and the admins listing.
 */
class AdminsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'SuperAdmin', 'Individual', 'Company', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->superAdmin = User::factory()->create(['is_active' => true]);
        $this->superAdmin->assignRole('SuperAdmin');
    }

    private function asSuper(): self
    {
        $this->actingAs($this->superAdmin, 'admin-panel');

        return $this;
    }

    private function guest(array $overrides = []): User
    {
        $u = User::factory()->create($overrides);
        $u->assignRole(Role::findByName('User', 'web'));

        return $u;
    }

    /* ---------- grant ---------- */

    public function test_super_admin_grants_super_admin_to_new_phone(): void
    {
        $this->asSuper()->postJson('/admin/admins', ['phone' => '+966512345670', 'name' => 'مشرف جديد'])
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('admin.role', 'superadmin')
            ->assertJsonPath('admin.isActive', true)
            ->assertJsonPath('admin.phone', '+966512345670')
            ->assertJsonStructure(['ok', 'admin' => ['id', 'name', 'email', 'phone', 'role', 'isActive', 'memberSince']]);

        $created = User::where('phone', '+966512345670')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->is_active);
        $this->assertTrue($created->hasRole('SuperAdmin', 'web'));
    }

    public function test_super_admin_promotes_existing_inactive_account(): void
    {
        $existing = $this->guest(['phone' => '+966512345671', 'is_active' => false]);

        $this->asSuper()->postJson('/admin/admins', ['phone' => '+966512345671'])
            ->assertStatus(201)
            ->assertJsonPath('admin.id', (string) $existing->id)
            ->assertJsonPath('admin.role', 'superadmin');

        $existing->refresh();
        $this->assertTrue($existing->is_active); // reactivated so it can log in
        $this->assertTrue($existing->hasRole('SuperAdmin', 'web'));
        $this->assertTrue($existing->hasRole('User', 'web')); // prior role preserved
    }

    public function test_granting_an_existing_super_admin_conflicts(): void
    {
        $this->asSuper()->postJson('/admin/admins', ['phone' => '+966512345672'])->assertStatus(201);

        $this->asSuper()->postJson('/admin/admins', ['phone' => '+966512345672'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CONFLICT');
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->asSuper()->postJson('/admin/admins', ['phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    /* ---------- authorization ---------- */

    public function test_plain_admin_cannot_grant_or_list(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findByName('Admin', 'web'));

        $this->actingAs($admin, 'admin-panel')->postJson('/admin/admins', ['phone' => '+966512345673'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->actingAs($admin, 'admin-panel')->getJson('/admin/admins')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');

        // The rejected grant left no super-admin behind.
        $this->assertDatabaseMissing('users', ['phone' => '+966512345673']);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/admin/admins', ['phone' => '+966512345674'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    /* ---------- list ---------- */

    public function test_index_lists_admins_only(): void
    {
        $this->guest(['name' => 'ضيف عادي']); // must NOT appear

        $extra = User::factory()->create(['is_active' => true]);
        $extra->assignRole(Role::findByName('Admin', 'web'));

        $res = $this->asSuper()->getJson('/admin/admins')->assertOk()
            ->assertJsonStructure(['items' => [['id', 'name', 'phone', 'role', 'isActive']], 'total', 'page', 'pageSize']);

        $roles = collect($res->json('items'))->pluck('role')->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['superadmin', 'admin'], $roles);
        $this->assertSame(2, $res->json('total')); // superAdmin (setUp) + extra admin; guest excluded
    }
}
