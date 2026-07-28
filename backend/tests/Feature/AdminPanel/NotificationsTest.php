<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin notification feed — BACKEND_SPEC §5.11. Feed shape + the bare-number
 * unread count + mark-one / mark-all read.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'SuperAdmin'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('SuperAdmin');
    }

    private function pushNotification(array $data, ?string $readAt = null, string $type = 'App\\Notifications\\UnitReviewResult'): string
    {
        $id = (string) Str::uuid();
        $this->adminUser->notifications()->create([
            'id'      => $id,
            'type'    => $type,
            'data'    => $data,
            'read_at' => $readAt,
        ]);

        return $id;
    }

    public function test_feed_shape_and_category_mapping(): void
    {
        $this->pushNotification(['title' => 'طلب مراجعة جديد', 'message' => 'وحدة بانتظار المراجعة', 'unit_id' => 42]);

        $this->actingAs($this->adminUser, 'admin-panel')->getJson('/admin/notifications')
            ->assertOk()
            ->assertJsonStructure([['id', 'category', 'title', 'body', 'at', 'read', 'entity']])
            ->assertJsonPath('0.category', 'approval')
            ->assertJsonPath('0.read', false)
            ->assertJsonPath('0.entity.type', 'approval')
            ->assertJsonPath('0.entity.id', '42');
    }

    public function test_unread_count_is_a_bare_number(): void
    {
        $this->pushNotification(['title' => 'أ', 'message' => 'ب']);
        $this->pushNotification(['title' => 'ج', 'message' => 'د']);
        $this->pushNotification(['title' => 'ه', 'message' => 'و'], readAt: now()->toDateTimeString());

        // Body must decode to the scalar 2, not { count: 2 }.
        $res = $this->actingAs($this->adminUser, 'admin-panel')->getJson('/admin/notifications/unread-count')->assertOk();
        $this->assertSame(2, $res->json());
    }

    public function test_mark_one_and_mark_all_read(): void
    {
        $id = $this->pushNotification(['title' => 'أ', 'message' => 'ب']);
        $this->pushNotification(['title' => 'ج', 'message' => 'د']);

        $this->actingAs($this->adminUser, 'admin-panel')->postJson("/admin/notifications/{$id}/read")
            ->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame(1, $this->adminUser->unreadNotifications()->count());

        $this->actingAs($this->adminUser, 'admin-panel')->postJson('/admin/notifications/read-all')
            ->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame(0, $this->adminUser->unreadNotifications()->count());
    }

    public function test_mark_unknown_notification_is_404(): void
    {
        $this->actingAs($this->adminUser, 'admin-panel')->postJson('/admin/notifications/'.Str::uuid().'/read')
            ->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
    }
}
