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
        // --- ضمان وجود وحدات ---
        $units = Unit::query()->get(['id','price','user_id','name','code']);
        if ($units->isEmpty()) {
            $this->command->warn('لا توجد وحدات في قاعدة البيانات.');
            return;
        }

        // --- هنا نستخدم المستخدم 2 فقط ---
        $anyUserId = 2;

        // --- حالات الحجوزات ---
        $statuses = ['new','confirmed','completed','cancelled'];

        foreach ($units as $unit) {
            $count = rand(6, 10);

            for ($i = 0; $i < $count; $i++) {

                $start = Carbon::now()
                    ->subMonths(rand(0, 6))
                    ->startOfMonth()
                    ->addDays(rand(0, 27));

                $nights = rand(1, 5);
                $end    = (clone $start)->addDays($nights);

                // الحالات حسب الزمن
                $statusPool = $start->isPast()
                    ? ['completed','confirmed','cancelled']
                    : ['new','confirmed'];

                $status = $statusPool[array_rand($statusPool)];

                $pricePerNight = $unit->price ?? 300;
                $total = $pricePerNight * $nights;

                $delta = rand(-3, 3);
                if ($delta !== 0) {
                    $start->addDays($delta);
                    $end->addDays($delta);
                }

                Booking::create([
                    'unit_id'      => $unit->id,
                    'user_id'      => $anyUserId,
                    'status'       => $status,
                    'start_date'   => $start->format('Y-m-d'),
                    'end_date'     => $end->format('Y-m-d'),
                    'total_amount' => $total,
                    'notes'        => rand(0, 100) < 25 ? 'حجز تجريبي عبر Seeder' : null,
                ]);
            }
        }

        $this->command->info('تم توليد حجوزات للمستخدم 2 بنجاح ✅');
    }
}