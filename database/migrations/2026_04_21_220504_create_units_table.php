<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id(); // bigint unsigned auto increment

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('unit_name')->nullable();
            $table->string('unit_type', 100)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->integer('capacity')->nullable();
            $table->integer('bedrooms')->nullable();

            $table->string('city', 100)->nullable();
            $table->string('district', 150)->nullable();

            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->text('description');

            $table->string('tourism_permit_no')->nullable();
            $table->string('tourism_permit_file')->nullable();
            $table->string('company_license_no')->nullable();

            $table->enum('approval_status', ['pending', 'approved', 'rejected'])
                ->default('pending');

            $table->text('rejection_reason')->nullable();

            $table->enum('status', ['available', 'unavailable'])
                ->default('available');

            $table->enum('cancellation_policy', ['no_cancel', '48_hours'])
                ->default('no_cancel');

            $table->time('checkin_time')->nullable();
            $table->time('checkout_time')->nullable();

            $table->string('calendar_token')->nullable();
            $table->string('calendar_external_url')->nullable();

            $table->string('code', 50)->nullable()->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};