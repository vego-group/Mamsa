<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The legacy Bearer surfaces write nothing any more.
 *
 * `/api/v1/partner` and `/api/v1/admin` (the testvue console) each had a full
 * unit write path. Every permit rule being built — uniqueness, expiry,
 * per-apartment permits — would have to be implemented and kept correct on
 * four surfaces instead of two, and the admin one could approve a listing
 * without the permit check the admin console's own approve() runs.
 *
 * Reads stay. The public guest API is untouched — it is what the mobile app
 * uses, and nothing here may narrow it.
 */
class RetiredEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private User $admin;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('SuperAdmin');

        $this->unit = $this->partner->units()->create([
            'unit_name' => 'شقة', 'unit_type' => 'apartment', 'code' => 'RET'.fake()->unique()->numerify('#####'),
            'price' => 400, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 1, 'bathrooms' => 1,
            'city' => 'الرياض', 'approval_status' => 'approved', 'status' => 'available',
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function retiredRoutes(): array
    {
        return [
            'create a listing' => ['post', '/api/v1/partner/units'],
            'edit a listing' => ['put', '/api/v1/partner/units/%d'],
            'delete a listing' => ['delete', '/api/v1/partner/units/%d'],
            'submit for review' => ['post', '/api/v1/partner/units/%d/submit'],
            'expand into apartments' => ['post', '/api/v1/partner/units/%d/apartments'],
            'replace the calendar' => ['put', '/api/v1/partner/units/%d/calendar'],
            'block dates' => ['post', '/api/v1/partner/units/%d/blocked-dates'],
            'add a photo' => ['post', '/api/v1/partner/units/%d/images'],
            'attach a document' => ['post', '/api/v1/partner/units/%d/documents'],
        ];
    }

    #[DataProvider('retiredRoutes')]
    public function test_a_partner_write_is_gone(string $verb, string $path): void
    {
        $this->actingAs($this->partner)
            ->json(strtoupper($verb), sprintf($path, $this->unit->id), [])
            ->assertStatus(410)
            ->assertJsonPath('code', 'ENDPOINT_RETIRED')
            ->assertJsonPath('success', false);
    }

    public function test_the_admin_review_decisions_are_gone(): void
    {
        // These bypassed the permit check the admin console's approve() runs,
        // which is the reason they go first.
        $this->unit->update(['approval_status' => 'pending']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/requests/{$this->unit->id}/approve")
            ->assertStatus(410)->assertJsonPath('code', 'ENDPOINT_RETIRED');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/requests/{$this->unit->id}/reject", ['reason' => 'x'])
            ->assertStatus(410)->assertJsonPath('code', 'ENDPOINT_RETIRED');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/units/{$this->unit->id}/featured", ['is_featured' => true])
            ->assertStatus(410)->assertJsonPath('code', 'ENDPOINT_RETIRED');

        // Nothing happened to the unit.
        $this->assertSame('pending', $this->unit->fresh()->approval_status);
        $this->assertFalse((bool) $this->unit->fresh()->is_featured);
    }

    public function test_every_call_to_a_retired_route_is_written_down(): void
    {
        // The whole point of retiring rather than deleting: this host keeps no
        // access logs, so the log is the only way to learn whether anything
        // real was calling these.
        Log::shouldReceive('channel')->with('retired')->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
            return $message === 'retired endpoint called'
                && $context['route'] === 'api.partner.units.store'
                && $context['method'] === 'POST'
                && $context['user_id'] === $this->partner->id
                && $context['roles'] === ['Individual'];
        });

        $this->actingAs($this->partner)->postJson('/api/v1/partner/units', [])->assertStatus(410);
    }

    /* ---------- what must NOT have changed ---------- */

    public function test_the_partner_can_still_read(): void
    {
        $this->actingAs($this->partner)->getJson('/api/v1/partner/units')->assertOk();
        $this->actingAs($this->partner)->getJson("/api/v1/partner/units/{$this->unit->id}")->assertOk();
        $this->actingAs($this->partner)->getJson("/api/v1/partner/units/{$this->unit->id}/calendar")->assertOk();
        $this->actingAs($this->admin)->getJson('/api/v1/admin/requests')->assertOk();
    }

    public function test_the_guest_api_is_untouched(): void
    {
        // The mobile app lives here. Retiring a partner write must never reach it.
        $this->getJson('/api/v1/units')->assertOk();
        $this->getJson("/api/v1/units/{$this->unit->id}")->assertOk();

        $guest = User::factory()->create(['is_active' => true]);
        $guest->assignRole('User');
        $this->actingAs($guest)
            ->postJson('/api/v1/bookings', [
                'unit_id' => $this->unit->id,
                'start_date' => now()->addDays(5)->toDateString(),
                'end_date' => now()->addDays(6)->toDateString(),
                'guests' => 2,
            ])
            ->assertStatus(201);
    }

    public function test_the_two_live_surfaces_still_write(): void
    {
        // The control: retiring the legacy paths must not have caught the
        // surfaces the product actually uses.
        // Admin first: unpublish needs the listing approved, and the partner
        // edit below is what takes an approved listing out of that state.
        $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/units/{$this->unit->id}/unpublish", ['reason' => 'اختبار'])
            ->assertOk();

        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$this->unit->id}", ['pricePerNight' => 450])
            ->assertOk();

        $this->assertSame(450.0, (float) $this->unit->fresh()->price);
    }
}
