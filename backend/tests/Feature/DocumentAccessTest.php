<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DashboardUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who may read a compliance document.
 *
 * A signature proves the link came from us. It says nothing about who is
 * holding it — and these are tourism permits, commercial registrations and
 * national ID scans, which until now sat on a public disk reachable forever by
 * anyone who had ever seen the address.
 *
 * So the tests that matter are the refusals: a valid signature in the wrong
 * hands, and a partner reaching for a document that is not theirs.
 */
class DocumentAccessTest extends TestCase
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

    /* ---------- who may look ---------- */

    public function test_the_owning_partner_may_read_their_own_document(): void
    {
        [$upload, $owner] = $this->document();

        $this->actingAs($owner, 'dashboard')
            ->get(DashboardUpload::signedUrl($upload->id))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            // Symfony normalises the directive order; the value is what matters.
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_a_reviewer_may_read_any_document(): void
    {
        [$upload] = $this->document();

        $this->actingAs($this->admin(), 'admin-panel')
            ->get(DashboardUpload::signedUrl($upload->id))
            ->assertOk();
    }

    /* ---------- and who may not ---------- */

    public function test_a_valid_signature_is_not_enough_on_its_own(): void
    {
        // The whole reason this route exists rather than reusing the attachment
        // pattern: anonymous, correctly signed, and still refused.
        [$upload] = $this->document();

        $this->get(DashboardUpload::signedUrl($upload->id))->assertForbidden();
    }

    public function test_a_partner_cannot_read_another_partners_document(): void
    {
        [$upload] = $this->document();

        $stranger = User::factory()->create();
        $stranger->assignRole('Individual');

        $this->actingAs($stranger, 'dashboard')
            ->get(DashboardUpload::signedUrl($upload->id))
            ->assertForbidden();
    }

    public function test_an_unsigned_link_is_refused_even_for_a_reviewer(): void
    {
        [$upload] = $this->document();

        $this->actingAs($this->admin(), 'admin-panel')
            ->get("/documents/{$upload->id}")
            ->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        [$upload, $owner] = $this->document();

        $url = URL::temporarySignedRoute('documents.show', now()->subMinute(), ['upload' => $upload->id]);

        $this->actingAs($owner, 'dashboard')->get($url)->assertForbidden();
    }

    /* ---------- both disks, because the route ships before the files move ---------- */

    public function test_it_serves_a_document_still_on_the_public_disk(): void
    {
        [$upload, $owner] = $this->document(onVault: false);

        $this->actingAs($owner, 'dashboard')
            ->get(DashboardUpload::signedUrl($upload->id))
            ->assertOk();
    }

    public function test_it_serves_a_document_already_moved_to_the_vault(): void
    {
        [$upload, $owner] = $this->document(onVault: true);

        // And the public copy is gone, so this can only have come from the vault.
        Storage::disk('public')->assertMissing($upload->path);

        $this->actingAs($owner, 'dashboard')
            ->get(DashboardUpload::signedUrl($upload->id))
            ->assertOk();
    }

    public function test_a_row_whose_file_is_on_neither_disk_is_a_404(): void
    {
        [$upload, $owner] = $this->document();
        Storage::disk('public')->delete($upload->path);

        $this->actingAs($owner, 'dashboard')
            ->get(DashboardUpload::signedUrl($upload->id))
            ->assertNotFound();
    }

    /* ---------- the helper ---------- */

    public function test_the_url_expires_on_the_configured_window(): void
    {
        config()->set('documents.link_minutes', 120);
        [$upload] = $this->document();

        parse_str((string) parse_url(DashboardUpload::signedUrl($upload->id), PHP_URL_QUERY), $q);

        $this->assertEqualsWithDelta(now()->addMinutes(120)->timestamp, (int) $q['expires'], 5);
    }

    public function test_a_raw_path_falls_back_to_the_public_url(): void
    {
        // Columns hold either an upload id or a raw path. A raw path has no row
        // to authorise against, so it keeps the old URL until the migration
        // gives it one — rather than producing a signed link to nothing.
        $url = DashboardUpload::signedUrl('dashboard/license_pdf/legacy.pdf');

        $this->assertStringContainsString('/storage/dashboard/license_pdf/legacy.pdf', (string) $url);
        $this->assertStringNotContainsString('signature=', (string) $url);
    }

    public function test_a_blank_value_returns_null(): void
    {
        $this->assertNull(DashboardUpload::signedUrl(null));
        $this->assertNull(DashboardUpload::signedUrl(''));
    }

    /* ---------- fixtures ---------- */

    /** @return array{0: DashboardUpload, 1: User} */
    private function document(bool $onVault = false): array
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');

        $path = 'dashboard/license_pdf/file_'.fake()->unique()->numerify('########').'.pdf';

        $upload = DashboardUpload::create([
            'id' => 'file_'.fake()->unique()->numerify('##########'),
            'user_id' => $owner->id,
            'kind' => 'license_pdf',
            'original_name' => 'permit.pdf',
            'mime' => 'application/pdf',
            'size' => 12,
            'path' => $path,
            'status' => 'stored',
        ]);

        if ($onVault) {
            Storage::disk('local')->put('secured-documents/'.$path, 'PERMIT');
        } else {
            Storage::disk('public')->put($path, 'PERMIT');
        }

        return [$upload, $owner];
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('SuperAdmin');

        return $admin;
    }
}
