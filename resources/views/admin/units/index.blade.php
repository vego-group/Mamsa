@extends('layouts.admin', ['title' => 'إدارة الوحدات'])

@section('content')
    <h1 class="text-2xl font-semibold text-[#2f4b46] mb-4">إدارة الوحدات</h1>

    {{-- فلاش --}}
    @if(session('success'))
        <div class="mb-4 bg-green-50 text-green-700 border border-green-200 rounded-xl p-3">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 bg-red-50 text-red-700 border border-red-200 rounded-xl p-3">
            {{ session('error') }}
        </div>
    @endif

    {{-- بحث + إضافة --}}
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
        <form method="GET" action="{{ route('admin.units.index') }}" class="flex items-center gap-2">
            <input type="text" name="q" value="{{ request('q') }}"
                   placeholder="ابحث بالاسم / الكود / الوصف"
                   class="w-72 rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] px-3 py-2 text-sm">
            <button type="submit" class="px-4 py-2 rounded-lg bg-[#2f4b46] text-white hover:bg-[#2a433f] text-sm">
                بحث
            </button>
            @if(request('q'))
                <a href="{{ route('admin.units.index') }}"
                   class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-sm">
                    مسح البحث
                </a>
            @endif
        </form>

        @can('create', \App\Models\Unit::class)
        {{-- زر الإضافة محذوف لأن صفحة الإنشاء عند زميلتك --}}
{{-- <a href="{{ route('admin.units.create') }}" class="...">
    + إضافة وحدة
</a> --}}
        @endcan
    </div>

    {{-- الجدول --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-700">
            <tr>
                <th class="py-3 px-4 text-right">#</th>
                <th class="py-3 px-4 text-right">اسم الوحدة</th>
                <th class="py-3 px-4 text-right">الكود</th>
                <th class="py-3 px-4 text-right">الحالة</th>
                <th class="py-3 px-4 text-right">السعر</th>
                @role('super_admin')
                <th class="py-3 px-4 text-right">المالك</th>
                @endrole
                <th class="py-3 px-4 text-center">إجراءات</th>
            </tr>
            </thead>
            <tbody>
            @forelse($units as $u)
                @php
                    // في الموديل ضفنا accessors: status_label و status_class
                    $priceText = $u->price !== null ? number_format($u->price, 2) . ' ر.س' : '-';
                @endphp
                <tr class="border-t">
                    <td class="py-3 px-4">{{ $u->id }}</td>
                    <td class="py-3 px-4">{{ $u->name }}</td>
                    <td class="py-3 px-4 font-mono">{{ $u->code }}</td>
                    <td class="py-3 px-4">
                        <span class="inline-flex px-3 py-1 rounded-full text-xs border {{ $u->status_class }}">
                            {{ $u->status_label }}
                        </span>
                    </td>
                    <td class="py-3 px-4">{{ $priceText }}</td>
                    @role('super_admin')
                    <td class="py-3 px-4">{{ $u->owner?->name ?? '-' }}</td>
                    @endrole
                    <td class="py-3 px-4 text-center">
                        <div class="inline-flex items-center gap-2">
                            @can('update', $u)
                                <a href="{{ route('admin.units.edit', $u->id) }}"
                                   class="px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 text-xs">
                                    تعديل
                                </a>
                            @endcan

                            @can('delete', $u)
                                <form method="POST" action="{{ route('admin.units.destroy', $u->id) }}"
                                      onsubmit="return confirm('هل أنت متأكد من حذف هذه الوحدة؟');"
                                      class="inline">
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
                <tr>
                    <td colspan="@role('super_admin')7 @else 6 @endrole"
                        class="py-6 text-center text-gray-500">
                        لا توجد وحدات.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $units->links() }}
    </div>
@endsection