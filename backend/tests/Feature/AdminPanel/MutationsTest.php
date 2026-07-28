<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Refund;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\PartnerApplicationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin-panel mutations — BACKEND_SPEC §5.4–5.6, §5.9. Every action returns the
 * flat { ok: true } envelope; guards return the flat { message, code } error.
 */
class MutationsTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'SuperAdmin', 'Individual', 'Company', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('SuperAdmin');
    }

    private function as(): self
    {
        $this->actingAs($this->adminUser, 'admin-panel');

        return $this;
    }

    private function guest(array $overrides = []): User
    {
        $u = User::factory()->create($overrides);
        $u->assignRole(Role::findByName('User', 'web'));

        return $u;
    }

    private function partner(string $status = PartnerDetail::STATUS_PENDING, array $userOverrides = [], array $detailOverrides = []): User
    {
        $u = User::factory()->create($userOverrides + ['is_active' => true]);
        $u->assignRole(Role::findByName('Individual', 'web'));
        $u->partnerDetail()->create($detailOverrides + [
            'type'        => 'individual',
            'status'      => $status,
            'national_id' => '1098765432',
            'iban'        => 'SA0380000000608010167519',
        ]);

        return $u;
    }

    private function bookingFor(User $guest, User $partner, string $status = Booking::STATUS_CONFIRMED): Booking
    {
        $unit = $partner->units()->create([
            'unit_name'       => 'وحدة',
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => 300,
            'capacity'        => 4,
            'bedrooms'        => 2,
            'bathrooms'       => 1,
            'area'            => 90,
            'city'            => 'الرياض',
            'district'        => 'النرجس',
            'approval_status' => 'approved',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ]);

        return $unit->bookings()->create([
            'user_id'           => $guest->id,
            'start_date'        => now()->addDays(5)->toDateString(),
            'end_date'          => now()->addDays(8)->toDateString(),
            'guests'            => 2,
            'nightly_rate'      => 300,
            'subtotal'          => 900,
            'total_amount'      => 900,
            'commission_amount' => 18,
            'status'            => $status,
        ]);
    }

    /* ---------- users §5.4 ---------- */

    public function test_user_status_transitions(): void
    {
        $u = $this->guest(['is_active' => true]);

        $this->as()->patchJson("/admin/users/{$u->id}/status", ['status' => 'disabled'])->assertOk()->assertExactJson(['ok' => true]);
        $this->assertFalse($u->fresh()->is_active);
        $this->assertNull($u->fresh()->invited_at);

        $this->as()->patchJson("/admin/users/{$u->id}/status", ['status' => 'pending_activation'])->assertOk();
        $this->assertNotNull($u->fresh()->invited_at);
        $this->assertSame('pending_activation', $this->as()->getJson("/admin/users/{$u->id}")->json('status'));

        $this->as()->patchJson("/admin/users/{$u->id}/status", ['status' => 'active'])->assertOk();
        $this->assertTrue($u->fresh()->is_active);
    }

    public function test_delete_user_blocked_by_active_bookings(): void
    {
        $partner = $this->partner(PartnerDetail::STATUS_APPROVED);
        $guest   = $this->guest();
        $this->bookingFor($guest, $partner, Booking::STATUS_CONFIRMED);

        $this->as()->deleteJson("/admin/users/{$guest->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'USER_HAS_ACTIVE_BOOKINGS');

        $this->assertDatabaseHas('users', ['id' => $guest->id]);
    }

    public function test_delete_user_without_active_bookings(): void
    {
        $guest = $this->guest();

        $this->as()->deleteJson("/admin/users/{$guest->id}")->assertOk()->assertExactJson(['ok' => true]);
        $this->assertDatabaseMissing('users', ['id' => $guest->id]);
    }

    public function test_invite_user_creates_pending_activation_and_rejects_duplicates(): void
    {
        $this->as()->postJson('/admin/users/invite', ['phone' => '+966512345678', 'name' => 'ضيف مدعو'])
            ->assertOk()->assertExactJson(['ok' => true]);

        $this->assertDatabaseHas('users', ['phone' => '+966512345678', 'is_active' => false]);
        $invited = User::where('phone', '+966512345678')->first();
        $this->assertNotNull($invited->invited_at);
        $this->assertTrue($invited->hasRole('User', 'web'));

        // Same phone again → 409 CONFLICT.
        $this->as()->postJson('/admin/users/invite', ['phone' => '+966512345678'])
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
    }

    /* ---------- partners §5.5 ---------- */

    public function test_partner_approve_and_reject_guarded_by_pending(): void
    {
        Notification::fake();

        $p = $this->partner(PartnerDetail::STATUS_PENDING);
        $this->as()->postJson("/admin/partners/{$p->id}/approve")->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame(PartnerDetail::STATUS_APPROVED, $p->partnerDetail->fresh()->status);
        Notification::assertSentTo($p, PartnerApplicationResult::class);

        // Approving again (now active, not pending) → 409.
        $this->as()->postJson("/admin/partners/{$p->id}/approve")->assertStatus(409)->assertJsonPath('code', 'CONFLICT');

        $p2 = $this->partner(PartnerDetail::STATUS_PENDING);
        $this->as()->postJson("/admin/partners/{$p2->id}/reject", ['reason' => 'مستندات ناقصة'])->assertOk();
        $this->assertSame(PartnerDetail::STATUS_REJECTED, $p2->partnerDetail->fresh()->status);
        $this->assertSame('مستندات ناقصة', $p2->partnerDetail->fresh()->rejection_reason);
    }

    public function test_partner_suspend_only_when_active(): void
    {
        $p = $this->partner(PartnerDetail::STATUS_APPROVED);

        $this->as()->postJson("/admin/partners/{$p->id}/suspend", ['reason' => 'شكاوى متكررة'])->assertOk();
        $this->assertFalse($p->fresh()->is_active);
        $this->assertSame('شكاوى متكررة', $p->partnerDetail->fresh()->suspension_reason);

        // Already suspended → 409.
        $this->as()->postJson("/admin/partners/{$p->id}/suspend", ['reason' => 'مجدداً'])->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
    }

    public function test_partner_verify_and_revoke_badge(): void
    {
        $p = $this->partner(PartnerDetail::STATUS_APPROVED);

        // Badge is independent of KYC status: not verified until explicitly granted.
        $this->assertFalse($this->as()->getJson("/admin/partners/{$p->id}")->json('verified'));

        $this->as()->postJson("/admin/partners/{$p->id}/verify")->assertOk();
        $this->assertNotNull($p->partnerDetail->fresh()->verified_at);
        $this->assertTrue($this->as()->getJson("/admin/partners/{$p->id}")->json('verified'));

        $this->as()->postJson("/admin/partners/{$p->id}/revoke-verification")->assertOk();
        $this->assertNull($p->partnerDetail->fresh()->verified_at);
    }

    public function test_partner_document_verify_flips_status(): void
    {
        $p = $this->partner(PartnerDetail::STATUS_PENDING);

        // Before: national_id doc is pending_review (KYC pending).
        $docs = $this->as()->getJson("/admin/partners/{$p->id}")->json('documents');
        $this->assertSame('pending_review', collect($docs)->firstWhere('kind', 'national_id')['status']);

        $this->as()->postJson("/admin/partners/{$p->id}/documents/national_id/verify")->assertOk();

        $docs = $this->as()->getJson("/admin/partners/{$p->id}")->json('documents');
        $this->assertSame('verified', collect($docs)->firstWhere('kind', 'national_id')['status']);
    }

    public function test_partner_invite_creates_pending_company(): void
    {
        $this->as()->postJson('/admin/partners/invite', ['phone' => '+966598765432', 'type' => 'company', 'name' => 'شركة'])
            ->assertOk()->assertExactJson(['ok' => true]);

        $u = User::where('phone', '+966598765432')->first();
        $this->assertNotNull($u);
        $this->assertTrue($u->hasRole('Company', 'web'));
        $this->assertSame('company', $u->partnerDetail->type);
        $this->assertSame(PartnerDetail::STATUS_PENDING, $u->partnerDetail->status);
    }

    /* ---------- units §5.6 ---------- */

    public function test_create_mamsa_owned_unit_starts_draft(): void
    {
        $this->as()->postJson('/admin/units', [
            'name' => 'شاليه ممسى', 'type' => 'chalet', 'city' => 'أبها', 'district' => 'السد',
            'pricePerNight' => 750, 'bedrooms' => 3, 'bathrooms' => 2, 'capacity' => 8, 'sizeSqm' => 200,
        ])->assertStatus(201)->assertExactJson(['ok' => true]);

        $unit = Unit::where('unit_name', 'شاليه ممسى')->first();
        $this->assertNotNull($unit);
        $this->assertTrue($unit->mamsa_owned);
        $this->assertSame('draft', $unit->approval_status);
        $this->assertSame($this->adminUser->id, $unit->user_id);

        // Its card shows mamsaOwned + partnerName "ممسى".
        $row = $this->as()->getJson('/admin/units/'.$unit->id)->json();
        $this->assertTrue($row['mamsaOwned']);
        $this->assertSame('ممسى', $row['partnerName']);
        $this->assertSame('draft', $row['status']);
    }

    public function test_unpublish_requires_approved_unit(): void
    {
        $partner = $this->partner(PartnerDetail::STATUS_APPROVED);
        $unit    = $partner->units()->create([
            'unit_name' => 'وحدة منشورة', 'unit_type' => 'apartment', 'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 400, 'capacity' => 4, 'bedrooms' => 2, 'bathrooms' => 1, 'area' => 100,
            'city' => 'الرياض', 'district' => 'العليا', 'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);

        $this->as()->postJson("/admin/units/{$unit->id}/unpublish", ['reason' => 'مخالفة الشروط'])->assertOk();
        $fresh = $unit->fresh();
        $this->assertSame('rejected', $fresh->approval_status);
        $this->assertSame('مخالفة الشروط', $fresh->rejection_reason);

        // Not approved anymore → 409.
        $this->as()->postJson("/admin/units/{$unit->id}/unpublish", ['reason' => 'مجدداً'])->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
    }

    /* ---------- cancellations §5.9 ---------- */

    public function test_retry_failed_refund(): void
    {
        $partner = $this->partner(PartnerDetail::STATUS_APPROVED);
        $guest   = $this->guest();
        $booking = $this->bookingFor($guest, $partner, Booking::STATUS_CANCELLED);
        $booking->update(['cancelled_at' => now(), 'cancelled_by' => 'customer']);

        $payment = $booking->payment()->create([
            'amount' => 900, 'refunded_amount' => 0, 'payment_method' => 'mada',
            'payment_status' => 'paid', 'moyasar_id' => null, 'paid_at' => now(),
        ]);
        $booking->refunds()->create([
            'payment_id' => $payment->id, 'type' => Refund::TYPE_REFUND,
            'amount' => 900, 'refund_percent' => 100, 'status' => 'failed',
        ]);

        $this->as()->postJson("/admin/cancellations/{$booking->id}/retry-refund")->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame('succeeded', $booking->refunds()->first()->status);
        $this->assertEqualsWithDelta(900.0, (float) $payment->fresh()->refunded_amount, 0.01);
    }

    public function test_retry_refund_conflicts_without_failed_refund(): void
    {
        $partner = $this->partner(PartnerDetail::STATUS_APPROVED);
        $guest   = $this->guest();
        $booking = $this->bookingFor($guest, $partner, Booking::STATUS_CANCELLED);
        $booking->update(['cancelled_at' => now(), 'cancelled_by' => 'customer']);

        $this->as()->postJson("/admin/cancellations/{$booking->id}/retry-refund")
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
    }
}
