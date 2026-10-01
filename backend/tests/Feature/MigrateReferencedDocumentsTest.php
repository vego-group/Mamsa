<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DashboardUpload;
use App\Models\Unit;
use App\Models\User;
use App\Support\Documents\DocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Step three: the documents people can still reach come off the public disk.
 *
 * The property that matters is completeness in both directions — every
 * referenced document moves, and nothing unreferenced or non-document does.
 * The migration is driven from the database precisely because a directory list
 * once missed every file under units/{id}/docs/.
 */
class MigrateReferencedDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Individual', 'SuperAdmin'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_a_referenced_public_document_moves_to_the_vault_and_stays_readable(): void
    {
        [$upload, $unit] = $this->referenced('dashboard/license_pdf/file_a.pdf');

        $this->artisan('documents:migrate-referenced')->assertExitCode(0);

        Storage::disk('public')->assertMissing($upload->path);
        Storage::disk('local')->assertExists(DocumentStorage::vaultPath($upload->path));

        // The reader still finds it — that is what "moved" has to mean here.
        [$disk, $resolved] = DocumentStorage::locate($upload->path);
        $this->assertNotNull($disk);
        $this->assertSame('PERMIT', $disk->get($resolved));
    }

    public function test_a_document_referenced_by_an_odd_path_is_still_found(): void
    {
        // The directory-driven sweep's blind spot: a path outside dashboard/*.
        // Database-driven means the path shape does not matter.
        [$upload] = $this->referenced('units/28/docs/permit.pdf');

        $this->artisan('documents:migrate-referenced')->assertExitCode(0);

        Storage::disk('public')->assertMissing('units/28/docs/permit.pdf');
        Storage::disk('local')->assertExists(DocumentStorage::vaultPath('units/28/docs/permit.pdf'));
    }

    public function test_unit_photos_and_unreferenced_files_are_left_alone(): void
    {
        Storage::disk('public')->put('dashboard/unit_photo/file_pic.jpg', 'PIC');
        Storage::disk('public')->put('dashboard/license_pdf/file_orphan.pdf', 'ORPHAN');

        $this->artisan('documents:migrate-referenced')->assertExitCode(0);

        Storage::disk('public')->assertExists('dashboard/unit_photo/file_pic.jpg');
        // Orphans are the other command's job; this one moves what is REFERENCED.
        Storage::disk('public')->assertExists('dashboard/license_pdf/file_orphan.pdf');
    }

    public function test_it_refuses_to_run_while_a_bare_path_reference_exists(): void
    {
        // A bare path cannot be signed. Moving its bytes would take the document
        // off the public disk with nothing able to serve it.
        $owner = $this->partner();
        $this->unitFor($owner, ['tourism_permit_file' => 'units/9/docs/raw.pdf']);
        Storage::disk('public')->put('units/9/docs/raw.pdf', 'RAW');

        $this->artisan('documents:migrate-referenced')->assertExitCode(1);

        // nothing may move past the gate
        Storage::disk('public')->assertExists('units/9/docs/raw.pdf');
    }

    public function test_a_document_already_in_the_vault_is_skipped_not_duplicated(): void
    {
        // Written by step two, after the flip.
        $owner = $this->partner();
        $upload = $this->upload($owner, 'dashboard/license_pdf/file_new.pdf');
        Storage::disk('local')->put(DocumentStorage::vaultPath($upload->path), 'NEW');
        $this->unitFor($owner, ['tourism_permit_file' => $upload->id]);

        $this->artisan('documents:migrate-referenced')->assertExitCode(0);

        Storage::disk('public')->assertMissing($upload->path);
        Storage::disk('local')->assertExists(DocumentStorage::vaultPath($upload->path));
    }

    public function test_a_dry_run_moves_nothing(): void
    {
        [$upload] = $this->referenced('dashboard/license_pdf/file_dry.pdf');

        $this->artisan('documents:migrate-referenced --dry-run')->assertExitCode(0);

        Storage::disk('public')->assertExists($upload->path);
        Storage::disk('local')->assertMissing(DocumentStorage::vaultPath($upload->path));
    }

    /* ---------- fixtures ---------- */

    /** @return array{0: DashboardUpload, 1: Unit} */
    private function referenced(string $path): array
    {
        $owner = $this->partner();
        $upload = $this->upload($owner, $path);
        Storage::disk('public')->put($path, 'PERMIT');
        $unit = $this->unitFor($owner, ['tourism_permit_file' => $upload->id]);

        return [$upload, $unit];
    }

    private function upload(User $owner, string $path): DashboardUpload
    {
        return DashboardUpload::create([
            'id' => 'file_'.fake()->unique()->numerify('##########'), 'user_id' => $owner->id,
            'kind' => 'license_pdf', 'original_name' => basename($path), 'mime' => 'application/pdf',
            'size' => 6, 'path' => $path, 'status' => 'stored',
        ]);
    }

    private function partner(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Individual');

        return $u;
    }

    /** @param array<string, mixed> $extra */
    private function unitFor(User $owner, array $extra): Unit
    {
        return $owner->units()->create(array_merge([
            'unit_name' => 'وحدة', 'unit_type' => 'apartment', 'code' => 'MIG'.fake()->unique()->numerify('#####'),
            'price' => 400, 'capacity' => 2, 'bedrooms' => 1, 'city' => 'الرياض',
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
        ], $extra));
    }
}
