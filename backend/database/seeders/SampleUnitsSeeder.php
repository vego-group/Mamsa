<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds approved, available sample units (owned by the seeded partners) so the
 * public landing page has browsable content out of the box.
 *
 * Business rules honoured (backend gaps):
 *   #1 — every unit carries real lat/lng so the location map renders.
 *   #2 — every unit ships with images (first is the main).
 *   #3 — only supported types are seeded: apartment | studio | villa.
 */
class SampleUnitsSeeder extends Seeder
{
    public function run(): void
    {
        $individual = User::where('phone', '+966500000002')->first();
        $company    = User::where('phone', '+966500000003')->first();

        if (! $individual || ! $company) {
            return; // DevUsersSeeder must run first
        }

        $units = [
            [
                'owner' => $individual,
                'unit_name' => 'شقة مودرن بإطلالة على الواجهة',
                'unit_type' => 'apartment',
                'price' => 450, 'capacity' => 4, 'bedrooms' => 2, 'beds' => 2, 'bathrooms' => 2, 'area' => 120, 'is_featured' => true,
                'city' => 'الرياض', 'district' => 'حي الملقا',
                'lat' => 24.7743, 'lng' => 46.6086,
                'description' => 'شقة عصرية مفروشة بالكامل في قلب حي الملقا، قريبة من المطاعم والمقاهي، مثالية للعائلات الصغيرة ورجال الأعمال.',
                'features' => ['واي فاي', 'مكيف', 'مطبخ', 'موقف سيارات', 'شاشة ذكية'],
                'images' => [
                    'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=900',
                    'https://images.unsplash.com/photo-1493809842364-78817add7ffb?w=900',
                ],
            ],
            [
                'owner' => $company,
                'unit_name' => 'فيلا فاخرة مع مسبح خاص',
                'unit_type' => 'villa',
                'price' => 2400, 'capacity' => 10, 'bedrooms' => 5, 'beds' => 6, 'bathrooms' => 5, 'area' => 450, 'is_featured' => true,
                'city' => 'الرياض', 'district' => 'حي حطين',
                'lat' => 24.7580, 'lng' => 46.6250,
                'description' => 'فيلا واسعة بتصميم راقٍ، مسبح خاص وحديقة، مناسبة للمناسبات والتجمعات العائلية الكبيرة.',
                'features' => ['مسبح', 'واي فاي', 'حديقة', 'شواء', 'موقف سيارات', 'مكيف'],
                'images' => [
                    'https://images.unsplash.com/photo-1613490493576-7fde63acd811?w=900',
                    'https://images.unsplash.com/photo-1567496898669-ee935f5f647a?w=900',
                ],
            ],
            [
                'owner' => $individual,
                'unit_name' => 'استوديو أنيق قرب الكورنيش',
                'unit_type' => 'studio',
                'price' => 320, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 2, 'bathrooms' => 1, 'area' => 45,
                'city' => 'جدة', 'district' => 'حي الشاطئ',
                'lat' => 21.6000, 'lng' => 39.1050,
                'description' => 'استوديو مريح على بعد دقائق من الكورنيش، مثالي للأزواج والرحلات القصيرة.',
                'features' => ['واي فاي', 'مكيف', 'مطبخ', 'مصعد'],
                'images' => [
                    'https://images.unsplash.com/photo-1554995207-c18c203602cb?w=900',
                    'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=900',
                ],
            ],
            [
                'owner' => $company,
                'unit_name' => 'شقة عائلية واسعة',
                'unit_type' => 'apartment',
                'price' => 680, 'capacity' => 6, 'bedrooms' => 3, 'beds' => 4, 'bathrooms' => 3, 'area' => 160,
                'city' => 'مكة المكرمة', 'district' => 'العزيزية',
                'lat' => 21.4100, 'lng' => 39.8300,
                'description' => 'شقة رحبة قريبة من الحرم، تتسع لعائلة كبيرة مع جميع وسائل الراحة.',
                'features' => ['واي فاي', 'مكيف', 'مطبخ', 'غسالة', 'مصعد'],
                'images' => [
                    'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=900',
                    'https://images.unsplash.com/photo-1484154218962-a197022b5858?w=900',
                ],
            ],
            [
                'owner' => $individual,
                'unit_name' => 'فيلا هادئة بإطلالة على الدرعية',
                'unit_type' => 'villa',
                'price' => 1500, 'capacity' => 8, 'bedrooms' => 4, 'beds' => 5, 'bathrooms' => 4, 'area' => 380,
                'city' => 'الرياض', 'district' => 'الدرعية',
                'lat' => 24.7370, 'lng' => 46.5750,
                'description' => 'فيلا بتصميم تراثي عصري، أجواء هادئة بعيدة عن صخب المدينة مع جلسات خارجية.',
                'features' => ['مسبح', 'شواء', 'حديقة', 'واي فاي', 'موقف سيارات'],
                'images' => [
                    'https://images.unsplash.com/photo-1505691938895-1758d7feb511?w=900',
                    'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=900',
                ],
            ],
            [
                'owner' => $company,
                'unit_name' => 'شقة بإطلالة بحرية مباشرة',
                'unit_type' => 'apartment',
                'price' => 900, 'capacity' => 4, 'bedrooms' => 2, 'beds' => 2, 'bathrooms' => 2, 'area' => 140, 'is_featured' => true,
                'city' => 'الدمام', 'district' => 'الكورنيش',
                'lat' => 26.4360, 'lng' => 50.1030,
                'description' => 'شقة راقية بإطلالة بانورامية على البحر، تشطيبات فاخرة وموقع متميز.',
                'features' => ['واي فاي', 'مكيف', 'مطبخ', 'شاشة ذكية', 'مصعد'],
                'images' => [
                    'https://images.unsplash.com/photo-1502005229762-cf1b2da7c5d6?w=900',
                    'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=900',
                ],
            ],

            // ── More studios so the "استديو" category has depth ──────────
            [
                'owner' => $individual,
                'unit_name' => 'استوديو عصري في العليا',
                'unit_type' => 'studio',
                'price' => 380, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 2, 'bathrooms' => 1, 'area' => 50,
                'city' => 'الرياض', 'district' => 'حي العليا',
                'lat' => 24.6900, 'lng' => 46.6850,
                'description' => 'استوديو أنيق في قلب العليا، قريب من مراكز الأعمال والمطاعم، مثالي للإقامات القصيرة.',
                'features' => ['واي فاي', 'مكيف', 'مطبخ', 'مصعد', 'موقف سيارات'],
                'images' => [
                    'https://images.unsplash.com/photo-1540518614846-7eded433c457?w=900',
                    'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=900',
                ],
            ],
            [
                'owner' => $company,
                'unit_name' => 'شقة أنيقة قرب أبها',
                'unit_type' => 'apartment',
                'price' => 520, 'capacity' => 5, 'bedrooms' => 2, 'beds' => 3, 'bathrooms' => 2, 'area' => 130,
                'city' => 'أبها', 'district' => 'حي الموظفين',
                'lat' => 18.2160, 'lng' => 42.5050,
                'description' => 'شقة مريحة بأجواء الجنوب الباردة، قريبة من المتنزهات والمطلات السياحية.',
                'features' => ['واي فاي', 'مكيف', 'مطبخ', 'موقف سيارات'],
                'images' => [
                    'https://images.unsplash.com/photo-1449844908441-8829872d2607?w=900',
                    'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=900',
                ],
            ],

            // ── Luxury tier (3000–5000 ر.س/ليلة) — gives the price slider real inventory ──
            [
                'owner' => $company,
                'unit_name' => 'فيلا فاخرة مع إطلالة على البحر',
                'unit_type' => 'villa',
                'price' => 3500, 'capacity' => 12, 'bedrooms' => 6, 'beds' => 8, 'bathrooms' => 7, 'area' => 650,
                'city' => 'جدة', 'district' => 'حي الشاطئ',
                'lat' => 21.5900, 'lng' => 39.1000,
                'description' => 'فيلا فخمة على الواجهة البحرية بمسبح لا متناهٍ وشاطئ خاص، تشطيبات راقية وخدمة كاملة للمناسبات المميزة.',
                'features' => ['مسبح', 'شاطئ خاص', 'واي فاي', 'حديقة', 'شواء', 'موقف سيارات', 'مكيف'],
                'images' => [
                    'https://images.unsplash.com/photo-1613977257363-707ba9348227?w=900',
                    'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=900',
                ],
            ],
            [
                'owner' => $individual,
                'unit_name' => 'قصر ريفي للمناسبات الكبرى',
                'unit_type' => 'villa',
                'price' => 4800, 'capacity' => 30, 'bedrooms' => 10, 'beds' => 14, 'bathrooms' => 12, 'area' => 1200,
                'city' => 'الرياض', 'district' => 'الدرعية',
                'lat' => 24.7320, 'lng' => 46.5810,
                'description' => 'قصر ريفي واسع بقاعات احتفالات ومجالس فخمة وحدائق غنّاء، مثالي للأعراس والمناسبات الكبيرة.',
                'features' => ['مسبح', 'قاعة مناسبات', 'حديقة', 'شواء', 'واي فاي', 'موقف سيارات', 'مكيف'],
                'images' => [
                    'https://images.unsplash.com/photo-1568605114967-8130f3a36994?w=900',
                    'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=900',
                ],
            ],
        ];

        foreach ($units as $data) {
            /** @var User $owner */
            $owner = $data['owner'];

            // Idempotent: keyed on (owner, unit_name) so re-seeding never duplicates.
            $unit = $owner->units()->firstOrCreate(
                ['unit_name' => $data['unit_name']],
                [
                    'unit_type'           => $data['unit_type'],
                    'code'                => strtoupper(Str::random(8)),
                    'price'               => $data['price'],
                    'capacity'            => $data['capacity'],
                    'bedrooms'            => $data['bedrooms'],
                    'beds'                => $data['beds'],
                    'bathrooms'           => $data['bathrooms'],
                    'area'                => $data['area'],
                    'city'                => $data['city'],
                    'district'            => $data['district'],
                    'lat'                 => $data['lat'],
                    'lng'                 => $data['lng'],
                    'description'         => $data['description'],
                    'approval_status'     => 'approved',
                    'status'              => 'available',
                    'is_featured'         => $data['is_featured'] ?? false,
                    'cancellation_policy' => '48_hours',
                    'checkin_time'        => '15:00',
                    'checkout_time'       => '12:00',
                    'calendar_token'      => Str::random(60),
                ]
            );

            // Backfill coordinates on rows seeded before lat/lng existed (#1).
            if ($unit->lat === null || $unit->lng === null) {
                $unit->update(['lat' => $data['lat'], 'lng' => $data['lng']]);
            }

            // Backfill beds/bathrooms on rows seeded before those fields — so
            // the public unit display always has real counts to show.
            $fill = [];
            if ($unit->beds === null)      $fill['beds'] = $data['beds'];
            if ($unit->bathrooms === null) $fill['bathrooms'] = $data['bathrooms'];
            // Apply the featured flag to existing seeded rows too (editorial).
            if (($data['is_featured'] ?? false) && ! $unit->is_featured) {
                $fill['is_featured'] = true;
            }
            if ($fill) {
                $unit->update($fill);
            }

            // Only attach features/images for freshly created units.
            if (! $unit->wasRecentlyCreated) {
                continue;
            }

            // Features
            $featureIds = collect($data['features'])
                ->map(fn ($name) => Feature::firstOrCreate(['name' => $name])->id);
            $unit->features()->sync($featureIds);

            // Ship every unit with the bundled default image. (The per-unit
            // `images` URLs above are retained as documentation for when real
            // photos replace the placeholder — see DefaultMediaSeeder.)
            $unit->images()->create([
                'path'    => \App\Support\Media::defaultImagePath(),
                'is_main' => true,
            ]);
        }
    }
}
