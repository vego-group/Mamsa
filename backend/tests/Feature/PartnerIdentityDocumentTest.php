<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DashboardUpload;
use App\Models\PartnerDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Identity document captured at partner registration and reviewed by an admin. */
class PartnerIdentityDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        foreach (['Individual', 'Company', 'User', 'SuperAdmin'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        config(['otp.fixed_code' => '424242']);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'type'        => 'individual',
            'name'        => 'محمد الشهري',
            'phone'       => '512345678',
            'code'        => '424242',
            'email'       => 'partner'.fake()->unique()->numerify('###').'@example.com',
            'national_id' => '1012345678',
        ], $over);
    }

    private function requestOtp(string $phone = '512345678'): void
    {
        $this->postJson('/api/v1/auth/request-otp', ['phone' => $phone])->assertOk();
    }

    public function test_individual_registration_requires_the_identity_image(): void
    {
        $this->requestOtp();

        $this->postJson('/api/v1/auth/partner/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['national_id_file']);
    }

    public function test_identity_image_is_stored_and_linked_to_the_partner(): void
    {
        $this->requestOtp();

        $this->post('/api/v1/auth/partner/register', $this->payload([
            'national_id_file' => UploadedFile::fake()->image('id.jpg'),
        ]))->assertCreated();

        $detail = PartnerDetail::firstOrFail();
        $this->assertNotNull($detail->national_id_file, 'the scan must be linked to the partner');

        $upload = DashboardUpload::find($detail->national_id_file);
        $this->assertNotNull($upload, 'it must be a resolvable DashboardUpload');
        $this->assertSame('stored', $upload->status);
        Storage::disk('public')->assertExists($upload->path);
    }

    public function test_a_rejected_file_type_is_refused(): void
    {
        $this->requestOtp();

        $this->post('/api/v1/auth/partner/register', $this->payload([
            'national_id_file' => UploadedFile::fake()->create('id.exe', 100, 'application/x-msdownload'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['national_id_file']);
    }

    /**
     * The requirement ships switched off where the client form has not landed
     * yet, so the server side can be deployed everywhere ahead of the frontend.
     */
    public function test_the_requirement_can_be_rolled_out_per_environment(): void
    {
        config(['dashboard.require_identity_file' => false]);
        $this->requestOtp();

        $this->post('/api/v1/auth/partner/register', $this->payload())->assertCreated();
    }

    public function test_a_supplied_file_is_validated_even_where_it_is_optional(): void
    {
        config(['dashboard.require_identity_file' => false]);
        $this->requestOtp();

        $this->post('/api/v1/auth/partner/register', $this->payload([
            'national_id_file' => UploadedFile::fake()->create('id.exe', 100, 'application/x-msdownload'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['national_id_file']);
    }

    public function test_a_company_does_not_need_an_identity_image(): void
    {
        $this->requestOtp('512345679');

        $this->post('/api/v1/auth/partner/register', $this->payload([
            'type'        => 'company',
            'phone'       => '512345679',
            'national_id' => null,
            'cr_number'   => '1010101010',
        ]))->assertCreated();
    }

    /**
     * Regression: `complete` gates COMPANY unit submission (UnitController §4).
     * The identity scan is an individual-only document, so folding it into that
     * computation would block every company from submitting a unit over a file
     * they are never asked for.
     */
    public function test_the_identity_scan_does_not_gate_company_payout_completeness(): void
    {
        $company = User::factory()->create(['is_active' => true]);
        $company->assignRole('Company');
        $company->partnerDetail()->create([
            'type'                      => 'company',
            'cr_number'                 => '1010101010',
            'iban'                      => 'SA'.str_repeat('3', 22),
            'authorization_letter_file' => 'file_auth',
            'vat_certificate_file'      => 'file_vat',
            'operator_license_file'     => 'file_op',
            'national_id_file'          => null,
        ]);

        $body = $this->actingAs($company, 'dashboard')
            ->getJson('/me/company-docs')->assertOk()->json();

        $this->assertTrue($body['complete'], 'a company with every company doc must read as complete');
        $this->assertNull($body['nationalIdFileId'], 'the field is still reported');
    }

    /**
     * The route a partner who registered BEFORE the identity scan existed has
     * to take: presign → upload → attach. A KYC document is usually
     * photographed, so the upload must accept an image, not only a PDF.
     */
    public function test_an_existing_partner_can_add_their_identity_scan_later(): void
    {
        $partner = User::factory()->create(['is_active' => true]);
        $partner->assignRole('Individual');
        $partner->partnerDetail()->create(['type' => 'individual', 'national_id' => '1012345678']);

        $presign = $this->actingAs($partner, 'dashboard')
            ->postJson('/uploads/presign', [
                'kind' => 'company_doc', 'fileName' => 'id.jpg',
                'mimeType' => 'image/jpeg', 'size' => 1024,
            ])->assertOk()->json();

        $fileId = $presign['fileId'];

        // A JPEG, not a PDF. Sent to the signed URL presign handed back.
        // Really encoded — the receiver decodes every image it accepts.
        $jpeg = \Tests\Support\ImageFactory::jpeg(600, 400);
        $this->call('PUT', $presign['uploadUrl'], [], [], [], [], $jpeg)->assertOk();

        $this->actingAs($partner, 'dashboard')
            ->putJson('/me/company-docs', ['nationalIdFileId' => $fileId])
            ->assertOk()->assertJsonPath('nationalIdFileId', $fileId);

        $upload = DashboardUpload::findOrFail($fileId);
        $this->assertStringEndsWith('.jpg', $upload->path, 'a photo must not be stored as .pdf');
        Storage::disk('public')->assertExists($upload->path);
    }

    public function test_admin_sees_the_identity_scan_on_the_document_row(): void
    {
        $this->requestOtp();
        $this->post('/api/v1/auth/partner/register', $this->payload([
            'national_id_file' => UploadedFile::fake()->image('id.jpg'),
        ]))->assertCreated();

        $partner = User::whereHas('partnerDetail')->firstOrFail();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('SuperAdmin');

        $docs = $this->actingAs($admin, 'admin-panel')
            ->getJson("/admin/partners/{$partner->id}")
            ->assertOk()->json('documents');

        $identity = collect($docs)->firstWhere('kind', 'national_id');

        $this->assertNotNull($identity, 'the identity row must be present');
        $this->assertNotNull($identity['fileUrl'], 'the admin must be able to open the scan');
        $this->assertSame('1012345678', $identity['value']);
    }
}
