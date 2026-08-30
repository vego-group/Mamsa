<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Submitting a listing for review must mean the same thing on both surfaces.
 *
 * The dashboard has always run UnitWriter::submitErrors(). The v1 endpoint
 * flipped approval_status with no checks at all, so a unit could reach an
 * admin's queue with no location, no licence number and no licence file — and
 * one had: unit 28 on staging sat `pending` with lat/lng NULL.
 */
class V1SubmitValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
    }

    /** A unit missing everything the gate asks for. */
    private function bareUnit(): Unit
    {
        return $this->partner->units()->create([
            'unit_name' => 'وحدة', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1,
            'approval_status' => 'draft', 'status' => 'unavailable',
            'calendar_token' => str()->random(60),
        ]);
    }

    private function completeUnit(): Unit
    {
        $unit = $this->bareUnit();
        $unit->update([
            'beds' => 2, 'bathrooms' => 1, 'city' => 'الرياض',
            'description' => str_repeat('وصف كافٍ للوحدة. ', 5),
            'address' => 'حي الملقا، الرياض',
            'lat' => 24.7136, 'lng' => 46.6753,
            'tourism_permit_no' => 'TL-0001',
            'tourism_permit_file' => 'units/1/docs/licence.pdf',
        ]);
        $unit->images()->create(['path' => 'units/1/real.jpg', 'is_main' => true]);

        return $unit->fresh();
    }

    public function test_an_incomplete_unit_is_refused_and_stays_a_draft(): void
    {
        $unit = $this->bareUnit();

        $body = $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNIT_INCOMPLETE')
            ->json();

        // The status must NOT have moved — a refused submit that still queues
        // the unit is worse than no check at all.
        $this->assertSame('draft', $unit->fresh()->approval_status);

        // Reported against the names THIS surface accepts, not the dashboard's.
        $fields = array_keys($body['errors']);
        $this->assertContains('location', $fields);
        $this->assertContains('tourism_permit_no', $fields);
        $this->assertContains('tourism_permit_file', $fields);
        $this->assertContains('address', $fields);
        $this->assertContains('images', $fields);

        // camelCase keys would point at inputs that do not exist here.
        $this->assertNotContains('tourismLicenseNumber', $fields);
        $this->assertNotContains('photos', $fields);
    }

    public function test_a_complete_unit_still_submits(): void
    {
        $unit = $this->completeUnit();

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/submit")
            ->assertOk();

        $this->assertSame('pending', $unit->fresh()->approval_status);
    }

    public function test_a_location_outside_saudi_is_refused(): void
    {
        $unit = $this->completeUnit();
        $unit->update(['lat' => 48.8566, 'lng' => 2.3522]); // Paris

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('errors.location.0', 'الموقع يجب أن يكون داخل حدود المملكة');
    }

    public function test_the_address_this_surface_could_never_set_is_now_settable(): void
    {
        // submitErrors requires an address, and v1 accepted no such field: the
        // gate would have been unsatisfiable from this surface, blocking every
        // partner permanently rather than telling them what to fix.
        $unit = $this->bareUnit();

        $this->actingAs($this->partner, 'sanctum')
            ->putJson("/api/v1/partner/units/{$unit->id}", ['address' => 'حي الملقا، الرياض'])
            ->assertOk();

        $this->assertSame('حي الملقا، الرياض', $unit->fresh()->address);

        // And it must come BACK, or the edit form reloads blank and a partner
        // retypes an address that was saved correctly the first time.
        // Through the PARTNER endpoint: this is a draft, and the public route
        // 404s anything not approved-and-available. That route is where the
        // edit form actually loads from.
        $this->actingAs($this->partner, 'sanctum')
            ->getJson("/api/v1/partner/units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.address', 'حي الملقا، الرياض');
    }

    public function test_a_placeholder_photo_does_not_count_as_a_photo(): void
    {
        $unit = $this->completeUnit();
        $unit->images()->delete();
        $unit->images()->create(['path' => \App\Support\Media::defaultImagePath(), 'is_main' => true]);

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('errors.images.0', 'أضف صورة واحدة على الأقل');
    }

    public function test_another_partner_still_cannot_submit_your_unit(): void
    {
        $unit = $this->completeUnit();
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Individual');

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/submit")
            ->assertForbidden();
    }
}
