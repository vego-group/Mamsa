@extends('layouts.app')

@section('content')

@php
$profile = auth()->user()->partner;
@endphp

<div class="container py-60">

<h1 class="title mb-40">
لوحة تحكم الشريك
</h1>

@if($profile)

    @if($profile->type === 'company' && $profile->verification_status === 'pending')
        <div class="alert alert-warning">
            حسابك كشركة قيد المراجعة. لا يمكنك إضافة وحدات حتى يتم اعتماد التصريح.
        </div>
    @endif

    @if($profile->type === 'individual')
        <div class="alert alert-info">
            الوحدات المضافة من الأفراد تحتاج موافقة الإدارة قبل النشر.
        </div>
    @endif

@endif

<div class="grid grid-3">

    {{-- وحداتي --}}
    <div class="card text-center">
        <h3 style="margin-bottom:15px;">وحداتي</h3>
        <p class="muted mb-20">إدارة جميع الوحدات المضافة</p>

        @if($profile && $profile->type === 'company' && $profile->verification_status !== 'approved')
            <button class="btn" disabled style="background:#ccc;cursor:not-allowed;">
                بانتظار اعتماد الشركة
            </button>
        @else
            <a href="{{ route('partner.unit.create') }}" class="btn">
               إضافة وحدة جديدة
            </a>
        @endif
    </div>

    {{-- حالة التحقق --}}
    <div class="card text-center">
        <h3 style="margin-bottom:15px;">حالة التحقق</h3>

        @if(!$profile)
            <p class="muted">لم يتم رفع بيانات التصريح بعد.</p>
        @else
            <p class="muted">
                نوع الحساب: {{ $profile->type === 'company' ? 'شركة' : 'فرد' }}
            </p>

            <p style="margin-top:10px;font-weight:600;">
                الحالة:
                @if($profile->verification_status === 'approved')
                    <span style="color:green;">معتمد</span>
                @elseif($profile->verification_status === 'pending')
                    <span style="color:orange;">قيد المراجعة</span>
                @else
                    <span style="color:red;">مرفوض</span>
                @endif
            </p>
        @endif
    </div>

    {{-- المدفوعات --}}
    <div class="card text-center">
        <h3 style="margin-bottom:15px;">المدفوعات</h3>
        <p class="muted">
            تفاصيل الاشتراك ستتوفر قريبًا
        </p>
    </div>

</div>

</div>

@endsection