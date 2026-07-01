<?php

namespace Database\Seeders;

use App\Models\Offer;
use Illuminate\Database\Seeder;

class OffersSeeder extends Seeder
{
    public function run(): void
    {
        $offers = [
            [
                'title'            => 'عرض الصيف الحار',
                'subtitle'         => 'خصم على الإقامات الصيفية',
                'discount_percent' => 25,
                'image_url'        => 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=900&q=70',
                'valid_until'      => '2026-08-31',
                'sort_order'       => 1,
            ],
            [
                'title'            => 'عرض الصيف الحار',
                'subtitle'         => 'خصم على الإقامات الصيفية',
                'discount_percent' => 25,
                'image_url'        => 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=900&q=70',
                'valid_until'      => '2026-08-31',
                'sort_order'       => 2,
            ],
        ];

        foreach ($offers as $offer) {
            Offer::updateOrCreate(
                ['title' => $offer['title'], 'sort_order' => $offer['sort_order']],
                $offer
            );
        }
    }
}
