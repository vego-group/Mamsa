<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Booking;
use App\Services\CancellationPolicyService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Pure-logic tests for the cancellation & refund engine (SRS 2.3.1).
 * No DB: each booking carries an in-memory frozen snapshot, so these assert
 * the calculator alone — the part the SRS flags as financially sensitive.
 */
class CancellationRefundEngineTest extends TestCase
{
    private CancellationPolicyService $service;

    /** Fixed check-in so every case is deterministic. */
    private const CHECKIN = '2026-07-10T15:00:00+03:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CancellationPolicyService();
    }

    /** @return array<string, array{0:int,1:int,2:int}> [flexible, moderate, strict] %, keyed by tier */
    private static function tiers(int $f168, int $m168, int $s168, int $f72, int $m72, int $s72): array
    {
        return compact('f168', 'm168', 's168', 'f72', 'm72', 's72');
    }

    private function booking(string $template, float $total = 1000.0): Booking
    {
        $tierSets = [
            // Approved preset table 2026-07-18 (guest-friendly revision).
            'flexible' => [[168, 100], [72, 75], [0, 50]],
            'moderate' => [[168, 100], [72, 50], [0, 25]],
            'strict'   => [[168, 75],  [72, 25], [0, 0]],
        ];

        $tiers = array_map(fn ($t) => [
            'min_hours_before_checkin' => $t[0],
            'refund_percent'           => $t[1],
            'label'                    => 'tier-'.$t[0],
        ], $tierSets[$template]);

        $booking = new Booking();
        $booking->total_amount = $total;
        $booking->cancellation_snapshot = [
            'policy_key'  => $template,
            'policy_name' => $template,
            'checkin_at'  => self::CHECKIN,
            'tiers'       => $tiers,
        ];

        return $booking;
    }

    private function at(string $iso): Carbon
    {
        return Carbon::parse($iso);
    }

    public function test_more_than_7_days_before_checkin(): void
    {
        // 8 days before check-in → top tier of each template.
        $when = $this->at('2026-07-02T15:00:00+03:00');

        $this->assertSame(100, $this->service->quote($this->booking('flexible'), $when)->refundPercent);
        $this->assertSame(100, $this->service->quote($this->booking('moderate'), $when)->refundPercent);
        $this->assertSame(75,  $this->service->quote($this->booking('strict'),   $when)->refundPercent);

        $this->assertEqualsWithDelta(1000.0, $this->service->quote($this->booking('flexible'), $when)->refundAmount, 0.001);
        $this->assertEqualsWithDelta(750.0,  $this->service->quote($this->booking('strict'),   $when)->refundAmount, 0.001);
    }

    public function test_between_3_and_7_days_before_checkin(): void
    {
        // 5 days before check-in (120h) → middle tier.
        $when = $this->at('2026-07-05T15:00:00+03:00');

        $this->assertSame(75, $this->service->quote($this->booking('flexible'), $when)->refundPercent);
        $this->assertSame(50, $this->service->quote($this->booking('moderate'), $when)->refundPercent);
        $this->assertSame(25, $this->service->quote($this->booking('strict'),   $when)->refundPercent);

        $this->assertEqualsWithDelta(750.0, $this->service->quote($this->booking('flexible'), $when)->refundAmount, 0.001);
        $this->assertEqualsWithDelta(500.0, $this->service->quote($this->booking('moderate'), $when)->refundAmount, 0.001);
    }

    public function test_less_than_3_days_before_checkin(): void
    {
        // 24h before check-in → bottom tier: 50 / 25 / 0.
        $when = $this->at('2026-07-09T15:00:00+03:00');

        $expected = ['flexible' => 50, 'moderate' => 25, 'strict' => 0];

        foreach ($expected as $template => $percent) {
            $quote = $this->service->quote($this->booking($template), $when);
            $this->assertTrue($quote->cancellable);
            $this->assertSame($percent, $quote->refundPercent);
            $this->assertEqualsWithDelta($percent * 10.0, $quote->refundAmount, 0.001);
        }
    }

    public function test_after_checkin_is_blocked(): void
    {
        // One hour into the stay → cancellation forbidden (FR-045).
        $when = $this->at('2026-07-10T16:00:00+03:00');

        $quote = $this->service->quote($this->booking('flexible'), $when);

        $this->assertFalse($quote->cancellable);
        $this->assertSame(0.0, $quote->refundAmount);
        $this->assertNotNull($quote->reason);
    }

    public function test_refund_amount_scales_with_total(): void
    {
        $when = $this->at('2026-07-05T15:00:00+03:00'); // 75% tier on flexible

        $quote = $this->service->quote($this->booking('flexible', 2480.0), $when);

        $this->assertEqualsWithDelta(1860.0, $quote->refundAmount, 0.001);
    }
}
