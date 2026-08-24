<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\DashboardUpload;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The admin listing wizard — creating a unit Mamsa owns.
 *
 * Before this, `POST /admin/units` stored nine fields and answered `{ok:true}`:
 * no photos, no permit, no description, and no id to address the result with.
 * A unit cannot pass review without photos, so every unit an admin created was
 * unpublishable by construction.
 */
class UnitWizardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        foreach (['Admin', 'SuperAdmin', 'Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('SuperAdmin');
    }

    private function as(): self
    {
        $this->actingAs($this->admin, 'admin-panel');

        return $this;
    }

    /** A stored upload owned by the given user, as presign+PUT would leave it. */
    private function upload(string $kind, ?User $owner = null): DashboardUpload
    {
        $id   = 'file_'.strtolower((string) str()->ulid());
        $path = "dashboard/{$kind}/{$id}.jpg";
        Storage::disk('public')->put($path, 'bytes');

        return DashboardUpload::create([
            'id'            => $id,
            'user_id'       => ($owner ?? $this->admin)->id,
            'kind'          => $kind,
            'original_name' => 'x.jpg',
            'mime'          => 'image/jpeg',
            'size'          => 5,
            'status'        => 'stored',
            'path'          => $path,
        ]);
    }

    /** @return array<string, mixed> */
    private function fullBody(array $overrides = []): array
    {
        return array_merge([
            'name'          => 'استوديو ممسى العليا',
            'type'          => 'studio',
            'city'          => 'Riyadh',
            'district'      => 'العليا',
            'pricePerNight' => 450,
            'bedrooms'      => 1,
            'bathrooms'     => 1,
            'capacity'      => 2,
            'sizeSqm'       => 90,
        ], $overrides);
    }

    /* ---------- §1 uploads ---------- */

    public function test_presign_returns_an_upload_url_and_file_id(): void
    {
        $this->as()->postJson('/admin/uploads/presign', [
            'kind' => 'unit_photo', 'fileName' => 'photo.jpg', 'mimeType' => 'image/jpeg', 'size' => 204800,
        ])->assertStatus(201)
            ->assertJsonStructure(['uploadUrl', 'fileId']);

        $this->assertSame(1, DashboardUpload::where('user_id', $this->admin->id)->count());
    }

    public function test_presign_rejects_a_kind_that_is_not_an_admins_to_upload(): void
    {
        // company_doc is partner KYC; an admin has no business minting one.
        $this->as()->postJson('/admin/uploads/presign', [
            'kind' => 'company_doc', 'fileName' => 'cr.pdf', 'mimeType' => 'application/pdf', 'size' => 1000,
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_the_signed_put_actually_stores_the_bytes(): void
    {
        $url = $this->as()->postJson('/admin/uploads/presign', [
            'kind' => 'unit_photo', 'fileName' => 'p.png', 'mimeType' => 'image/png', 'size' => 8,
        ])->json('uploadUrl');

        // A real PNG header — the receiving end sniffs magic bytes, not the
        // MIME type the client claimed.
        $this->call('PUT', $url, [], [], [], [], "\x89PNG\r\n\x1a\n")
            ->assertOk();
    }

    public function test_a_file_belonging_to_someone_else_cannot_be_attached(): void
    {
        $stranger = User::factory()->create();
        $photo    = $this->upload('unit_photo', $stranger);

        $this->as()->postJson('/admin/units', $this->fullBody([
            'photoFileIds' => [$photo->id],
        ]))->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');

        // The key is the literal body path, dots and all — `photoFileIds.2`
        // tells the wizard which thumbnail to mark, not just that something
        // was wrong. (Read directly: assertJsonPath would treat the dot as
        // nesting and miss it.)
        $fields = $this->as()->postJson('/admin/units', $this->fullBody([
            'photoFileIds' => [$photo->id],
        ]))->json('fields');
        $this->assertSame('الصورة غير موجودة', $fields['photoFileIds.0'] ?? null);

        $this->assertSame(0, Unit::count(), 'A rejected file list still created the unit.');
    }

    /* ---------- §2 the full body ---------- */

    public function test_the_whole_listing_is_stored_not_just_nine_fields(): void
    {
        $photoA  = $this->upload('unit_photo');
        $photoB  = $this->upload('unit_photo');
        $licence = $this->upload('license_pdf');

        $this->as()->postJson('/admin/units', $this->fullBody([
            'description'          => 'استوديو مفروش بالكامل في قلب العليا، قريب من كل الخدمات.',
            'amenities'            => ['wifi', 'ac', 'kitchen'],
            'cancellationPolicy'   => 'moderate',
            'checkIn'              => '15:00',
            'checkOut'             => '12:00',
            'lat'                  => 24.7136,
            'lng'                  => 46.6753,
            'address'              => 'حي العليا، الرياض',
            'tourismLicenseNumber' => 'TL-2025-00042',
            'tourismLicenseFileId' => $licence->id,
            'photoFileIds'         => [$photoA->id, $photoB->id],
            'coverFileId'          => $photoB->id,
        ]))->assertStatus(201);

        $unit = Unit::firstOrFail();

        $this->assertSame('استوديو مفروش بالكامل في قلب العليا، قريب من كل الخدمات.', $unit->description);
        $this->assertSame('TL-2025-00042', $unit->tourism_permit_no);
        $this->assertSame($licence->id, $unit->tourism_permit_file);
        $this->assertSame('حي العليا، الرياض', $unit->address);
        $this->assertEqualsWithDelta(24.7136, (float) $unit->lat, 0.0001);
        $this->assertSame(2, $unit->images()->count());
        $this->assertSame(3, $unit->features()->count());

        // coverFileId marks the main image — not simply the first one.
        $this->assertSame($photoB->id, $unit->images()->where('is_main', true)->value('file_id'));
    }

    public function test_an_english_city_label_is_stored_as_the_arabic_name(): void
    {
        // The two consoles disagree on spelling: the partner dashboard sends
        // `riyadh`, the admin console sends `Riyadh`, and `units.city` holds
        // `الرياض`. Storing the label verbatim would make the unit invisible to
        // every city filter and browse surface — silently, as an empty list.
        $this->as()->postJson('/admin/units', $this->fullBody(['city' => 'Riyadh']))->assertStatus(201);

        $this->assertSame('الرياض', Unit::firstOrFail()->city);
    }

    public function test_a_city_the_platform_does_not_serve_is_refused(): void
    {
        $this->as()->postJson('/admin/units', $this->fullBody(['city' => 'Atlantis']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['fields' => ['city']]);
    }

    public function test_an_unsupported_unit_type_is_refused_with_a_named_field(): void
    {
        // The platform supports three types; a migration once DELETED every unit
        // of any other. Accepting `chalet` would create a row the rest of the
        // platform cannot publish — better a 422 on step one than a unit that
        // dies at review.
        $this->as()->postJson('/admin/units', $this->fullBody(['type' => 'chalet']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['fields' => ['type']]);
    }

    /* ---------- §3 the response ---------- */

    public function test_create_returns_an_addressable_unit(): void
    {
        $id = $this->as()->postJson('/admin/units', $this->fullBody())->assertStatus(201)->json('id');

        $this->assertNotEmpty($id);
        $this->as()->getJson("/admin/units/{$id}")->assertOk()->assertJsonPath('id', (string) $id);
    }

    /* ---------- §5 submit ---------- */

    public function test_submit_refuses_an_incomplete_draft_and_names_every_gap(): void
    {
        $id = $this->as()->postJson('/admin/units', $this->fullBody())->json('id');

        $this->as()->postJson("/admin/units/{$id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            // The wizard needs to know WHICH step to send the admin back to.
            ->assertJsonStructure(['fields' => ['description', 'address', 'location', 'tourismLicenseNumber', 'photos']]);

        $this->assertSame('draft', Unit::find($id)->approval_status);
    }

    public function test_a_complete_draft_reaches_the_review_queue(): void
    {
        $photo   = $this->upload('unit_photo');
        $licence = $this->upload('license_pdf');

        $id = $this->as()->postJson('/admin/units', $this->fullBody([
            'description'          => 'وصف كامل للوحدة يزيد عن عشرة أحرف بوضوح.',
            'address'              => 'حي العليا، الرياض',
            'lat'                  => 24.7136,
            'lng'                  => 46.6753,
            'tourismLicenseNumber' => 'TL-2025-00042',
            'tourismLicenseFileId' => $licence->id,
            'photoFileIds'         => [$photo->id],
        ]))->json('id');

        $this->as()->postJson("/admin/units/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('status', 'pending_review');

        $unit = Unit::find($id);
        $this->assertSame('pending', $unit->approval_status);
        $this->assertNotNull($unit->submitted_at, 'submitted_at was never stamped.');

        // And it is now in the queue the reviewer actually looks at.
        $this->as()->getJson('/admin/approvals')->assertOk()
            ->assertJsonPath('items.0.unitId', (string) $id);
    }

    public function test_a_unit_already_under_review_cannot_be_submitted_again(): void
    {
        $id = $this->as()->postJson('/admin/units', $this->fullBody())->json('id');
        Unit::find($id)->update(['approval_status' => 'pending']);

        $this->as()->postJson("/admin/units/{$id}/submit")
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
    }

    /* ---------- §6 edit ---------- */

    public function test_a_partial_patch_changes_only_what_it_names(): void
    {
        $id = $this->as()->postJson('/admin/units', $this->fullBody([
            'description' => 'الوصف الأصلي للوحدة هنا.',
        ]))->json('id');

        $this->as()->patchJson("/admin/units/{$id}", ['pricePerNight' => 520])->assertOk();

        $unit = Unit::find($id);
        $this->assertEqualsWithDelta(520.0, (float) $unit->price, 0.01);
        // An absent key means "unchanged", never "blank it".
        $this->assertSame('الوصف الأصلي للوحدة هنا.', $unit->description);
        $this->assertSame('استوديو ممسى العليا', $unit->unit_name);
    }

    public function test_editing_an_approved_unit_sends_it_back_for_review(): void
    {
        $id = $this->as()->postJson('/admin/units', $this->fullBody())->json('id');
        Unit::find($id)->update(['approval_status' => 'approved']);

        $this->as()->patchJson("/admin/units/{$id}", ['pricePerNight' => 999])->assertOk();

        $this->assertSame('pending', Unit::find($id)->approval_status);
    }

    public function test_a_unit_under_review_cannot_be_edited(): void
    {
        $id = $this->as()->postJson('/admin/units', $this->fullBody())->json('id');
        Unit::find($id)->update(['approval_status' => 'pending']);

        $this->as()->patchJson("/admin/units/{$id}", ['pricePerNight' => 999])
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
    }

    public function test_replacing_the_photo_list_removes_what_is_left_out(): void
    {
        $a = $this->upload('unit_photo');
        $b = $this->upload('unit_photo');

        $id = $this->as()->postJson('/admin/units', $this->fullBody([
            'photoFileIds' => [$a->id, $b->id],
        ]))->json('id');
        $this->assertSame(2, Unit::find($id)->images()->count());

        $this->as()->patchJson("/admin/units/{$id}", ['photoFileIds' => [$a->id]])->assertOk();

        $unit = Unit::find($id);
        $this->assertSame(1, $unit->images()->count());
        $this->assertSame($a->id, $unit->images()->value('file_id'));
    }

    /* ---------- §7 delete ---------- */

    public function test_a_draft_can_be_deleted(): void
    {
        $id = $this->as()->postJson('/admin/units', $this->fullBody())->json('id');

        $this->as()->deleteJson("/admin/units/{$id}")->assertOk();
        $this->assertNull(Unit::find($id));
    }

    public function test_a_unit_past_draft_cannot_be_deleted(): void
    {
        $id = $this->as()->postJson('/admin/units', $this->fullBody())->json('id');
        Unit::find($id)->update(['approval_status' => 'approved']);

        $this->as()->deleteJson("/admin/units/{$id}")
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
        $this->assertNotNull(Unit::find($id));
    }

    /* ---------- permissions ---------- */

    public function test_a_finance_admin_cannot_create_a_unit(): void
    {
        // `units.manage` gates every mutation here. Finance is the only role
        // that currently resolves to a narrower permission set — a plain
        // `Admin` still resolves to superadmin, which is its own open item.
        Role::findOrCreate('finance', 'web');
        $finance = User::factory()->create(['is_active' => true]);
        $finance->assignRole('finance');

        $this->actingAs($finance, 'admin-panel')
            ->postJson('/admin/units', $this->fullBody())
            ->assertStatus(403)
            ->assertJsonPath('code', 'INSUFFICIENT_PERMISSION');
    }
}
