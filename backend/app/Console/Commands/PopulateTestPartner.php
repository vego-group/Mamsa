<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Feature;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\UnitIcalFeed;
use App\Models\User;
use App\Support\Media;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Populate the test partner with sample data for the dashboard.
 *
 * Default: UNITS only (one per lifecycle state), prod-safe — no bookings, and the
 * approved unit is kept `unavailable` so it never appears in consumer search.
 *
 * With --rich: also adds bookings (confirmed/completed/cancelled) + paid payments
 * + an iCal feed + a manual block, so Overview revenue, Bookings and Calendar
 * populate. NOTE: the bookings count toward the admin's platform-wide revenue /
 * analytics until removed with `test-partner:purge`. No notifications are sent
 * (rows are inserted directly — no observer/SMS/email fires).
 *
 * Idempotent (keyed on unit_name / booking start_date); safe to re-run.
 *
 *   php artisan test-partner:populate
 *   php artisan test-partner:populate --rich
 *   php artisan test-partner:populate --phone=+9665XXXXXXXX --rich
 */
class PopulateTestPartner extends Command
{
    protected $signature = 'test-partner:populate
        {--phone= : Partner phone (defaults to config test_mode.accounts.partner)}
        {--rich : Also add bookings/payments/iCal/block (touches admin analytics; purge to undo)}';

    protected $description = 'Give the test partner sample units (and, with --rich, bookings/revenue)';

    public function handle(): int
    {
        $raw = (string) ($this->option('phone') ?: config('test_mode.accounts.partner'));

        if (trim($raw) === '') {
            $this->error('No partner phone: pass --phone or set TEST_PARTNER_PHONE.');

            return self::FAILURE;
        }

        $partner = $this->partner(PhoneNumber::toE164Ksa($raw));

        $units = [
            [
                'unit_name' => 'شقة تجريبية — معتمدة (غير مُدرجة للعملاء)',
                'unit_type' => 'apartment',
                'approval_status' => 'approved',
                'status' => 'unavailable', // approved but NOT available → invisible to consumers
                'price' => 480,
                'description' => 'شقة أنيقة للاختبار — معتمدة في لوحة الشريك، لكنها غير مُدرجة للعملاء.',
                'city' => 'الرياض', 'district' => 'العليا', 'address' => 'حي العليا، الرياض',
                'lat' => 24.7136, 'lng' => 46.6753, 'tourism_permit_no' => 'TL-TEST-0001',
                'amenities' => ['واي فاي', 'تكييف', 'مطبخ', 'موقف سيارات'],
            ],
            [
                'unit_name' => 'استوديو تجريبي — قيد المراجعة',
                'unit_type' => 'studio',
                'approval_status' => 'pending',
                'status' => 'unavailable',
                'price' => 260,
                'city' => 'الرياض', 'district' => 'النخيل', 'address' => 'حي النخيل، الرياض',
                'lat' => 24.75, 'lng' => 46.63, 'tourism_permit_no' => 'TL-TEST-0002',
                'amenities' => ['واي فاي', 'تكييف'],
            ],
            [
                'unit_name' => 'مسودة تجريبية غير مكتملة',
                'unit_type' => 'apartment',
                'approval_status' => 'draft',
                'status' => 'unavailable',
                'price' => 300,
                'city' => 'الرياض', 'district' => 'الملقا',
                'amenities' => [],
            ],
            [
                'unit_name' => 'فيلا تجريبية — مرفوضة',
                'unit_type' => 'villa',
                'approval_status' => 'rejected',
                'status' => 'unavailable',
                'price' => 1200,
                'city' => 'الرياض', 'district' => 'الياسمين', 'address' => 'حي الياسمين، الرياض',
                'lat' => 24.83, 'lng' => 46.64,
                'rejection_reason' => 'الصور غير واضحة ورقم رخصة السياحة غير صالح — يرجى التصحيح وإعادة التقديم.',
                'amenities' => [],
            ],
        ];

        $rows = [];
        $approved = null;
        foreach ($units as $spec) {
            $unit = $this->upsertUnit($partner, $spec);
            if ($unit->approval_status === 'approved') {
                $approved = $unit;
            }
            $rows[] = [$unit->unit_name, $unit->unit_type, $unit->approval_status, $unit->status, $this->isPublic($unit) ? '⚠️ YES' : 'no'];
        }

        $this->newLine();
        $this->line("  Partner: {$partner->name} ({$partner->phone})");
        $this->table(['Unit', 'Type', 'Approval', 'Status', 'Public on mamsaa.com?'], $rows);

        if ($this->option('rich') && $approved) {
            $n = $this->richData($approved);
            $this->info("  Rich data: {$n} bookings (+ paid payments), 1 iCal feed, 1 manual block on the approved unit.");
            $this->warn('  These bookings COUNT toward admin platform revenue/analytics until `test-partner:purge`.');
        } else {
            $this->info('  Units only — no bookings/revenue; nothing exposed to consumers.');
        }

        return self::SUCCESS;
    }

    private function partner(string $phone): User
    {
        foreach (['Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $partner = User::firstOrCreate(['phone' => $phone], ['name' => 'شريك تجريبي', 'is_active' => true]);
        $partner->forceFill(['is_active' => true])->save();

        if (! $partner->isPartner()) {
            $partner->assignRole('Individual');
        }

        $partner->partnerDetail()->updateOrCreate(
            ['user_id' => $partner->id],
            ['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED],
        );

        return $partner;
    }

    private function upsertUnit(User $partner, array $spec): Unit
    {
        $amenities = $spec['amenities'] ?? [];
        unset($spec['amenities']);

        $unit = $partner->units()->updateOrCreate(
            ['unit_name' => $spec['unit_name']],
            array_merge([
                'capacity' => 4,
                'bedrooms' => 2,
                'bathrooms' => 2,
                'checkin_time' => '15:00',
                'checkout_time' => '12:00',
            ], $spec),
        );

        // Stable unique fields — set once, never regenerated on re-run.
        if (blank($unit->code) || blank($unit->calendar_token)) {
            $unit->update([
                'code' => $unit->code ?: 'MRN'.strtoupper(Str::random(5)),
                'calendar_token' => $unit->calendar_token ?: Str::random(60),
            ]);
        }

        if ($amenities) {
            $ids = collect($amenities)->map(fn ($n) => Feature::firstOrCreate(['name' => $n])->id);
            $unit->features()->sync($ids);
        }

        if ($unit->images()->count() === 0) {
            $unit->images()->create(['path' => Media::defaultImagePath(), 'is_main' => true]);
        }

        return $unit;
    }

    /**
     * Bookings across every status (real financials + paid payments) + an iCal
     * feed + a manual block, on the approved unit. Returns the booking count.
     * Rows are inserted directly (no payment flow), so no notification/SMS fires.
     */
    private function richData(Unit $approved): int
    {
        $guest = User::firstOrCreate(['phone' => '+966599000001'], ['name' => 'ضيف تجريبي', 'is_active' => true]);
        if (! $guest->roles()->exists()) {
            $guest->assignRole('User');
        }

        $mk = function (string $start, string $end, string $status, array $extra = []) use ($approved, $guest): void {
            $nights = Carbon::parse($start)->diffInDays(Carbon::parse($end));
            $subtotal = $nights * (float) $approved->price;
            $cleaning = 100;
            $total = $subtotal + $cleaning;
            $rate = (float) config('booking.commission_rate');

            $booking = $approved->bookings()->updateOrCreate(
                ['unit_id' => $approved->id, 'user_id' => $guest->id, 'start_date' => $start],
                array_merge([
                    'end_date' => $end,
                    'guests' => 2,
                    'nightly_rate' => $approved->price,
                    'subtotal' => $subtotal,
                    'cleaning_fee' => $cleaning,
                    'service_fee' => 0,
                    'taxes' => 0,
                    // Seeded at the LIVE rate so test data behaves like a real
                    // booking taken today, not like a historical one.
                    'commission_rate' => $rate,
                    'commission_amount' => round($subtotal * $rate, 2),
                    'total_amount' => $total,
                    'status' => $status,
                    'cancellation_snapshot' => [
                        'policy_key' => 'flexible', 'policy_name' => 'مرنة',
                        'checkin_at' => Carbon::parse($start.' 15:00')->toIso8601String(),
                        'tiers' => [['min_hours_before_checkin' => 168, 'refund_percent' => 100, 'label' => 'أكثر من 7 أيام']],
                    ],
                ], $extra),
            );

            $booking->payment()->updateOrCreate(
                ['booking_id' => $booking->id],
                ['amount' => $total, 'payment_method' => 'creditcard', 'payment_status' => 'paid', 'paid_at' => Carbon::parse($start)->subDays(3)],
            );
        };

        // 2 upcoming confirmed, 2 past completed, 1 host-cancelled.
        $mk(now()->addDays(12)->toDateString(), now()->addDays(15)->toDateString(), Booking::STATUS_CONFIRMED);
        $mk(now()->addDays(25)->toDateString(), now()->addDays(28)->toDateString(), Booking::STATUS_CONFIRMED);
        $mk(now()->subDays(20)->toDateString(), now()->subDays(17)->toDateString(), Booking::STATUS_COMPLETED);
        $mk(now()->subDays(9)->toDateString(), now()->subDays(6)->toDateString(), Booking::STATUS_COMPLETED);
        $mk(now()->addDays(40)->toDateString(), now()->addDays(43)->toDateString(), Booking::STATUS_CANCELLED, [
            'cancelled_at' => now()->subDays(2),
            'cancelled_by' => 'partner',
            'cancellation_reason' => 'الوحدة محجوزة في منصة أخرى',
        ]);

        UnitIcalFeed::updateOrCreate(
            ['unit_id' => $approved->id, 'source' => 'Airbnb'],
            ['url' => 'https://www.airbnb.com/calendar/ical/seed-test.ics', 'status' => UnitIcalFeed::STATUS_SYNCED, 'last_synced_at' => now()->subMinutes(8)],
        );

        $approved->blockedDates()->updateOrCreate(
            ['start_date' => now()->addDays(7)->toDateString(), 'source' => 'manual'],
            ['end_date' => now()->addDays(9)->toDateString(), 'note' => 'صيانة'],
        );

        return $approved->bookings()->count();
    }

    /** Mirrors the consumer visibility filter (UnitController): approved AND available. */
    private function isPublic(Unit $unit): bool
    {
        return $unit->approval_status === 'approved' && $unit->status === 'available';
    }
}
