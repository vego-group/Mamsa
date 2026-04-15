@extends('layouts.app')

@section('content')

<div style="text-align:center;margin-top:120px;">

    <h1>🎉 تم الحجز بنجاح</h1>

    <p>رقم الحجز: {{ $booking->id }}</p>

    <a href="{{ route('home') }}" class="btn" style="margin-top:20px;">
        العودة للرئيسية
    </a>

</div>

@endsection