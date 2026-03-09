<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $title }}</title>
<style>
  /* ===== Brand ===== */
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

  /* Header Bar */
  .bar {
    background: var(--brand);
    color: #fff;
    padding: 10px 12px;
    display: flex; align-items: center; justify-content: space-between;
    border-radius: 6px;
  }
  .brand {
    display:flex; align-items:center; gap:10px; font-weight: 700; letter-spacing:.3px;
  }
  .brand img { height: 22px; }
  .meta { font-size: 11px; text-align:right; }

  /* Table */
  table { width:100%; border-collapse: collapse; margin-top: 10px; }
  th, td { border:1px solid var(--border); padding: 6px 8px; }
  th { background: var(--th-bg); color: var(--brand); text-align: left; }
  tr:nth-child(even) td { background: var(--brand-50); }

  /* Footer (fixed) */
  .footer {
    position: fixed; left:28px; right:28px; bottom:10px;
    color: var(--muted); font-size: 11px; text-align: center;
  }
</style>
</head>
<body>

  <!-- Header -->
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

  <!-- (Optional) Filters note -->
  @if(!empty($filters))
    <div style="margin:10px 0; font-size:11px; color:var(--muted);">
      <strong>Filters:</strong>
      @php $f=$filters; @endphp
      q={{ $f['q'] ?: '-' }} |
      status={{ $f['status'] ?: 'all' }} |
      unit={{ $f['unit_id'] ?: 'all' }} |
      from={{ $f['from'] ?: '-' }} |
      to={{ $f['to'] ?: '-' }}
    </div>
  @endif

  <!-- Table -->
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Unit</th>
        <th>Code</th>
        <th>Owner</th>
        <th>Customer</th>
        <th>Status</th>
        <th>From</th>
        <th>To</th>
        <th>Total (SAR)</th>
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $b)
        <tr>
          <td>{{ $b->id }}</td>
          <td>{{ $b->unit?->name }}</td>
          <td>{{ $b->unit?->code }}</td>
          <td>{{ $b->unit?->owner?->name }}</td>
          <td>{{ $b->customer?->name }}</td>
          <td>{{ $b->status }}</td>
          <td>{{ $b->start_date?->format('Y-m-d') }}</td>
          <td>{{ $b->end_date?->format('Y-m-d') }}</td>
          <td>{{ $b->total_amount !== null ? number_format($b->total_amount,2) : '-' }}</td>
        </tr>
      @empty
        <tr><td colspan="9" style="text-align:center; color:var(--muted);">No data.</td></tr>
      @endforelse
    </tbody>
  </table>

  <div class="footer">
    © {{ date('Y') }} Mamsa — Detailed Bookings Report | Page <span class="pageNumber"></span> / <span class="totalPages"></span>
  </div>

  <script type="text/php">
    if (isset($pdf)) {
      $font = $fontMetrics->getFont("helvetica", "normal");
      $pdf->page_script('
        $font = $fontMetrics->get_font("helvetica", "normal");
        $size = 8;
        $page = $PAGE_NUM;
        $pages = $PAGE_COUNT;
        $text = "'.$title.'";
        $pdf->text(28, 18, "", $font, 8); // reserved
        // Footer page numbers
        $pdf->text(520, 810, "Page $page / $pages", $font, 8);
      ');
    }
  </script>

</body>
</html>
