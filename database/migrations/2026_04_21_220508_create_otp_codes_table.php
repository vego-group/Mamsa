<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id(); // bigint unsigned auto increment

            $table->string('phone', 20)->nullable();
            $table->string('code', 10)->nullable();

            $table->integer('attempts')->default(0);

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();

            $table->string('purpose', 50)->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};