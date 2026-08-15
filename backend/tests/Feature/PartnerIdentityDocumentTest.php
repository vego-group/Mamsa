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
