@extends('layouts.app')

@section('content')

<div style="width:90%; margin:40px auto;">

    <h2 style="color:#3E5A50; font-size:26px; font-weight:700; margin-bottom:25px; text-align:right;">
        حجوزاتي
    </h2>

    @forelse ($bookings as $booking)

        <div style="
            background:white;
            border-radius:18px;
            padding:20px;
            margin-bottom:20px;
            border:1px solid #e5e5e5;
            box-shadow:0 5px 20px rgba(0,0,0,0.08);
            text-align:right;
        ">
            
            <h3 style="font-size:18px; font-weight:700; color:#3E5A50; margin-bottom:8px;">
                {{ $booking->unit->name ?? '—' }}
            </h3>

            <p style="color:#666; margin:3px 0;">الحالة: 
                <span style="
                    padding:5px 12px;
                    border-radius:12px;
                    color:white;
                    background:
                    @if ($booking->status == 'confirmed') #ca8a04
                    @elseif ($booking->status == 'completed') #16a34a
                    @elseif ($booking->status == 'cancelled') #dc2626
                    @else #2563eb @endif
                ">
                    {{ $booking->status_label }}
                </span>
            </p>

            <p style="color:#666; margin:3px 0;">من: {{ $booking->start_date }}</p>
            <p style="color:#666; margin:3px 0;">إلى: {{ $booking->end_date }}</p>

            <div style="margin-top:15px;">

                <a href="{{ route('user.bookings.show', $booking->id) }}"
                   style="
                        background:#3E5A50;
                        color:white;
                        padding:8px 15px;
                        border-radius:10px;
                        text-decoration:none;
                        box-shadow:0 2px 5px rgba(0,0,0,0.15);
                   ">
                    عرض التفاصيل
                </a>

            </div>

        </div>

    @empty

        <p style="color:#999; text-align:center; font-size:18px;">
            لا توجد حجوزات حتى الآن.
        </p>

    @endforelse

</div>

@endsection