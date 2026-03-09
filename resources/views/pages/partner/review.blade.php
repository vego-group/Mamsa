@extends('layouts.app')

@section('content')

<div class="center">
    <div class="card card-lg text-center" style="max-width:600px;">

        <h2 class="title mb-20">
            تم إرسال طلبك بنجاح
        </h2>

        <p class="muted mb-40">
            بيانات التصريح قيد المراجعة من قبل الإدارة.
            سيتم إشعارك فور اعتماد الحساب.
        </p>

        <a href="{{ route('home') }}" class="btn">
            العودة للرئيسية
        </a>

    </div>
</div>

@endsection
