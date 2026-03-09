<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')    // مالك الوحدة (المشرف الذي أدخلها)
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->string('name');         // اسم الوحدة
            $table->string('code')->unique(); // كود / معرف فريد
            $table->text('description')->nullable(); // وصف
            $table->enum('status', ['available','unavailable','reserved'])
                  ->default('available');   // حالة الوحدة
            $table->decimal('price', 10, 2)->nullable(); // سعر قياسي (اختياري)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};