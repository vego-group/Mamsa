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
        ];

        foreach ($units as $data) {
            /** @var User $owner */
            $owner = $data['owner'];

            $unit = $owner->units()->create([
                'unit_name'           => $data['unit_name'],
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
            ]);

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
