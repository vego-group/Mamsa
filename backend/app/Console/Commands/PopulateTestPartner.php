<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Feature;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use App\Support\Media;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Populate the test partner with sample UNITS only — one in every lifecycle
 * state (draft / pending / approved / rejected). Deliberately creates NO
 * bookings or payments, so it can run against production without touching the
 * admin's revenue/analytics, and the "approved" unit is kept `unavailable` so it
 * never appears in consumer search (which requires approved AND available).
 *
 * Idempotent (keyed on unit_name); safe to re-run.
 *
 *   php artisan test-partner:populate            # uses TEST_PARTNER_PHONE
 *   php artisan test-partner:populate --phone=+9665XXXXXXXX
 */
class PopulateTestPartner extends Command
{
    protected $signature = 'test-partner:populate {--phone= : Partner phone (defaults to config test_mode.accounts.partner)}';

    protected $description = 'Give the test partner sample units in every lifecycle state (no bookings; prod-safe)';

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
        foreach ($units as $spec) {
            $unit = $this->upsertUnit($partner, $spec);
            $rows[] = [
                $unit->unit_name,
                $unit->unit_type,
                $unit->approval_status,
                $unit->status,
                $this->isPublic($unit) ? '⚠️ YES' : 'no',
            ];
        }

        $this->newLine();
        $this->line("  Partner: {$partner->name} ({$partner->phone})");
        $this->table(['Unit', 'Type', 'Approval', 'Status', 'Public on mamsaa.com?'], $rows);
        $this->info('  Done — '.count($rows).' units. No bookings/revenue created; nothing exposed to consumers.');

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

    /** Mirrors the consumer visibility filter (UnitController): approved AND available. */
    private function isPublic(Unit $unit): bool
    {
        return $unit->approval_status === 'approved' && $unit->status === 'available';
    }
}
