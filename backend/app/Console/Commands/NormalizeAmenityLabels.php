<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Feature;
use App\Support\Dashboard\Maps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off data hygiene (frontend request 2026-07-21): merge spelling variants
 * of amenity labels into their canonical form (مكيف → تكييف, تلفزيون ذكي →
 * شاشة ذكية …) so stored labels match the published vocabulary. Idempotent —
 * re-running finds nothing left to merge.
 */
class NormalizeAmenityLabels extends Command
{
    protected $signature = 'amenities:normalize-labels';

    protected $description = 'Merge amenity spelling variants into their canonical labels';

    public function handle(): int
    {
        $merged = 0;

        foreach (Maps::AMENITY_VARIANTS as $variant => $canonical) {
            $variantFeature = Feature::where('name', $variant)->first();
            if (! $variantFeature) {
                continue;
            }

            $canonicalFeature = Feature::firstOrCreate(['name' => $canonical]);

            DB::transaction(function () use ($variantFeature, $canonicalFeature) {
                // Re-point each unit from the variant to the canonical feature,
                // skipping units that already carry the canonical one.
                foreach ($variantFeature->units()->pluck('units.id') as $unitId) {
                    $canonicalFeature->units()->syncWithoutDetaching([$unitId]);
                }
                $variantFeature->units()->detach();
                $variantFeature->delete();
            });

            $this->line("merged “{$variant}” → “{$canonical}”");
            $merged++;
        }

        $this->info("amenity labels normalized: {$merged} variant(s) merged");

        return self::SUCCESS;
    }
}
