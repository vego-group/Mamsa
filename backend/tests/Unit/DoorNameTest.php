<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Unit;
use App\Support\Units\DoorName;
use Tests\TestCase;

/**
 * The one door order (owner decision 2026-10-01), shared with the frontend:
 * empty first → all-digit names by value → other names by Arabic collation →
 * ties by id. And "٧" is "7".
 */
class DoorNameTest extends TestCase
{
    public function test_digit_names_sort_by_value_not_as_text(): void
    {
        // orderBy('apartment_no') gave 1, 10, 2, 402, 7.
        $this->assertSame(['1', '2', '7', '10', '402'], $this->order(['10', '2', '402', '7', '1']));
    }

    public function test_empty_first_then_numbers_then_names(): void
    {
        $this->assertSame(
            [null, '2', '10', 'الدور الثالث'],
            $this->order(['الدور الثالث', '10', null, '2']),
        );
    }

    public function test_a_tie_is_broken_by_id(): void
    {
        // "01" and "1" have the same value; identical names are identical.
        $doors = collect([
            $this->door(9, '01'), $this->door(3, '1'), $this->door(7, 'شقة'), $this->door(4, 'شقة'),
        ]);

        $this->assertSame([3, 9, 4, 7], DoorName::order($doors)->pluck('id')->all());
    }

    public function test_arabic_digits_sort_as_the_number_they_are(): void
    {
        $this->assertSame(['2', '٧', '10'], $this->order(['10', '٧', '2']));
    }

    public function test_arabic_names_sort_among_themselves(): void
    {
        // Identical under root and Arabic collation, so this runs everywhere.
        $this->assertSame(['أ', 'الدور الثالث', 'ب', 'شقة'], $this->order(['شقة', 'ب', 'الدور الثالث', 'أ']));
    }

    public function test_arabic_names_come_before_latin_like_the_browser(): void
    {
        // localeCompare('ar') puts Arabic script first. ICU without Arabic
        // data falls back to root and puts Latin first — the local Docker
        // image does; staging and production (ICU 64.2) do not.
        if (! DoorName::hasArabicCollation()) {
            $this->markTestSkipped('ICU here has no Arabic collation (falls back to root); verified on staging instead.');
        }

        $this->assertSame(['الملحق', 'B-12', 'Penthouse'], $this->order(['Penthouse', 'B-12', 'الملحق']));
    }

    public function test_normalize_writes_arabic_and_persian_digits_as_ascii_and_keeps_letters(): void
    {
        $this->assertSame('402', DoorName::normalize('٤٠٢'));
        $this->assertSame('7', DoorName::normalize(' ۷ '));
        $this->assertSame('B-12', DoorName::normalize('B-١٢'));
        $this->assertSame('الدور الثالث', DoorName::normalize('الدور الثالث'));
        $this->assertSame('', DoorName::normalize(null));
    }

    /** @param list<?string> $names */
    private function order(array $names): array
    {
        $doors = collect($names)->values()->map(fn (?string $n, int $i) => $this->door($i + 1, $n));

        return DoorName::order($doors)->pluck('apartment_no')->all();
    }

    private function door(int $id, ?string $name): Unit
    {
        $u = new Unit;
        $u->forceFill(['id' => $id, 'apartment_no' => $name]);

        return $u;
    }
}
