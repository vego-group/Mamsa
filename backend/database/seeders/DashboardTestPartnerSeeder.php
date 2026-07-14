<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Feature;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\UnitIcalFeed;
use App\Models\User;
use App\Notifications\IcalSyncFailed;
use App\Notifications\NewBooking;
use App\Notifications\UnitReviewResult;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Idempotent seed of a rich partner for exercising the Next.js partner
 * dashboard on staging. Creates one approved individual partner with units in
 * every lifecycle state, bookings across every status (with 2% commission +
 * payments), an iCal feed, manual blocks, and notifications.
 *
 * Login (staging): phone 0512345678, OTP 111222.
 *
 *   php artisan db:seed --class=DashboardTestPartnerSeeder
 */
class DashboardTestPartnerSeeder extends Seeder
{
    public function run(): void
    {
        // Notifications fan out to mail/SMS; keep seeding self-contained.
        config(['mail.default' => 'array']);

        foreach (['Individual', 'Company', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $partner = $this->partner();
        $guest   = $this->guest();

        // One rich APPROVED unit + the other lifecycle states.
        $approved = $this->unit($partner, [
            'unit_name'         => 'شقة تجريبية — لوحة الشريك',
            'unit_type'         => 'apartment',
            'approval_status'   => 'approved',
            'price'             => 480,
            'description'       => 'شقة أنيقة في قلب الرياض للاختبار — إطلالة، تشطيب فاخر، قريبة من الخدمات.',
            'address'           => 'حي العليا، الرياض',
            'lat'               => 24.7136,
            'lng'               => 46.6753,
            'tourism_permit_no' => 'TL-TEST-0001',
            'tourism_permit_file' => 'dashboard/license_pdf/seed-license.pdf',
        ], amenities: ['واي فاي', 'تكييف', 'مطبخ', 'موقف سيارات']);

        $this->unit($partner, [
            'unit_name'       => 'استوديو تجريبي — قيد المراجعة',
            'unit_type'       => 'studio',
            'approval_status' => 'pending',
            'price'           => 260,
            'address'         => 'حي النخيل، الرياض',
            'lat'             => 24.75, 'lng' => 46.63,
            'tourism_permit_no' => 'TL-TEST-0002',
        ], amenities: ['واي فاي', 'تكييف']);

        $this->unit($partner, [
            'unit_name'       => 'مسودة تجريبية غير مكتملة',
            'unit_type'       => 'apartment',
            'approval_status' => 'draft',
            'price'           => 300,
        ]);

        $this->unit($partner, [
            'unit_name'        => 'فيلا تجريبية — مرفوضة',
            'unit_type'        => 'villa',
            'approval_status'  => 'rejected',
            'price'            => 1200,
            'address'          => 'حي الياسمين، الرياض',
            'lat'              => 24.83, 'lng' => 46.64,
            'rejection_reason' => 'الصور غير واضحة ورقم رخصة السياحة غير صالح — يرجى التصحيح وإعادة التقديم.',
        ]);

        // Bookings on the approved unit — every status, real financials.
        $this->bookings($approved, $guest);

        // One synced iCal feed on the approved unit.
        UnitIcalFeed::updateOrCreate(
            ['unit_id' => $approved->id, 'source' => 'Airbnb'],
            ['url' => 'https://www.airbnb.com/calendar/ical/seed-test.ics',
             'status' => UnitIcalFeed::STATUS_SYNCED, 'last_synced_at' => now()->subMinutes(8)],
        );

        // A manual block (maintenance) next week.
        $approved->blockedDates()->updateOrCreate(
            ['start_date' => now()->addDays(7)->toDateString(), 'source' => 'manual'],
            ['end_date' => now()->addDays(9)->toDateString(), 'note' => 'صيانة'],
        );

        $this->notifications($partner, $approved);

        $this->command?->info('Dashboard test partner ready: phone 0512345678, OTP 111222 (staging).');
    }

    private function partner(): User
    {
        $user = User::updateOrCreate(
            ['phone' => '+966512345678'],
            ['name' => 'شريك تجريبي للوحة', 'email' => 'dashboard.partner@mamsaa.test', 'is_active' => true, 'email_verified_at' => now()],
        );
        $user->syncRoles('Individual');
        $user->partnerDetail()->updateOrCreate(
            ['user_id' => $user->id],
            ['type' => 'individual', 'national_id' => '1099887766', 'status' => PartnerDetail::STATUS_APPROVED],
        );

        return $user;
    }

    private function guest(): User
    {
        $guest = User::updateOrCreate(
            ['phone' => '+966599000001'],
            ['name' => 'ضيف تجريبي', 'is_active' => true],
        );
        if (! $guest->hasRole('User')) {
            $guest->assignRole('User');
        }

        return $guest;
    }

    /** @param array<int,string> $amenities */
    private function unit(User $partner, array $attrs, array $amenities = []): Unit
    {
        $unit = $partner->units()->updateOrCreate(
            ['user_id' => $partner->id, 'unit_name' => $attrs['unit_name']],
            array_merge([
                'code'           => 'MRN'.strtoupper(Str::random(5)),
                'capacity'       => 4,
                'bedrooms'       => 2,
                'bathrooms'      => 2,
                'city'           => 'الرياض',
                'district'       => 'العليا',
                'status'         => 'available',
                'checkin_time'   => '15:00',
                'checkout_time'  => '12:00',
                'calendar_token' => Str::random(60),
            ], $attrs),
        );

        if ($amenities) {
            $ids = collect($amenities)->map(fn ($n) => Feature::firstOrCreate(['name' => $n])->id);
            $unit->features()->sync($ids);
        }

        // Ensure at least one image so the approved unit passes UI expectations.
        if ($unit->images()->count() === 0) {
            $unit->images()->create(['path' => \App\Support\Media::defaultImagePath(), 'is_main' => true]);
        }

        return $unit;
    }

    private function bookings(Unit $unit, User $guest): void
    {
        $mk = function (string $tag, string $start, string $end, string $status, array $extra = []) use ($unit, $guest) {
            $nights   = Carbon::parse($start)->diffInDays(Carbon::parse($end));
            $subtotal = $nights * (float) $unit->price;
            $cleaning = 100;
            $commission = round($subtotal * 0.02, 2);
            $total    = $subtotal + $cleaning;

            $booking = $unit->bookings()->updateOrCreate(
                ['unit_id' => $unit->id, 'user_id' => $guest->id, 'start_date' => $start],
                array_merge([
                    'end_date'          => $end,
                    'guests'            => 2,
                    'nightly_rate'      => $unit->price,
                    'subtotal'          => $subtotal,
                    'cleaning_fee'      => $cleaning,
                    'service_fee'       => 0,
                    'taxes'             => 0,
                    'commission_rate'   => 0.02,
                    'commission_amount' => $commission,
                    'total_amount'      => $total,
                    'status'            => $status,
                    'cancellation_snapshot' => [
                        'policy_key' => 'flexible', 'policy_name' => 'مرنة',
                        'checkin_at' => Carbon::parse($start.' 15:00')->toIso8601String(),
                        'tiers' => [['min_hours_before_checkin' => 168, 'refund_percent' => 100, 'label' => 'أكثر من 7 أيام']],
                    ],
                ], $extra),
            );

            // Paid payment for non-draft money flows (host-cancel refund needs it).
            $booking->payment()->updateOrCreate(
                ['booking_id' => $booking->id],
                ['amount' => $total, 'payment_method' => 'creditcard', 'payment_status' => 'paid', 'paid_at' => Carbon::parse($start)->subDays(3)],
            );

            return $booking;
        };

        // 2 upcoming confirmed, 2 past completed, 1 host-cancelled.
        $mk('c1', now()->addDays(12)->toDateString(), now()->addDays(15)->toDateString(), Booking::STATUS_CONFIRMED);
        $mk('c2', now()->addDays(25)->toDateString(), now()->addDays(28)->toDateString(), Booking::STATUS_CONFIRMED);
        $mk('p1', now()->subDays(20)->toDateString(), now()->subDays(17)->toDateString(), Booking::STATUS_COMPLETED);
        $mk('p2', now()->subDays(9)->toDateString(),  now()->subDays(6)->toDateString(),  Booking::STATUS_COMPLETED);
        $mk('x1', now()->addDays(40)->toDateString(), now()->addDays(43)->toDateString(), Booking::STATUS_CANCELLED, [
            'cancelled_at'        => now()->subDays(2),
            'cancelled_by'        => 'partner',
            'cancellation_reason' => 'الوحدة محجوزة في منصة أخرى',
        ]);
    }

    private function notifications(User $partner, Unit $unit): void
    {
        // Avoid piling up duplicates on re-seed.
        $partner->notifications()->whereIn('type', [
            NewBooking::class, UnitReviewResult::class, IcalSyncFailed::class,
        ])->delete();

        try {
            $confirmed = $unit->bookings()->where('status', Booking::STATUS_CONFIRMED)->with('unit', 'user')->first();
            if ($confirmed) {
                $partner->notify(new NewBooking($confirmed));
            }
            $partner->notify(new UnitReviewResult($unit, true, null));
            $partner->notify(new IcalSyncFailed(
                UnitIcalFeed::where('unit_id', $unit->id)->first()->loadMissing('unit'),
            ));
        } catch (\Throwable $e) {
            $this->command?->warn('Notification seed skipped: '.$e->getMessage());
        }
    }
}
