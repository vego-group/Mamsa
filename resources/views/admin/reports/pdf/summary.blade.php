<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
<meta charset="UTF-8">
<title>{{ $title }}</title>

<style>
  @page { margin: 28px 28px 40px 28px; }
  body { font-family: DejaVu Sans, sans-serif; color:#111; font-size:12px; direction: rtl; }

  .bar {
    background:#2f4b46;
    color:#fff;
    padding:10px 12px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    border-radius:6px;
  }
  .brand { display:flex; align-items:center; gap:10px; font-weight:700; }

  table { width:100%; border-collapse:collapse; margin-top:12px; }
  th, td { border:1px solid #e5e7eb; padding:6px 8px; }
  th { background:#f3f4f6; color:#2f4b46; }
  tr:nth-child(even) td { background:#eef5f3; }

  .totals { background:#eef2ff; font-weight:bold; }
  .footer {
    position: fixed;
    left:28px;
    right:28px;
    bottom:12px;
    color:#6b7280;
    font-size:11px;
    text-align:center;
  }
</style>

</head>

<body>

  {{-- Header --}}
  <div class="bar">
    <div class="brand">

      @php
        $logo = public_path('assets/mamsa-logo.png');
      @endphp

      @if(is_file($logo))
        <img src="{{ $logo }}" alt="logo">
      @else
        <span>Mamsa</span>
      @endif

      <span>| تقرير الملخّص الشهري</span>
    </div>

    <div style="font-size:11px; text-align:right;">
      <div>{{ $title }}</div>
      <div>وقت التوليد: {{ $generated_at }}</div>
    </div>
  </div>


  {{-- Table --}}
  <table>
    <thead>
      <tr>
        <th>الشهر (YYYY‑MM)</th>
        <th>عدد الحجوزات</th>
        <th>الإيراد (ر.س)</th>
      </tr>
    </thead>

    <tbody>
      @forelse($rows as $r)
        <tr>
          <td>{{ $r['month'] }}</td>
          <td>{{ number_format($r['bookings_count']) }}</td>
          <td>{{ number_format($r['revenue_sum'], 2) }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="3" style="text-align:center; color:#6b7280;">لا توجد بيانات</td>
        </tr>
      @endforelse

      <tr class="totals">
        <td>الإجمالي</td>
        <td>{{ number_format($totalCount) }}</td>
        <td>{{ number_format($totalRevenue, 2) }}</td>
      </tr>
    </tbody>
  </table>


  {{-- Footer --}}
  <div class="footer">
    © {{ date('Y') }} Mamsa — Monthly Summary Report |
    صفحة <span class="pageNumber"></span> / <span class="totalPages"></span>
  </div>


  {{-- dompdf page script --}}
  <script type="text/php">
    if (isset($pdf)) {
      $pdf->page_script('
        $font = $fontMetrics->get_font("DejaVu Sans", "normal");
        $page = $PAGE_NUM;
        $pages = $PAGE_COUNT;
        $pdf->text(520, 810, "Page $page / $pages", $font, 8);
      ');
    }
  </script>

</body>
</html>