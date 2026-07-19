@extends('emails.layout')

@section('title', 'تأكيد الحجز')

@section('content')
    <p style="margin:0 0 16px;font-size:16px;">مرحباً {{ $booking->user->name ?? '' }}،</p>
    <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#4b5563;">
        تم تأكيد حجزك بنجاح. تفاصيل الحجز:
    </p>

    @include('emails.partials.booking-summary', ['rows' => [
        'وقت الدخول'     => $checkinTime,
        'الإجمالي المدفوع' => number_format((float) $booking->total_amount, 2).' SAR',
    ]])

    @if (!empty($tiers))
        <p style="margin:0 0 8px;font-size:14px;font-weight:bold;color:#111827;">سياسة الإلغاء ({{ $policyName }})</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;">
            @foreach ($tiers as $tier)
                <tr>
                    <td style="padding:8px 14px;font-size:13px;color:#134e4a;">{{ $tier['label'] ?? '' }}</td>
                    <td style="padding:8px 14px;font-size:13px;color:#134e4a;font-weight:bold;text-align:left;">استرداد {{ $tier['refund_percent'] ?? 0 }}%</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="2" style="padding:8px 14px;font-size:12px;color:#0f766e;">بعد بدء الإقامة لا يمكن الإلغاء.</td>
            </tr>
        </table>
    @endif

    <p style="margin:0;font-size:14px;color:#6b7280;">نتمنى لك إقامة سعيدة 🌙</p>
@endsection
