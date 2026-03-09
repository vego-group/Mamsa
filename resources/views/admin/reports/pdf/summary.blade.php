<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $title }}</title>
<style>
  :root {
    --brand: #2f4b46;
    --brand-50: #eef5f3;
    --text: #111;
    --muted: #6b7280;
    --border: #e5e7eb;
    --th-bg: #f3f4f6;
  }
  @page { margin: 28px 28px 40px 28px; }
  body { font-family: sans-serif; color: var(--text); font-size: 12px; }
  .bar {
    background: var(--brand); color:#fff; padding:10px 12px;
    display:flex; align-items:center; justify-content:space-between; border-radius:6px;
  }
  .brand { display:flex; align-items:center; gap:10px; font-weight:700; letter-spacing:.3px; }
  .brand img { height:22px; }
  .meta  { font-size:11px; text-align:right; }

  table { width:100%; border-collapse:collapse; margin-top:10px; }
  th, td { border:1px solid var(--border); padding:6px 8px; }
  th { background: var(--th-bg); color: var(--brand); text-align:left; }
  tr:nth-child(even) td { background: var(--brand-50); }

  .footer { position: fixed; left:28px; right:28px; bottom:10px; color:var(--muted); font-size:11px; text-align:center; }
  .totals { background:#eef2ff; font-weight:bold; }
</style>
</head>
<body>

  <div class="bar">
    <div class="brand">
      @if(file_exists(public_path('assets/mamsa-logo.png')))
        {{ public_path(
      @else
        <span>Mamsa</span>
      @endif
      <span>| Reports</span>
    </div>
    <div class="meta">
      <div>{{ $title }}</div>
      <div>Generated at: {{ $generated_at }}</div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Month (YYYY-MM)</th>
        <th>Bookings</th>
        <th>Revenue (SAR)</th>
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
        <tr><td colspan="3" style="text-align:center; color:var(--muted);">No data.</td></tr>
      @endforelse
        <tr class="totals">
          <td>Total</td>
          <td>{{ number_format($totalCount) }}</td>
          <td>{{ number_format($totalRevenue, 2) }}</td>
        </tr>
    </tbody>
  </table>

  <div class="footer">
    © {{ date('Y') }} Mamsa — Monthly Summary Report | Page <span class="pageNumber"></span> / <span class="totalPages"></span>
  </div>

  <script type="text/php">
    if (isset($pdf)) {
      $font = $fontMetrics->getFont("helvetica", "normal");
      $pdf->page_script('
        $font = $fontMetrics->get_font("helvetica", "normal");
        $page = $PAGE_NUM; $pages = $PAGE_COUNT;
        $pdf->text(520, 810, "Page $page / $pages", $font, 8);
      ');
    }
  </script>

</body>
</html>
