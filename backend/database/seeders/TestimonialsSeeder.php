<?php

namespace Database\Seeders;

use App\Models\Testimonial;
use Illuminate\Database\Seeder;

/**
 * Curated client testimonials shown in the home "لماذا ممسى" section.
 */
class TestimonialsSeeder extends Seeder
{
    public function run(): void
    {
        $testimonials = [
            [
                'name'       => 'محمد بن سلطان الحمدان',
                'role'       => 'رئيس مجلس إدارة مجموعة الحمدان',
                'quote'      => 'وجد الفريق بنتهاوس أحلامنا في أقل من ثلاثة أسابيع. مستوى السرية والاحترافية والاهتمام بالتفاصيل كان استثنائياً لم نشهده في عشرين عاماً من تجارب الاستثمار العقاري.',
                'deal'       => 'بنتهاوس سكاي دبي مارينا · عملية محقّقة',
                'avatar_url' => 'https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=200&q=80',
                'rating'     => 5,
                'sort_order' => 1,
            ],
            [
                'name'       => 'سارة عبدالعزيز القحطاني',
                'role'       => 'مديرة محفظة استثمارية',
                'quote'      => 'تعاملت مع وكالات كثيرة، لكن ممسى وحدها فهمت ما أبحث عنه بالضبط. الشفافية في كل خطوة وسرعة الإنجاز فاقت توقعاتي تماماً.',
                'deal'       => 'فيلا بحرية جدة · عملية محقّقة',
                'avatar_url' => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=200&q=80',
                'rating'     => 5,
                'sort_order' => 2,
            ],
            [
                'name'       => 'فهد ناصر الدوسري',
                'role'       => 'مستثمر عقاري',
                'quote'      => 'من أول مكالمة شعرت أنني بين أيدٍ محترفة. التحليلات الدقيقة للسوق ساعدتني على اتخاذ قرار استثماري واثق دون أي ضغط.',
                'deal'       => 'برج سكني الرياض · عملية محقّقة',
                'avatar_url' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=200&q=80',
                'rating'     => 5,
                'sort_order' => 3,
            ],
        ];

        foreach ($testimonials as $t) {
            Testimonial::updateOrCreate(
                ['name' => $t['name'], 'sort_order' => $t['sort_order']],
                $t
            );
        }
    }
}
