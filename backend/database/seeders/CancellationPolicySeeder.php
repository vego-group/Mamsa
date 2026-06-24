<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CancellationPolicy;
use Illuminate\Database\Seeder;

/**
 * Seeds the three cancellation templates and their tiers — SRS 2.3.1.
 * Idempotent (updateOrCreate) so it is safe to re-run. Values are the
 * approved defaults and remain editable from the DB without code changes
 * (NFR-013). Default template for Mamsa units = "flexible".
 */
class CancellationPolicySeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'key'        => 'flexible',
                'name_ar'    => 'مرنة',
                'name_en'    => 'Flexible',
                'is_default' => true,
                'tiers'      => [
                    ['min_hours_before_checkin' => 168, 'refund_percent' => 100, 'label_ar' => 'أكثر من 7 أيام'],
                    ['min_hours_before_checkin' => 72,  'refund_percent' => 50,  'label_ar' => 'من 3 إلى 7 أيام'],
                    ['min_hours_before_checkin' => 0,   'refund_percent' => 0,   'label_ar' => 'أقل من 48 ساعة'],
                ],
            ],
            [
                'key'        => 'moderate',
                'name_ar'    => 'متوسطة',
                'name_en'    => 'Moderate',
                'is_default' => false,
                'tiers'      => [
                    ['min_hours_before_checkin' => 168, 'refund_percent' => 100, 'label_ar' => 'أكثر من 7 أيام'],
                    ['min_hours_before_checkin' => 72,  'refund_percent' => 25,  'label_ar' => 'من 3 إلى 7 أيام'],
                    ['min_hours_before_checkin' => 0,   'refund_percent' => 0,   'label_ar' => 'أقل من 48 ساعة'],
                ],
            ],
            [
                'key'        => 'strict',
                'name_ar'    => 'صارمة',
                'name_en'    => 'Strict',
                'is_default' => false,
                'tiers'      => [
                    ['min_hours_before_checkin' => 168, 'refund_percent' => 50, 'label_ar' => 'أكثر من 7 أيام'],
                    ['min_hours_before_checkin' => 72,  'refund_percent' => 0,  'label_ar' => 'من 3 إلى 7 أيام'],
                    ['min_hours_before_checkin' => 0,   'refund_percent' => 0,  'label_ar' => 'أقل من 48 ساعة'],
                ],
            ],
        ];

        foreach ($templates as $template) {
            $tiers = $template['tiers'];
            unset($template['tiers']);

            $policy = CancellationPolicy::updateOrCreate(['key' => $template['key']], $template);

            foreach ($tiers as $tier) {
                $policy->tiers()->updateOrCreate(
                    ['min_hours_before_checkin' => $tier['min_hours_before_checkin']],
                    $tier,
                );
            }
        }
    }
}
