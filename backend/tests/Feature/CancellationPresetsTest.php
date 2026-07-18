<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use App\Services\CancellationPolicyService;
use Database\Seeders\CancellationPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The 3 cancellation presets end-to-end (contract 2026-07-18): seeded table →
 * partner picks per unit → snapshot frozen → guest cancel pays the tier's
 * partial refund. Gateway runs in test mode here; the Moyasar call itself is
 * MoyasarService::refund(id, halalas) — verified against staging manually.
 */
class CancellationPresetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Company', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->seed(CancellationPolicySeeder::class);
    }

    private function partnerUnit(?string $policyKey = null): Unit
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $owner->units()->create([
            'unit_name'              => 'وحدة سياسات',
            'unit_type'              => 'apartment',
            'code'                   => 'MRN'.fake()->unique()->numerify('#####'),
            'price'                  => 500,
            'capacity'               => 2,
            'bedrooms'               => 1,
            'approval_status'        => 'approved',
            'status'                 => 'available',
            'calendar_token'         => str()->random(60),
            'cancellation_policy_id' => $policyKey
                ? CancellationPolicy::where('key', $policyKey)->value('id')
                : null,
        ]);
    }

    public function test_seeder_matches_the_approved_preset_table(): void
    {
        // days-before-check-in → % : the exact table from the product decision.
        $approved = [
            'flexible' => [168 => 100, 72 => 75, 0 => 50],
            'moderate' => [168 => 100, 72 => 50, 0 => 25],
            'strict'   => [168 => 75,  72 => 25, 0 => 0],
        ];

        foreach ($approved as $key => $tiers) {
            $policy = CancellationPolicy::with('tiers')->where('key', $key)->firstOrFail();
            $this->assertSame(
                $tiers,
                $policy->tiers->pluck('refund_percent', 'min_hours_before_checkin')->all(),
                "preset {$key}",
            );
        }

        // Partner skipped the field → moderate applies.
        $this->assertSame('moderate', CancellationPolicy::where('is_default', true)->value('key'));
    }

    public function test_dashboard_partner_picks_a_preset_per_unit(): void
    {
        $unit = $this->partnerUnit();          // no policy chosen yet
        $unit->update(['approval_status' => 'draft']);

        $this->actingAs($unit->owner, 'dashboard')
            ->patchJson('/units/'.$unit->id, ['cancellationPolicy' => 'strict'])
            ->assertOk()
            ->assertJsonFragment(['cancellationPolicy' => 'strict']);

        $this->assertSame(
            CancellationPolicy::where('key', 'strict')->value('id'),
            $unit->fresh()->cancellation_policy_id,
        );

        $this->actingAs($unit->owner, 'dashboard')
            ->patchJson('/units/'.$unit->id, ['cancellationPolicy' => 'lenient'])
            ->assertStatus(400); // dashboard VALIDATION envelope, not a Laravel 422
    }

    public function test_frozen_snapshot_drives_partial_refund_immune_to_later_policy_change(): void
    {
        $unit  = $this->partnerUnit('moderate');
        $guest = User::factory()->create();
        $guest->assignRole('User');

        // Confirmed + paid booking, check-in ~5 days out → moderate 3–7d tier = 50%.
        $booking = Booking::create([
            'unit_id'    => $unit->id, 'user_id' => $guest->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date'   => now()->addDays(8)->toDateString(),
            'guests'     => 2, 'status' => Booking::STATUS_CONFIRMED,
            'nightly_rate' => 500, 'subtotal' => 1500,
            'taxes' => 225, 'tax_percent' => 15, 'total_amount' => 1725,
        ]);
        $booking->payment()->create([
            'amount' => 1725, 'refunded_amount' => 0,
            'payment_method' => 'creditcard', 'payment_status' => 'paid',
            'paid_at' => now()->subHours(5), // outside the 2h void window → refund path
        ]);

        // Freeze the snapshot exactly as PaymentController does on payment success…
        $booking->update([
            'cancellation_snapshot' => app(CancellationPolicyService::class)->snapshotForBooking($booking),
        ]);

        // …then the partner flips the unit to strict. Must NOT affect this booking.
        $unit->update(['cancellation_policy_id' => CancellationPolicy::where('key', 'strict')->value('id')]);

        $this->actingAs($guest)
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertOk();

        // 50% of 1725 = 862.50 — recorded refund, payment ledger, wallet entry.
        $this->assertDatabaseHas('refunds', [
            'booking_id'     => $booking->id,
            'amount'         => 862.50,
            'refund_percent' => 50,
            'status'         => 'succeeded',
        ]);
        $this->assertSame(862.50, $booking->payment->fresh()->refunded_amount);
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertDatabaseHas('wallet_transactions', [
            'booking_id' => $booking->id,
            'amount'     => 862.50,
        ]);
    }

    public function test_cancellation_locked_after_checkin(): void
    {
        $unit  = $this->partnerUnit('flexible');
        $guest = User::factory()->create();
        $guest->assignRole('User');

        $booking = Booking::create([
            'unit_id'    => $unit->id, 'user_id' => $guest->id,
            'start_date' => now()->subDay()->toDateString(),   // stay in progress
            'end_date'   => now()->addDays(2)->toDateString(),
            'guests'     => 2, 'status' => Booking::STATUS_CONFIRMED,
            'total_amount' => 1725,
        ]);
        $booking->update([
            'cancellation_snapshot' => app(CancellationPolicyService::class)->snapshotForBooking($booking),
        ]);

        $this->actingAs($guest)
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertStatus(422);

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
    }
}
