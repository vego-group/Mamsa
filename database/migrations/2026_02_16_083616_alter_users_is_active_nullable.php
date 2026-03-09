<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // نجعل is_active تقبل NULL (تعني pending)
            $table->boolean('is_active')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // نعيدها not null مع افتراضي true
            $table->boolean('is_active')->default(true)->nullable(false)->change();
        });
    }
};