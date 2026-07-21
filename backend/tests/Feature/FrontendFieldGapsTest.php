<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Feature;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use App\Services\CancellationPolicyService;
use Database\Seeders\CancellationPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Frontend field-gap task (2026-07-21): the additive API fields that stop the
 * Next.js adapter from inventing/deriving values — owner.type, amenities
 * {key,label}, tax_percent, is_featured, guests split, and the explicit
 * money/tier figures on cancellation-preview.
 */
class FrontendFieldGapsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Company', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        $this->seed(CancellationPolicySeeder::class);
    }

    private function companyUnit(): Unit
    {
        $owner = User::factory()->create(['name' => 'شركة الضيافة']);
        $owner->assignRole('Company');
        $owner->partnerDetail()->create(['type' => 'company', 'status' => PartnerDetail::STATUS_APPROVED]);

        $unit = $owner->units()->create([
            'unit_name' => 'وحدة الحقول', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 4, 'bedrooms' => 2, 'beds' => 3, 'bathrooms' => 2,
            'approval_status' => 'approved', 'status' => 'available',
            'is_featured' => true, 'calendar_token' => str()->random(60),
        ]);

        // Mixed amenities: one canonical, one spelling-variant, one unmapped.
        $ids = collect(['واي فاي', 'مكيف', 'مسبح خاص جداً'])
            ->map(fn ($n) => Feature::firstOrCreate(['name' => $n])->id);
        $unit->features()->sync($ids);

        return $unit;
    }

    public function test_unit_resource_exposes_owner_amenities_tax_and_featured(): void
    {
        $unit = $this->companyUnit();
        $guest = User::factory()->create();
        $guest->assignRole('User');

        $res = $this->actingAs($guest)->getJson('/api/v1/units/'.$unit->id)->assertOk();
        $u = $res->json('data') ?? $res->json();

        // owner.type distinguishes companies (was always "individual").
        $this->assertSame('company', $u['owner']['type']);
        $this->assertTrue($u['owner']['is_verified']);
        $this->assertNull($u['owner']['avatar_url']);

        // Uniform VAT exposed, not hardcoded client-side.
        $this->assertSame(15.0, (float) $u['tax_percent']);
        $this->assertTrue($u['is_featured']);
        $this->assertSame(3, $u['beds']);

        // amenities: stable key + label; variant resolves, unmapped → null key.
        $byLabel = collect($u['amenities'])->keyBy('label');
        $this->assertSame('wifi', $byLabel['واي فاي']['key']);
        $this->assertSame('ac', $byLabel['مكيف']['key']);        // variant → ac
        $this->assertNull($byLabel['مسبح خاص جداً']['key']);      // outside vocabulary
    }

    public function test_featured_filter_and_admin_toggle(): void
    {
        $unit = $this->companyUnit(); // featured
        $plain = $this->companyUnit();
        $plain->update(['is_featured' => false]);
        $guest = User::factory()->create();
        $guest->assignRole('User');

        $ids = collect($this->actingAs($guest)->getJson('/api/v1/units?featured=1')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertTrue($ids->contains($unit->id));
        $this->assertFalse($ids->contains($plain->id));

        // Admin toggle.
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin)
            ->patchJson('/api/v1/admin/units/'.$plain->id.'/featured', ['is_featured' => true])
            ->assertOk();
        $this->assertTrue($plain->fresh()->is_featured);
    }

    public function test_booking_stores_and_returns_children_split(): void
    {
        $unit = $this->companyUnit();
        $guest = User::factory()->create(['email' => 'g@m.com', 'email_verified_at' => now()]);
        $guest->assignRole('User');

        $res = $this->actingAs($guest)->postJson('/api/v1/bookings', [
            'unit_id' => $unit->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'guests' => 3, 'children' => 1,
        ])->assertStatus(201);

        $body = $res->json('data') ?? $res->json();
        $this->assertSame(3, $body['guests']);
        $this->assertSame(2, $body['guests_detail']['adults']);
        $this->assertSame(1, $body['guests_detail']['children']);

        // children must not exceed guests.
        $this->actingAs($guest)->postJson('/api/v1/bookings', [
            'unit_id' => $unit->id,
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(21)->toDateString(),
            'guests' => 2, 'children' => 5,
        ])->assertStatus(422);
    }

    public function test_cancellation_preview_returns_explicit_money_and_tier(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);
        $unit = $owner->units()->create([
            'unit_name' => 'و', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1, 'status' => 'available',
            'approval_status' => 'approved', 'calendar_token' => str()->random(60),
            'cancellation_policy_id' => CancellationPolicy::where('key', 'moderate')->value('id'),
        ]);

        $guest = User::factory()->create();
        $guest->assignRole('User');

        $booking = Booking::create([
            'unit_id' => $unit->id, 'user_id' => $guest->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'guests' => 2, 'status' => Booking::STATUS_CONFIRMED,
            'total_amount' => 1000,
        ]);
        $booking->update([
            'cancellation_snapshot' => app(CancellationPolicyService::class)->snapshotForBooking($booking),
        ]);

        // 5 days out → moderate 3–7d tier = 50%.
        $res = $this->actingAs($guest)
            ->getJson("/api/v1/bookings/{$booking->id}/cancellation-preview")
            ->assertOk();
        $q = $res->json('data') ?? $res->json();

        $this->assertSame(1000.0, (float) $q['total_amount']);
        $this->assertSame(500.0, (float) $q['refund_amount']);
        $this->assertSame(500.0, (float) $q['forfeited_amount']);   // was reverse-divided
        $this->assertSame(50, $q['refund_percent']);
        $this->assertSame(72, $q['tier']['min_hours_before_checkin']);
        $this->assertSame(50, $q['tier']['refund_percent']);
    }

    public function test_me_returns_partner_type_and_avatar(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Company');
        $owner->partnerDetail()->create(['type' => 'company', 'status' => PartnerDetail::STATUS_APPROVED]);

        $this->actingAs($owner)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.partner_type', 'company')
            ->assertJsonPath('data.avatar_url', null);
    }
}
