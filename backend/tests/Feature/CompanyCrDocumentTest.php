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

/**
 * The commercial-registration DOCUMENT.
 *
 * `commercial_registration` rendered with `fileUrl: null` since it was written,
 * so an admin approving a company was approving a ten-digit number somebody
 * typed — while the individual path has had a number AND a scan for a while.
 */
class CompanyCrDocumentTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function company(): User
    {
        $u = User::factory()->create(['is_active' => true, 'name' => 'شركة الأفق']);
        $u->assignRole('Company');
        $u->partnerDetail()->create([
            'type' => 'company', 'cr_number' => '1010101010',
            'status' => PartnerDetail::STATUS_APPROVED,
        ]);

        return $u;
    }

    /** OTPs live in the cache, not a table — seed one the way OtpService reads it. */
    private function seedOtp(string $e164, string $code = '123456'): void
    {
        cache()->put("otp:login:{$e164}", [
            'code' => $code, 'attempts' => 0, 'created_at' => now()->timestamp,
        ], 300);
    }

    private function documents(User $partner): array
    {
        return $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/partners/'.$partner->id)->assertOk()->json('documents');
    }

    /* ---- at registration ---- */

    public function test_a_company_registers_with_its_cr_scan(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $phone = '512345678';
        $this->seedOtp('+966512345678');

        $response = $this->postJson('/api/v1/auth/partner/register', [
            'type'      => 'company',
            'name'      => 'شركة الأفق',
            'phone'     => $phone,
            'code'      => '123456',
            'email'     => 'ops@alofuq.test',
            'cr_number' => '1010101010',
            'cr_file'   => UploadedFile::fake()->image('cr.jpg'),
        ]);

        $response->assertSuccessful();

        $detail = User::where('phone', '+966512345678')->first()->partnerDetail;

        $this->assertNotNull($detail->cr_file, 'the CR scan must be stored at registration');
        $this->assertStringStartsWith('file_', $detail->cr_file);

        // Stored as a DashboardUpload so the admin's existing resolution and
        // verify/reject flow pick it up with no special-casing.
        $upload = DashboardUpload::find($detail->cr_file);
        $this->assertNotNull($upload);
        $this->assertSame('company_doc', $upload->kind);
        Storage::disk('public')->assertExists($upload->path);
    }

    /** A CR is usually photographed; a PDF scan must work too. */
    public function test_a_pdf_cr_is_accepted_at_registration(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->seedOtp('+966512345679');

        $this->postJson('/api/v1/auth/partner/register', [
            'type' => 'company', 'name' => 'شركة ب', 'phone' => '512345679',
            'code' => '123456', 'email' => 'b@test.test', 'cr_number' => '2020202020',
            'cr_file' => UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();

        $this->assertNotNull(
            User::where('phone', '+966512345679')->first()->partnerDetail->cr_file,
        );
    }

    public function test_an_unsupported_cr_file_type_is_rejected(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->seedOtp('+966512345670');

        $this->postJson('/api/v1/auth/partner/register', [
            'type' => 'company', 'name' => 'شركة ج', 'phone' => '512345670',
            'code' => '123456', 'email' => 'c@test.test', 'cr_number' => '3030303030',
            'cr_file' => UploadedFile::fake()->create('cr.exe', 10),
        ])->assertStatus(422)->assertJsonValidationErrors('cr_file');
    }

    /* ---- through the partner dashboard ---- */

    public function test_a_company_attaches_its_cr_from_the_dashboard(): void
    {
        $company = $this->company();

        $presign = $this->actingAs($company, 'dashboard')->postJson('/uploads/presign', [
            'kind' => 'company_doc', 'fileName' => 'cr.pdf',
            'mimeType' => 'application/pdf', 'size' => 100,
        ])->assertOk()->json();

        $this->call('PUT', $presign['uploadUrl'], [], [], [], [], '%PDF-1.4'.str_repeat('x', 64))
            ->assertOk();

        $docs = $this->actingAs($company, 'dashboard')
            ->putJson('/me/company-docs', ['crFileId' => $presign['fileId']])
            ->assertOk()->json();

        $this->assertSame($presign['fileId'], $docs['crFileId']);
        $this->assertNotNull($docs['crUrl']);
    }

    public function test_a_file_belonging_to_another_partner_is_refused(): void
    {
        $company = $this->company();
        $other   = $this->company();

        $presign = $this->actingAs($other, 'dashboard')->postJson('/uploads/presign', [
            'kind' => 'company_doc', 'fileName' => 'cr.pdf',
            'mimeType' => 'application/pdf', 'size' => 100,
        ])->assertOk()->json();

        $this->call('PUT', $presign['uploadUrl'], [], [], [], [], '%PDF-1.4'.str_repeat('x', 64));

        $this->actingAs($company, 'dashboard')
            ->putJson('/me/company-docs', ['crFileId' => $presign['fileId']])
            ->assertStatus(400)
            ->assertJsonPath('error.fields.crFileId', 'ملف غير موجود');
    }

    /* ---- the admin review surface ---- */

    public function test_the_cr_row_carries_its_document_and_its_number(): void
    {
        $company = $this->company();
        $company->partnerDetail->update(['cr_file' => 'file_cr_scan']);

        DashboardUpload::create([
            'id' => 'file_cr_scan', 'user_id' => $company->id, 'kind' => 'company_doc',
            'original_name' => 'cr.pdf', 'mime' => 'application/pdf', 'size' => 100,
            'path' => 'dashboard/commercial_registration/file_cr_scan.pdf', 'status' => 'stored',
        ]);

        $cr = collect($this->documents($company))->firstWhere('kind', 'commercial_registration');

        $this->assertNotNull($cr['fileUrl'], 'the reviewer needs something to open');
        $this->assertSame('1010101010', $cr['value'], 'the typed number is still shown beside it');
    }

    /**
     * The sequencing the admin panel asked for: shipping the column must not
     * flip every existing company to incomplete, because none of them has
     * uploaded one and an unclearable finding is one reviewers scroll past.
     */
    public function test_shipping_the_column_does_not_flip_existing_companies_to_incomplete(): void
    {
        $company = $this->company();
        $company->partnerDetail->update([
            'iban'                      => 'SA2480000000000000000000',
            'vat_certificate_file'      => 'file_vat',
            'operator_license_file'     => 'file_licence',
            'authorization_letter_file' => 'file_auth',
            'cr_file'                   => null,          // never uploaded
        ]);

        $this->assertTrue(
            $this->actingAs($this->admin, 'admin-panel')
                ->getJson('/admin/partners/'.$company->id)->assertOk()->json('documentsComplete'),
            'the CR row expects its VALUE only until partners can supply the scan',
        );
    }
}
