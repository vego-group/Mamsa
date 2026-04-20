@extends('layouts.admin', ['title' => 'الرئيسية'])

@section('content')

<h1 class="text-3xl font-bold text-[#2f4b46] mb-6">
    {{ $isSuper ? 'المشرف العام' : 'لوحة المشرف' }}
</h1>

{{-- ========================================================= --}}
{{--  البطاقات --}}

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-6 mb-8">

    {{-- عدد الوحدات --}}
    <div class="bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
        <div class="text-4xl font-semibold text-[#2f4b46]">
            {{ number_format($unitsCount) }}
        </div>
        <div class="mt-2 text-sm text-gray-600">
            عدد الوحدات ({{ $isSuper ? 'الكل' : 'الخاصة بي' }})
        </div>
    </div>

    {{-- الوحدات Pending (للسوبر فقط) --}}
    @if($isSuper)
    <div class="bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
        <div class="text-4xl font-semibold text-[#2f4b46]">
            {{ number_format($pendingUnitsCount) }}
        </div>
        <div class="mt-2 text-sm text-gray-600">وحدات تنتظر الموافقة</div>

        <a href="{{ route('Admin.requests.index') }}"
           class="inline-block mt-3 text-xs text-white bg-[#2f4b46] px-4 py-2 rounded-full hover:bg-[#263e3a] transition">
            عرض الطلبات
        </a>
    </div>
    @endif

    {{-- عدد المستخدمين (سوبر فقط) --}}
    @if($isSuper)
    <div class="bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
        <div class="text-4xl font-semibold text-[#2f4b46]">
            {{ number_format($usersCount) }}
        </div>
        <div class="mt-2 text-sm text-gray-600">عدد المستخدمين</div>
    </div>
    @endif

    {{-- عدد الحجوزات --}}
    <div class="bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
        <div class="text-4xl font-semibold text-[#2f4b46]">
            {{ number_format($bookingsCount) }}
        </div>
        <div class="mt-2 text-sm text-gray-600">
            عدد الحجوزات ({{ $isSuper ? 'الكل' : 'الخاصة بي' }})
        </div>
    </div>

    {{-- إجمالي الإيرادات --}}
    <div class="bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
        <div class="text-4xl font-semibold text-[#2f4b46]">
            {{ number_format($revenueTotal, 2) }}
            <span class="text-base">ر.س</span>
        </div>
        <div class="mt-2 text-sm text-gray-600">
            إجمالي الإيرادات ({{ $isSuper ? 'الكل' : 'الخاصة بي' }})
        </div>
    </div>

</div>



{{-- ========================================================= --}}
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



{{-- ========================================================= --}}
{{-- أحدث الوحدات --}}
@if($isSuper)
<div class="mt-10 bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
    <h2 class="text-lg font-semibold mb-4">أحدث الوحدات</h2>

    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-700">
            <tr>
                <th class="py-3 px-4 text-right">الوحدة</th>
                <th class="py-3 px-4 text-right">الحالة</th>
                <th class="py-3 px-4 text-right">تاريخ الإضافة</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lastUnits as $u)
            <tr class="border-t">
                <td class="py-3 px-4">{{ $u->unit_name }}</td>
                <td class="py-3 px-4">{{ $u->approval_status }}</td>
                <td class="py-3 px-4">{{ $u->created_at->format('Y-m-d') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif



{{-- ========================================================= --}}
{{-- أحدث الحجوزات --}}
<div class="mt-10 bg-white rounded-2xl border border-[#2f4b46]/30 p-6">
    <h2 class="text-lg font-semibold mb-4">أحدث الحجوزات</h2>

    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-700">
            <tr>
                <th class="py-3 px-4 text-right">الوحدة</th>
                <th class="py-3 px-4 text-right">العميل</th>
                <th class="py-3 px-4 text-right">التاريخ</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lastBookings as $b)
            <tr class="border-t">
                <td class="py-3 px-4">{{ $b->unit->unit_name ?? '-' }}</td>
                <td class="py-3 px-4">{{ $b->customer->name ?? '-' }}</td>
                <td class="py-3 px-4">{{ $b->created_at->format('Y-m-d') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>




{{-- ========================================================= --}}
{{-- Chart JS --}}
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
        options: { scales: { y: { beginAtZero: true }}, plugins: { legend: { display: false }} }
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
        options: { scales: { y: { beginAtZero: true }}, plugins: { legend: { display: false }} }
    });
</script>

@endsection
