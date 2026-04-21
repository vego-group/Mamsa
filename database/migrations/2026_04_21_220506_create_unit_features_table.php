<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_features', function (Blueprint $table) {
            $table->foreignId('unit_id')
                ->constrained('units')
                ->cascadeOnDelete();

            $table->foreignId('feature_id')
                ->constrained('features')
                ->cascadeOnDelete();

            // Composite Primary Key
            $table->primary(['unit_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_features');
    }
};
