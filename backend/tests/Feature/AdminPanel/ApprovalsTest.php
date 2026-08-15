<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\UnitReviewResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Approvals — unit review queue (BACKEND_SPEC §5.7). Covers the SLA-ordered
 * queue, stats, the detail payload that embeds a full UnitDetail, and the
 * approve/reject transitions (incl. the pending-only 409 guard).
 */
class ApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $partner;
    private Unit $newUnit;
    private Unit $resubmitted;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'SuperAdmin', 'Individual', 'Company', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        // Admin created while default guard is still 'web' (Spatie guard gotcha).
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('SuperAdmin');

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
        $this->partner->partnerDetail()->create([
            'type'        => 'individual',
            'status'      => PartnerDetail::STATUS_APPROVED,
            'verified_at' => now(),           // verified badge (independent of KYC status)
            'national_id' => '1098765432',
            'iban'        => 'SA0380000000608010167519',
            'reviewed_at' => now(),
        ]);

        // Older submission → must sort first (oldest submittedAt first).
        $this->resubmitted = $this->makeUnit('resubmission', [
            'rejection_reason' => 'الصور غير واضحة',
            'updated_at'       => now()->subDays(3),
        ]);
        $this->newUnit = $this->makeUnit('new', [
            'updated_at' => now()->subDay(),
        ]);
    }

    private function makeUnit(string $tag, array $overrides = []): Unit
    {
        return $this->partner->units()->create(array_merge([
            'unit_name'       => "وحدة {$tag}",
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => 350,
            'capacity'        => 4,
            'bedrooms'        => 2,
            'beds'            => 3,
            'bathrooms'       => 1,
            'area'            => 90,
            'city'            => 'جدة',
            'district'        => 'الشاطئ',
            'lat'             => 21.54,
            'lng'             => 39.17,
            'approval_status' => 'pending',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ], $overrides));
    }

    private function admin(): User
    {
        return $this->adminUser;
    }

    public function test_queue_lists_pending_units_oldest_first(): void
    {
        $res = $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/approvals')
            ->assertOk()
            ->assertJsonStructure([
                'items' => [['id', 'code', 'unitId', 'unitName', 'unitType', 'city', 'partnerId', 'partnerName', 'partnerType', 'submittedAt', 'requestType', 'previousRejection']],
                'total', 'page', 'pageSize',
            ])
            ->assertJsonPath('total', 2)
            ->assertJsonPath('pageSize', 10);

        // Oldest (the resubmission) must come first.
        $res->assertJsonPath('items.0.id', (string) $this->resubmitted->id)
            ->assertJsonPath('items.0.requestType', 'resubmission')
            ->assertJsonPath('items.0.previousRejection.reason', 'الصور غير واضحة')
            ->assertJsonPath('items.1.requestType', 'new')
            ->assertJsonPath('items.1.previousRejection', null);
    }

    public function test_queue_filters_by_request_type(): void
    {
        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/approvals?requestType=new')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', (string) $this->newUnit->id);

        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/approvals?requestType=resubmission')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', (string) $this->resubmitted->id);
    }

    public function test_stats_counts_pending_and_review_time(): void
    {
        // One historically approved unit → avgReviewHours becomes measurable.
        $this->makeUnit('done', [
            'approval_status' => 'approved',
            'created_at'      => now()->subDays(2),
            'updated_at'      => now()->subDays(2)->addHours(10),
        ]);

        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/approvals/stats')
            ->assertOk()
            ->assertJsonStructure(['pendingReview', 'approvedToday', 'rejectedToday', 'avgReviewHours'])
            ->assertJsonPath('pendingReview', 2);
    }

    public function test_detail_embeds_full_unit_detail(): void
    {
        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/approvals/'.$this->newUnit->id)
            ->assertOk()
            ->assertJsonStructure([
                'id', 'code', 'unitId', 'requestType',
                'unit' => ['description', 'images', 'amenities', 'lat', 'lng', 'publicUrl', 'tourismPermitNo', 'ownerIdNumber'],
                'partnerVerified', 'partnerRating',
            ])
            ->assertJsonPath('unit.id', (string) $this->newUnit->id)
            ->assertJsonPath('partnerVerified', true);
    }

    public function test_approve_publishes_unit_and_notifies_owner(): void
    {
        Notification::fake();

        $this->actingAs($this->admin(), 'admin-panel')->postJson('/admin/approvals/'.$this->newUnit->id.'/approve')
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertSame('approved', $this->newUnit->fresh()->approval_status);
        Notification::assertSentTo($this->partner, UnitReviewResult::class);
    }

    public function test_reject_requires_reason_and_stores_it(): void
    {
        Notification::fake();

        // Missing reason → flat 422 VALIDATION_ERROR envelope.
        $this->actingAs($this->admin(), 'admin-panel')->postJson('/admin/approvals/'.$this->newUnit->id.'/reject', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->actingAs($this->admin(), 'admin-panel')->postJson('/admin/approvals/'.$this->newUnit->id.'/reject', [
            'reason' => 'العنوان غير مطابق للصك',
        ])->assertOk()->assertExactJson(['ok' => true]);

        $fresh = $this->newUnit->fresh();
        $this->assertSame('rejected', $fresh->approval_status);
        $this->assertSame('العنوان غير مطابق للصك', $fresh->rejection_reason);
        Notification::assertSentTo($this->partner, UnitReviewResult::class);
    }

    public function test_approving_non_pending_unit_conflicts(): void
    {
        $this->newUnit->update(['approval_status' => 'approved']);

        $this->actingAs($this->admin(), 'admin-panel')->postJson('/admin/approvals/'.$this->newUnit->id.'/approve')
            ->assertStatus(409)
            ->assertJsonPath('code', 'CONFLICT');
    }

    /* ---- range on stats (frontend request 2026-08-15) ---- */

    public function test_stats_defaults_to_today_and_echoes_the_range(): void
    {
        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/approvals/stats')
            ->assertOk()
            ->assertJsonStructure(['pendingReview', 'approved', 'rejected', 'avgReviewHours', 'range'])
            ->assertJsonPath('range', 'today');
    }

    public function test_stats_accepts_the_three_ranges_and_echoes_each(): void
    {
        $admin = $this->admin();

        foreach (['today', '7d', '30d'] as $range) {
            $this->actingAs($admin, 'admin-panel')->getJson("/admin/approvals/stats?range={$range}")
                ->assertOk()->assertJsonPath('range', $range);
        }
    }

    public function test_unknown_range_falls_back_to_today(): void
    {
        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/approvals/stats?range=all-time')
            ->assertOk()->assertJsonPath('range', 'today');
    }

    public function test_decision_counters_are_scoped_by_range_but_pending_is_not(): void
    {
        $admin = $this->admin();

        // Decided 10 days ago: inside 30d, outside 7d and today.
        $old = $this->makeUnit('old-decision', ['approval_status' => 'approved']);
        $old->forceFill(['updated_at' => now()->subDays(10)])->saveQuietly();

        // Still awaiting review — must be counted regardless of range.
        $this->makeUnit('pending');

        $today = $this->actingAs($admin, 'admin-panel')->getJson('/admin/approvals/stats?range=today')->json();
        $d30   = $this->actingAs($admin, 'admin-panel')->getJson('/admin/approvals/stats?range=30d')->json();

        $this->assertSame(0, $today['approved'], 'a 10-day-old decision must not count as today');
        $this->assertSame(1, $d30['approved'], 'a 10-day-old decision must count in 30d');

        // Queue depth answers "what is on my desk now" — identical in both.
        $this->assertSame($today['pendingReview'], $d30['pendingReview']);
        $this->assertGreaterThanOrEqual(1, $today['pendingReview']);
    }

    public function test_list_rows_carry_the_units_own_cover_image(): void
    {
        $unit = $this->makeUnit('with-photo');
        $unit->images()->create(['path' => 'units/real-photo.jpg', 'is_main' => true]);

        // The queue is ordered oldest-submission-first, so find our own row.
        $items = $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/approvals')
            ->assertOk()->json('items');
        $row = collect($items)->firstWhere('unitId', (string) $unit->id);

        $this->assertNotNull($row, 'the pending unit must be in the queue');
        $this->assertStringContainsString('units/real-photo.jpg', $row['coverImage']);
    }

    /**
     * The detail page gates Approve behind a "photos reviewed" step. A padded
     * placeholder let a reviewer tick it on a listing that has no photos.
     */
    public function test_a_unit_with_no_photos_reports_an_empty_image_array(): void
    {
        $unit = $this->makeUnit('no-photos');

        $detail = $this->actingAs($this->admin(), 'admin-panel')
            ->getJson("/admin/approvals/{$unit->id}")->assertOk()->json('unit.images');

        $this->assertSame([], $detail, 'absence must not be padded with a default image');
    }

    public function test_a_units_own_photos_are_listed(): void
    {
        $unit = $this->makeUnit('with-photos');
        $unit->images()->create(['path' => 'units/a.jpg', 'is_main' => true]);
        $unit->images()->create(['path' => 'units/b.jpg']);

        $images = $this->actingAs($this->admin(), 'admin-panel')
            ->getJson("/admin/approvals/{$unit->id}")->assertOk()->json('unit.images');

        $this->assertCount(2, $images);
    }

    /**
     * "This listing has no photos" is review-relevant, so absence must reach the
     * reviewer as absence — a shared default would make an empty listing look
     * photographed.
     */
    public function test_a_unit_with_no_photo_reports_a_null_cover_image(): void
    {
        $unit = $this->makeUnit('no-photo');

        $items = $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/approvals')
            ->assertOk()->json('items');
        $row = collect($items)->firstWhere('unitId', (string) $unit->id);

        $this->assertNotNull($row, 'the pending unit must be in the queue');
        $this->assertArrayHasKey('coverImage', $row, 'the key must still be sent');
        $this->assertNull($row['coverImage']);
    }

    /* ---- submitted_at (review-SLA basis) ---- */

    public function test_submitted_at_is_stamped_when_a_unit_enters_review(): void
    {
        $unit = $this->makeUnit('fresh', ['approval_status' => 'draft']);
        $this->assertNull($unit->submitted_at, 'a draft has not been submitted');

        $unit->update(['approval_status' => 'pending']);

        $this->assertNotNull($unit->fresh()->submitted_at, 'entering review must stamp submitted_at');
    }

    public function test_submitted_at_is_restamped_on_resubmission(): void
    {
        $unit = $this->makeUnit('resub', ['approval_status' => 'pending']);
        $first = $unit->fresh()->submitted_at;

        // rejected, then submitted again
        $unit->update(['approval_status' => 'rejected', 'rejection_reason' => 'صور غير واضحة']);
        $this->travel(2)->hours();
        $unit->update(['approval_status' => 'pending']);

        $this->assertTrue(
            $unit->fresh()->submitted_at->greaterThan($first),
            'a resubmission restarts the review clock'
        );
    }

    public function test_avg_review_hours_measures_submission_to_decision_not_draft_time(): void
    {
        // Created 10 days ago, submitted 2 hours ago, decided now.
        // Measured from created_at this would read ~240h; from submitted_at, ~2h.
        $unit = $this->makeUnit('slow-draft', ['approval_status' => 'draft']);
        $unit->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        $unit->update(['approval_status' => 'pending']);
        $unit->forceFill(['submitted_at' => now()->subHours(2)])->saveQuietly();
        $unit->update(['approval_status' => 'approved']);

        $avg = $this->actingAs($this->admin(), 'admin-panel')
            ->getJson('/admin/approvals/stats?range=30d')->json('avgReviewHours');

        $this->assertLessThan(24, $avg, "draft time must not count as review time (got {$avg}h)");
        $this->assertGreaterThan(1, $avg);
    }

    /**
     * 0 reads as "reviews are instant" — the same false signal the whole
     * submitted_at change exists to remove. No measurable sample must be
     * distinguishable from a genuinely fast one.
     */
    public function test_avg_review_hours_is_null_when_nothing_is_measurable(): void
    {
        // Decided, but pre-migration: no submission time to measure from.
        $unit = $this->makeUnit('legacy', ['approval_status' => 'approved']);
        $unit->forceFill(['submitted_at' => null])->saveQuietly();

        $avg = $this->actingAs($this->admin(), 'admin-panel')
            ->getJson('/admin/approvals/stats?range=30d')->json('avgReviewHours');

        $this->assertNull($avg, 'no sample must report as null, not 0');
    }
}
