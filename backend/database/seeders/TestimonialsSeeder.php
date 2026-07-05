<?php

namespace Database\Seeders;

use App\Models\Testimonial;
use Illuminate\Database\Seeder;

/**
 * Curated guest testimonials shown in the home "لماذا ممسى" section.
 * Guest tone (stays/bookings) — NOT real-estate investment copy.
 */
class TestimonialsSeeder extends Seeder
{
    public function run(): void
    {
        $testimonials = [
            [
                'name'       => 'عبدالله السبيعي',
                'role'       => 'نزيل من الرياض',
                'quote'      => 'حجزت شقة لعائلتي خلال دقائق، والتأكيد وصلني فوراً برسالة. الشقة كانت مطابقة تماماً للصور، والدخول كان سلساً بدون أي تعقيد. تجربة تعيد الثقة في الحجز الإلكتروني.',
                'deal'       => 'حجز شقة في الرياض · إقامة مكتملة',
                'avatar_url' => 'https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=200&q=80',
                'rating'     => 5,
                'sort_order' => 1,
            ],
            [
                'name'       => 'نورة الشمري',
                'role'       => 'نزيلة من جدة',
                'quote'      => 'قضينا نهاية أسبوع رائعة في شاليه على البحر. النظافة ممتازة، والتواصل مع خدمة العملاء كان سريعاً لما احتجنا نعدّل موعد الوصول. أكيد بنكرر التجربة.',
                'deal'       => 'حجز شاليه في جدة · إقامة مكتملة',
                'avatar_url' => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=200&q=80',
                'rating'     => 5,
                'sort_order' => 2,
            ],
            [
                'name'       => 'خالد العتيبي',
                'role'       => 'نزيل من أبها',
                'quote'      => 'أكثر ما أعجبني وضوح الأسعار وسياسة الإلغاء قبل الدفع — ما فيه مفاجآت. الفيلا كانت مجهزة بالكامل والإطلالة أجمل من الصور. ممسى صارت خياري الأول للإجازات.',
                'deal'       => 'حجز فيلا في أبها · إقامة مكتملة',
                'avatar_url' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=200&q=80',
                'rating'     => 5,
                'sort_order' => 3,
            ],
        ];

        foreach ($testimonials as $t) {
            // Keyed by sort_order only so re-seeding REPLACES the old copy
            // in place instead of appending rows with the new names.
            Testimonial::updateOrCreate(['sort_order' => $t['sort_order']], $t);
        }
    }
}
