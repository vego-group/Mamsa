<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds approved, available sample units (owned by the seeded partners)
 * so the public landing page has browsable content out of the box.
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
                'price' => 450, 'capacity' => 4, 'bedrooms' => 2,
                'city' => 'الرياض', 'district' => 'حي الملقا',
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
                'price' => 2400, 'capacity' => 10, 'bedrooms' => 5,
                'city' => 'الرياض', 'district' => 'حي حطين',
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
                'price' => 320, 'capacity' => 2, 'bedrooms' => 1,
                'city' => 'جدة', 'district' => 'حي الشاطئ',
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
                'price' => 680, 'capacity' => 6, 'bedrooms' => 3,
                'city' => 'مكة المكرمة', 'district' => 'العزيزية',
                'description' => 'شقة رحبة قريبة من الحرم، تتسع لعائلة كبيرة مع جميع وسائل الراحة.',
                'features' => ['واي فاي', 'مكيف', 'مطبخ', 'غسالة', 'مصعد'],
                'images' => [
                    'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=900',
                    'https://images.unsplash.com/photo-1484154218962-a197022b5858?w=900',
                ],
            ],
            [
                'owner' => $individual,
                'unit_name' => 'شاليه هادئ بإطلالة صحراوية',
                'unit_type' => 'villa',
                'price' => 1500, 'capacity' => 8, 'bedrooms' => 4,
                'city' => 'الرياض', 'district' => 'الدرعية',
                'description' => 'شاليه بتصميم تراثي عصري، أجواء هادئة بعيدة عن صخب المدينة مع جلسات خارجية.',
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
                'price' => 900, 'capacity' => 4, 'bedrooms' => 2,
                'city' => 'الدمام', 'district' => 'الكورنيش',
                'description' => 'شقة راقية بإطلالة بانورامية على البحر، تشطيبات فاخرة وموقع متميز.',
                'features' => ['واي فاي', 'مكيف', 'مطبخ', 'شاشة ذكية', 'مصعد'],
                'images' => [
                    'https://images.unsplash.com/photo-1502005229762-cf1b2da7c5d6?w=900',
                    'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=900',
                ],
            ],

            // ── Chalets (شاليهات) ──────────────────────────────────────
            [
                'owner' => $individual,
                'unit_name' => 'شاليه راقٍ مع مسبح وجلسات خارجية',
                'unit_type' => 'chalet',
                'price' => 1200, 'capacity' => 8, 'bedrooms' => 3,
                'city' => 'الطائف', 'district' => 'حي الشفا',
                'description' => 'شاليه عصري بمسبح خاص وجلسات خارجية مظللة، أجواء عائلية هادئة وإطلالة على المرتفعات.',
                'features' => ['مسبح', 'شواء', 'واي فاي', 'موقف سيارات', 'مكيف', 'حديقة'],
                'images' => [
                    'https://images.unsplash.com/photo-1542718610-a1d656d1884c?w=900',
                    'https://images.unsplash.com/photo-1571003123894-1f0594d2b5d9?w=900',
                ],
            ],
            [
                'owner' => $company,
                'unit_name' => 'شاليه عائلي بمسبح مدفأ',
                'unit_type' => 'chalet',
                'price' => 950, 'capacity' => 6, 'bedrooms' => 2,
                'city' => 'أبها', 'district' => 'حي الموظفين',
                'description' => 'شاليه مريح بمسبح مدفأ ومرافق متكاملة، مثالي للعطلات القصيرة في أجواء الجنوب الباردة.',
                'features' => ['مسبح', 'مكيف', 'واي فاي', 'مطبخ', 'موقف سيارات'],
                'images' => [
                    'https://images.unsplash.com/photo-1449844908441-8829872d2607?w=900',
                    'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=900',
                ],
            ],

            // ── Rest houses (استراحات) ─────────────────────────────────
            [
                'owner' => $individual,
                'unit_name' => 'استراحة واسعة بمسبح وملعب',
                'unit_type' => 'rest',
                'price' => 800, 'capacity' => 20, 'bedrooms' => 2,
                'city' => 'الرياض', 'district' => 'حي العمارية',
                'description' => 'استراحة كبيرة بمسبح وملعب كرة قدم ومجالس واسعة، تتسع للتجمعات والمناسبات.',
                'features' => ['مسبح', 'ملعب', 'شواء', 'واي فاي', 'موقف سيارات', 'مكيف'],
                'images' => [
                    'https://images.unsplash.com/photo-1568605114967-8130f3a36994?w=900',
                    'https://images.unsplash.com/photo-1505691938895-1758d7feb511?w=900',
                ],
            ],

            // ── Resorts (منتجعات) ──────────────────────────────────────
            [
                'owner' => $company,
                'unit_name' => 'منتجع صحي بإطلالة جبلية',
                'unit_type' => 'resort',
                'price' => 1800, 'capacity' => 4, 'bedrooms' => 1,
                'city' => 'أبها', 'district' => 'السودة',
                'description' => 'منتجع صحي هادئ بإطلالة بانورامية على الجبال، يوفر تجربة استجمام راقية وخدمات سبا.',
                'features' => ['مسبح', 'سبا', 'واي فاي', 'مطعم', 'مكيف', 'موقف سيارات'],
                'images' => [
                    'https://images.unsplash.com/photo-1571896349842-33c89424de2d?w=900',
                    'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=900',
                ],
            ],

            // ── Camps (مخيمات) ─────────────────────────────────────────
            [
                'owner' => $individual,
                'unit_name' => 'مخيم صحراوي فاخر',
                'unit_type' => 'camp',
                'price' => 600, 'capacity' => 12, 'bedrooms' => 1,
                'city' => 'الرياض', 'district' => 'الثمامة',
                'description' => 'مخيم صحراوي بخيام مكيّفة وجلسات نار ومرافق حديثة، تجربة برّية أصيلة قرب المدينة.',
                'features' => ['مكيف', 'شواء', 'واي فاي', 'موقف سيارات', 'جلسة نار'],
                'images' => [
                    'https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?w=900',
                    'https://images.unsplash.com/photo-1537905569824-f89f14cceb68?w=900',
                ],
            ],

            // ── Luxury tier (3000–5000 ر.س/ليلة) — gives the price slider real inventory ──
            [
                'owner' => $company,
                'unit_name' => 'فيلا فاخرة مع إطلالة على البحر',
                'unit_type' => 'villa',
                'price' => 3500, 'capacity' => 12, 'bedrooms' => 6,
                'city' => 'جدة', 'district' => 'حي الشاطئ',
                'description' => 'فيلا فخمة على الواجهة البحرية بمسبح لا متناهٍ وشاطئ خاص، تشطيبات راقية وخدمة كاملة للمناسبات المميزة.',
                'features' => ['مسبح', 'شاطئ خاص', 'واي فاي', 'حديقة', 'شواء', 'موقف سيارات', 'مكيف'],
                'images' => [
                    'https://images.unsplash.com/photo-1613977257363-707ba9348227?w=900',
                    'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=900',
                ],
            ],
            [
                'owner' => $company,
                'unit_name' => 'منتجع خاص بمسابح وسبا',
                'unit_type' => 'resort',
                'price' => 4200, 'capacity' => 16, 'bedrooms' => 8,
                'city' => 'أبها', 'district' => 'السودة',
                'description' => 'منتجع متكامل بمسابح متعددة وسبا ومطعم خاص، إطلالات جبلية ساحرة وخصوصية تامة للنزلاء.',
                'features' => ['مسبح', 'سبا', 'مطعم', 'واي فاي', 'موقف سيارات', 'مكيف', 'حديقة'],
                'images' => [
                    'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=900',
                    'https://images.unsplash.com/photo-1571896349842-33c89424de2d?w=900',
                ],
            ],
            [
                'owner' => $individual,
                'unit_name' => 'قصر ريفي للمناسبات الكبرى',
                'unit_type' => 'villa',
                'price' => 4800, 'capacity' => 30, 'bedrooms' => 10,
                'city' => 'الرياض', 'district' => 'الدرعية',
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
                    'city'                => $data['city'],
                    'district'            => $data['district'],
                    'description'         => $data['description'],
                    'approval_status'     => 'approved',
                    'status'              => 'available',
                    'cancellation_policy' => '48_hours',
                    'checkin_time'        => '15:00',
                    'checkout_time'       => '12:00',
                    'calendar_token'      => Str::random(60),
                ]
            );

            // Only attach features/images for freshly created units.
            if (! $unit->wasRecentlyCreated) {
                continue;
            }

            // Features
            $featureIds = collect($data['features'])
                ->map(fn ($name) => Feature::firstOrCreate(['name' => $name])->id);
            $unit->features()->sync($featureIds);

            // Images (first is main)
            foreach ($data['images'] as $i => $url) {
                $unit->images()->create(['path' => $url, 'is_main' => $i === 0]);
            }
        }
    }
}
