@extends('layouts.admin', ['title' => 'إدارة الوحدات'])

@section('content')
    <h1 class="text-2xl font-semibold text-[#2f4b46] mb-4">إدارة الوحدات</h1>

    {{-- فلاش --}}
    @if(session('success'))
        <div class="mb-4 bg-green-50 text-green-700 border border-green-200 rounded-xl p-3">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 bg-red-50 text-red-700 border border-red-200 rounded-xl p-3">{{ session('error') }}</div>
    @endif

    {{-- شريط علوي: بحث + إضافة --}}
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
        <form method="GET" action="{{ route('admin.units.index') }}" class="flex items-center gap-2">
            <input type="text" name="q" value="{{ $q }}"
                   placeholder="ابحث بالاسم / الكود / الوصف"
                   class="w-72 rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] px-3 py-2 text-sm">

            <select name="status" class="rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] px-2 py-2 text-sm">
                <option value="">كل الحالات</option>
                <option value="available"   @selected($status==='available')>متاحة</option>
                <option value="unavailable" @selected($status==='unavailable')>غير متاحة</option>
                <option value="reserved"    @selected($status==='reserved')>محجوزة</option>
            </select>

            <input type="number" step="0.01" name="price_from" value="{{ $priceFrom }}"
                   placeholder="السعر من"
                   class="w-36 rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] px-3 py-2 text-sm">
            <input type="number" step="0.01" name="price_to" value="{{ $priceTo }}"
                   placeholder="السعر إلى"
                   class="w-36 rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] px-3 py-2 text-sm">

            @if(auth()->user()->hasRole('super_admin'))
                <select name="owner_id"
                        class="rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] px-2 py-2 text-sm">
                    <option value="">المالك: الكل</option>
                    @foreach($ownersList as $o)
                        <option value="{{ $o->id }}" @selected((string)$ownerId===(string)$o->id)>{{ $o->name }}</option>
                    @endforeach
                </select>
            @endif

            <button type="submit"
                    class="px-4 py-2 rounded-lg bg-[#2f4b46] text-white hover:bg-[#2a433f] text-sm">
                بحث
            </button>

            @if($q || $status || $priceFrom || $priceTo || $ownerId)
                <a href="{{ route('admin.units.index') }}"
                   class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-sm">
                    مسح
                </a>
            @endif
        </form>

        @can('create', \App\Models\Unit::class)
            <a href="{{ route('admin.units.create') }}"
               class="inline-flex items-center px-4 py-2 rounded-lg bg-[#2f4b46] text-white hover:bg-[#2a433f] text-sm">
                + إضافة وحدة
            </a>
        @endcan
    </div>

    {{-- الجدول --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-700">
                <tr>
                    <th class="py-3 px-4 text-right">#</th>
                    <th class="py-3 px-4 text-right">الاسم</th>
                    <th class="py-3 px-4 text-right">الكود</th>
                    <th class="py-3 px-4 text-right">السعر</th>
                    <th class="py-3 px-4 text-right">الحالة</th>
                    <th class="py-3 px-4 text-right">الصور</th>
                    <th class="py-3 px-4 text-right">المالك</th>
                    <th class="py-3 px-4 text-center">التقويم</th>
                    <th class="py-3 px-4 text-center">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($units as $u)
                    @php
                        $firstImage = optional($u->images->first())->image_url;
                        $thumb = $firstImage ? asset('storage/'.$firstImage) : null;
                    @endphp
                    <tr class="border-t">
                        <td class="py-3 px-4">{{ $u->id }}</td>
                        <td class="py-3 px-4 font-medium">{{ $u->name }}</td>
                        <td class="py-3 px-4 font-mono">{{ $u->code }}</td>
                        <td class="py-3 px-4">{{ $u->price !== null ? number_format($u->price,2) : '-' }}</td>
                        <td class="py-3 px-4">
                            <span class="inline-flex px-3 py-1 rounded-full text-xs border {{ $u->status_class }}">
                                {{ $u->status_label }}
                            </span>
                        </td>
                        <td class="py-3 px-4">
                            @if($thumb)
                                <img src="{{ $thumb }}" alt="" class="w-16 h-12 rounded-lg object-cover border border-gray-200">
                                <div class="text-gray-500 text-xs mt-1">{{ $u->images->count() }} صورة</div>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-3 px-4">{{ optional($u->owner)->name ?? '-' }}</td>
                        <td class="py-3 px-4 text-center">
                            @if($u->calendar_public_url)
                                <div class="inline-flex items-center gap-2">
                                    <a href="{{ $u->calendar_public_url }}"
                                       class="px-3 py-1.5 rounded-lg bg-gray-100 hover:bg-gray-200 text-xs"
                                       target="_blank" rel="noopener">
                                        iCal (ICS)
                                    </a>
                                    @can('update', $u)
                                        <button type="button"
                                            class="px-3 py-1.5 rounded-lg bg-gray-100 hover:bg-gray-200 text-xs"
                                            onclick="navigator.clipboard.writeText('{{ $u->calendar_public_url }}').then(()=>alert('تم نسخ رابط التقويم'));">
                                            نسخ الرابط
                                        </button>
                                        <form action="{{ route('admin.units.calendar.rotate', $u->id) }}"
                                              method="POST"
                                              onsubmit="return confirm('تأكيد: تجديد الرابط سيُبطل الرابط السابق. متابعة؟');"
                                              class="inline">
                                            @csrf
                                            @method('PUT')
                                            <button class="px-3 py-1.5 rounded-lg bg-yellow-100 text-yellow-800 hover:bg-yellow-200 border border-yellow-300 text-xs">
                                                تجديد الرابط
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-center">
                            <div class="inline-flex items-center gap-2">
                                @can('update', $u)
                                    <a href="{{ route('admin.units.edit', $u->id) }}"
                                       class="px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 text-xs">تعديل</a>
                                @endcan
                                @can('delete', $u)
                                    <form action="{{ route('admin.units.destroy', $u->id) }}"
                                          method="POST"
                                          onsubmit="return confirm('حذف الوحدة وجميع صورها؟');"
                                          class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button class="px-3 py-1.5 rounded-lg bg-red-600 text-white hover:bg-red-700 text-xs">حذف</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="py-6 text-center text-gray-500">لا توجد وحدات.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ترقيم --}}
    <div class="mt-4">
        {{ $units->links() }}
    </div>
@endsection