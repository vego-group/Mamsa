<?php

use App\Support\Media;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair unit images whose `path` was written blank (e.g. seeded on prod while
 * the config cache was stale, producing "<base>/storage" URLs). Points blank
 * paths at the bundled default asset and guarantees one main image per unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('unit_images')
            ->where(fn ($q) => $q->whereNull('path')->orWhere('path', ''))
            ->update(['path' => Media::DEFAULT_IMAGE_PATH]);

        // Ensure every unit that has images has exactly one main image.
        $unitIds = DB::table('unit_images')->distinct()->pluck('unit_id');

        foreach ($unitIds as $unitId) {
            $hasMain = DB::table('unit_images')
                ->where('unit_id', $unitId)
                ->where('is_main', true)
                ->exists();

            if (! $hasMain) {
                $firstId = DB::table('unit_images')
                    ->where('unit_id', $unitId)
                    ->orderBy('id')
                    ->value('id');

                if ($firstId) {
                    DB::table('unit_images')->where('id', $firstId)->update(['is_main' => true]);
                }
            }
        }
    }

    public function down(): void
    {
        // Data repair only — nothing to reverse.
    }
};
