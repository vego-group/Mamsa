<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\EmailVerificationCode;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\CheckinReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Email task doc (2026-07-19): /user/email OTP flow with machine-coded
 * errors, the EMAIL_VERIFICATION_REQUIRED booking gate, and the idempotent
 * check-in reminder job.
 */
class EmailVerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Company', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        // Deterministic 6-digit code, same rule as staging (non-production
        // only) — and pin the contract's OTP policy so a dev .env with
        // shortened staging values can't skew these assertions.
        config([
            'otp.fixed_code'         => '111222',
            'otp.resend_seconds'     => 60,
            'otp.exp_minutes'        => 5,
            'otp.email_max_attempts' => 5,
        ]);
    }

    private function guest(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole('User');

        return $user;
    }

    public function test_attach_email_sends_code_and_stores_unverified(): void
    {
        Mail::fake();
        $user = $this->guest(['email' => null]);

        $this->actingAs($user)
            ->postJson('/api/v1/user/email', ['email' => 'Guest@Example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'guest@example.com')
            ->assertJsonPath('data.verified', false)
            ->assertJsonPath('data.resend_available_in', 60);

        $this->assertSame('guest@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
        Mail::assertSent(EmailVerificationCode::class, 1);
    }

    public function test_invalid_and_duplicate_emails_map_to_machine_codes(): void
    {
        Mail::fake();
        $this->guest(['email' => 'taken@mamsaa.com']);
        $user = $this->guest(['email' => null]);

        $this->actingAs($user)
            ->postJson('/api/v1/user/email', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMAIL_INVALID');

        $this->actingAs($user)
            ->postJson('/api/v1/user/email', ['email' => 'taken@mamsaa.com'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMAIL_ALREADY_IN_USE');

        Mail::assertNothingSent();
    }

    public function test_resend_cooldown_returns_rate_limited_with_retry_after(): void
    {
        Mail::fake();
        $user = $this->guest(['email' => null]);

        $this->actingAs($user)->postJson('/api/v1/user/email', ['email' => 'g@m.com'])->assertOk();

        $res = $this->actingAs($user)->postJson('/api/v1/user/email/resend')->assertStatus(429);
        $res->assertJsonPath('code', 'RATE_LIMITED');
        $this->assertIsInt($res->json('retry_after'));
        $this->assertGreaterThan(0, $res->json('retry_after'));

        // After the cooldown the resend goes through.
        $this->travel(61)->seconds();
        $this->actingAs($user)->postJson('/api/v1/user/email/resend')->assertOk();
        Mail::assertSent(EmailVerificationCode::class, 2);
    }

    public function test_verify_paths_wrong_expired_max_attempts_and_success(): void
    {
        Mail::fake();
        $user = $this->guest(['email' => null]);
        $this->actingAs($user)->postJson('/api/v1/user/email', ['email' => 'g@m.com'])->assertOk();

        // Wrong code → OTP_INVALID with remaining attempts.
        $this->actingAs($user)
            ->postJson('/api/v1/user/email/verify', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID')
            ->assertJsonPath('remaining_attempts', 4);

        // 4 more wrong tries (5 total) kill the code.
        foreach ([3, 2, 1] as $left) {
            $this->actingAs($user)
                ->postJson('/api/v1/user/email/verify', ['code' => '000000'])
                ->assertJsonPath('remaining_attempts', $left);
        }
        $this->actingAs($user)
            ->postJson('/api/v1/user/email/verify', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_MAX_ATTEMPTS');

        // Even the right code is dead now → expired (must request a new one).
        $this->actingAs($user)
            ->postJson('/api/v1/user/email/verify', ['code' => '111222'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_EXPIRED');

        // Fresh code, but past its 300s validity → OTP_EXPIRED.
        $this->travel(61)->seconds();
        $this->actingAs($user)->postJson('/api/v1/user/email/resend')->assertOk();
        $this->travel(6)->minutes();
        $this->actingAs($user)
            ->postJson('/api/v1/user/email/verify', ['code' => '111222'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_EXPIRED');

        // Fresh code, right away → verified.
        $this->travel(2)->minutes();
        $this->actingAs($user)->postJson('/api/v1/user/email/resend')->assertOk();
        $this->actingAs($user)
            ->postJson('/api/v1/user/email/verify', ['code' => '111222'])
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);

        // /auth/me reflects the flags the frontend polls.
        $this->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.email', 'g@m.com')
            ->assertJsonPath('data.email_verified', true);
    }

    public function test_changing_email_resets_verification_and_profile_change_too(): void
    {
        Mail::fake();
        $user = $this->guest(['email' => 'old@m.com', 'email_verified_at' => now()]);

        // Via the OTP flow: new address drops back to unverified.
        $this->actingAs($user)->postJson('/api/v1/user/email', ['email' => 'new@m.com'])->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);

        // Via PUT /user/profile: same rule (and uniqueness enforced).
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user)->putJson('/api/v1/user/profile', ['email' => 'third@m.com'])->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_booking_gate_requires_verified_email_when_enabled(): void
    {
        config(['booking.require_verified_email' => true]);

        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $unit = $owner->units()->create([
            'unit_name' => 'وحدة بوابة الإيميل', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);

        $payload = [
            'unit_id'    => $unit->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date'   => now()->addDays(12)->toDateString(),
            'guests'     => 2,
        ];

        // No verified email → machine-coded 422, nothing created.
        $unverified = $this->guest(['email' => 'u@m.com', 'email_verified_at' => null]);
        $this->actingAs($unverified)
            ->postJson('/api/v1/bookings', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');
        $this->assertDatabaseCount('bookings', 0);

        // Verified email → the gate opens (booking is created).
        $verified = $this->guest(['email' => 'v@m.com', 'email_verified_at' => now()]);
        $this->actingAs($verified)->postJson('/api/v1/bookings', $payload)->assertStatus(201);

        // Flag off (prod today) → unverified users still book.
        config(['booking.require_verified_email' => false]);
        $this->actingAs($unverified)
            ->postJson('/api/v1/bookings', array_merge($payload, [
                'start_date' => now()->addDays(20)->toDateString(),
                'end_date'   => now()->addDays(21)->toDateString(),
            ]))
            ->assertStatus(201);
    }

    public function test_checkin_reminder_job_is_idempotent_and_riyadh_scoped(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $unit = $owner->units()->create([
            'unit_name' => 'وحدة التذكير', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);

        $tomorrowRiyadh = Carbon::now('Asia/Riyadh')->addDay()->toDateString();

        $guest = $this->guest(['email' => 'r@m.com', 'email_verified_at' => now()]);
        $due = \App\Models\Booking::create([
            'unit_id' => $unit->id, 'user_id' => $guest->id,
            'start_date' => $tomorrowRiyadh,
            'end_date' => Carbon::parse($tomorrowRiyadh)->addDays(2)->toDateString(),
            'guests' => 2, 'status' => \App\Models\Booking::STATUS_CONFIRMED,
            'total_amount' => 1150,
        ]);
        // Not due: check-in further out.
        \App\Models\Booking::create([
            'unit_id' => $unit->id, 'user_id' => $guest->id,
            'start_date' => Carbon::parse($tomorrowRiyadh)->addDays(5)->toDateString(),
            'end_date' => Carbon::parse($tomorrowRiyadh)->addDays(6)->toDateString(),
            'guests' => 2, 'status' => \App\Models\Booking::STATUS_CONFIRMED,
            'total_amount' => 575,
        ]);

        $this->artisan('bookings:checkin-reminders')->assertSuccessful();
        Notification::assertSentTo($guest, CheckinReminder::class, fn ($n) => $n->booking->id === $due->id);
        Notification::assertCount(1);
        $this->assertNotNull($due->fresh()->checkin_reminder_sent_at);

        // Second run (doc §3: idempotent) — nothing new goes out.
        $this->artisan('bookings:checkin-reminders')->assertSuccessful();
        Notification::assertCount(1);
    }
}
