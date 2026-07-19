@extends('emails.layout')

@section('title', 'تم اكتمال الاسترداد')

@section('content')
    <p style="margin:0 0 16px;font-size:16px;">مرحباً {{ $booking->user->name ?? '' }}،</p>
    <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#4b5563;">
        تم اكتمال استرداد مبلغ حجزك بنجاح لدى مزوّد الدفع، وسيظهر في وسيلة الدفع الأصلية بحسب مواعيد البنك.
    </p>

    @include('emails.partials.booking-summary', ['rows' => [
        'المبلغ المسترد' => number_format($refundAmount, 2).' SAR',
    ]])

    <p style="margin:0;font-size:14px;color:#6b7280;">شكراً لاستخدامك مَمسَى.</p>
@endsection
