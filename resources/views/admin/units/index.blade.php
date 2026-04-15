@extends('layouts.Admin', ['title' => 'إدارة الوحدات'])

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

@php
    $user = auth()->user();
    $activeTab = request('tab', 'pending');

    $filteredUnits = $units->filter(function ($u) use ($activeTab, $user) {

        // 👑 SuperAdmin
        if ($user->hasRole('SuperAdmin')) {

            if ($activeTab === 'individual') {
                return !is_null($u->tourism_permit_no);
            }

            if ($activeTab === 'company') {
                return !is_null($u->company_license_no);
            }

            if ($activeTab === 'mine') {
                return $u->user_id === $user->id;
            }

            return true;
        }

        // 👤 Admin
        return $u->approval_status === $activeTab;
    });
@endphp

{{-- شريط علوي --}}
<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">

    {{-- بحث --}}
    <form method="GET" action="{{ route('Admin.units.index') }}" class="flex items-center gap-2">
        <input type="hidden" name="tab" value="{{ $activeTab }}">

        <input type="text" name="q" value="{{ request('q') }}"
               placeholder="ابحث بالاسم / الكود"
               class="w-72 rounded-lg border-gray-300 px-3 py-2 text-sm">

        <button class="px-4 py-2 rounded-lg bg-[#2f4b46] text-white text-sm">
            بحث
        </button>

        @if(request('q'))
            <a href="{{ route('Admin.units.index', ['tab'=>$activeTab]) }}"
               class="px-3 py-2 rounded-lg bg-gray-100 text-sm">
                مسح
            </a>
        @endif
    </form>

    {{-- إضافة --}}
    @can('create', \App\Models\Unit::class)
        <a href="{{ route('Admin.units.create') }}"
           class="px-4 py-2 rounded-lg bg-[#2f4b46] text-white text-sm">
            + إضافة وحدة
        </a>
    @endcan
</div>

{{-- Tabs --}}
<div class="flex items-center gap-2 mb-4">

    {{-- 👑 SuperAdmin --}}
    @if($user->hasRole('SuperAdmin'))

        <a href="{{ route('Admin.units.index', ['tab'=>'individual']) }}"
           class="px-4 py-2 rounded-lg text-sm
           {{ $activeTab==='individual' ? 'bg-[#2f4b46] text-white' : 'bg-white border text-gray-700' }}">
            الأفراد
        </a>

        <a href="{{ route('Admin.units.index', ['tab'=>'company']) }}"
           class="px-4 py-2 rounded-lg text-sm
           {{ $activeTab==='company' ? 'bg-[#2f4b46] text-white' : 'bg-white border text-gray-700' }}">
            الشركات
        </a>

        <a href="{{ route('Admin.units.index', ['tab'=>'mine']) }}"
           class="px-4 py-2 rounded-lg text-sm
           {{ $activeTab==='mine' ? 'bg-[#2f4b46] text-white' : 'bg-white border text-gray-700' }}">
            وحداتي
        </a>

    {{-- 👤 Admin --}}
    @else

        <a href="{{ route('Admin.units.index', ['tab'=>'pending']) }}"
           class="px-4 py-2 rounded-lg text-sm
           {{ $activeTab==='pending' ? 'bg-[#2f4b46] text-white' : 'bg-white border text-gray-700' }}">
            قيد الموافقة
        </a>

        <a href="{{ route('Admin.units.index', ['tab'=>'approved']) }}"
           class="px-4 py-2 rounded-lg text-sm
           {{ $activeTab==='approved' ? 'bg-[#2f4b46] text-white' : 'bg-white border text-gray-700' }}">
            تمت الموافقة
        </a>

        <a href="{{ route('Admin.units.index', ['tab'=>'rejected']) }}"
           class="px-4 py-2 rounded-lg text-sm
           {{ $activeTab==='rejected' ? 'bg-[#2f4b46] text-white' : 'bg-white border text-gray-700' }}">
            مرفوضة
        </a>

    @endif

</div>

{{-- الجدول --}}
<div class="bg-white rounded-2xl border overflow-hidden">
<table class="w-full text-sm">

<thead class="bg-gray-50 text-gray-700">
<tr>
    <th class="px-4 py-3 text-right">#</th>
    <th class="px-4 py-3 text-right">الاسم</th>
    <th class="px-4 py-3 text-right">الكود</th>
    <th class="px-4 py-3 text-right">السعر</th>
    <th class="px-4 py-3 text-right">الحالة</th>
    <th class="px-4 py-3 text-right">الصور</th>
    <th class="px-4 py-3 text-center">إجراءات</th>
</tr>
</thead>

<tbody>
@forelse($filteredUnits as $u)

@php
    $img = optional($u->images->first())->image_url;
@endphp

<tr class="border-t">
    <td class="px-4 py-3">{{ $u->id }}</td>
    <td class="px-4 py-3 font-medium">{{ $u->unit_name }}</td>
    <td class="px-4 py-3 font-mono">{{ $u->code }}</td>
    <td class="px-4 py-3">
        {{ $u->price !== null ? number_format($u->price,2) : '-' }}
    </td>

    <td class="px-4 py-3">

    @if($u->approval_status === 'approved')
        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700 border border-green-200">
            ✓ تمت الموافقة
        </span>

    @elseif($u->approval_status === 'pending')
        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700 border border-yellow-200">
            ⏳ قيد الموافقة
        </span>

    @elseif($u->approval_status === 'rejected')
        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700 border border-red-200">
            ✕ مرفوضة
        </span>

       @if($u->approval_status === 'rejected' && $u->rejection_reason)
    <div class="mt-2 p-2 bg-red-50 border border-red-200 rounded-lg text-xs text-red-700">
        سبب الرفض: {{ $u->rejection_reason }}
    </div>
@endif

    @endif

</td>

    <td class="px-4 py-3">
        @if($img)
            <img src="{{ asset('storage/'.$img) }}"
                 class="w-16 h-12 rounded-lg border object-cover">
        @else
            <span class="text-gray-400">—</span>
        @endif
    </td>

  <td class="px-4 py-3 text-center">
    <div class="flex justify-center items-center gap-2 flex-wrap">

        {{-- تعديل --}}
        @can('update', $u)
            <a href="{{ route('Admin.units.edit', $u->id) }}"
               class="inline-flex items-center gap-1.5
                      px-3 py-1.5 rounded-lg
                      border border-blue-300
                      text-blue-700 bg-blue-50
                      text-xs font-medium
                      hover:bg-blue-100 transition">
                <span>تعديل</span>
            </a>
        @endcan

        {{-- نسخ رابط التقويم --}}
        @if($u->calendar_token)
            <button
                type="button"
                onclick="copyCalendarUrl('{{ $u->calendar_token }}', this)"
                class="inline-flex items-center gap-1.5
                       px-3 py-1.5 rounded-lg
                       border border-gray-300
                       text-gray-700 bg-gray-50
                       text-xs font-medium
                       hover:bg-gray-100 transition"
                title="نسخ رابط التقويم">
                <span>نسخ الرابط</span>
            </button>
        @endif

        {{-- حذف --}}
        @can('delete', $u)
            <form method="POST"
                  action="{{ route('Admin.units.destroy', $u->id) }}"
                  onsubmit="return confirm('هل أنت متأكد من الحذف؟');">
                @csrf
                @method('DELETE')
                <button
                    type="submit"
                    class="inline-flex items-center gap-1.5
                           px-3 py-1.5 rounded-lg
                           border border-red-300
                           text-red-700 bg-red-50
                           text-xs font-medium
                           hover:bg-red-100 transition">
                   
                    <span>حذف</span>
                </button>
            </form>
        @endcan

    </div>
</td>
</tr>

@empty
<tr>
    <td colspan="7" class="py-6 text-center text-gray-500">
        لا توجد وحدات.
    </td>
</tr>
@endforelse

</tbody>
</table>
</div>
<script>
function copyCalendarUrl(token, btn) {
    const url = "{{ url('/ical') }}/" + token;

    // fallback إذا clipboard API غير مدعوم
    function fallbackCopy(text) {
        const textarea = document.createElement("textarea");
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand("copy");
        document.body.removeChild(textarea);
    }

    const copyAction = navigator.clipboard
        ? navigator.clipboard.writeText(url)
        : new Promise((resolve) => {
            fallbackCopy(url);
            resolve();
        });

    copyAction.then(() => {
        const oldHtml = btn.innerHTML;

        btn.innerHTML = 'تم النسخ';
        btn.classList.remove('border-gray-300','bg-gray-50','text-gray-700');
        btn.classList.add('border-green-300','bg-green-50','text-green-700');

        setTimeout(() => {
            btn.innerHTML = oldHtml;
            btn.classList.remove('border-green-300','bg-green-50','text-green-700');
            btn.classList.add('border-gray-300','bg-gray-50','text-gray-700');
        }, 1400);
    }).catch(() => {
        alert('❌ فشل النسخ، حاول مرة أخرى');
    });
}
</script>

@endsection