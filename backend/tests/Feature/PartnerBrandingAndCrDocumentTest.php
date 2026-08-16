<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DashboardUpload;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A company partner's brand logo (optional, shown on every listing they own)
 * and the commercial-registration DOCUMENT (reviewable by an admin).
 */
class PartnerBrandingAndCrDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        foreach (['Individual', 'Company', 'User', 'SuperAdmin'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('SuperAdmin');

        $this->company = User::factory()->create(['is_active' => true, 'name' => 'شركة الأفق']);
        $this->company->assignRole('Company');
        $this->company->partnerDetail()->create([
            'type' => 'company', 'cr_number' => '1010101010', 'iban' => 'SA'.str_repeat('1', 22),
            'status' => PartnerDetail::STATUS_APPROVED,
        ]);
    }

    /** Push real bytes through presign → signed PUT and return the fileId. */
    private function upload(User $as, string $kind, string $bytes): string
    {
        $presign = $this->actingAs($as, 'dashboard')->postJson('/uploads/presign', [
            'kind' => $kind, 'fileName' => 'f.png', 'mimeType' => 'image/png', 'size' => strlen($bytes),
        ])->assertOk()->json();

        $this->call('PUT', $presign['uploadUrl'], [], [], [], [], $bytes)->assertOk();

        return $presign['fileId'];
    }

    private function png(): string
    {
        return "\x89PNG\r\n\x1a\n".str_repeat('x', 64);
    }

    private function pdf(): string
    {
        return '%PDF-1.4'.str_repeat('x', 64);
    }

    /* ---- the logo ---- */

    public function test_a_company_uploads_a_logo_and_reads_it_back(): void
    {
        $fileId = $this->upload($this->company, 'logo', $this->png());

        $docs = $this->actingAs($this->company, 'dashboard')
            ->putJson('/me/company-docs', ['logoFileId' => $fileId])
            ->assertOk()->json();

        $this->assertSame($fileId, $docs['logoFileId']);
        $this->assertNotNull($docs['logoUrl']);
    }

    /**
     * The regression this codebase has already had once, in the same array:
     * an optional file folded into `complete` freezes every partner out of unit
     * submission on the day it deploys.
     */
    public function test_the_logo_never_gates_completeness(): void
    {
        $this->company->partnerDetail->update([
            'authorization_letter_file' => 'file_a', 'vat_certificate_file' => 'file_b',
            'operator_license_file' => 'file_c',
        ]);

        $docs = $this->actingAs($this->company, 'dashboard')
            ->getJson('/me/company-docs')->assertOk()->json();

        $this->assertNull($docs['logoFileId']);
        $this->assertTrue($docs['complete'], 'a company with no logo is still complete');
    }

    public function test_an_individual_cannot_set_a_logo(): void
    {
        $individual = User::factory()->create(['is_active' => true]);
        $individual->assignRole('Individual');
        $individual->partnerDetail()->create(['type' => 'individual', 'national_id' => '1012345678']);

        $fileId = $this->upload($individual, 'logo', $this->png());

        $this->actingAs($individual, 'dashboard')
            ->putJson('/me/company-docs', ['logoFileId' => $fileId])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LOGO_COMPANY_ONLY');
    }

    /** A PDF logo renders as a broken tile on every listing the company owns. */
    public function test_a_pdf_cannot_be_used_as_a_logo(): void
    {
        $docId = $this->upload($this->company, 'company_doc', $this->pdf());

        $this->actingAs($this->company, 'dashboard')
            ->putJson('/me/company-docs', ['logoFileId' => $docId])
            ->assertStatus(400)
            ->assertJsonPath('error.fields.logoFileId', 'يجب رفع الشعار كصورة');
    }

    public function test_the_presign_endpoint_rejects_a_pdf_sent_as_a_logo(): void
    {
        $presign = $this->actingAs($this->company, 'dashboard')->postJson('/uploads/presign', [
            'kind' => 'logo', 'fileName' => 'l.pdf', 'mimeType' => 'application/pdf', 'size' => 100,
        ])->assertOk()->json();

        $this->call('PUT', $presign['uploadUrl'], [], [], [], [], $this->pdf())
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_FILE_TYPE');
    }

    /** Optional means removable — a partner who uploaded the wrong image needs a way back. */
    public function test_a_logo_can_be_cleared(): void
    {
        $fileId = $this->upload($this->company, 'logo', $this->png());

        $this->actingAs($this->company, 'dashboard')
            ->putJson('/me/company-docs', ['logoFileId' => $fileId])->assertOk();

        $docs = $this->actingAs($this->company, 'dashboard')
            ->putJson('/me/company-docs', ['logoFileId' => null])
            ->assertOk()->json();

        $this->assertNull($docs['logoFileId']);
        $this->assertNull($docs['logoUrl']);
    }

    /** The point of the feature: the logo rides along on every listing they own. */
    public function test_the_logo_appears_on_each_unit_the_company_owns(): void
    {
        $fileId = $this->upload($this->company, 'logo', $this->png());
        $this->actingAs($this->company, 'dashboard')
            ->putJson('/me/company-docs', ['logoFileId' => $fileId])->assertOk();

        $unit = $this->unit();

        $owner = $this->getJson('/api/v1/units/'.$unit->id)->assertOk()->json('data.owner');

        $this->assertNotNull($owner['logo_url']);
        $this->assertSame('company', $owner['type']);
    }

    public function test_an_individuals_unit_reports_a_null_logo_rather_than_omitting_it(): void
    {
        $individual = User::factory()->create(['is_active' => true, 'name' => 'مالك فردي']);
        $individual->assignRole('Individual');
        $individual->partnerDetail()->create(['type' => 'individual', 'national_id' => '1012345678']);

        $unit = $this->unit($individual);

        $owner = $this->getJson('/api/v1/units/'.$unit->id)->assertOk()->json('data.owner');

        $this->assertArrayHasKey('logo_url', $owner, 'a missing key and a null are different claims to a client');
        $this->assertNull($owner['logo_url']);
    }

    public function test_the_admin_sees_the_logo_outside_the_kyc_document_list(): void
    {
        $fileId = $this->upload($this->company, 'logo', $this->png());
        $this->actingAs($this->company, 'dashboard')
            ->putJson('/me/company-docs', ['logoFileId' => $fileId])->assertOk();

        $body = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/partners/'.$this->company->id)->assertOk()->json();

        $this->assertNotNull($body['logoUrl']);
        $this->assertNotContains('logo', collect($body['documents'])->pluck('kind')->all(),
            'branding must never enter the review queue');
    }

    /* ---- the commercial-registration document ---- */

    /**
     * السجل التجاري rendered with `fileUrl: null` since it was written, so an
     * admin approving a company's CR was approving ten typed digits with
     * nothing behind them.
     */
    public function test_the_cr_document_reaches_the_admin_review_list(): void
    {
        $fileId = $this->upload($this->company, 'company_doc', $this->pdf());

        $this->actingAs($this->company, 'dashboard')
            ->putJson('/me/company-docs', ['crFileId' => $fileId])->assertOk();

        $documents = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/partners/'.$this->company->id)->assertOk()->json('documents');

        $cr = collect($documents)->firstWhere('kind', 'commercial_registration');

        $this->assertNotNull($cr['fileUrl'], 'the reviewer needs something to open');
        $this->assertSame('1010101010', $cr['value'], 'the typed number is still shown beside it');
    }

    /** A CR is usually photographed, not scanned to PDF. */
    public function test_a_photographed_cr_is_accepted(): void
    {
        $fileId = $this->upload($this->company, 'company_doc', $this->png());

        $docs = $this->actingAs($this->company, 'dashboard')
            ->putJson('/me/company-docs', ['crFileId' => $fileId])
            ->assertOk()->json();

        $this->assertSame($fileId, $docs['crFileId']);
        $this->assertStringEndsWith('.png', (string) $docs['crUrl'], 'the extension follows the bytes');
    }

    /**
     * Every company already registered has a CR number and no scan. Gating
     * completeness on the file would freeze all of them out of unit submission
     * the day it deployed.
     */
    public function test_the_cr_scan_never_gates_completeness(): void
    {
        $this->company->partnerDetail->update([
            'authorization_letter_file' => 'file_a', 'vat_certificate_file' => 'file_b',
            'operator_license_file' => 'file_c',
        ]);

        $docs = $this->actingAs($this->company, 'dashboard')
            ->getJson('/me/company-docs')->assertOk()->json();

        $this->assertNull($docs['crFileId']);
        $this->assertTrue($docs['complete']);
    }

    public function test_a_file_belonging_to_another_partner_is_refused(): void
    {
        $other  = User::factory()->create(['is_active' => true]);
        $other->assignRole('Company');
        $other->partnerDetail()->create(['type' => 'company', 'cr_number' => '2020202020']);

        $fileId = $this->upload($other, 'company_doc', $this->pdf());

        $this->actingAs($this->company, 'dashboard')
            ->putJson('/me/company-docs', ['crFileId' => $fileId])
            ->assertStatus(400)
            ->assertJsonPath('error.fields.crFileId', 'ملف غير موجود');
    }

    private function unit(?User $owner = null): Unit
    {
        return ($owner ?? $this->company)->units()->create([
            'unit_name' => 'استوديو', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 350, 'capacity' => 4, 'bedrooms' => 2, 'beds' => 3, 'bathrooms' => 1,
            'area' => 90, 'city' => 'جدة', 'district' => 'الشاطئ', 'lat' => 21.5, 'lng' => 39.1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);
    }
}
