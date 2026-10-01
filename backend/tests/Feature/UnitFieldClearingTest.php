<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\CancellationPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Clearing an optional field, and the last of the strip_tags removals.
 *
 * The rule across every writable field is now one sentence: an ABSENT key means
 * unchanged, and anything else is the new value — including the empty one.
 */
class UnitFieldClearingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(CancellationPolicySeeder::class);

        foreach (['Admin', 'SuperAdmin', 'Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('SuperAdmin');
        $this->partner = User::factory()->create(['is_active' => true]);
    }

    /* ---------- amenities ---------- */

    public function test_an_empty_array_clears_every_amenity(): void
    {
        $unit = $this->unitWithAmenities();

        $this->edit($unit, ['amenities' => []])->assertOk();

        $this->assertSame(0, $unit->features()->count());
    }

    public function test_null_also_clears_every_amenity(): void
    {
        // Used to be a 422 ("must be an array"), which was a trap: the console
        // had settled on null as its spelling for "no value" on the text fields
        // and would reasonably reach for it here too.
        $unit = $this->unitWithAmenities();

        $this->edit($unit, ['amenities' => null])->assertOk();

        $this->assertSame(0, $unit->features()->count());
    }

    public function test_an_absent_amenities_key_leaves_them_alone(): void
    {
        $unit = $this->unitWithAmenities();

        $this->edit($unit, ['bedrooms' => 3])->assertOk();

        $this->assertSame(2, $unit->features()->count());
    }

    public function test_a_list_replaces_rather_than_adds(): void
    {
        // Same semantics as photoFileIds: the array IS the set, not a delta.
        $unit = $this->unitWithAmenities();

        $this->edit($unit, ['amenities' => ['pool']])->assertOk();

        $this->assertSame(1, $unit->features()->count());
    }

    public function test_the_admin_console_can_clear_amenities_too(): void
    {
        $id = $this->createViaAdmin(['amenities' => ['wifi', 'ac']]);

        $this->assertSame(2, Unit::findOrFail($id)->features()->count());

        $this->actingAs($this->admin, 'admin-panel')
            ->patchJson("/admin/units/{$id}", ['amenities' => []])
            ->assertOk();

        $this->assertSame(0, Unit::findOrFail($id)->features()->count());
    }

    /* ---------- photos, same shape ---------- */

    public function test_an_empty_photo_list_clears_the_gallery(): void
    {
        // A photo the client CAN address — the only kind an empty list removes.
        $unit   = $this->unitWithAmenities();
        $upload = $this->upload((int) $unit->user_id);
        $unit->images()->create(['file_id' => $upload->id, 'path' => $upload->path, 'is_main' => true]);

        $this->edit($unit, ['photoFileIds' => []])->assertOk();

        $this->assertSame(0, $unit->images()->count());
    }

    public function test_a_null_photo_list_clears_the_gallery(): void
    {
        $unit   = $this->unitWithAmenities();
        $upload = $this->upload((int) $unit->user_id);
        $unit->images()->create(['file_id' => $upload->id, 'path' => $upload->path, 'is_main' => true]);

        $this->edit($unit, ['photoFileIds' => null])->assertOk();

        $this->assertSame(0, $unit->images()->count());
    }

    /* ---------- photos that cannot be addressed ---------- */

    public function test_a_photo_without_a_file_id_survives_an_empty_list(): void
    {
        // It predates the upload flow, so no client can name it in photoFileIds
        // — deleting it would be answering a limit of the request format rather
        // than anyone's intent, and the file cannot be put back.
        $unit = $this->unitWithAmenities();
        $unit->images()->create(['path' => 'legacy/old.jpg', 'is_main' => true]);

        $this->edit($unit, ['photoFileIds' => []])->assertOk();

        $this->assertSame(1, $unit->images()->count());
        $this->assertSame('legacy/old.jpg', $unit->images()->first()->path);
    }

    public function test_it_also_survives_an_ordinary_photo_edit(): void
    {
        // The case the admin console's own guard could not cover: adding a photo
        // used to wipe the gallery just the same as clearing it.
        $unit   = $this->unitWithAmenities();
        $upload = $this->upload((int) $unit->user_id);
        $unit->images()->create(['path' => 'legacy/old.jpg', 'is_main' => true]);

        $this->edit($unit, ['photoFileIds' => [$upload->id]])->assertOk();

        $paths = $unit->images()->orderBy('sort_order')->pluck('path')->all();

        $this->assertContains('legacy/old.jpg', $paths);
        $this->assertSame(2, count($paths));
    }

    public function test_the_addressable_photos_keep_the_order_that_was_sent(): void
    {
        $unit = $this->unitWithAmenities();
        $unit->images()->create(['path' => 'legacy/old.jpg', 'is_main' => true]);
        $a = $this->upload((int) $unit->user_id);
        $b = $this->upload((int) $unit->user_id);

        $this->edit($unit, ['photoFileIds' => [$a->id, $b->id], 'coverFileId' => $b->id])->assertOk();

        $rows = $unit->images()->orderBy('sort_order')->get();

        // Client order first, unaddressable trailing behind it.
        $this->assertSame([$a->path, $b->path, 'legacy/old.jpg'], $rows->pluck('path')->all());
        // And the cover the client chose is the cover, not the survivor.
        $this->assertSame($b->path, $rows->firstWhere('is_main', true)?->path);
    }

    public function test_a_normal_gallery_is_still_fully_replaced(): void
    {
        // The preservation must not turn into "photos never get removed".
        $unit = $this->unitWithAmenities();
        $old  = $this->upload((int) $unit->user_id);
        $unit->images()->create(['file_id' => $old->id, 'path' => $old->path, 'is_main' => true]);

        $this->edit($unit, ['photoFileIds' => []])->assertOk();

        $this->assertSame(0, $unit->images()->count());
    }

    /* ---------- the last strip_tags removals ---------- */

    /**
     * `address` is the one a guest navigates by, and the likeliest of the three
     * to carry a `<`: "<200م من المسجد" lost everything after the bracket.
     *
     * @param  string  $field  contract key
     * @param  string  $column DB column
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('textFields')]
    public function test_an_angle_bracket_survives_in_free_text(string $field, string $column, string $value): void
    {
        $id = $this->createViaAdmin([$field => $value]);

        $this->assertSame($value, Unit::findOrFail($id)->{$column});
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function textFields(): array
    {
        return [
            'address with a distance'  => ['address', 'address', '<200م من المسجد، حي النرجس'],
            'address with minutes'     => ['address', 'address', '<5 دقائق من المطار'],
            'name with a bracket'      => ['name', 'unit_name', 'شقة <الفخامة> بالنرجس'],
            'district with a bracket'  => ['district', 'district', 'النرجس <الشمالي>'],
        ];
    }

    public function test_the_partner_path_keeps_them_too(): void
    {
        $unit = $this->unitWithAmenities();
        $address = '<200م من المسجد';

        $this->edit($unit, ['address' => $address])->assertOk();

        $this->assertSame($address, $unit->fresh()->address);
    }

    /* ---------- helpers ---------- */

    /** @param array<string, mixed> $body */
    private function edit(Unit $unit, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$unit->id}", $body);
    }

    /** @param array<string, mixed> $overrides */
    private function createViaAdmin(array $overrides = []): string
    {
        return (string) $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/units', array_merge([
                'name'          => 'وحدة اختبار',
                'type'          => 'apartment',
                'city'          => 'الرياض',
                'district'      => 'النرجس',
                'pricePerNight' => 400,
                'bedrooms'      => 2,
                'bathrooms'     => 1,
                'capacity'      => 4,
                'sizeSqm'       => 90,
            ], $overrides))
            ->assertStatus(201)
            ->json('id');
    }

    /** A stored unit_photo upload owned by the given user, as presign+PUT leaves it. */
    private function upload(int $ownerId): \App\Models\DashboardUpload
    {
        $id = 'file_'.strtolower((string) str()->ulid());

        return \App\Models\DashboardUpload::create([
            'id'            => $id,
            'user_id'       => $ownerId,
            'kind'          => 'unit_photo',
            'original_name' => 'p.jpg',
            'mime'          => 'image/jpeg',
            'size'          => 1000,
            'path'          => "dashboard/unit_photo/{$id}.jpg",
            'status'        => 'stored',
        ]);
    }

    private function unitWithAmenities(): Unit
    {
        $unit = $this->partner->units()->create([
            'unit_name'       => 'وحدة شريك',
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => 300,
            'capacity'        => 2,
            'bedrooms'        => 1,
            'approval_status' => 'draft',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ]);

        $unit->features()->sync([
            Feature::firstOrCreate(['name' => 'واي فاي'])->id,
            Feature::firstOrCreate(['name' => 'تكييف'])->id,
        ]);

        return $unit;
    }
}
