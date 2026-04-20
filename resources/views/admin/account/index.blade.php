@extends('layouts.admin', ['title' => 'الحساب'])

@section('content')

<h1 class="text-2xl font-bold text-[#2f4b46] mb-6">الحساب</h1>

<div class="bg-white p-6 rounded-2xl border border-[#2f4b46]/30">

    {{-- معلومات أساسية --}}
    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">الاسم</label>
        <input type="text" class="w-full rounded-lg border-gray-300 px-3 py-2"
               value="{{ auth()->user()->name }}" readonly>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">الجوال</label>
        <input type="text" class="w-full rounded-lg border-gray-300 px-3 py-2"
               value="{{ auth()->user()->phone }}" readonly>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">الإيميل</label>
        <input type="text" class="w-full rounded-lg border-gray-300 px-3 py-2"
               value="{{ auth()->user()->email }}" readonly>
    </div>

    {{-- ✅ معلومات إضافية من جدول Admin_details --}}
    @php $d = auth()->user()->AdminDetails; @endphp

    @if($d)

        @if($d->type === 'individual')
            <div class="mb-4">
                <label class="block text-sm text-gray-600 mb-1">رقم الهوية</label>
                <input type="text" class="w-full rounded-lg border-gray-300 px-3 py-2"
                       value="{{ $d->national_id }}" readonly>
            </div>
        @endif

        @if($d->type === 'company')
            <div class="mb-4">
                <label class="block text-sm text-gray-600 mb-1">السجل التجاري (CR)</label>
                <input type="text" class="w-full rounded-lg border-gray-300 px-3 py-2"
                       value="{{ $d->cr_number }}" readonly>
            </div>
        @endif

    @endif

</div>

@endsection
