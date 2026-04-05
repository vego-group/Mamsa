<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // الوحدة المحجوزة
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();

            // الحاجز (User داخل النظام) — بإمكاننا لاحقًا جعله nullable أو ربطه بجدول عملاء مستقل
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // الحالة
            $table->enum('status', ['new','confirmed','completed','cancelled'])->default('new');

            // فترات الحجز (تاريخ/وقت اختياريين حسب طبيعة مشروعك)
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // السعر النهائي للحجز (اختياري الآن — نفعّل لاحقًا التقارير)
            $table->decimal('total_amount', 10, 2)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};