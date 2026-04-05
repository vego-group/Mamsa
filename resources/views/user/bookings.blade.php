@extends('layouts.app')

@section('content')

<div class="profile-page">

    <h2 class="profile-title">حجوزاتي</h2>

    @forelse ($bookings as $booking)
        <div class="booking-card">
            <p>رقم الحجز: {{ $booking->booking_id }}</p>
            <p>الوحدة: {{ $booking->unit->name ?? '—' }}</p>
            <p>من: {{ $booking->start_date }}</p>
            <p>إلى: {{ $booking->end_date }}</p>
            <p>الحالة: {{ $booking->booking_status }}</p>
        </div>
    @empty
        <p style="color:#666">لا توجد حجوزات بعد.</p>
    @endforelse

</div>

@endsection