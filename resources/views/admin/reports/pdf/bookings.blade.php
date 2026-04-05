<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>{{ $title }}</title>
<style>
  /* ==== ألوان ثابتة (بدون CSS variables) ==== */
  @page { margin: 28px 28px 40px 28px; }
  body { font-family: DejaVu Sans, sans-serif; color: #111; font-size: 12px; }

  /* Header Bar */
  .bar {
    background: #2f4b46;
    color: #fff;
    padding: 10px 12px;
    display: flex; align-items: center; justify-content: space-between;
    border-radius: 6px;
  }
  .brand { display:flex; align-items:center; gap:10px; font-weight:700; letter-spacing:.3px; }
  .brand img { height: 22px; }
  .meta { font-size: 11px; text-align:right; }

  /* Table */
  table { width:100%; border-collapse: collapse; margin-top: 10px; }
  th, td { border:1px solid #e5e7eb; padding: 6px 8px; }
  th { background: #f3f4f6; color: #2f4b46; text-align: left; }
  tr:nth-child(even) td { background: #eef5f3; }

  /* Footer (fixed) */
  .footer {
    position: fixed; left:28px; right:28px; bottom:10px;
    color: #6b7280; font-size: 11px; text-align: center;
  }
</style>
</head>
<body>

  {{-- Header --}}
  <div class="bar">
    <div class="brand">
      @php
        $logoPath = public_path('assets/mamsa-logo.png');
      @endphp

      @if(is_file($logoPath))
        <img src="{{ $logoPath }}" alt="Mamsa Logo">
      @else
        <span>Mamsa</span>
      @endif

      <span>| التقارير</span>
    </div>
    <div class="meta">
      <div>{{ $title }}</div>
      <div>وقت التوليد: {{ $generated_at }}</div>
    </div>
  </div>

  {{-- Filters (اختياري) --}}
  @if(!empty($filters))
    @php $f = $filters; @endphp
    <div style="margin:10px 0; font-size:11px; color:#6b7280;">
      <strong>المرشِّحات:</strong>
      q={{ $f['q'] ?: '-' }} |
      الحالة={{ $f['status'] ?: 'الكل' }} |
      الوحدة={{ $f['unit_id'] ?: 'الكل' }} |
      من={{ $f['from'] ?: '-' }} |
      إلى={{ $f['to'] ?: '-' }}
    </div>
  @endif

  {{-- Table --}}
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>الوحدة</th>
        <th>الكود</th>
        <th>المالك</th>
        <th>الحاجز</th>
        <th>الحالة</th>
        <th>من</th>
        <th>إلى</th>
        <th>الإجمالي (ر.س)</th>
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $b)
        <tr>
          <td>{{ $b->id }}</td>
          <td>{{ optional($b->unit)->name }}</td>
          <td>{{ optional($b->unit)->code }}</td>
          <td>{{ optional(optional($b->unit)->owner)->name }}</td>
          <td>{{ optional($b->customer)->name }}</td>
          <td>{{ $b->status }}</td>
          <td>{{ optional($b->start_date)?->format('Y-m-d') }}</td>
          <td>{{ optional($b->end_date)?->format('Y-m-d') }}</td>
          <td>{{ $b->total_amount !== null ? number_format((float)$b->total_amount, 2) : '-' }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="9" style="text-align:center; color:#6b7280;">لا توجد بيانات.</td>
        </tr>
      @endforelse
    </tbody>
  </table>

  <div class="footer">
    © {{ date('Y') }} Mamsa — تقرير الحجوزات التفصيلي | صفحة
    <span class="pageNumber"></span> / <span class="totalPages"></span>
  </div>

  {{-- dompdf page_script --}}
  <script type="text/php">
    if (isset($pdf)) {
      $pdf->page_script('
        $font = $fontMetrics->get_font("DejaVu Sans", "normal");
        $size = 8;
        $page = $PAGE_NUM;
        $pages = $PAGE_COUNT;
        // أرقام الصفحات (الأسفل يمين/يسار حسب اتجاه الصفحة)
        $pdf->text(520, 810, "Page $page / $pages", $font, $size);
      ');
    }
  </script>

</body>
</html>