<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reviews')) return;
        Schema::create('reviews', function (Blueprint $table) {
            $table->id(); // bigint unsigned auto increment

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('unit_id')
                ->constrained('units')
                ->cascadeOnDelete();

            $table->integer('rating');

            $table->text('comment')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};