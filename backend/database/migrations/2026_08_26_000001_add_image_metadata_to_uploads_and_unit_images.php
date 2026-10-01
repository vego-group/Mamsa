<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Image dimensions + derivative paths.
 *
 * Stored in both places on purpose, mirroring how `path` already works:
 * `dashboard_uploads` owns the file and is where processing writes, while
 * `unit_images` is the read-hot path the storefront hits on every listing and
 * must not need a join. Legacy image rows have no upload behind them at all.
 *
 * `width`/`height` are worth having even with no derivatives: the client
 * reserves the box before the bytes arrive, which is what stops the page
 * jumping as photos load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_uploads', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable()->after('size');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->json('variants')->nullable()->after('height');
        });

        Schema::table('unit_images', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable()->after('path');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->json('variants')->nullable()->after('height');
            // Display order was previously whatever auto-increment produced.
            // That happens to match insertion order today, which is not the
            // same as being ordered.
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_main');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_uploads', function (Blueprint $table) {
            $table->dropColumn(['width', 'height', 'variants']);
        });

        Schema::table('unit_images', function (Blueprint $table) {
            $table->dropColumn(['width', 'height', 'variants', 'sort_order']);
        });
    }
};
