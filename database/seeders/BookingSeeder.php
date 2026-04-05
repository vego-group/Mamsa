<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;

class BookingSeeder extends Seeder
{
    public function run(): void
    {
        // --- ضمان وجود وحدات ومستخدم واحد على الأقل ---
        $units = Unit::query()->get(['id','price','user_id','name','code']);
        if ($units->isEmpty()) {
            $this->command->warn('لا توجد وحدات في قاعدة البيانات. أضف وحدات أولاً.');
            return;
        }

        $anyUserId = User::query()->value('id');
        if (!$anyUserId) {
            $this->command->warn('لا يوجد مستخدمون في قاعدة البيانات. أضف مستخدم واحد على الأقل.');
            return;
        }

        // --- نصنع بعض حجوزات واقعية لكل وحدة ---
        $statuses = ['new','confirmed','completed','cancelled'];

        // لتتبع التواريخ ومنع تعارضات بسيطة، بنولّد 6–10 حجوزات لكل وحدة موزعة على آخر 6 أشهر + الشهر الحالي
        foreach ($units as $unit) {
            $count = rand(6, 10);

            for ($i = 0; $i < $count; $i++) {
                // تاريخ بداية بين -6 أشهر إلى +15 يوم
                $start = Carbon::now()
                    ->subMonths(rand(0, 6))
                    ->startOfMonth()
                    ->addDays(rand(0, 27));

                // عدد الليالي 1 إلى 5
                $nights = rand(1, 5);
                $end    = (clone $start)->addDays($nights);

                // حالة واقعية بحسب الزمن:
                // - في الماضي: completed/confirmed/cancelled
                // - قريب/حالي: new/confirmed
                $statusPool = $start->isPast()
                    ? ['completed','confirmed','cancelled']
                    : ['new','confirmed'];

                $status = $statusPool[array_rand($statusPool)];

                // الحاجز: لو عندك "عميل" محدد حطيه هنا، افتراضياً أول مستخدم
                $customerId = $anyUserId;

                // المبلغ = سعر الليلة * عدد الليالي (إن وجد price)
                $pricePerNight = $unit->price ?? 300;
                $total = $pricePerNight * $nights;

                // لتقليل التداخل الشديد: نغيّر اليوم عشوائياً
                $delta = rand(-3, 3);
                if ($delta !== 0) {
                    $start->addDays($delta);
                    $end->addDays($delta);
                }

                Booking::create([
                    'unit_id'      => $unit->id,
                    'user_id'      => $customerId,
                    'status'       => $status,
                    'start_date'   => $start->format('Y-m-d'),
                    'end_date'     => $end->format('Y-m-d'),
                    'total_amount' => $total,
                    'notes'        => rand(0, 100) < 25 ? 'حجز تجريبي عبر Seeder' : null,
                ]);
            }
        }

        $this->command->info('تم توليد حجوزات تجريبية بنجاح ✅');
    }
}