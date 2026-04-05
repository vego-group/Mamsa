@extends('layouts.admin', ['title' => 'الرئيسية'])

@section('content')
    <h1 class="text-3xl font-bold text-[#2f4b46] mb-6">
        {{ $isSuper ? 'المشرف العام' : 'لوحة المشرف' }}
    </h1>

    {{-- البطاقات --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
            <div class="text-4xl font-semibold text-[#2f4b46]">{{ number_format($unitsCount) }}</div>
            <div class="mt-2 text-sm text-gray-600">عدد الوحدات {{ $isSuper ? '(الكل)' : '(الخاصة بي)' }}</div>
        </div>

        {{-- بطاقة عدد المستخدمين — تظهر للسوبر أدمن فقط --}}
        @if($isSuper)
            <div class="bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
                <div class="text-4xl font-semibold text-[#2f4b46]">{{ number_format($usersCount) }}</div>
                <div class="mt-2 text-sm text-gray-600">عدد المستخدمين</div>
            </div>
        @endif

        <div class="bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
            <div class="text-4xl font-semibold text-[#2f4b46]">
                {{ number_format($revenueTotal, 2) }} <span class="text-base">ر.س</span>
            </div>
            <div class="mt-2 text-sm text-gray-600">إجمالي الإيرادات {{ $isSuper ? '(الكل)' : '(الخاصة بي)' }}</div>
        </div>

        <div class="bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
            <div class="text-4xl font-semibold text-[#2f4b46]">{{ number_format($bookingsCount) }}</div>
            <div class="mt-2 text-sm text-gray-600">عدد الحجوزات {{ $isSuper ? '(الكل)' : '(الخاصة بي)' }}</div>
        </div>
    </div>

    {{-- المخططات --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
            <div class="mb-2 text-sm text-gray-700">التغير الشهري في الإيرادات (آخر 12 شهر)</div>
            <canvas id="revenueChart" height="130"></canvas>
        </div>

        <div class="bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
            <div class="mb-2 text-sm text-gray-700">عدد الحجوزات خلال الأشهر (آخر 12 شهر)</div>
            <canvas id="bookingsChart" height="130"></canvas>
        </div>
    </div>

    {{-- تحميل Chart.js من CDN إن ما كان موجود ضمن Vite --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const revLabels   = @json($revLabels);
        const revDataset  = @json($revDataset);
        const bookLabels  = @json($bookLabels);
        const bookDataset = @json($bookDataset);
        const brand = '#2f4b46';

        new Chart(document.getElementById('revenueChart'), {
            type: 'bar',
            data: {
                labels: revLabels,
                datasets: [{
                    label: 'الإيرادات (ر.س)',
                    data: revDataset,
                    backgroundColor: brand + 'CC',
                    borderColor: brand,
                    borderWidth: 1.5,
                    borderRadius: 8,
                }]
            },
            options: {
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { legend: { display: false } }
            }
        });

        new Chart(document.getElementById('bookingsChart'), {
            type: 'bar',
            data: {
                labels: bookLabels,
                datasets: [{
                    label: 'عدد الحجوزات',
                    data: bookDataset,
                    backgroundColor: brand + 'CC',
                    borderColor: brand,
                    borderWidth: 1.5,
                    borderRadius: 8,
                }]
            },
            options: {
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { legend: { display: false } }
            }
        });
    </script>
@endsection