<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BookingsExport implements FromQuery, WithMapping, WithHeadings, ShouldAutoSize, WithStyles
{
    protected Builder $query;

    public function __construct(Builder $query)
    {
        $this->query = $query;
    }

    public function query()
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'ID', 'الوحدة', 'كود الوحدة', 'مالك الوحدة', 'الحاجز',
            'الحالة', 'من', 'إلى', 'المبلغ (ر.س)',
        ];
    }

    public function map($b): array
    {
        return [
            $b->id,
            optional($b->unit)->name,
            optional($b->unit)->code,
            optional(optional($b->unit)->owner)->name,
            optional($b->customer)->name,
            $b->status,
            optional($b->start_date)->format('Y-m-d'),
            optional($b->end_date)->format('Y-m-d'),
            $b->total_amount,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [ 1 => ['font' => ['bold' => true]] ];
    }
}