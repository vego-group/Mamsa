<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BookingsSummaryExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    protected array $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function array(): array
    {
        return array_map(fn($r) => [
            $r['month'], $r['bookings_count'], $r['revenue_sum'],
        ], $this->rows);
    }

    public function headings(): array
    {
        return ['الشهر (YYYY-MM)', 'عدد الحجوزات', 'إجمالي المبالغ (ر.س)'];
    }

    public function styles(Worksheet $sheet)
    {
        return [ 1 => ['font' => ['bold' => true]] ];
    }
}