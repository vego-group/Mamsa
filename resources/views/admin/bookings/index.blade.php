@extends('layouts.Admin', ['title' => 'إدارة الحجوزات'])

@section('content')
    <h1 class="text-2xl font-semibold text-[#2f4b46] mb-4">إدارة الحجوزات</h1>

    @if(session('success'))
        <div class="mb-4 bg-green-50 text-green-700 border border-green-200 rounded-xl p-3">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 bg-red-50 text-red-700 border border-red-200 rounded-xl p-3">{{ session('error') }}</div>
    @endif

    {{-- شريط الفلاتر --}}
    <form method="GET" action="{{ route('Admin.bookings.index') }}"
          class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3 mb-4 items-end">

        {{-- بحث نصّي --}}
        <div class="xl:col-span-2">
            <label class="block mb-1 text-sm text-gray-700">بحث</label>
            <input type="text" name="q" value="{{ $q }}"
                   placeholder="ابحث بالوحدة / الكود / اسم الحاجز / البريد"
                   class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] px-3 py-2 text-sm">
        </div>

        {{-- الحالة --}}
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

        {{-- الوحدة --}}
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

        {{-- التاريخ من --}}
        <div>
            <label class="block mb-1 text-sm text-gray-700">من</label>
            <input type="date" name="from" value="{{ $dateFrom }}"
                   class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] text-sm">
        </div>

        {{-- التاريخ إلى --}}
        <div>
            <label class="block mb-1 text-sm text-gray-700">إلى</label>
            <input type="date" name="to" value="{{ $dateTo }}"
                   class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] text-sm">
        </div>

        {{-- أزرار --}}
        <div class="flex items-center gap-2">
            <button type="submit"
                    class="px-4 py-2 rounded-lg bg-[#2f4b46] text-white hover:bg-[#2a433f] text-sm">
                بحث
            </button>

            @if($q || $status || $unitId || $dateFrom || $dateTo)
                <a href="{{ route('Admin.bookings.index') }}"
                   class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-sm">
                    مسح الفلاتر
                </a>
            @endif
        </div>
    </form>

    {{-- الجدول --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
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
                <th class="py-3 px-4 text-center">إجراءات</th>
            </tr>
            </thead>
            <tbody>
            @forelse($bookings as $b)
                <tr class="border-t">
                    <td class="py-3 px-4">{{ $b->id }}</td>
                    <td class="py-3 px-4">
                        {{ $b->unit?->unit_name }}
                        <span class="text-gray-500">({{ $b->unit?->code }})</span>
                    </td>
                    <td class="py-3 px-4">{{ $b->customer?->name ?? '—' }}</td>
                    <td class="py-3 px-4">
                        <span class="inline-flex px-3 py-1 rounded-full text-xs border {{ $b->status_class }}">
                            {{ $b->status_label }}
                        </span>
                    </td>
                    <td class="py-3 px-4">{{ $b->start_date?->format('Y-m-d') ?? '—' }}</td>
                    <td class="py-3 px-4">{{ $b->end_date?->format('Y-m-d') ?? '—' }}</td>
                    <td class="py-3 px-4">
                        {{ $b->total_amount !== null ? number_format($b->total_amount, 2) . ' ر.س' : '—' }}
                    </td>
                    <td class="py-3 px-4 text-center">
                        <div class="inline-flex items-center gap-2">
                            @can('update', $b)
                                {{-- تعديل الحالة سريعاً --}}
                                <form action="{{ route('Admin.bookings.update', $b->id) }}" method="POST"
                                      class="inline-flex items-center gap-2">
                                    @csrf
                                    @method('PUT')
                                    <select name="status" class="rounded-md border-gray-300 text-xs">
                                        <option value="new"       @selected($b->status==='new')>جديد</option>
                                        <option value="confirmed" @selected($b->status==='confirmed')>مؤكّد</option>
                                        <option value="completed" @selected($b->status==='completed')>مكتمل</option>
                                        <option value="cancelled" @selected($b->status==='cancelled')>ملغي</option>
                                    </select>
                                    <button class="px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 text-xs">
                                        حفظ
                                    </button>
                                </form>
                            @endcan

                            @can('delete', $b)
                                <form action="{{ route('Admin.bookings.destroy', $b->id) }}" method="POST"
                                      onsubmit="return confirm('تأكيد حذف الحجز؟');" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button class="px-3 py-1.5 rounded-lg bg-red-600 text-white hover:bg-red-700 text-xs">
                                        حذف
                                    </button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="py-6 text-center text-gray-500">لا توجد حجوزات.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $bookings->appends([
            'q' => $q,
            'status' => $status,
            'unit_id' => $unitId,
            'from' => $dateFrom,
            'to' => $dateTo,
        ])->links() }}
    </div>
@endsection