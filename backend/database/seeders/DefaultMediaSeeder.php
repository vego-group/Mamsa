<?php

namespace Database\Seeders;

use App\Models\Offer;
use App\Models\Testimonial;
use App\Models\Unit;
use App\Models\UnitImage;
use App\Support\Media;
use Illuminate\Database\Seeder;

/**
 * Points every image across the site at the bundled default/placeholder asset
 * (stored on the public storage disk). Runs last so it also normalises legacy
 * rows seeded with external (Unsplash) URLs.
 *
 * Remove this seeder from DatabaseSeeder once real photography is in place.
 */
class DefaultMediaSeeder extends Seeder
{
    public function run(): void
    {
        $path = Media::defaultImagePath();
        $url  = Media::defaultImageUrl();

        // 1. Point every existing unit image at the default asset.
        UnitImage::query()->update(['path' => $path]);

        // 2. Guarantee each unit has exactly one main image.
        Unit::query()->with('images')->each(function (Unit $unit) use ($path) {
            $first = $unit->images->first();

            if (! $first) {
                $unit->images()->create(['path' => $path, 'is_main' => true]);
                return;
            }

            $unit->images()->update(['is_main' => false]);
            $first->update(['is_main' => true]);
        });

        // 3. Offers + testimonials use the same default artwork.
        Offer::query()->update(['image_url' => $url]);
        Testimonial::query()->update(['avatar_url' => $url]);
    }
}
