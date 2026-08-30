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
 * Unit documents on the v1 surface: the tourism licence and the ownership
 * proof (title deed / lease contract).
 *
 * Before this existed, a partner on the Vue app could not attach the licence at
 * all — the only upload path was the dashboard's cookie-session presign flow —
 * even though a listing cannot be submitted for review without one.
 */
class UnitDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
        $this->unit = $this->makeUnit($this->partner);
    }

    private function makeUnit(User $owner): Unit
    {
        return $owner->units()->create([
            'unit_name' => 'وحدة '.fake()->unique()->numerify('###'),
            'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1,
            'approval_status' => 'draft', 'status' => 'unavailable',
            'calendar_token' => str()->random(60),
        ]);
    }

    public function test_a_partner_can_attach_the_tourism_licence(): void
    {
        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$this->unit->id}/documents", [
                'type' => 'tourism_permit',
                'file' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'tourism_permit');

        $this->unit->refresh();
        $this->assertNotNull($this->unit->tourism_permit_file);
        Storage::disk('public')->assertExists($this->unit->tourism_permit_file);
    }

    public function test_a_partner_can_attach_an_ownership_document_as_an_image(): void
    {
        // A deed is photographed at least as often as it is scanned; a PDF-only
        // rule would reject the ordinary case.
        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$this->unit->id}/documents", [
                'type' => 'ownership_doc',
                'file' => UploadedFile::fake()->image('deed.jpg'),
            ])
            ->assertCreated();

        $this->assertNotNull($this->unit->fresh()->ownership_doc_file);
    }

    public function test_replacing_a_document_deletes_the_file_it_replaced(): void
    {
        $post = fn () => $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$this->unit->id}/documents", [
                'type' => 'ownership_doc',
                'file' => UploadedFile::fake()->image('deed.jpg'),
            ])->assertCreated();

        $post();
        $first = $this->unit->fresh()->ownership_doc_file;

        $post();
        $second = $this->unit->fresh()->ownership_doc_file;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_dashboard_upload_id_is_never_deleted_from_disk(): void
    {
        // The dashboard stores a `file_...` id pointing at a DashboardUpload row
        // it owns. Replacing from this surface must not delete those bytes —
        // they are not ours and may be referenced elsewhere.
        $this->unit->update(['ownership_doc_file' => 'file_abc123']);

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$this->unit->id}/documents", [
                'type' => 'ownership_doc',
                'file' => UploadedFile::fake()->image('deed.jpg'),
            ])->assertCreated();

        // Nothing to assert on disk — the point is that no delete was attempted
        // on a non-path value, which a path-shaped delete would have turned into
        // an unlink of "file_abc123".
        $this->assertNotSame('file_abc123', $this->unit->fresh()->ownership_doc_file);
    }

    public function test_another_partner_cannot_attach_to_someone_elses_unit(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Individual');

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/partner/units/{$this->unit->id}/documents", [
                'type' => 'ownership_doc',
                'file' => UploadedFile::fake()->image('deed.jpg'),
            ])
            ->assertForbidden();
    }

    public function test_an_unknown_document_type_is_rejected(): void
    {
        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$this->unit->id}/documents", [
                'type' => 'passport',
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertStatus(422);
    }

    public function test_an_executable_is_not_a_document(): void
    {
        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$this->unit->id}/documents", [
                'type' => 'ownership_doc',
                'file' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
            ])
            ->assertStatus(422);
    }

    public function test_a_document_can_be_removed(): void
    {
        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$this->unit->id}/documents", [
                'type' => 'ownership_doc',
                'file' => UploadedFile::fake()->image('deed.jpg'),
            ])->assertCreated();

        $path = $this->unit->fresh()->ownership_doc_file;

        $this->actingAs($this->partner, 'sanctum')
            ->deleteJson("/api/v1/partner/units/{$this->unit->id}/documents/ownership_doc")
            ->assertOk();

        $this->assertNull($this->unit->fresh()->ownership_doc_file);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_documents_never_appear_on_a_public_unit_payload(): void
    {
        $this->unit->update([
            'approval_status'    => 'approved',
            'status'             => 'available',
            'tourism_permit_no'  => 'TL-SECRET-1',
            'ownership_doc_file' => 'units/1/docs/deed.jpg',
        ]);

        // A title deed carries the owner's name and the property's registry
        // details. A guest must never receive it, nor the licence number.
        $body = $this->getJson("/api/v1/units/{$this->unit->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('TL-SECRET-1', $body);
        $this->assertStringNotContainsString('ownership_doc_url', $body);
        $this->assertStringNotContainsString('deed.jpg', $body);
    }

    public function test_the_admin_review_row_reflects_the_units_own_document(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Admin');

        $this->partner->partnerDetail()->create([
            'type' => 'individual',
            'status' => \App\Models\PartnerDetail::STATUS_APPROVED,
            // The three files the row USED to be derived from. None of them is
            // proof of property ownership, and an approved partner made the row
            // read "verified" while no deed existed anywhere.
            'authorization_letter_file' => 'file_auth',
            'vat_certificate_file'      => 'file_vat',
        ]);
        $this->unit->update(['approval_status' => 'pending']);

        $row = fn () => collect(
            $this->actingAs($admin, 'sanctum')
                ->getJson("/api/v1/admin/requests/{$this->unit->id}")
                ->assertOk()->json('data.partner.documents')
        )->firstWhere('key', 'ownership');

        // No deed on the unit → missing, despite the unrelated partner files.
        $this->assertSame('missing', $row()['status']);

        $this->unit->update(['ownership_doc_file' => 'units/1/docs/deed.pdf']);

        $after = $row();
        $this->assertSame('pending', $after['status']);
        $this->assertStringContainsString('deed.pdf', $after['fileUrl']);
    }

    public function test_the_bank_certificate_is_stored_on_the_PARTNER_not_the_unit(): void
    {
        $second = $this->makeUnit($this->partner);

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$this->unit->id}/documents", [
                'type' => 'bank_certificate',
                'file' => UploadedFile::fake()->create('iban.pdf', 50, 'application/pdf'),
            ])->assertCreated();

        // One bank account serves every listing. Storing it per unit would keep
        // duplicate copies that can drift apart, so it lands on partner_details
        // and is immediately true of the partner's OTHER units as well.
        $detail = $this->partner->partnerDetail()->first();
        $this->assertNotNull($detail->bank_certificate_file);
        $this->assertNull($this->unit->fresh()->ownership_doc_file);
        $this->assertNull($second->fresh()->ownership_doc_file);
    }

    public function test_the_admin_bank_row_needs_both_the_iban_and_the_document(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Admin');
        $detail = $this->partner->partnerDetail()->create([
            'type' => 'individual',
            'status' => \App\Models\PartnerDetail::STATUS_APPROVED,
        ]);
        $this->unit->update(['approval_status' => 'pending']);

        $row = fn () => collect(
            $this->actingAs($admin, 'sanctum')
                ->getJson("/api/v1/admin/requests/{$this->unit->id}")
                ->assertOk()->json('data.partner.documents')
        )->firstWhere('key', 'bank');

        $this->assertSame('missing', $row()['status'], 'no iban, no file');

        // An IBAN alone used to make this row green. A number nobody can check
        // against a document is not verification — but it is not "nothing on
        // file" either, so it reports as incomplete rather than missing.
        $detail->update(['iban' => 'SA2480000000000000000000']);
        $this->assertSame('pending', $row()['status'], 'iban without a document');

        $detail->update(['bank_certificate_file' => 'units/1/docs/iban.pdf']);
        $after = $row();
        $this->assertSame('verified', $after['status']);
        $this->assertStringContainsString('iban.pdf', $after['fileUrl']);
    }

    public function test_the_owner_does_see_them(): void
    {
        $this->unit->update([
            'approval_status'    => 'approved',
            'status'             => 'available',
            'tourism_permit_no'  => 'TL-SECRET-1',
        ]);

        // A REAL Bearer token, not actingAs(): on this PUBLIC route there is no
        // sanctum middleware, so `$request->user()` reads the default guard and
        // returns null. actingAs(…, 'sanctum') sets the default guard, which
        // hides that entirely — this test passed against the broken code and
        // only a live request exposed it.
        $token = $this->partner->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/units/{$this->unit->id}")
            ->assertOk()
            ->assertJsonPath('data.tourism_permit_no', 'TL-SECRET-1');
    }
}
