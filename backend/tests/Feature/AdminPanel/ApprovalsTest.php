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
}
