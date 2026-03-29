@extends('layouts.app')

@section('content')

<div style="width:90%; margin:40px auto;">

    <a href="{{ route('user.bookings') }}"
       style="color:#3E5A50; text-decoration:none; display:inline-block; margin-bottom:20px;">
        ← الرجوع إلى حجوزاتي
    </a>

    <div style="
        background:white;
        padding:25px;
        border-radius:20px;
        box-shadow:0 10px 25px rgba(0,0,0,0.08);
        border:1px solid #e5e5e5;
        text-align:right;
    ">

        <h2 style="font-size:24px; color:#3E5A50; font-weight:700; margin-bottom:18px;">
            تفاصيل الحجز #{{ $booking->id }}
        </h2>

        <p><strong>اسم الوحدة:</strong> {{ $booking->unit->name }}</p>
        <p><strong>الإجمالي:</strong> {{ $booking->total_amount }} ريال</p>
        <p><strong>تاريخ البداية:</strong> {{ $booking->start_date }}</p>
        <p><strong>تاريخ النهاية:</strong> {{ $booking->end_date }}</p>

    </div>

</div>

@endsection