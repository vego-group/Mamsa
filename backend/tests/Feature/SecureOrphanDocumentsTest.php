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
 * The command runs against production data, so its safety property is the
 * whole test: it must never move a file something still points at.
 *
 * A false positive here does not corrupt anything, but it does take a live
 * permit off the screen an admin reviews approvals on — and the first person
 * to notice would be a reviewer looking at a blank frame.
 */
class SecureOrphanDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_it_moves_an_unreferenced_document_and_keeps_the_bytes(): void
    {
        Storage::disk('public')->put('dashboard/license_pdf/file_orphan.pdf', 'ORPHAN-BYTES');

        $this->artisan('documents:secure-orphans')->assertExitCode(0);

        Storage::disk('public')->assertMissing('dashboard/license_pdf/file_orphan.pdf');
        Storage::disk('local')->assertExists('secured-documents/dashboard/license_pdf/file_orphan.pdf');

        // Moved, not deleted — a superseded permit may still be the evidence
        // for an approval already granted.
        $this->assertSame(
            'ORPHAN-BYTES',
            Storage::disk('local')->get('secured-documents/dashboard/license_pdf/file_orphan.pdf'),
        );
    }

    public function test_it_never_moves_a_document_a_unit_points_at_by_path(): void
    {
        Storage::disk('public')->put('dashboard/license_pdf/file_live.pdf', 'LIVE');

        $this->unit(['tourism_permit_file' => 'dashboard/license_pdf/file_live.pdf']);

        $this->artisan('documents:secure-orphans')->assertExitCode(0);

        Storage::disk('public')->assertExists('dashboard/license_pdf/file_live.pdf');
        Storage::disk('local')->assertMissing('secured-documents/dashboard/license_pdf/file_live.pdf');
    }

    public function test_it_never_moves_a_document_referenced_through_an_upload_id(): void
    {
        // The columns hold either a raw path or a `file_…` id depending on
        // which surface wrote them. Resolving only one of the two would move a
        // live permit.
        Storage::disk('public')->put('dashboard/license_pdf/file_byid.pdf', 'LIVE-BY-ID');

        DashboardUpload::create([
            'id' => 'file_byid', 'user_id' => $this->partner()->id, 'kind' => 'license_pdf',
            'original_name' => 'p.pdf', 'mime' => 'application/pdf', 'size' => 10,
            'path' => 'dashboard/license_pdf/file_byid.pdf', 'status' => 'stored',
        ]);

        $this->unit(['tourism_permit_file' => 'file_byid']);

        $this->artisan('documents:secure-orphans')->assertExitCode(0);

        Storage::disk('public')->assertExists('dashboard/license_pdf/file_byid.pdf');
    }

    public function test_it_never_moves_a_national_id_a_partner_still_points_at(): void
    {
        Storage::disk('public')->put('dashboard/national_id/file_nid.png', 'ID');

        $partner = $this->partner();
        $partner->partnerDetail()->create([
            'type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED,
            'national_id_file' => 'dashboard/national_id/file_nid.png',
        ]);

        $this->artisan('documents:secure-orphans')->assertExitCode(0);

        Storage::disk('public')->assertExists('dashboard/national_id/file_nid.png');
    }

    public function test_it_leaves_unit_photos_alone(): void
    {
        // Public by nature. Routing them through PHP would put every listing
        // image on the app server.
        Storage::disk('public')->put('dashboard/unit_photo/file_pic.jpg', 'PIC');

        $this->artisan('documents:secure-orphans')->assertExitCode(0);

        Storage::disk('public')->assertExists('dashboard/unit_photo/file_pic.jpg');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        Storage::disk('public')->put('dashboard/license_pdf/file_orphan.pdf', 'ORPHAN');

        $this->artisan('documents:secure-orphans --dry-run')->assertExitCode(0);

        Storage::disk('public')->assertExists('dashboard/license_pdf/file_orphan.pdf');
        Storage::disk('local')->assertMissing('secured-documents/dashboard/license_pdf/file_orphan.pdf');
    }

    /* ---------- fixtures ---------- */

    private function partner(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Individual');

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function unit(array $attributes): Unit
    {
        return $this->partner()->units()->create(array_merge([
            'unit_name' => 'وحدة', 'unit_type' => 'apartment',
            'code' => 'DOC'.fake()->unique()->numerify('#####'),
            'price' => 400, 'capacity' => 2, 'bedrooms' => 1,
            'city' => 'الرياض', 'checkout_time' => '12:00',
            'calendar_token' => str()->random(60),
        ], $attributes));
    }
}
