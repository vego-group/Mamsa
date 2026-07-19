{{-- Shared booking facts card. Expects: $booking (loaded unit), $rows extra
     array<label, value> appended after the standard rows. --}}
@php
    $fmtDate = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d/m/Y');
    $base = [
        'رقم الحجز'   => 'BK-'.$booking->id,
        'الوحدة'      => $booking->unit->unit_name ?? '—',
        'تاريخ الوصول' => $fmtDate($booking->start_date),
        'تاريخ المغادرة' => $fmtDate($booking->end_date),
    ];
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border:1px solid #e5e7eb;border-radius:10px;">
    @foreach ($base + ($rows ?? []) as $label => $value)
        <tr>
            <td style="padding:10px 14px;font-size:14px;color:#6b7280;border-bottom:1px solid #f3f4f6;white-space:nowrap;">{{ $label }}</td>
            <td style="padding:10px 14px;font-size:14px;color:#111827;font-weight:bold;border-bottom:1px solid #f3f4f6;text-align:left;">{{ $value }}</td>
        </tr>
    @endforeach
</table>
