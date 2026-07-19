@extends('emails.layout')

@section('title', 'إلغاء حجز على وحدتك')

@section('content')
    <p style="margin:0 0 16px;font-size:16px;">مرحباً {{ $partnerName }}،</p>
    <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#4b5563;">
        قام الضيف بإلغاء الحجز التالي على وحدتك، وتم تحرير التواريخ في التقويم تلقائياً.
    </p>

    @include('emails.partials.booking-summary', ['rows' => [
        'الضيف'                 => $booking->user->name ?? '—',
        'المبلغ المسترد للضيف'   => number_format($refundAmount, 2).' SAR',
    ]])

    <p style="margin:0;font-size:13px;color:#6b7280;">الاسترداد محسوب من سياسة الإلغاء المثبتة على الحجز وقت الشراء.</p>
@endsection
