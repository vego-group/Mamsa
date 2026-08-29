<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\PartnerLedgerEntry;
use App\Models\Payout;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The reseed command destroys ledger history, so its guards are the part that
 * matters most — a command like this is only safe if it refuses more readily
 * than it runs.
 */
class LedgerReseedCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    public function test_it_refuses_to_run_on_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->ledgerEntry();

        $this->artisan('ledger:reseed-staging', ['--confirm' => true])->assertFailed();

        $this->assertSame(1, PartnerLedgerEntry::count(), 'production data must be untouched');
    }

    public function test_an_unconfigured_environment_cannot_reach_a_real_database(): void
    {
        // The safe default: a checkout with no RESEED_ALLOWED_DATABASES set can
        // only touch test databases. Nothing real is reachable by accident.
        //
        // Asserted on the decision rather than by swapping the live connection:
        // the name is resolved when the connection opens, so changing config
        // afterwards would not move it and the test would pass vacuously.
        config()->set('database.reseed_allowlist', []);

        $allowed = $this->invokeAllowed();

        $this->assertNotContains('some_real_db', $allowed);
        $this->assertSame(['testing', ':memory:'], $allowed, 'only test databases without configuration');
    }

    public function test_a_configured_database_is_accepted(): void
    {
        // And naming it in config is what makes it reachable — no code change.
        config()->set('database.reseed_allowlist', ['some_real_db']);

        $this->assertContains(
            'some_real_db',
            $this->invokeAllowed(),
            'a configured database must be on the effective allow-list',
        );
    }

    public function test_test_databases_are_always_safe_without_configuration(): void
    {
        config()->set('database.reseed_allowlist', []);

        $allowed = $this->invokeAllowed();

        $this->assertContains(':memory:', $allowed);
        $this->assertContains('testing', $allowed);
    }

    /** @return list<string> */
    private function invokeAllowed(): array
    {
        $m = new \ReflectionMethod(\App\Console\Commands\ReseedStagingLedger::class, 'allowed');
        $m->setAccessible(true);

        return $m->invoke(null);
    }

    public function test_it_refuses_a_database_it_does_not_recognise(): void
    {
        $this->ledgerEntry();

        // --force-env has to name the ACTUAL database, so a wrong guess is a
        // refusal rather than an override.
        $this->artisan('ledger:reseed-staging', ['--confirm' => true, '--force-env' => 'some_other_db'])
            ->assertFailed();

        $this->assertSame(1, PartnerLedgerEntry::count());
    }

    public function test_without_confirm_it_reports_and_changes_nothing(): void
    {
        $this->ledgerEntry();

        $this->artisan('ledger:reseed-staging')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(1, PartnerLedgerEntry::count(), 'a dry run must not delete');
    }

    public function test_it_rebuilds_earnings_from_the_frozen_share(): void
    {
        // A stale entry at the OLD 98% share, and a completed booking whose
        // frozen share is 90%. The rebuild must follow the booking, not the
        // entry, and not today's config either.
        $booking = $this->completedBooking(subtotal: 1000, commission: 100, share: 900);
        $this->ledgerEntry(amount: 980);
        Payout::create([
            'partner_user_id' => $booking->unit->user_id, 'reference' => 'PO-1',
            'period_month' => '2026-07', 'amount' => 500, 'bookings_count' => 1,
            'currency' => 'SAR', 'status' => Payout::STATUS_PAID,
            'iban_masked' => 'SA •••• 1111', 'bank_name' => 'بنك', 'paid_at' => now(),
        ]);

        $this->artisan('ledger:reseed-staging', ['--confirm' => true])->assertSuccessful();

        $this->assertSame(0, Payout::count(), 'payouts are cleared with the entries');
        $this->assertSame(1, PartnerLedgerEntry::count());
        $this->assertEqualsWithDelta(
            900.0,
            (float) PartnerLedgerEntry::first()->amount,
            0.01,
            'the rebuilt entry must equal the share frozen on the booking',
        );
    }

    public function test_a_booking_that_credits_nothing_is_counted_and_listed(): void
    {
        // A completed booking with a zero share produces no ledger entry. The
        // rebuild must say so rather than report a clean total over a row that
        // contributed nothing.
        $this->completedBooking(subtotal: 1000, commission: 1000, share: 0);
        $this->completedBooking(subtotal: 1000, commission: 100, share: 900);

        $this->artisan('ledger:reseed-staging', ['--confirm' => true])
            ->expectsOutputToContain('Re-posted 1 / 2 completed booking(s)')
            ->expectsOutputToContain('produced no entry')
            ->assertSuccessful();

        $this->assertSame(1, PartnerLedgerEntry::count());
    }

    public function test_the_payout_scenario_leaves_a_partner_above_the_floor(): void
    {
        $this->artisan('ledger:seed-payout-scenario')->assertSuccessful();

        $partner = User::where('phone', '+966599000777')->first();

        $this->assertNotNull($partner);
        $this->assertEqualsWithDelta(
            2500.0,
            (float) PartnerLedgerEntry::where('partner_user_id', $partner->id)->sum('amount'),
            0.01,
            '7500 earned less a 5000 payout — comfortably clear of the 2000 floor',
        );
        $this->assertSame(1, Payout::where('partner_user_id', $partner->id)->count());
    }

    public function test_the_payout_scenario_refuses_on_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('ledger:seed-payout-scenario')->assertFailed();

        $this->assertSame(0, Payout::count());
    }

    /* ---------- helpers ---------- */

    private function partner(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Individual');
        $u->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $u;
    }

    private function ledgerEntry(float $amount = 980): PartnerLedgerEntry
    {
        return PartnerLedgerEntry::create([
            'partner_user_id' => $this->partner()->id,
            'type'            => PartnerLedgerEntry::TYPE_EARNING,
            'amount'          => $amount,
            'balance_after'   => $amount,
            'ref_type'        => 'booking',
            'ref_id'          => '999',
            'description'     => 'stale',
            'created_at'      => now(),
        ]);
    }

    private function completedBooking(float $subtotal, float $commission, float $share): Booking
    {
        $unit = $this->partner()->units()->create([
            'unit_name' => 'وحدة', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);

        return Booking::create([
            'unit_id'           => $unit->id,
            'user_id'           => User::factory()->create()->id,
            'start_date'        => now()->subDays(10)->toDateString(),
            'end_date'          => now()->subDays(8)->toDateString(),
            'guests'            => 2,
            'subtotal'          => $subtotal,
            'commission_rate'   => 0.10,
            'commission_amount' => $commission,
            'partner_share'     => $share,
            'total_amount'      => $subtotal * 1.15,
            'status'            => Booking::STATUS_COMPLETED,
        ])->load('unit');
    }
}
