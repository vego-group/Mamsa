<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill lat/lng for legacy units created before coordinates were captured
 * (backend gaps #1). The location map hides any unit with null/0 coordinates,
 * so every unit gets a real city-centroid position with a small deterministic
 * jitter (derived from the id) to keep map pins from stacking.
 */
return new class extends Migration
{
    /** City centroids (lat, lng). Unknown cities fall back to Riyadh. */
    private const CITY_COORDS = [
        'الرياض'         => [24.7136, 46.6753],
        'جدة'            => [21.5433, 39.1728],
        'مكة المكرمة'    => [21.3891, 39.8579],
        'المدينة المنورة' => [24.5247, 39.5692],
        'الدمام'         => [26.4207, 50.0888],
        'الطائف'         => [21.2703, 40.4158],
        'أبها'           => [18.2465, 42.5117],
    ];

    public function up(): void
    {
        $units = DB::table('units')
            ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng'))
            ->get(['id', 'city']);

        foreach ($units as $unit) {
            [$lat, $lng] = self::CITY_COORDS[$unit->city] ?? self::CITY_COORDS['الرياض'];

            // Deterministic jitter (~±0.02°) so co-located units don't overlap.
            $lat += (($unit->id % 20) - 10) * 0.002;
            $lng += ((($unit->id * 7) % 20) - 10) * 0.002;

            DB::table('units')->where('id', $unit->id)->update([
                'lat' => round($lat, 7),
                'lng' => round($lng, 7),
            ]);
        }
    }

    public function down(): void
    {
        // Backfill only — nothing to reverse.
    }
};
