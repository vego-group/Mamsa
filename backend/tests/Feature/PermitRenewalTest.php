<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permit;
use App\Models\PermitReminder;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\PermitExpiring;
use App\Support\Permits\PermitExpiry;
use App\Support\Permits\PermitRenewal;
use App\Support\Permits\PermitWriter;
use App\Support\Units\UnitCloner;
use App\Support\Units\UnitLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3: warning before the date, and renewing without going dark.
 *
 * The obvious implementation of a renewal — overwrite the permit, send the
 * listing back for review — makes the partner choose between filing early and
 * losing the listing to the queue, or filing late and losing it to the expiry.
 * So a renewal is a second permit row beside the one in force, and the listing
 * never stops selling on account of it.
 */
class PermitRenewalTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        config()->set('units.multi_unit_enabled', true);

        $this->partner = User::factory()->create(['is_active' => true, 'email' => 'partner@example.test']);
        $this->partner->assignRole('Individual');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('SuperAdmin');
    }

    /* ---------- reminders ---------- */

    public function test_a_reminder_goes_out_once_per_threshold_however_often_the_job_runs(): void
    {
        Notification::fake();
        $unit = $this->unit(expires: now()->addDays(30)->toDateString());

        $this->artisan('permits:check-expiry')->assertSuccessful();
        $this->artisan('permits:check-expiry')->assertSuccessful();
        $this->artisan('permits:check-expiry')->assertSuccessful();

        Notification::assertSentToTimes($this->partner, PermitExpiring::class, 1);
        $this->assertSame(1, PermitReminder::count());
        $this->assertSame(30, PermitReminder::first()->threshold);
    }

    public function test_every_threshold_is_its_own_reminder(): void
    {
        Notification::fake();

        foreach (PermitReminder::THRESHOLDS as $days) {
            $this->unit(expires: now()->addDays($days)->toDateString());
        }

        $this->artisan('permits:check-expiry')->assertSuccessful();

        $this->assertSame(
            PermitReminder::THRESHOLDS,
            PermitReminder::orderByDesc('threshold')->pluck('threshold')->all(),
        );
        Notification::assertSentToTimes($this->partner, PermitExpiring::class, count(PermitReminder::THRESHOLDS));
    }

    public function test_a_permit_that_is_not_at_a_threshold_is_left_alone(): void
    {
        Notification::fake();
        $this->unit(expires: now()->addDays(45)->toDateString());

        $this->artisan('permits:check-expiry')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(0, PermitReminder::count());
    }

    public function test_a_filed_renewal_stops_the_reminders(): void
    {
        // The partner has already done the thing the reminder would ask for.
        Notification::fake();
        $unit = $this->unit(expires: now()->addDays(14)->toDateString());
        PermitRenewal::open($unit, ['expires_at' => now()->addYear()->toDateString()], $this->partner->id);

        $this->artisan('permits:check-expiry')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(0, PermitReminder::count(), 'a skipped reminder must not be recorded as sent');
    }

    public function test_the_last_three_also_go_by_sms_and_the_earlier_ones_do_not(): void
    {
        // A text message is an interruption; spending it sixty days out is how
        // partners learn to ignore them.
        $this->assertSame([7, 1, 0], PermitReminder::URGENT);

        $unit = $this->unit(expires: now()->addDays(7)->toDateString());
        $permit = Permit::currentFor($unit);

        $early = new PermitExpiring($permit, 60, 'وحدة');
        $late = new PermitExpiring($permit, 7, 'وحدة');

        $this->assertSame(['database', 'mail'], $early->via($this->partner));
        $this->assertContains(SmsChannel::class, $late->via($this->partner));
    }

    public function test_a_partner_without_an_email_still_hears_in_the_app(): void
    {
        // One partner in three on production registered by phone alone.
        $silent = User::factory()->create(['is_active' => true, 'email' => null]);
        $silent->assignRole('Individual');
        $unit = $this->unit(expires: now()->addDays(30)->toDateString(), owner: $silent);

        $permit = Permit::currentFor($unit);
        $channels = (new PermitExpiring($permit, 30, 'وحدة'))->via($silent);

        $this->assertContains('database', $channels);
        $this->assertNotContains('mail', $channels);
    }

    public function test_a_platform_listing_warns_the_admins_not_the_platform_account(): void
    {
        Notification::fake();
        $unit = $this->unit(expires: now()->addDays(30)->toDateString(), owner: User::platform(), extra: ['mamsa_owned' => true]);

        $this->artisan('permits:check-expiry')->assertSuccessful();

        Notification::assertSentTo($this->admin, PermitExpiring::class);
        Notification::assertNotSentTo(User::platform(), PermitExpiring::class);
    }

    public function test_the_dry_run_tells_nobody_and_records_nothing(): void
    {
        Notification::fake();
        $this->unit(expires: now()->addDays(30)->toDateString());

        $this->artisan('permits:check-expiry --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(0, PermitReminder::count());
    }

    /* ---------- filing a renewal ---------- */

    public function test_the_listing_keeps_selling_while_a_renewal_waits(): void
    {
        // The whole point: filing early must not cost the partner their listing.
        $unit = $this->unit(expires: now()->addDays(20)->toDateString());

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$unit->id}/permit-renewals", [
                'permitExpiresAt' => now()->addYear()->toDateString(),
                'tourismLicenseNumber' => ' ٩٩٩ ',
            ])
            ->assertStatus(201)
            ->assertJsonPath('status', Permit::STATUS_PENDING)
            ->assertJsonPath('tourismLicenseNumber', '999');

        $unit->refresh();
        $this->assertSame('approved', $unit->approval_status, 'a renewal sent the listing back for review');
        $this->assertSame(now()->addDays(20)->toDateString(), PermitExpiry::on($unit)?->toDateString(), 'the cap moved before the renewal was approved');
        $this->assertSame(2, Permit::count());

        // And it is still on the storefront, on the old permit.
        $this->getJson("/api/v1/units/{$unit->id}")->assertOk();
    }

    public function test_a_renewal_inherits_what_it_does_not_name(): void
    {
        // Replacing only the document and the date must not require retyping
        // the number, and a blank field must not read as "clear it".
        $unit = $this->unit(expires: now()->addDays(20)->toDateString());
        $current = Permit::currentFor($unit);

        $renewal = PermitRenewal::open($unit, ['expires_at' => now()->addYear()->toDateString()], $this->partner->id);

        $this->assertSame($current->number, $renewal->number);
        $this->assertSame($current->file, $renewal->file);
        $this->assertSame($current->scope_type, $renewal->scope_type);
        $this->assertSame($current->scope_id, $renewal->scope_id);
    }

    public function test_only_one_renewal_may_wait_at_a_time(): void
    {
        $unit = $this->unit(expires: now()->addDays(20)->toDateString());
        PermitRenewal::open($unit, ['expires_at' => now()->addYear()->toDateString()]);

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$unit->id}/permit-renewals", ['permitExpiresAt' => now()->addYears(2)->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RENEWAL_ALREADY_PENDING');
    }

    public function test_a_renewal_dated_in_the_past_is_refused(): void
    {
        $unit = $this->unit(expires: now()->addDays(20)->toDateString());

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$unit->id}/permit-renewals", ['permitExpiresAt' => now()->subDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PERMIT_EXPIRED');

        $this->assertSame(1, Permit::count());
    }

    public function test_a_listing_with_no_permit_has_nothing_to_renew(): void
    {
        $unit = $this->unit(expires: null, extra: ['tourism_permit_no' => null, 'tourism_permit_file' => null]);

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$unit->id}/permit-renewals", ['permitExpiresAt' => now()->addYear()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NO_PERMIT_TO_RENEW');
    }

    /* ---------- deciding ---------- */

    public function test_approval_swaps_the_permits_and_moves_the_cap(): void
    {
        $unit = $this->unit(expires: now()->addDays(20)->toDateString());
        $old = Permit::currentFor($unit);
        $renewal = PermitRenewal::open($unit, [
            'expires_at' => now()->addYear()->toDateString(),
            'number' => 'NEW-9',
        ], $this->partner->id);

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/permit-renewals/{$renewal->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', Permit::STATUS_CURRENT);

        $this->assertSame(Permit::STATUS_SUPERSEDED, $old->fresh()->status, 'the old permit was not kept as history');
        $this->assertSame(now()->addYear()->toDateString(), PermitExpiry::on($unit->fresh())?->toDateString());
        $this->assertSame('NEW-9', $unit->fresh()->tourism_permit_no, 'the unit did not mirror the new permit');
        $this->assertSame($this->admin->id, $renewal->fresh()->reviewed_by);
    }

    public function test_rejection_leaves_the_old_permit_running_to_its_own_date(): void
    {
        $unit = $this->unit(expires: now()->addDays(20)->toDateString());
        $old = Permit::currentFor($unit);
        $renewal = PermitRenewal::open($unit, ['expires_at' => now()->addYear()->toDateString()]);

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/permit-renewals/{$renewal->id}/reject", ['reason' => 'الملف غير واضح', 'notes' => 'داخلي'])
            ->assertOk()
            ->assertJsonPath('status', Permit::STATUS_REJECTED)
            ->assertJsonPath('rejectionReason', 'الملف غير واضح');

        $this->assertSame(Permit::STATUS_CURRENT, $old->fresh()->status);
        $this->assertSame(now()->addDays(20)->toDateString(), PermitExpiry::on($unit->fresh())?->toDateString());

        // The reviewer's notes are internal — the partner is told the reason.
        $partnerView = $this->actingAs($this->partner, 'dashboard')
            ->getJson("/units/u_{$unit->id}/permit-renewals")->assertOk()->json();
        $this->assertSame('الملف غير واضح', $partnerView[0]['rejectionReason']);
        $this->assertArrayNotHasKey('reviewNotes', $partnerView[0]);
    }

    public function test_a_decision_cannot_be_made_twice(): void
    {
        $unit = $this->unit(expires: now()->addDays(20)->toDateString());
        $renewal = PermitRenewal::open($unit, ['expires_at' => now()->addYear()->toDateString()]);

        $this->actingAs($this->admin, 'admin-panel')->postJson("/admin/permit-renewals/{$renewal->id}/approve")->assertOk();
        $this->actingAs($this->admin, 'admin-panel')->postJson("/admin/permit-renewals/{$renewal->id}/approve")
            ->assertStatus(409)->assertJsonPath('code', 'RENEWAL_NOT_PENDING');
    }

    public function test_a_lapsed_listing_comes_back_the_moment_the_renewal_is_approved(): void
    {
        // Nothing was written when it went quiet, so nothing has to be undone.
        $unit = $this->unit(expires: now()->subDay()->toDateString());
        $this->getJson("/api/v1/units/{$unit->id}")->assertStatus(404);

        $renewal = PermitRenewal::open($unit, ['expires_at' => now()->addYear()->toDateString()]);
        $this->actingAs($this->admin, 'admin-panel')->postJson("/admin/permit-renewals/{$renewal->id}/approve")->assertOk();

        $this->getJson("/api/v1/units/{$unit->id}")->assertOk();
        $this->assertSame('approved', $unit->fresh()->approval_status, 'the listing was never sent back for review');
    }

    public function test_approving_clears_the_old_warnings_so_the_new_date_can_warn_again(): void
    {
        $unit = $this->unit(expires: now()->addDays(30)->toDateString());
        Notification::fake();
        $this->artisan('permits:check-expiry')->assertSuccessful();
        $this->assertSame(1, PermitReminder::count());

        $renewal = PermitRenewal::open($unit, ['expires_at' => now()->addDays(60)->toDateString()]);
        PermitRenewal::approve($renewal, $this->admin->id);

        $this->assertSame(0, PermitReminder::where('permit_id', $renewal->id)->count());

        // 60 days out from today — the new date warns in its own right.
        $this->artisan('permits:check-expiry')->assertSuccessful();
        $this->assertSame(60, PermitReminder::where('permit_id', $renewal->id)->value('threshold'));
    }

    public function test_a_building_renews_once_for_every_door(): void
    {
        $source = $this->unit(expires: now()->addDays(20)->toDateString(), extra: [
            'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 4,
        ]);
        UnitCloner::ensureTotal($source, 3);
        Unit::where('unit_group_id', $source->fresh()->unit_group_id)->update(['approval_status' => 'approved']);

        $renewal = PermitRenewal::open($source->fresh(), ['expires_at' => now()->addYear()->toDateString()]);
        $this->assertSame(Permit::SCOPE_GROUP, $renewal->scope_type);

        PermitRenewal::approve($renewal, $this->admin->id);

        foreach (Unit::where('unit_group_id', $source->fresh()->unit_group_id)->get() as $door) {
            $this->assertSame(now()->addYear()->toDateString(), PermitExpiry::on($door)?->toDateString(), "door {$door->id} kept the old date");
        }
    }

    /* ---------- the admin lists ---------- */

    public function test_the_admin_sees_what_is_about_to_lapse_and_what_already_has(): void
    {
        $expiring = $this->unit(expires: now()->addDays(10)->toDateString());
        $expired = $this->unit(expires: now()->subDays(2)->toDateString());
        $fine = $this->unit(expires: now()->addYear()->toDateString());

        $ids = fn (string $status) => collect($this->actingAs($this->admin, 'admin-panel')
            ->getJson("/admin/permits?status={$status}&pageSize=50")->assertOk()->json('items'))->pluck('unitId');

        $this->assertEqualsCanonicalizing([(string) $expiring->id], $ids('expiring')->all());
        $this->assertEqualsCanonicalizing([(string) $expired->id], $ids('expired')->all());
        $this->assertEqualsCanonicalizing([(string) $fine->id], $ids('valid')->all());
    }

    public function test_the_renewal_queue_shows_both_addresses_side_by_side(): void
    {
        // The comparison a human makes, and the one the system cannot: does the
        // address on the document match the listing.
        $unit = $this->unit(expires: now()->addDays(20)->toDateString());
        PermitRenewal::open($unit, [
            'expires_at' => now()->addYear()->toDateString(),
            'addr_city' => 'الرياض', 'addr_district' => 'النرجس', 'addr_building' => '12', 'addr_unit_no' => '3',
        ], $this->partner->id);

        $row = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/permit-renewals')->assertOk()->json('items.0');

        $this->assertSame('النرجس', $row['permitAddress']['district']);
        $this->assertSame($unit->address, $row['listingAddress']['address']);
        $this->assertSame(now()->addDays(20)->toDateString(), $row['currentPermit']['permitExpiresAt']);
        $this->assertSame(now()->addYear()->toDateString(), $row['permitExpiresAt']);
        $this->assertSame($this->partner->id, $row['createdBy']);
    }

    public function test_a_finance_admin_cannot_decide_a_renewal(): void
    {
        Role::findOrCreate('finance', 'web');
        $finance = User::factory()->create(['is_active' => true]);
        $finance->assignRole('finance');

        $unit = $this->unit(expires: now()->addDays(20)->toDateString());
        $renewal = PermitRenewal::open($unit, ['expires_at' => now()->addYear()->toDateString()]);

        $this->actingAs($finance, 'admin-panel')
            ->postJson("/admin/permit-renewals/{$renewal->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('code', 'INSUFFICIENT_PERMISSION');
    }

    /* ---------- fixtures ---------- */

    /** @param array<string, mixed> $extra */
    private function unit(?string $expires, array $extra = [], ?User $owner = null): Unit
    {
        $owner ??= $this->partner;

        $unit = $owner->units()->create(array_merge([
            'unit_name' => 'شقة', 'unit_type' => 'apartment',
            'code' => 'PR'.fake()->unique()->numerify('######'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 2, 'bathrooms' => 1,
            'city' => 'الرياض', 'district' => 'الملقا', 'address' => 'حي الملقا، الرياض',
            'lat' => 24.7136, 'lng' => 46.6753,
            'description' => str_repeat('وصف كافٍ للوحدة. ', 5),
            'tourism_permit_no' => 'TL-'.fake()->unique()->numerify('######'),
            'tourism_permit_file' => 'dashboard/license_pdf/file_test.pdf',
            'approval_status' => 'approved', 'status' => 'available',
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
        ], $extra));

        $unit->images()->create(['path' => 'units/'.$unit->id.'/photo.jpg', 'is_main' => true, 'sort_order' => 1]);

        if ($expires !== null) {
            PermitWriter::apply($unit, ['expires_at' => $expires]);
        }

        return $unit->fresh();
    }
}
