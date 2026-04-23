<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_details', function (Blueprint $table) {
            $table->id(); // bigint unsigned auto increment

            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->enum('type', ['individual', 'company']);

            $table->string('national_id')->nullable();
            $table->string('cr_number')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_details');
    }
};