@extends('layouts.admin', ['title' => 'تفاصيل الطلب'])

@section('content')

<h1 class="text-2xl font-bold text-[#2f4b46] mb-6">
    تفاصيل الوحدة: {{ $unit->unit_name }}
</h1>

{{-- معلومات أساسية --}}
<div class="bg-white p-6 rounded-xl border mb-6">
    <h2 class="font-semibold text-lg mb-3">معلومات الوحدة</h2>

    <p><strong>الكود:</strong> {{ $unit->code }}</p>
    <p><strong>المالك:</strong> {{ $unit->user->name }}</p>
    <p><strong>السعر:</strong> {{ $unit->price }} ر.س</p>
    <p><strong>النوع:</strong> {{ $unit->unit_type }}</p>
    <p><strong>المدينة:</strong> {{ $unit->city }}</p>
    <p><strong>الحي:</strong> {{ $unit->district }}</p>
    <p><strong>الوصف:</strong> {{ $unit->description }}</p>
</div>

{{-- التصاريح --}}
<div class="bg-white p-6 rounded-xl border mb-6">
    <h2 class="font-semibold text-lg mb-3">التصاريح</h2>

    {{-- ✅ أفراد --}}
    @if(!empty($unit->tourism_permit_no))
        <p>
            <strong>نوع الطلب:</strong> فرد
        </p>

        <p class="mt-2">
            <strong>رقم التصريح السياحي:</strong>
            {{ $unit->tourism_permit_no }}
        </p>

        @if(optional($unit->user->AdminDetails)->national_id)
            <p class="mt-2">
                <strong>رقم الهوية:</strong>
                {{ $unit->user->AdminDetails->national_id }}
            </p>
        @endif
    @endif

    {{-- ✅ شركات --}}
    @if(!empty($unit->company_license_no))
        <p>
            <strong>نوع الطلب:</strong> شركة
        </p>

        <p class="mt-2">
            <strong>رقم ترخيص الشركة:</strong>
            {{ $unit->company_license_no }}
        </p>

        @if(optional($unit->user->AdminDetails)->cr_number)
            <p class="mt-2">
                <strong>رقم السجل التجاري (CR):</strong>
                {{ $unit->user->AdminDetails->cr_number }}
            </p>
        @endif
    @endif

    {{-- ملف التصريح --}}
    @if(!empty($unit->tourism_permit_file))
        <p class="mt-3">
            <strong>ملف التصريح:</strong>
        </p>
        <a href="{{ asset('storage/' . $unit->tourism_permit_file) }}"
           target="_blank"
           class="text-blue-600 underline">
            عرض الملف
        </a>
    @endif
</div>

{{-- المميزات --}}
<div class="bg-white p-6 rounded-xl border mb-6">
    <h2 class="font-semibold text-lg mb-3">المميزات</h2>

    @if($unit->features->isEmpty())
        <p class="text-gray-500">لا يوجد مميزات.</p>
    @else
        <ul class="list-disc pr-6">
            @foreach($unit->features as $f)
                <li>{{ $f->name }}</li>
            @endforeach
        </ul>
    @endif
</div>

{{-- الصور --}}
<div class="bg-white p-6 rounded-xl border mb-6">
    <h2 class="font-semibold text-lg mb-3">صور الوحدة</h2>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach($unit->images as $img)
            <img src="{{ asset('storage/' . $img->image_url) }}"
                 class="rounded-xl border">
        @endforeach
    </div>
</div>

{{-- قبول / رفض --}}
<div class="flex gap-4">

    {{-- زر القبول --}}
    <form method="POST" action="{{ route('Admin.requests.approve', $unit->id) }}">
        @csrf
        <button type="submit"
            class="px-6 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700">
            ✅ قبول
        </button>
    </form>

    {{-- زر الرفض --}}
    <button onclick="document.getElementById('rejectModal').showModal()"
        class="px-6 py-3 bg-red-600 text-white rounded-xl hover:bg-red-700">
        ❌ رفض
    </button>

</div>

{{-- نافذة سبب الرفض --}}
<dialog id="rejectModal" class="rounded-xl p-6 border w-full max-w-lg">
    <h2 class="text-lg font-semibold mb-4">سبب الرفض</h2>

    <form method="POST" action="{{ route('Admin.requests.reject', $unit->id) }}">
        @csrf

        <textarea name="reason"
                  rows="4"
                  class="w-full p-3 border rounded-xl"
                  placeholder="اكتب سبب الرفض..."
                  required></textarea>

        <div class="flex justify-end mt-4">
            <button type="submit"
                class="px-6 py-3 bg-red-600 text-white rounded-xl">
                رفض
            </button>
        </div>
    </form>
</dialog>

@endsection
