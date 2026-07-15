<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard contract allows partial DRAFT unit bodies (§4: "drafts don't
 * validate required fields") — but these columns were NOT NULL, so saving an
 * early draft without a price/capacity/name/type 500'd. Make them nullable;
 * they're enforced at POST /units/:id/submit, and drafts are never public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->string('unit_name')->nullable()->change();
            $table->string('unit_type', 50)->nullable()->change();
            $table->decimal('price', 10, 2)->nullable()->change();
            $table->unsignedTinyInteger('capacity')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->string('unit_name')->nullable(false)->change();
            $table->string('unit_type', 50)->nullable(false)->change();
            $table->decimal('price', 10, 2)->nullable(false)->change();
            $table->unsignedTinyInteger('capacity')->nullable(false)->change();
        });
    }
};
