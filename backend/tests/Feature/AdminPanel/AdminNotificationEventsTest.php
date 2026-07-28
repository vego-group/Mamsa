<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Refund;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Platform events → admin notification feed (BACKEND_SPEC §5.11). The model
 * observers must fan out an in-app AdminAlert to every admin on the right
 * transitions, and nothing else. Verifies the feed endpoint reflects them.
 */
class AdminNotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'SuperAdmin', 'Individual', 'Company', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('SuperAdmin');

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
    }

    private function adminNotifications(?string $category = null): \Illuminate\Support\Collection
    {
        return DatabaseNotification::query()
            ->where('notifiable_id', $this->admin->id)
            ->get()
            ->when($category !== null, fn ($c) => $c->filter(fn ($n) => ($n->data['category'] ?? null) === $category))
            ->values();
    }

    private function makeUnit(string $status): Unit
    {
        return $this->partner->units()->create([
            'unit_name' => 'وحدة', 'unit_type' => 'apartment', 'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 300, 'capacity' => 4, 'bedrooms' => 2, 'bathrooms' => 1, 'area' => 90,
            'city' => 'الرياض', 'district' => 'النرجس', 'approval_status' => $status, 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);
    }

    public function test_pending_unit_alerts_admins_but_draft_does_not(): void
    {
        $this->makeUnit('draft');
        $this->assertCount(0, $this->adminNotifications('approval'));

        $unit = $this->makeUnit('pending');                 // new submission
        $this->assertCount(1, $this->adminNotifications('approval'));

        $approved = $this->makeUnit('approved');
        $approved->update(['approval_status' => 'pending']); // resubmission
        $this->assertCount(2, $this->adminNotifications('approval'));

        $alert = $this->adminNotifications('approval')->first();
        $this->assertSame((string) $unit->id, (string) $alert->data['unit_id']);
    }

    public function test_host_cancellation_alerts_admins_guest_cancellation_does_not(): void
    {
        $unit = $this->makeUnit('approved');
        $mk = fn () => $unit->bookings()->create([
            'user_id' => $this->partner->id, 'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(), 'guests' => 2, 'nightly_rate' => 300,
            'subtotal' => 600, 'total_amount' => 600, 'commission_amount' => 12, 'status' => Booking::STATUS_CONFIRMED,
        ]);

        $mk()->update(['status' => 'cancelled', 'cancelled_by' => 'customer']); // guest → ignored
        $this->assertCount(0, $this->adminNotifications('cancellation'));

        $host = $mk();
        $host->update(['status' => 'cancelled', 'cancelled_by' => 'partner']);  // host → alert
        $this->assertCount(1, $this->adminNotifications('cancellation'));
        $this->assertSame((string) $host->id, (string) $this->adminNotifications('cancellation')->first()->data['cancellation_id']);
    }

    public function test_failed_refund_alerts_admins(): void
    {
        $unit    = $this->makeUnit('approved');
        $booking = $unit->bookings()->create([
            'user_id' => $this->partner->id, 'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
            'guests' => 1, 'nightly_rate' => 300, 'subtotal' => 300, 'total_amount' => 300, 'commission_amount' => 6,
            'status' => Booking::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => 'customer',
        ]);
        $payment = $booking->payment()->create([
            'amount' => 300, 'refunded_amount' => 0, 'payment_method' => 'mada', 'payment_status' => 'paid', 'paid_at' => now(),
        ]);

        $refund = $booking->refunds()->create([
            'payment_id' => $payment->id, 'type' => Refund::TYPE_REFUND, 'amount' => 300, 'refund_percent' => 100, 'status' => 'pending',
        ]);
        $this->assertCount(0, $this->adminNotifications('refund'));

        $refund->update(['status' => 'failed']);            // gateway/webhook failure
        $this->assertCount(1, $this->adminNotifications('refund'));
    }

    public function test_new_partner_application_alerts_admins_and_reaches_feed(): void
    {
        $this->partner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_PENDING]);
        $this->assertCount(1, $this->adminNotifications('partner'));

        // And it surfaces through the §5.11 feed endpoint with the mapped shape.
        $this->actingAs($this->admin, 'admin-panel')->getJson('/admin/notifications')
            ->assertOk()
            ->assertJsonPath('0.category', 'partner')
            ->assertJsonPath('0.entity.type', 'partner')
            ->assertJsonPath('0.entity.id', (string) $this->partner->id);

        $this->assertSame(
            $this->adminNotifications()->count(),
            (int) $this->actingAs($this->admin, 'admin-panel')->getJson('/admin/notifications/unread-count')->json(),
        );
    }
}
