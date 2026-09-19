<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DashboardUpload;
use App\Models\PartnerLedgerEntry;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\UnitReviewResult;
use App\Services\OtpService;
use App\Services\PartnerWalletService;
use App\Services\Sms\SmsProvider;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Platform-owned listings point at the platform, not at a person.
 *
 * `units.user_id` is NOT NULL, so a Mamsa-owned unit always pointed at some
 * user — the admin who typed it in. That put an employee's name on the
 * storefront as host (live on production, unit #34) and left platform revenue
 * one owner-join away from being credited to a staff member. The wallet was
 * protected only by arithmetic in another file (`partner_share` happens to be
 * exactly 0 at rate 1). Both were correct today and not correct by construction.
 */
class PlatformAccountTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../database/migrations/2026_09_19_000001_reassign_mamsa_units_to_platform_account.php';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['SuperAdmin', 'Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('SuperAdmin');

        return $admin;
    }

    /** @param array<string, mixed> $overrides */
    private function unit(User $owner, array $overrides = []): Unit
    {
        return $owner->units()->create(array_merge([
            'unit_name' => 'وحدة', 'unit_type' => 'apartment', 'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 1150, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 1, 'bathrooms' => 1, 'area' => 90,
            'city' => 'الرياض', 'district' => 'العليا', 'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ], $overrides));
    }

    /* ---------- the account itself ---------- */

    public function test_the_platform_account_is_created_once_and_is_not_a_person(): void
    {
        $a = User::platform();
        $b = User::platform();

        $this->assertSame($a->id, $b->id, 'platform() minted a second account.');
        $this->assertTrue($a->isPlatform());
        $this->assertFalse((bool) $a->is_active);
        $this->assertNull($a->email);
        $this->assertSame(0, $a->roles()->count(), 'The platform account has a role a human could use.');
        $this->assertSame('ممسى', $a->name);
    }

    public function test_the_platform_account_cannot_log_in_on_any_surface(): void
    {
        $platform = User::platform();

        // /api/v1 is the loosest surface: the phone rule is only a length. Fix
        // the OTP for the sentinel so the only thing standing in the way is
        // the account itself.
        $code = $this->fixOtpFor(User::PLATFORM_PHONE);
        app(OtpService::class)->request(User::PLATFORM_PHONE, 'login');

        $this->postJson('/api/v1/auth/verify-otp', ['phone' => User::PLATFORM_PHONE, 'code' => $code]);

        // Whatever that request did, it did not reach this row: every login
        // surface normalises the phone to E.164 first, and the sentinel does
        // not survive normalisation — so no lookup can ever resolve to it.
        $this->assertNotSame(User::PLATFORM_PHONE, PhoneNumber::toE164Ksa(User::PLATFORM_PHONE));
        $this->assertSame(0, $platform->fresh()->tokens()->count(), 'The platform account holds an access token.');
        $this->assertSame(0, $platform->fresh()->refreshTokens()->count(), 'The platform account holds a refresh token.');
        $this->assertFalse((bool) $platform->fresh()->is_active);

        // The two cookie surfaces refuse the sentinel as a phone before any lookup.
        $this->postJson('/auth/otp/request', ['phone' => User::PLATFORM_PHONE])->assertStatus(400);
        $this->postJson('/admin/auth/request-otp', ['phone' => User::PLATFORM_PHONE])->assertStatus(422);
    }

    public function test_the_platform_account_receives_no_sms_or_mail(): void
    {
        // Every "to the unit's owner" notification now lands on this account.
        // Its phone normalises to "+", which the gateway would reject per send.
        $sms = Mockery::spy(SmsProvider::class);
        $this->app->instance(SmsProvider::class, $sms);
        Mail::fake();

        $platform = User::platform();
        $unit = $this->unit($platform, ['mamsa_owned' => true]);

        $platform->notify(new UnitReviewResult($unit, true));

        $sms->shouldNotHaveReceived('send');
        Mail::assertNothingSent();
        // The in-app row is harmless and still written — it is the audit trail.
        $this->assertSame(1, $platform->notifications()->count());
    }

    public function test_a_real_partner_still_gets_the_sms(): void
    {
        // The control for the channel change: the fallback that was removed
        // must not have taken ordinary partners' SMS with it.
        $sms = Mockery::spy(SmsProvider::class);
        $this->app->instance(SmsProvider::class, $sms);

        $partner = User::factory()->create(['phone' => '+966512345678']);
        $partner->assignRole('Individual');
        $unit = $this->unit($partner);

        $partner->notify(new UnitReviewResult($unit, true));

        $sms->shouldHaveReceived('send')->once()->with('+966512345678', Mockery::type('string'), Mockery::any());
    }

    /* ---------- the migration ---------- */

    public function test_the_migration_moves_mamsa_units_off_the_admin_and_nothing_else(): void
    {
        $admin = $this->admin();
        $partner = User::factory()->create();
        $partner->assignRole('Individual');

        $mamsaA = $this->unit($admin, ['mamsa_owned' => true]);
        $mamsaB = $this->unit($admin, ['mamsa_owned' => true]);
        $partnerUnit = $this->unit($partner);

        $migration = require self::MIGRATION;
        $migration->up();

        $platform = User::platform();
        $this->assertSame($platform->id, $mamsaA->fresh()->user_id);
        $this->assertSame($platform->id, $mamsaB->fresh()->user_id);
        $this->assertSame($partner->id, $partnerUnit->fresh()->user_id, 'A partner unit was reassigned.');
        $this->assertSame(0, $admin->units()->count(), 'The admin still owns a platform unit.');

        // Running it again changes nothing — the migration is a state, not an event.
        $migration->up();
        $this->assertSame(2, Unit::where('user_id', $platform->id)->count());
        $this->assertSame(1, User::where('phone', User::PLATFORM_PHONE)->count());
    }

    /* ---------- the wallet ---------- */

    public function test_the_wallet_refuses_a_mamsa_owned_unit_even_with_a_positive_share(): void
    {
        // The existing guard is `$share <= 0`, which holds because Pricing
        // splits at rate 1. This test bypasses Pricing and writes a positive
        // share directly — it fails only if the explicit flag check is gone,
        // which is exactly the case the arithmetic guard cannot cover.
        $guest = User::factory()->create();
        $guest->assignRole('User');
        $unit = $this->unit(User::platform(), ['mamsa_owned' => true]);

        $booking = Booking::withoutEvents(fn () => Booking::create([
            'unit_id' => $unit->id, 'user_id' => $guest->id,
            'start_date' => now()->addDays(3)->toDateString(), 'end_date' => now()->addDays(4)->toDateString(),
            'guests' => 2, 'nights' => 1,
            'subtotal' => 1000, 'taxes' => 150, 'tax_percent' => 15, 'total_amount' => 1150,
            'commission_rate' => 0.10, 'commission_amount' => 100,
            'partner_share' => 900, // deliberately wrong: what a rounding change elsewhere could produce
            'status' => Booking::STATUS_COMPLETED,
        ]));

        $this->assertNull(app(PartnerWalletService::class)->recordEarning($booking->fresh('unit')));
        $this->assertSame(0, PartnerLedgerEntry::count(), 'A Mamsa-owned booking credited a wallet.');
    }

    /* ---------- the admin console ---------- */

    public function test_a_unit_created_from_the_admin_console_is_owned_by_the_platform(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin-panel')->postJson('/admin/units', [
            'name' => 'استوديو ممسى', 'type' => 'studio', 'city' => 'Riyadh', 'district' => 'العليا',
            'pricePerNight' => 450, 'bedrooms' => 1, 'bathrooms' => 1, 'capacity' => 2, 'sizeSqm' => 90,
        ])->assertStatus(201);

        $unit = Unit::firstOrFail();
        $this->assertTrue((bool) $unit->mamsa_owned);
        $this->assertSame(User::platform()->id, $unit->user_id);
        $this->assertNotSame($admin->id, $unit->user_id, 'The acting admin owns the platform listing.');

        // And the storefront names the platform, never a person.
        $unit->update(['approval_status' => 'approved']);
        $this->getJson("/api/v1/units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.owner.type', 'mamsa')
            ->assertJsonPath('data.owner.name', 'ممسى');
    }

    public function test_a_second_admin_can_replace_the_photos_of_a_platform_unit(): void
    {
        // update() synced photos against the UNIT OWNER's uploads. It only
        // worked because the owner was the same admin who was editing; with
        // the platform owning — or a second admin editing — the gallery was
        // cleared and nothing re-attached, behind a 200.
        Storage::fake('public');

        $creator = $this->admin();
        $editor = $this->admin();

        $id = $this->actingAs($creator, 'admin-panel')->postJson('/admin/units', [
            'name' => 'استوديو', 'type' => 'studio', 'city' => 'Riyadh', 'district' => 'العليا',
            'pricePerNight' => 450, 'bedrooms' => 1, 'bathrooms' => 1, 'capacity' => 2, 'sizeSqm' => 90,
        ])->assertStatus(201)->json('id');

        $photo = DashboardUpload::create([
            'id' => 'file_'.strtolower((string) str()->ulid()), 'user_id' => $editor->id, 'kind' => 'unit_photo',
            'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 5, 'status' => 'stored',
            'path' => 'dashboard/unit_photo/x.jpg',
        ]);
        Storage::disk('public')->put($photo->path, 'bytes');

        $this->actingAs($editor, 'admin-panel')
            ->patchJson("/admin/units/{$id}", ['photoFileIds' => [$photo->id]])
            ->assertOk();

        $unit = Unit::find(str_replace('u_', '', (string) $id));
        $this->assertSame(1, $unit->images()->where('file_id', $photo->id)->count(), 'The photo a second admin attached was dropped.');
    }
}
