<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track the source presign upload (dashboard_uploads.id) for a gallery image
 * so the dashboard contract can echo a stable `photos[].id` the frontend
 * re-sends in photoFileIds on edit. Null for images added via the legacy Vue
 * multipart flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_images', function (Blueprint $table) {
            $table->string('file_id', 40)->nullable()->after('unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('unit_images', function (Blueprint $table) {
            $table->dropColumn('file_id');
        });
    }
};
