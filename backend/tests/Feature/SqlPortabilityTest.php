<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Sql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The driver-aware fragments must MEAN the same thing on both drivers, not
 * merely run on both.
 *
 * Running is what NoSurfaceReturns500Test checks. This checks the numbers,
 * because the dangerous version of this bug is not a 500 — it is an expression
 * that executes happily and returns a differently-scaled answer, so tests agree
 * with each other and disagree with production.
 */
class SqlPortabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function pick(string $expr, string $table = 'probe'): mixed
    {
        return DB::table($table)->selectRaw("{$expr} as v")->value('v');
    }

    private function probe(array $rows): void
    {
        DB::statement('CREATE TABLE probe (d TEXT, e TEXT)');
        DB::table('probe')->insert($rows);
    }

    public function test_ym_buckets_by_calendar_month(): void
    {
        $this->probe([['d' => '2026-08-29 23:30:00', 'e' => null]]);

        $this->assertSame('2026-08', $this->pick(Sql::ym('d')));
    }

    public function test_day_of_week_is_one_for_sunday_and_seven_for_saturday(): void
    {
        // The whole point of the shift: callers index a Sun..Sat array by this
        // number, so 0-based would relabel every single day.
        $this->probe([
            ['d' => '2026-08-30 12:00:00', 'e' => null],  // a Sunday
            ['d' => '2026-08-29 12:00:00', 'e' => null],  // a Saturday
        ]);

        $this->assertSame('Sunday', date('l', strtotime('2026-08-30')), 'fixture drifted');
        $this->assertSame('Saturday', date('l', strtotime('2026-08-29')), 'fixture drifted');

        $got = DB::table('probe')->selectRaw(Sql::dayOfWeek('d').' as dow')->orderBy('d')->pluck('dow')->all();

        $this->assertSame([7, 1], array_map('intval', $got));
    }

    public function test_avg_hours_keeps_the_fraction(): void
    {
        // 14h12m. TIMESTAMPDIFF(HOUR, …) truncates this to 14 on MySQL — the
        // reason avgHours is built on MINUTE/60 rather than HOUR.
        $this->probe([['d' => '2026-08-29 00:00:00', 'e' => '2026-08-29 14:12:00']]);

        $this->assertEqualsWithDelta(14.2, (float) $this->pick(Sql::avgHours('d', 'e')), 0.01);
        $this->assertNotEqualsWithDelta(14.0, (float) $this->pick(Sql::avgHours('d', 'e')), 0.05);
    }

    public function test_night_sums_and_averages(): void
    {
        $this->probe([
            ['d' => '2026-08-01', 'e' => '2026-08-04'],   // 3 nights
            ['d' => '2026-08-10', 'e' => '2026-08-15'],   // 5 nights
        ]);

        $this->assertEqualsWithDelta(8.0, (float) $this->pick(Sql::sumNights('e', 'd')), 0.001);
        $this->assertEqualsWithDelta(4.0, (float) $this->pick(Sql::avgDays('e', 'd')), 0.001);
    }

    public function test_sum_nights_is_zero_not_null_when_there_is_nothing(): void
    {
        $this->probe([]);

        $this->assertEqualsWithDelta(0.0, (float) $this->pick(Sql::sumNights('e', 'd')), 0.001);
    }
}
