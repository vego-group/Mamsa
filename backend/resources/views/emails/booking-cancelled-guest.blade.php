@extends('emails.layout')

@section('title', 'إلغاء الحجز')

@section('content')
    <p style="margin:0 0 16px;font-size:16px;">مرحباً {{ $booking->user->name ?? '' }}،</p>
    <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#4b5563;">
        @if ($byHost)
            نأسف لإبلاغك بأن المضيف قام بإلغاء حجزك التالي، وسيتم استرداد كامل المبلغ المدفوع (100%).
        @else
            تم إلغاء حجزك التالي بناءً على طلبك.
        @endif
    </p>

    @include('emails.partials.booking-summary', ['rows' => [
        'المبلغ المسترد' => number_format($refundAmount, 2).' SAR',
    ]])

    @if ($refundAmount > 0)
        <p style="margin:0 0 8px;font-size:14px;color:#4b5563;">
            سيظهر المبلغ في وسيلة الدفع الأصلية خلال أيام العمل المعتادة للبنوك، وسيصلك تأكيد عند اكتمال الاسترداد.
        </p>
    @else
        <p style="margin:0 0 8px;font-size:14px;color:#4b5563;">
            لا يوجد مبلغ مسترد وفق سياسة الإلغاء المعتمدة على حجزك.
        </p>
    @endif

    <p style="margin:0;font-size:13px;color:#6b7280;">قيمة الاسترداد محسوبة من سياسة الإلغاء المثبتة على حجزك وقت الشراء.</p>
@endsection
