@extends('emails.layout')

@section('title', 'تذكير بموعد الوصول')

@section('content')
    <p style="margin:0 0 16px;font-size:16px;">مرحباً {{ $booking->user->name ?? '' }}،</p>
    <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#4b5563;">
        نذكّرك بموعد وصولك غداً. تفاصيل الإقامة:
    </p>

    @include('emails.partials.booking-summary', ['rows' => array_filter([
        'وقت الدخول' => $checkinTime,
        'العنوان'    => $address,
    ])])

    <p style="margin:0;font-size:14px;color:#6b7280;">رحلة موفقة، ونتمنى لك إقامة سعيدة 🌙</p>
@endsection
