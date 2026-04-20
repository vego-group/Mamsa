@extends('layouts.admin', ['title' => 'الطلبات'])

@section('content')

<h1 class="text-2xl font-bold text-[#2f4b46] mb-6">الطلبات</h1>

@php
    $activeTab = request('tab', 'individual'); // individuals | companies
@endphp

{{-- Tabs --}}
<div class="flex gap-3 mb-6">
    <a href="{{ route('Admin.requests.index', ['tab' => 'individual']) }}"
       class="px-4 py-2 rounded-xl text-sm
           {{ $activeTab === 'individual'
               ? 'bg-[#2f4b46] text-white'
               : 'bg-gray-200 text-gray-700' }}">
        الأفراد
    </a>

    <a href="{{ route('Admin.requests.index', ['tab' => 'companies']) }}"
       class="px-4 py-2 rounded-xl text-sm
           {{ $activeTab === 'companies'
               ? 'bg-[#2f4b46] text-white'
               : 'bg-gray-200 text-gray-700' }}">
        الشركات
    </a>
</div>

{{-- ================================================= --}}
{{-- طلبات الأفراد --}}
{{-- ================================================= --}}
@if($activeTab === 'individual')
    <h2 class="text-xl font-semibold mb-4">طلبات الأفراد</h2>

    @if($individualUnits->isEmpty())
        <div class="bg-white p-4 rounded-xl border">
            لا يوجد طلبات أفراد حالياً.
        </div>
    @else
        <div class="bg-white rounded-xl border overflow-hidden mb-8">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-right">اسم الوحدة</th>
                        <th class="px-4 py-3 text-right">المالك</th>
                        <th class="px-4 py-3 text-right">الكود</th>
                        <th class="px-4 py-3 text-center">الإجراء</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($individualUnits as $unit)
                        <tr class="border-t">
                            <td class="px-4 py-3 font-medium">{{ $unit->unit_name }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $unit->user->name }}</td>
                            <td class="px-4 py-3 font-mono">{{ $unit->code }}</td>
                            <td class="px-4 py-3 text-center">
                                <a href="{{ route('Admin.requests.show', $unit->id) }}"
                                   class="px-4 py-2 rounded-lg bg-blue-600 text-white text-xs">
                                    عرض التفاصيل
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endif

{{-- ================================================= --}}
{{-- طلبات الشركات --}}
{{-- ================================================= --}}
@if($activeTab === 'companies')
    <h2 class="text-xl font-semibold mb-4">طلبات الشركات</h2>

    @if($companyUnits->isEmpty())
        <div class="bg-white p-4 rounded-xl border">
            لا يوجد طلبات شركات حالياً.
        </div>
    @else
        <div class="bg-white rounded-xl border overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-right">اسم الوحدة</th>
                        <th class="px-4 py-3 text-right">الشركة</th>
                        <th class="px-4 py-3 text-right">الكود</th>
                        <th class="px-4 py-3 text-center">الإجراء</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($companyUnits as $unit)
                        <tr class="border-t">
                            <td class="px-4 py-3 font-medium">{{ $unit->unit_name }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $unit->user->name }}</td>
                            <td class="px-4 py-3 font-mono">{{ $unit->code }}</td>
                            <td class="px-4 py-3 text-center">
                                <a href="{{ route('Admin.requests.show', $unit->id) }}"
                                   class="px-4 py-2 rounded-lg bg-blue-600 text-white text-xs">
                                    عرض التفاصيل
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endif

@endsection
