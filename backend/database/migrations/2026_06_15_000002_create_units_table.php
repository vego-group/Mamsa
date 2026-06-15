<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('unit_name');
            $table->string('unit_type', 50);   // apartment | studio | villa
            $table->string('code', 50)->nullable()->unique();

            $table->decimal('price', 10, 2);
            $table->unsignedTinyInteger('capacity');
            $table->unsignedTinyInteger('bedrooms')->default(1);

            $table->string('city', 100)->nullable();
            $table->string('district', 150)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->text('description')->nullable();

            // License / permit fields (FR-061)
            $table->string('tourism_permit_no')->nullable();
            $table->string('tourism_permit_file')->nullable();   // storage path
            $table->string('company_license_no')->nullable();    // for companies

            // Lifecycle (FR-065/066): draft → pending → approved | rejected
            $table->enum('approval_status', ['draft', 'pending', 'approved', 'rejected'])
                  ->default('draft');
            $table->text('rejection_reason')->nullable();

            $table->enum('status', ['available', 'unavailable'])->default('available');

            // Policies
            $table->enum('cancellation_policy', ['no_cancel', '48_hours'])->default('no_cancel');
            $table->time('checkin_time')->nullable();
            $table->time('checkout_time')->nullable();

            $table->string('calendar_token', 60)->nullable()->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
