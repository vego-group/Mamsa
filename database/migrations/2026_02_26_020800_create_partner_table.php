<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
           $table->id();
           $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

           $table->enum('type',['individual','company']);
           $table->string('tourism_permit_no');
           $table->string('national_id')->nullable();
           $table->string('company_license_no')->nullable();
           $table->string('cr_number')->nullable();

           $table->enum('verification_status',['pending','approved','rejected'])->default('pending');
           $table->timestamp('verified_at')->nullable();
           $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};