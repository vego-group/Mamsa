@extends('emails.layout')

@section('title', 'حجز جديد مؤكد')

@section('content')
    <p style="margin:0 0 16px;font-size:16px;">مرحباً {{ $partnerName }}،</p>
    <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#4b5563;">
        تم تأكيد حجز جديد على وحدتك. التفاصيل:
    </p>

    @include('emails.partials.booking-summary', ['rows' => [
        'الضيف'                    => $booking->user->name ?? '—',
        'صافي مستحقاتك (بعد العمولة)' => number_format($partnerShare, 2).' SAR',
    ]])

    <p style="margin:0;font-size:13px;color:#6b7280;">
        الصافي = إجمالي الحجز ناقص عمولة مَمسَى المجمّدة على هذا الحجز. تفاصيل أكثر في لوحة الشريك.
    </p>
@endsection
