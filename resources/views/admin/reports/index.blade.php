@extends('layouts.Admin', ['title' => 'التقارير'])

@section('content')
    <h1 class="text-2xl font-semibold text-[#2f4b46] mb-4">التقارير</h1>

    @if(session('success'))
        <div class="mb-4 bg-green-50 text-green-700 border border-green-200 rounded-xl p-3">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 bg-red-50 text-red-700 border border-red-200 rounded-xl p-3">{{ session('error') }}</div>
    @endif

    @php
        $params = array_filter([
            'q'       => $q,
            'status'  => $status,
            'unit_id' => $unitId,
            'from'    => $dateFrom,
            'to'      => $dateTo,
        ], fn($v) => $v !== null && $v !== '');
    @endphp

    {{-- فلاتر --}}
    <form method="GET" action="{{ route('Admin.reports.index') }}"
          class="grid grid-cols-1 md:grid-cols-6 gap-3 bg-white border border-gray-200 rounded-2xl p-4 mb-4">
        <div class="md:col-span-2">
            <label class="block mb-1 text-sm text-gray-700">بحث</label>
            <input type="text" name="q" value="{{ $q }}"
                   placeholder="الوحدة / الكود / الحاجز / البريد"
                   class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] px-3 py-2 text-sm">
        </div>

        <div>
            <label class="block mb-1 text-sm text-gray-700">الحالة</label>
            <select name="status"
                    class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] text-sm">
                <option value="">كل الحالات</option>
                <option value="new"       @selected($status==='new')>جديد</option>
                <option value="confirmed" @selected($status==='confirmed')>مؤكّد</option>
                <option value="completed" @selected($status==='completed')>مكتمل</option>
                <option value="cancelled" @selected($status==='cancelled')>ملغي</option>
            </select>
        </div>

        <div>
            <label class="block mb-1 text-sm text-gray-700">الوحدة</label>
            <select name="unit_id"
                    class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] text-sm">
                <option value="">كل الوحدات</option>
                @foreach($unitsList as $u)
                    <option value="{{ $u->id }}" @selected((string)$unitId === (string)$u->id)>
                        {{ $u->name }} ({{ $u->code }})
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block mb-1 text-sm text-gray-700">من</label>
            <input type="date" name="from" value="{{ $dateFrom }}"
                   class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] text-sm">
        </div>

        <div>
            <label class="block mb-1 text-sm text-gray-700">إلى</label>
            <input type="date" name="to" value="{{ $dateTo }}"
                   class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] text-sm">
        </div>

        <div class="md:col-span-6 flex flex-wrap gap-2 items-end">
            <button type="submit"
                    class="px-4 py-2 rounded-lg bg-[#2f4b46] text-white hover:bg-[#2a433f] text-sm">
                بحث
            </button>

            @if($params)
                <a href="{{ route('Admin.reports.index') }}"
                   class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-sm">
                    مسح الفلاتر
                </a>
            @endif

            {{-- تصدير تفصيلي --}}
            <a href="/Admin/reports/export/bookings.csv?{{ http_build_query($params) }}"
   class="px-3 py-2 rounded-lg bg-slate-600 text-white hover:bg-slate-700 text-sm">
   CSV تفصيلي
</a>

<a href="/Admin/reports/export/bookings.excel?{{ http_build_query($params) }}"
   class="px-3 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 text-sm">
   Excel تفصيلي
</a>

<a href="/Admin/reports/export/bookings.pdf?{{ http_build_query($params) }}"
   class="px-3 py-2 rounded-lg bg-rose-600 text-white hover:bg-rose-700 text-sm">
   PDF تفصيلي
</a>

            {{-- تصدير ملخص شهري --}}
            <a href="/Admin/reports/export/summary.csv?{{ http_build_query($params) }}"
   class="px-3 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 text-sm">
   CSV ملخّص
</a>

<a href="/Admin/reports/export/summary.excel?{{ http_build_query($params) }}"
   class="px-3 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 text-sm">
   Excel ملخّص
</a>

<a href="/Admin/reports/export/summary.pdf?{{ http_build_query($params) }}"
   class="px-3 py-2 rounded-lg bg-fuchsia-600 text-white hover:bg-fuchsia-700 text-sm">
   PDF ملخّص
</a>
 </div>
    </form>

    {{-- بطاقات --}}
    <div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-6 gap-4 my-6">
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <div class="text-sm text-gray-500">كل الحجوزات</div>
            <div class="text-2xl font-semibold text-[#2f4b46]">{{ number_format($totalBookings) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <div class="text-sm text-gray-500">جديد</div>
            <div class="text-2xl font-semibold text-[#2f4b46]">{{ number_format($countNew) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <div class="text-sm text-gray-500">مؤكّد</div>
            <div class="text-2xl font-semibold text-[#2f4b46]">{{ number_format($countConfirmed) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <div class="text-sm text-gray-500">مكتمل</div>
            <div class="text-2xl font-semibold text-[#2f4b46]">{{ number_format($countCompleted) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <div class="text-sm text-gray-500">ملغي</div>
            <div class="text-2xl font-semibold text-[#2f4b46]">{{ number_format($countCancelled) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <div class="text-sm text-gray-500">إجمالي المبالغ</div>
            <div class="text-2xl font-semibold text-[#2f4b46]">{{ number_format($sumAmount, 2) }} <span class="text-base">ر.س</span></div>
        </div>
    </div>

    {{-- المخططات --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <div class="mb-2 text-sm text-gray-700">عدد الحجوزات (آخر 12 شهر)</div>
            <canvas id="bookingsChart" height="130"></canvas>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <div class="mb-2 text-sm text-gray-700">إجمالي المبالغ (آخر 12 شهر)</div>
            <canvas id="revenueChart" height="130"></canvas>
        </div>
    </div>

    {{-- جدول الحجوزات --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden mt-6">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-700">
            <tr>
                <th class="py-3 px-4 text-right">#</th>
                <th class="py-3 px-4 text-right">الوحدة</th>
                <th class="py-3 px-4 text-right">الحاجز</th>
                <th class="py-3 px-4 text-right">الحالة</th>
                <th class="py-3 px-4 text-right">من</th>
                <th class="py-3 px-4 text-right">إلى</th>
                <th class="py-3 px-4 text-right">المبلغ</th>
            </tr>
            </thead>
            <tbody>
            @forelse($bookings as $b)
                <tr class="border-t">
                    <td class="py-3 px-4">{{ $b->id }}</td>
                    <td class="py-3 px-4">{{ $b->unit?->unit_name }} <span class="text-gray-500">({{ $b->unit?->code }})</span></td>
                    <td class="py-3 px-4">{{ $b->customer?->name ?? '—' }}</td>
                    <td class="py-3 px-4">
                        <span class="inline-flex px-3 py-1 rounded-full text-xs border">
                            {{ method_exists($b,'getStatusLabelAttribute') ? $b->status_label : $b->status }}
                        </span>
                    </td>
                    <td class="py-3 px-4">{{ $b->start_date?->format('Y-m-d') ?? '—' }}</td>
                    <td class="py-3 px-4">{{ $b->end_date?->format('Y-m-d') ?? '—' }}</td>
                    <td class="py-3 px-4">{{ $b->total_amount !== null ? number_format($b->total_amount, 2) . ' ر.س' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-6 text-center text-gray-500">لا توجد بيانات.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $bookings->appends($params)->links() }}
    </div>

    {{-- Chart.js CDN (لو غير مضمّن ضمن Vite) --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const labels         = @json($labels);
        const bookingsSeries = @json($bookingsSeries);
        const revenueSeries  = @json($revenueSeries);
        const brand = '#2f4b46';

        new Chart(document.getElementById('bookingsChart'), {
            type: 'bar',
            data: { labels, datasets: [{ label: 'عدد الحجوزات', data: bookingsSeries, backgroundColor: brand+'CC', borderColor: brand, borderWidth: 1.5, borderRadius: 8 }] },
            options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
        });

        new Chart(document.getElementById('revenueChart'), {
            type: 'bar',
            data: { labels, datasets: [{ label: 'الإيرادات (ر.س)', data: revenueSeries, backgroundColor: brand+'CC', borderColor: brand, borderWidth: 1.5, borderRadius: 8 }] },
            options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
        });
    </script>
@endsection