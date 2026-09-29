<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PartnerDetail;
use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The sitemap feed is a promise to a crawler: these URLs exist, and this is
 * when each last changed. Both halves were wrong.
 *
 * It emitted a row per APARTMENT while a building is one page, so eight doors
 * published eight URLs for the same content — the duplicate content that splits
 * a ranking. And it skipped the permit filter, so it advertised listings whose
 * page answers 404.
 */
class SitemapListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    public function test_a_building_is_one_row_not_one_per_door(): void
    {
        [$first, , $third] = $this->building(3);

        $rows = $this->getJson('/api/v1/units/sitemap')->assertOk()->json();

        $this->assertCount(1, $rows, 'the building was published once per apartment');
        $this->assertSame($first->unit_group_id, $rows[0]['listing_id']);
        $this->assertSame($first->id, $rows[0]['id'], 'the row must name the representative');

        // And the feed agrees with the router: the key opens that same unit.
        $this->getJson("/api/v1/units/{$rows[0]['listing_id']}")
            ->assertOk()
            ->assertJsonPath('data.id', $first->id);

        $this->assertNotSame($third->id, $rows[0]['id']);
    }

    public function test_the_date_is_the_newest_door_in_the_building(): void
    {
        // Editing apartment 3 changes the page. Dating the row by the
        // representative alone would tell a crawler nothing had moved.
        [$first, , $third] = $this->building(3);

        $first->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();
        $third->forceFill(['updated_at' => now()])->saveQuietly();

        $rows = $this->getJson('/api/v1/units/sitemap')->assertOk()->json();

        $this->assertSame(
            $third->fresh()->updated_at->toIso8601ZuluString(),
            $rows[0]['updated_at'],
            'the feed reported the representative date, not the building date',
        );
    }

    public function test_a_listing_whose_permit_lapsed_is_not_advertised(): void
    {
        // The page answers 404. A sitemap that names it is handing a crawler a
        // dead URL.
        $unit = $this->unit();

        Permit::create([
            'scope_type' => Permit::SCOPE_UNIT, 'scope_id' => (string) $unit->id,
            'number' => 'LAPSED-SM', 'license_type' => 'private_hospitality',
            'expires_at' => now()->subDay()->toDateString(), 'status' => Permit::STATUS_CURRENT,
        ]);

        $this->getJson("/api/v1/units/{$unit->id}")->assertStatus(404);
        $this->assertSame([], $this->getJson('/api/v1/units/sitemap')->assertOk()->json());
    }

    public function test_a_standalone_listing_is_keyed_u_prefixed(): void
    {
        $unit = $this->unit();

        $rows = $this->getJson('/api/v1/units/sitemap')->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame('u'.$unit->id, $rows[0]['listing_id']);
        $this->assertSame($unit->id, $rows[0]['id']);
        $this->assertArrayHasKey('updated_at', $rows[0]);
    }

    public function test_every_row_the_feed_publishes_actually_opens(): void
    {
        // The property that matters, asserted directly: nothing in the feed 404s.
        $this->building(3);
        $this->unit();
        $closed = $this->unit();
        $closed->forceFill(['status' => 'unavailable'])->saveQuietly();

        $rows = $this->getJson('/api/v1/units/sitemap')->assertOk()->json();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->getJson("/api/v1/units/{$row['listing_id']}")
                ->assertOk('the feed published '.$row['listing_id'].' and it does not open');
        }
    }

    /* ---------- fixtures ---------- */

    /** @return array<int, Unit> */
    private function building(int $doors): array
    {
        $first = $this->unit();
        $group = (string) str()->ulid();
        $first->forceFill(['unit_group_id' => $group, 'apartment_no' => '1'])->saveQuietly();

        $units = [$first->fresh()];

        for ($i = 2; $i <= $doors; $i++) {
            $units[] = $first->owner->units()->create([
                'unit_name' => 'برج — '.$i, 'unit_type' => 'apartment',
                'code' => 'MRN'.fake()->unique()->numerify('#####'),
                'price' => 500, 'capacity' => 4, 'bedrooms' => 1,
                'approval_status' => 'approved', 'status' => 'available',
                'calendar_token' => str()->random(60),
                'unit_group_id' => $group, 'apartment_no' => (string) $i,
            ])->fresh();
        }

        return $units;
    }

    private function unit(): Unit
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $owner->units()->create([
            'unit_name' => 'وحدة خريطة', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 4, 'bedrooms' => 1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ])->fresh();
    }
}
