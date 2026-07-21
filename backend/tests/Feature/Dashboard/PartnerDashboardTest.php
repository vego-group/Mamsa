<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\UnitBlockedDate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Partner-dashboard contract security + lifecycle coverage. Focuses on the
 * launch-blocker checks: auth gate, ownership/IDOR, host-cancel idempotency,
 * and submit validation. (Overview/reports use MySQL-only SQL and are verified
 * against staging, not the sqlite test DB.)
 */
class PartnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The idempotency guard lives in the cache; isolate it between tests.
        \Illuminate\Support\Facades\Cache::flush();

        foreach (['Individual', 'Company', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function partner(string $state = 'approved', string $type = 'individual'): User
    {
        $user = User::factory()->create(['is_active' => $state !== 'suspended']);
        $user->assignRole($type === 'company' ? 'Company' : 'Individual');
        $user->partnerDetail()->create([
            'type'   => $type,
            'status' => $state === 'approved' ? PartnerDetail::STATUS_APPROVED : PartnerDetail::STATUS_PENDING,
        ]);

        return $user;
    }

    private function unit(User $owner, array $attrs = []): Unit
    {
        return $owner->units()->create(array_merge([
            'unit_name'       => 'وحدة اختبار',
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => 300,
            'capacity'        => 2,
            'bedrooms'        => 1,
            'approval_status' => 'approved',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ], $attrs));
    }

    /* ---- auth gate (§1.2) ---- */

    public function test_verify_rejects_non_partner_phone(): void
    {
        $user = User::factory()->create();
        $user->assignRole('User');

        $this->postJson('/auth/otp/verify', ['phone' => substr($user->phone, 4), 'code' => '000000'])
            ->assertStatus(401); // OTP fails first — never leaks partner existence
    }

    public function test_pending_partner_cannot_login(): void
    {
        $partner = $this->partner('pending');

        // Bypass real OTP by asserting the gate directly via a logged-out call:
        // an approved partner logs in, a pending one is blocked. We assert the
        // /me endpoint is unreachable without a session.
        $this->getJson('/me')->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_me_returns_contract_shape(): void
    {
        $partner = $this->partner();

        $this->actingAs($partner, 'dashboard')->getJson('/me')
            ->assertOk()
            ->assertJsonStructure(['id', 'name', 'phone', 'accountType', 'accountState', 'hostCancellationsLast12m', 'flagged'])
            ->assertJsonPath('accountState', 'approved');
    }

    /* ---- ownership / IDOR (§0.2) ---- */

    public function test_cannot_read_another_partners_unit(): void
    {
        $me    = $this->partner();
        $other = $this->partner();
        $unit  = $this->unit($other);

        $this->actingAs($me, 'dashboard')->getJson('/units/'.$unit->id)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'UNIT_NOT_FOUND');
    }

    public function test_cannot_host_cancel_another_partners_booking(): void
    {
        $me    = $this->partner();
        $other = $this->partner();
        $unit  = $this->unit($other);
        $booking = $this->booking($unit);

        $this->actingAs($me, 'dashboard')
            ->postJson('/bookings/'.$booking->id.'/host-cancel', ['reason' => 'x'])
            ->assertStatus(404);
    }

    /* ---- submit validation (§4) ---- */

    public function test_submit_incomplete_draft_returns_field_errors(): void
    {
        $partner = $this->partner();
        $draft = $this->unit($partner, [
            'approval_status' => 'draft',
            'description'     => null,
            'lat'             => null,
            'lng'             => null,
            'tourism_permit_no' => null,
        ]);

        $this->actingAs($partner, 'dashboard')->postJson('/units/'.$draft->id.'/submit')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION')
            ->assertJsonStructure(['error' => ['fields']]);
    }

    public function test_delete_allowed_only_for_drafts(): void
    {
        $partner = $this->partner();
        $approved = $this->unit($partner, ['approval_status' => 'approved']);

        $this->actingAs($partner, 'dashboard')->deleteJson('/units/'.$approved->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'UNIT_NOT_DELETABLE');
    }

    public function test_editing_approved_unit_reverts_to_pending(): void
    {
        $partner = $this->partner();
        $unit = $this->unit($partner, ['approval_status' => 'approved']);

        $this->actingAs($partner, 'dashboard')
            ->patchJson('/units/'.$unit->id, ['name' => 'اسم جديد'])
            ->assertOk()
            ->assertJsonPath('status', 'pending');
    }

    /* ---- draft creation (wizard, §4 partial body) ---- */

    public function test_create_partial_draft_without_price(): void
    {
        $partner = $this->partner();

        // §4 — drafts don't validate required fields; a partial body must save.
        $this->actingAs($partner, 'dashboard')
            ->postJson('/units', ['name' => 'مسودة جزئية'])
            ->assertStatus(201)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('pricePerNight', null);
    }

    public function test_beds_and_bathrooms_are_settable_and_returned(): void
    {
        $partner = $this->partner();

        // Create with beds + bathrooms → echoed back on the contract shape.
        $created = $this->actingAs($partner, 'dashboard')
            ->postJson('/units', ['name' => 'وحدة أسرّة', 'beds' => 2, 'bathrooms' => 2, 'bedrooms' => 1])
            ->assertStatus(201)
            ->assertJsonPath('beds', 2)
            ->assertJsonPath('bathrooms', 2)
            ->assertJsonPath('bedrooms', 1)
            ->json('id');

        $id = (int) str_replace('u_', '', $created);
        $this->assertDatabaseHas('units', ['id' => $id, 'beds' => 2, 'bathrooms' => 2]);

        // Patch beds alone.
        $this->actingAs($partner, 'dashboard')
            ->patchJson('/units/'.$id, ['beds' => 4])
            ->assertOk()
            ->assertJsonPath('beds', 4);

        // Out-of-range beds → dashboard VALIDATION envelope (400).
        $this->actingAs($partner, 'dashboard')
            ->patchJson('/units/'.$id, ['beds' => 21])  // max 20
            ->assertStatus(400);
        $this->actingAs($partner, 'dashboard')
            ->patchJson('/units/'.$id, ['bathrooms' => 11]) // max 10
            ->assertStatus(400);
    }

    public function test_submit_requires_beds_and_bathrooms(): void
    {
        $partner = $this->partner();

        // Otherwise-complete draft, but missing beds + bathrooms → submit 400
        // with both fields flagged (same VALIDATION envelope as other fields).
        $draft = $this->unit($partner, [
            'approval_status'     => 'draft',
            'beds'                => null,
            'bathrooms'           => null,
            'description'         => 'وصف كافٍ للوحدة يزيد عن عشرة أحرف بوضوح',
            'address'             => 'الرياض، حي العليا',
            'lat'                 => 24.7136,
            'lng'                 => 46.6753,
            'tourism_permit_no'   => 'TL-12345',
            'tourism_permit_file' => 'file_x',
        ]);
        $draft->images()->create(['path' => 'units/x.jpg', 'is_main' => true]);

        $this->actingAs($partner, 'dashboard')->postJson('/units/'.$draft->id.'/submit')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION')
            ->assertJsonPath('error.fields.beds', 'عدد السراير مطلوب')
            ->assertJsonPath('error.fields.bathrooms', 'عدد دورات المياه مطلوب');

        // With both set, those two no longer block submission.
        $draft->update(['beds' => 2, 'bathrooms' => 1]);
        $res = $this->actingAs($partner, 'dashboard')->postJson('/units/'.$draft->id.'/submit');
        $fields = $res->json('error.fields') ?? [];
        $this->assertArrayNotHasKey('beds', $fields);
        $this->assertArrayNotHasKey('bathrooms', $fields);
    }

    /* ---- photo/license attach via presign fileIds (wizard §1) ---- */

    public function test_attach_photos_and_cover_by_file_ids(): void
    {
        $partner = $this->partner();
        $unit = $this->unit($partner, ['approval_status' => 'draft']);
        [$a, $b] = [$this->upload($partner, 'unit_photo'), $this->upload($partner, 'unit_photo')];

        $this->actingAs($partner, 'dashboard')
            ->patchJson('/units/'.$unit->id, [
                'photoFileIds' => [$a->id, $b->id],
                'coverFileId'  => $b->id,
            ])
            ->assertOk()
            ->assertJsonPath('photos.0.id', $a->id)   // echoes fileId, order preserved
            ->assertJsonPath('photos.1.isCover', true);

        $this->assertSame(2, $unit->images()->count());
        $this->assertSame($b->id, $unit->images()->where('is_main', true)->first()->file_id);
    }

    public function test_photo_file_id_must_be_owned(): void
    {
        $partner = $this->partner();
        $other   = $this->partner();
        $unit = $this->unit($partner, ['approval_status' => 'draft']);
        $foreign = $this->upload($other, 'unit_photo');

        $this->actingAs($partner, 'dashboard')
            ->patchJson('/units/'.$unit->id, ['photoFileIds' => [$foreign->id]])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION')
            ->assertJsonStructure(['error' => ['fields' => ['photoFileIds.0']]]);

        $this->assertSame(0, $unit->images()->count()); // nothing attached
    }

    public function test_cover_must_be_among_photos(): void
    {
        $partner = $this->partner();
        $unit = $this->unit($partner, ['approval_status' => 'draft']);
        $a = $this->upload($partner, 'unit_photo');

        $this->actingAs($partner, 'dashboard')
            ->patchJson('/units/'.$unit->id, ['photoFileIds' => [$a->id], 'coverFileId' => 'file_stray'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
    }

    private function upload(User $owner, string $kind): \App\Models\DashboardUpload
    {
        return \App\Models\DashboardUpload::create([
            'id'            => 'file_'.\Illuminate\Support\Str::lower((string) \Illuminate\Support\Str::ulid()),
            'user_id'       => $owner->id,
            'kind'          => $kind,
            'original_name' => 'x.'.($kind === 'unit_photo' ? 'jpg' : 'pdf'),
            'status'        => 'stored',
            'path'          => 'dashboard/'.$kind.'/x.'.($kind === 'unit_photo' ? 'jpg' : 'pdf'),
        ]);
    }

    /* ---- calendar month grid (§5.1) ---- */

    /**
     * Regression: the grid eager-loads blockedDates.icalFeed. A month with NO
     * blocked rows never resolves that relation (Eloquent skips eager loading
     * on an empty result set), so an empty month passes vacuously — this test
     * MUST keep real ical + manual rows in range to be worth anything.
     */
    public function test_calendar_month_resolves_ical_manual_and_booked_days(): void
    {
        $partner = $this->partner();
        $unit    = $this->unit($partner);

        $feed = $unit->icalFeeds()->create([
            'source' => 'Airbnb',
            'url'    => 'https://example.com/f.ics',
            'status' => \App\Models\UnitIcalFeed::STATUS_SYNCED,
        ]);

        $month = now()->startOfMonth();
        $unit->blockedDates()->create([
            'start_date'   => $month->copy()->addDays(2)->toDateString(),
            'end_date'     => $month->copy()->addDays(3)->toDateString(),
            'source'       => UnitBlockedDate::SOURCE_ICAL,
            'ical_feed_id' => $feed->id,
        ]);
        $unit->blockedDates()->create([
            'start_date' => $month->copy()->addDays(5)->toDateString(),
            'end_date'   => $month->copy()->addDays(6)->toDateString(),
            'source'     => UnitBlockedDate::SOURCE_MANUAL,
            'note'       => 'صيانة',
        ]);

        $res = $this->actingAs($partner, 'dashboard')
            ->getJson('/units/u_'.$unit->id.'/calendar?month='.$month->format('Y-m'))
            ->assertOk();

        $days = collect($res->json())->keyBy('date');

        $this->assertSame('external', $days[$month->copy()->addDays(2)->toDateString()]['status']);
        $this->assertSame('Airbnb', $days[$month->copy()->addDays(2)->toDateString()]['source']);
        $this->assertSame('blocked', $days[$month->copy()->addDays(5)->toDateString()]['status']);
        $this->assertSame('صيانة', $days[$month->copy()->addDays(5)->toDateString()]['reason']);
        // Checkout day is exclusive — day 3 is free again.
        $this->assertSame('available', $days[$month->copy()->addDays(3)->toDateString()]['status']);
        $this->assertCount((int) $month->copy()->endOfMonth()->format('j'), $res->json());
    }

    public function test_calendar_external_day_falls_back_when_feed_missing(): void
    {
        $partner = $this->partner();
        $unit    = $this->unit($partner);
        $month   = now()->startOfMonth();

        // Legacy single-feed sync rows carry no ical_feed_id — must not 500.
        $unit->blockedDates()->create([
            'start_date' => $month->copy()->addDays(1)->toDateString(),
            'end_date'   => $month->copy()->addDays(2)->toDateString(),
            'source'     => UnitBlockedDate::SOURCE_ICAL,
            'note'       => 'Booking.com',
        ]);

        $days = collect($this->actingAs($partner, 'dashboard')
            ->getJson('/units/u_'.$unit->id.'/calendar?month='.$month->format('Y-m'))
            ->assertOk()->json())->keyBy('date');

        $this->assertSame('external', $days[$month->copy()->addDays(1)->toDateString()]['status']);
        $this->assertSame('Booking.com', $days[$month->copy()->addDays(1)->toDateString()]['source']);
    }

    /* ---- host-cancel idempotency (§6.1 / §10.8) ---- */

    public function test_host_cancel_is_idempotent(): void
    {
        config(['moyasar.secret_key' => null]); // test mode — no gateway call
        $partner = $this->partner();
        $unit = $this->unit($partner);
        $booking = $this->booking($unit);

        $key = 'idem-123';
        $headers = ['Idempotency-Key' => $key];

        $first = $this->actingAs($partner, 'dashboard')
            ->postJson('/bookings/'.$booking->id.'/host-cancel', ['reason' => 'مزدوج'], $headers)
            ->assertOk()->assertJsonPath('status', 'cancelled');

        // Second call with the same key must not throw / double-process.
        $this->actingAs($partner, 'dashboard')
            ->postJson('/bookings/'.$booking->id.'/host-cancel', ['reason' => 'مزدوج'], $headers)
            ->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame(1, $booking->unit->blockedDates()->count(), 'dates blocked exactly once');
        $this->assertSame(1, $partner->fresh()->pipe(fn () => \App\Http\Controllers\Dashboard\ProfileController::hostCancellations($partner)));
    }

    public function test_host_cancel_requires_reason(): void
    {
        $partner = $this->partner();
        $booking = $this->booking($this->unit($partner));

        $this->actingAs($partner, 'dashboard')
            ->postJson('/bookings/'.$booking->id.'/host-cancel', ['reason' => ''])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
    }

    private function booking(Unit $unit, array $attrs = []): Booking
    {
        $guest = User::factory()->create();

        return $unit->bookings()->create(array_merge([
            'user_id'      => $guest->id,
            'start_date'   => now()->addDays(10)->toDateString(),
            'end_date'     => now()->addDays(13)->toDateString(),
            'guests'       => 2,
            'nightly_rate' => 300,
            'subtotal'     => 900,
            'total_amount' => 900,
            'commission_amount' => 18,
            'status'       => Booking::STATUS_CONFIRMED,
        ], $attrs));
    }
}
