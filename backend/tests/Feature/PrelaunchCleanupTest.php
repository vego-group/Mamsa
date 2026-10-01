<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\DashboardUpload;
use App\Models\PartnerLedgerEntry;
use App\Models\PartnerWallet;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\Refund;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The cleanup command runs once, on production, against real financial tables.
 * Its failure mode is not "it errored" — it is "it deleted a row that was not
 * ours", and that failure is silent. So every guard is tested by making it
 * fire, not by reading it.
 */
class PrelaunchCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const MANIFEST = 'prelaunch/test-manifest.json';

    private User $realOwner;

    private Unit $realUnit;

    protected function setUp(): void
    {
        parent::setUp();

        // What production already has, and must still have afterwards. The
        // platform account is created HERE, before the baseline is taken,
        // because on production it already exists — the test unit is owned by
        // it, and a fixture that mints it mid-run would read as drift that
        // production will never have.
        User::platform();
        $this->realOwner = User::factory()->create(['is_active' => true]);
        $this->realUnit = $this->unit($this->realOwner, 'شقة حقيقية منشورة');
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/'.self::MANIFEST));

        parent::tearDown();
    }

    public function test_it_refuses_without_a_manifest(): void
    {
        $this->artisan('prelaunch:cleanup', ['--manifest' => 'prelaunch/does-not-exist.json'])
            ->expectsOutputToContain('will not guess')
            ->assertExitCode(1);
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $fixture = $this->completedRun();

        $this->artisan('prelaunch:cleanup', ['--dry-run' => true, '--manifest' => self::MANIFEST])
            ->assertExitCode(0);

        $this->assertNotNull(Unit::find($fixture['unit_id']), 'a dry run deleted the unit');
        $this->assertNotNull(Booking::find($fixture['booking_id']), 'a dry run deleted the booking');
        $this->assertSame(1, Payment::count());
    }

    public function test_it_removes_exactly_the_recorded_rows_and_the_counts_return(): void
    {
        $fixture = $this->completedRun();

        $this->assertSame(2, Unit::count());
        $this->assertSame(4, User::count());   // platform + real owner + guest + partner

        $this->artisan('prelaunch:cleanup', ['--manifest' => self::MANIFEST])
            ->expectsOutputToContain('every count is back to baseline')
            ->assertExitCode(0);

        // The test rows are gone…
        $this->assertNull(Unit::find($fixture['unit_id']));
        $this->assertNull(Booking::find($fixture['booking_id']));
        $this->assertNull(User::find($fixture['guest_user_id']));
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Refund::count());
        $this->assertSame(0, PartnerLedgerEntry::count(), 'the ledger row for the test booking survived');

        // The wallet is restored, not removed: it existed before the test.
        $wallet = PartnerWallet::where('partner_user_id', User::platform()->id)->first();
        $this->assertNotNull($wallet, 'a wallet that existed before the test was deleted');
        $this->assertSame(0.0, (float) $wallet->available_balance);
        $this->assertSame(0, DashboardUpload::count());

        // …and the real ones are untouched.
        $this->assertNotNull(Unit::find($this->realUnit->id), 'the real listing was deleted');
        $this->assertNotNull(User::find($this->realOwner->id), 'a real account was deleted');
        $this->assertSame(1, Unit::count());
        $this->assertSame(2, User::count());   // platform + real owner, exactly the baseline
    }

    public function test_it_refuses_when_the_unit_is_not_the_test_unit(): void
    {
        // The manifest points at the REAL listing. A name check as an assertion
        // is the only thing standing between a stale manifest and a deleted
        // production row.
        $this->writeManifest(['unit_id' => $this->realUnit->id, 'booking_id' => null]);

        $this->artisan('prelaunch:cleanup', ['--manifest' => self::MANIFEST])
            ->expectsOutputToContain('REFUSING')
            ->assertExitCode(1);

        $this->assertNotNull(Unit::find($this->realUnit->id), 'the real listing was deleted anyway');
    }

    public function test_it_refuses_to_delete_an_account_that_existed_before_the_test(): void
    {
        $fixture = $this->completedRun();

        // The manifest claims the test created an account that the baseline
        // also lists as pre-existing. Contradiction → stop.
        $this->writeManifest([
            'unit_id' => $fixture['unit_id'],
            'booking_id' => $fixture['booking_id'],
            'guest_user_id' => $this->realOwner->id,
            'created' => ['guest_user_id' => true],
            'baseline_user_ids' => [$this->realOwner->id],
        ]);

        $this->artisan('prelaunch:cleanup', ['--manifest' => self::MANIFEST])
            ->expectsOutputToContain('existed before the test')
            ->assertExitCode(1);

        $this->assertNotNull(User::find($this->realOwner->id));
        $this->assertNotNull(Unit::find($fixture['unit_id']), 'it deleted before finishing its guards');
    }

    public function test_a_partner_account_kept_on_purpose_is_suspended_not_deleted(): void
    {
        $fixture = $this->completedRun(keepPartner: true);

        $this->artisan('prelaunch:cleanup', ['--manifest' => self::MANIFEST])
            ->expectsOutputToContain('kept and SUSPENDED')
            ->assertExitCode(0);

        $partner = User::find($fixture['partner_user_id']);

        $this->assertNotNull($partner, 'the partner account was deleted although it was to be kept');
        $this->assertFalse((bool) $partner->is_active, 'the kept account can still log in');
        // The declared, expected drift: one account more than the baseline.
        $this->assertSame(3, User::count());   // platform + real owner + the kept partner
    }

    public function test_the_dry_run_touches_exactly_the_same_tables_as_the_real_run(): void
    {
        // The reason the plan was rewritten: a hand-listed dry run drifted from
        // the purge and hid three tables. Running both and diffing the reports
        // is the only way that stays true as the purge grows.
        $this->completedRun();

        // A buffer per call: Artisan::output() accumulates across calls in one
        // test, which silently doubled every number the first time this ran.
        $dry = $this->tableCounts($this->cleanup(['--dry-run' => true]));
        $real = $this->tableCounts($this->cleanup([]));

        $this->assertNotEmpty($dry, 'the dry run reported no tables at all');
        $this->assertSame($dry, $real, 'the dry run and the real run disagree about what is removed');
    }

    public function test_it_refuses_when_the_test_unit_somehow_belongs_to_a_group(): void
    {
        $fixture = $this->completedRun();

        // A building means a group-scoped permit may cover doors that are not
        // ours. The test never makes one, so this can only be a surprise.
        Unit::where('id', $fixture['unit_id'])->update(['unit_group_id' => (string) str()->ulid()]);

        $this->artisan('prelaunch:cleanup', ['--manifest' => self::MANIFEST])
            ->expectsOutputToContain('REFUSING')
            ->assertExitCode(1);

        $this->assertNotNull(Unit::find($fixture['unit_id']), 'it deleted a unit that belongs to a group');
    }

    public function test_it_removes_audit_rows_written_against_the_unit_not_only_the_booking(): void
    {
        $fixture = $this->completedRun();

        // A reviewer approving the test listing writes this, and it names the
        // UNIT. Deleting only the booking's rows would leave it on production.
        AuditLog::create([
            'auditable_type' => Unit::class, 'auditable_id' => $fixture['unit_id'],
            'action' => 'unit.approved', 'actor_id' => $this->realOwner->id,
        ]);

        $this->assertSame(1, AuditLog::count());

        $this->artisan('prelaunch:cleanup', ['--manifest' => self::MANIFEST])->assertExitCode(0);

        $this->assertSame(0, AuditLog::count(), 'an audit row against the test unit survived');
    }

    public function test_it_deletes_the_files_on_disk_not_just_the_rows(): void
    {
        // The gap the 22/09 production cleanup had to go back and close: rows
        // gone, bytes still there and still reachable by URL.
        Storage::fake('public');

        $fixture = $this->completedRun();

        $photo = 'units/'.$fixture['unit_id'].'/p.jpg';
        $doc = 'dashboard/license_pdf/x.pdf';
        Storage::disk('public')->put($photo, 'jpeg-bytes');
        Storage::disk('public')->put($doc, 'pdf-bytes');

        $this->artisan('prelaunch:cleanup', ['--manifest' => self::MANIFEST])
            ->expectsOutputToContain('files deleted')
            ->assertExitCode(0);

        Storage::disk('public')->assertMissing($photo);
        Storage::disk('public')->assertMissing($doc);
    }

    public function test_a_file_another_listing_still_uses_is_left_alone(): void
    {
        Storage::fake('public');

        $fixture = $this->completedRun();

        // The real listing shares the path. Our rows going away must not take
        // somebody else's photo with them.
        $shared = 'units/shared/p.jpg';
        Storage::disk('public')->put($shared, 'jpeg-bytes');
        DB::table('unit_images')->where('unit_id', $fixture['unit_id'])->update(['path' => $shared]);
        $this->realUnit->images()->create(['path' => $shared, 'is_main' => false, 'sort_order' => 2]);

        $this->artisan('prelaunch:cleanup', ['--manifest' => self::MANIFEST])->assertExitCode(0);

        Storage::disk('public')->assertExists($shared);
    }

    /** @param array<string, mixed> $options */
    private function cleanup(array $options): string
    {
        $buffer = new BufferedOutput;

        Artisan::call('prelaunch:cleanup', $options + ['--manifest' => self::MANIFEST], $buffer);

        return $buffer->fetch();
    }

    /**
     * The "table  count" lines from a report, as a map. Ignores the totals and
     * the notes so the two runs compare on substance.
     *
     * @return array<string, int>
     */
    private function tableCounts(string $output): array
    {
        $rows = [];

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s{2}([a-z_]+)\s+(\d+)$/', rtrim($line), $hit)) {
                $rows[$hit[1]] = (int) $hit[2];
            }
        }

        return $rows;
    }

    /* ---------- fixtures ---------- */

    /**
     * A finished test run: the rows it would have written, and the manifest it
     * would have left behind.
     *
     * Named `completedRun` and not `testRun` on purpose: a method whose name
     * starts with "test" is a TEST to PHPUnit and to pint, which renames it.
     *
     * @return array<string, mixed>
     */
    private function completedRun(bool $keepPartner = false): array
    {
        $baselineUsers = User::pluck('id')->all();
        $baseline = [
            'units' => Unit::count(),
            'bookings' => Booking::count(),
            'payments' => Payment::count(),
            'refunds' => Refund::count(),
            'permits' => Permit::count(),
            'users' => User::count(),
            'notifications' => DB::table('notifications')->count(),
            'dashboard_uploads' => DashboardUpload::count(),
            'user_ids' => $baselineUsers,
        ];

        $guest = User::factory()->create(['is_active' => true]);
        $partner = User::factory()->create(['is_active' => true]);

        $unit = $this->unit(User::platform(), 'اختبار ما قبل الإطلاق — لا تحجز (PRELAUNCH-TEST)', 1.15);

        $booking = Booking::create([
            'unit_id' => $unit->id, 'user_id' => $guest->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'guests' => 2, 'total_amount' => 1.15, 'status' => 'confirmed',
            'commission_rate' => 0.10, 'partner_share' => 0.90,
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id, 'amount' => 1.15,
            'payment_method' => 'creditcard', 'payment_status' => 'paid', 'paid_at' => now(),
        ]);

        Refund::create([
            'booking_id' => $booking->id, 'payment_id' => $payment->id,
            'type' => 'refund', 'amount' => 1.15, 'refund_percent' => 100, 'status' => 'succeeded',
        ]);

        // A wallet that existed BEFORE the test, with a balance the booking
        // then moved. Cleanup must restore the number and keep the row.
        PartnerWallet::create([
            'partner_user_id' => User::platform()->id,
            'available_balance' => 0, 'pending_balance' => 0,
        ]);

        // A ledger row for the booking. It is referenced by ref_type/ref_id, and
        // the command used to look for a booking_id column that does not exist
        // — which SQLite accepted silently. Creating one makes the query real.
        PartnerLedgerEntry::create([
            'partner_user_id' => User::platform()->id, 'type' => 'earning',
            'amount' => 0.90, 'balance_after' => 0.90,
            'ref_type' => 'booking', 'ref_id' => (string) $booking->id,
            'description' => 'prelaunch test', 'created_at' => now(),
        ]);

        $upload = DashboardUpload::create([
            'id' => 'file_'.strtolower((string) str()->ulid()), 'user_id' => $partner->id,
            'kind' => 'license_pdf', 'original_name' => 'p.pdf', 'mime' => 'application/pdf',
            'size' => 10, 'status' => 'stored', 'path' => 'dashboard/license_pdf/x.pdf',
        ]);

        $fixture = [
            'unit_id' => $unit->id,
            'booking_id' => $booking->id,
            'guest_user_id' => $guest->id,
            'partner_user_id' => $partner->id,
            'upload_ids' => [$upload->id],
            'baseline' => $baseline,
            'keep_partner_suspended' => $keepPartner,
        ];

        $this->writeManifest([
            'unit_id' => $unit->id,
            'booking_id' => $booking->id,
            'guest_user_id' => $guest->id,
            'partner_user_id' => $partner->id,
            'created' => ['guest_user_id' => true, 'partner_user_id' => true],
            'upload_ids' => [$upload->id],
            'baseline_user_ids' => $baselineUsers,
            'baseline' => $baseline,
            'keep_partner_suspended' => $keepPartner,
            'wallet' => [
                'user_id' => User::platform()->id, 'existed' => true,
                'available_balance' => 0, 'pending_balance' => 0,
            ],
        ]);

        return $fixture;
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(array $manifest): void
    {
        $baseline = $manifest['baseline'] ?? [
            'units' => Unit::count(), 'bookings' => Booking::count(),
            'payments' => Payment::count(), 'refunds' => Refund::count(),
            'permits' => Permit::count(), 'users' => User::count(),
            'notifications' => DB::table('notifications')->count(),
            'dashboard_uploads' => DashboardUpload::count(),
        ];
        $baseline['user_ids'] = $manifest['baseline_user_ids'] ?? ($baseline['user_ids'] ?? []);

        $path = storage_path('app/'.self::MANIFEST);
        @mkdir(dirname($path), 0o775, true);

        file_put_contents($path, json_encode(
            array_merge(['created_at' => now()->toIso8601String()], $manifest, ['baseline' => $baseline]),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        ));
    }

    private function unit(User $owner, string $name, float $price = 500): Unit
    {
        $unit = $owner->units()->create([
            'unit_name' => $name, 'unit_type' => 'apartment',
            'code' => 'PL'.fake()->unique()->numerify('######'),
            'price' => $price, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 2, 'bathrooms' => 1,
            'city' => 'الرياض', 'district' => 'الملقا', 'address' => 'حي الملقا',
            'lat' => 24.7136, 'lng' => 46.6753,
            'description' => str_repeat('وصف كافٍ للوحدة. ', 5),
            'approval_status' => 'approved', 'status' => 'available',
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
        ]);

        $unit->images()->create(['path' => 'units/'.$unit->id.'/p.jpg', 'is_main' => true, 'sort_order' => 1]);

        return $unit->fresh();
    }
}
