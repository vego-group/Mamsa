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

    public function test_list_rows_carry_a_cover_image(): void
    {
        $this->makeUnit('pending');

        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/approvals')
            ->assertOk()
            ->assertJsonStructure(['items' => [['id', 'code', 'unitName', 'coverImage']]]);
    }
}
