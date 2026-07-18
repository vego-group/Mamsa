<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CancellationPolicy;
use Illuminate\Database\Seeder;

/**
 * Seeds the three cancellation presets and their tiers — SRS 2.3.1.
 * Idempotent (updateOrCreate) so it is safe to re-run. Values are data, not
 * logic (NFR-013): adjusting a percentage means editing here + re-seeding,
 * never a code change. Frozen booking snapshots are untouched by re-runs.
 *
 * Table approved by product 2026-07-18 (supersedes the earlier draft —
 * deliberately more guest-friendly while the platform is early-stage).
 * Default preset when a partner skips the field = "moderate".
 *
 *   days before check-in   flexible   moderate   strict
 *   7+  (>=168h)             100%       100%       75%
 *   3–7 (>=72h)               75%        50%       25%
 *   <3  (>=0h)                50%        25%        0%
 *   after check-in          locked     locked     locked   (FR-045, engine-enforced)
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
                'is_default' => false,
                'tiers'      => [
                    ['min_hours_before_checkin' => 168, 'refund_percent' => 100, 'label_ar' => 'أكثر من 7 أيام'],
                    ['min_hours_before_checkin' => 72,  'refund_percent' => 75,  'label_ar' => 'من 3 إلى 7 أيام'],
                    ['min_hours_before_checkin' => 0,   'refund_percent' => 50,  'label_ar' => 'أقل من 3 أيام'],
                ],
            ],
            [
                'key'        => 'moderate',
                'name_ar'    => 'متوسطة',
                'name_en'    => 'Moderate',
                'is_default' => true,
                'tiers'      => [
                    ['min_hours_before_checkin' => 168, 'refund_percent' => 100, 'label_ar' => 'أكثر من 7 أيام'],
                    ['min_hours_before_checkin' => 72,  'refund_percent' => 50,  'label_ar' => 'من 3 إلى 7 أيام'],
                    ['min_hours_before_checkin' => 0,   'refund_percent' => 25,  'label_ar' => 'أقل من 3 أيام'],
                ],
            ],
            [
                'key'        => 'strict',
                'name_ar'    => 'صارمة',
                'name_en'    => 'Strict',
                'is_default' => false,
                'tiers'      => [
                    ['min_hours_before_checkin' => 168, 'refund_percent' => 75, 'label_ar' => 'أكثر من 7 أيام'],
                    ['min_hours_before_checkin' => 72,  'refund_percent' => 25, 'label_ar' => 'من 3 إلى 7 أيام'],
                    ['min_hours_before_checkin' => 0,   'refund_percent' => 0,  'label_ar' => 'أقل من 3 أيام'],
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
