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

    $table->foreignId('partner_id')
      ->constrained('partners')
      ->cascadeOnDelete();

    $table->enum('unit_type',['apartment','villa','studio']);
    $table->string('unit_name');
    $table->unsignedTinyInteger('bedrooms')->nullable();

    $table->string('city');
    $table->string('district');

    $table->decimal('lat',10,7)->nullable();
    $table->decimal('lng',10,7)->nullable();

    $table->decimal('price',10,2);
    $table->text('description');
     $table->unsignedInteger('capacity');

     $table->enum('approval_status',['pending','approved','rejected'])->default('pending');

     $table->enum('unit_status',['available','unavailable','maintenance'])
      ->default('available');

     $table->timestamps();
        });
  }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
