@extends('layouts.app')

@section('content')
<div class="center">
    <div class="card" style="width:min(560px,100%); text-align:center;">
        <h3>تحقق البريد</h3>
        <p class="muted">إذا لم يصلك الرابط، يمكنك طلب إعادة الإرسال.</p>
        <form method="post" action="{{ route('verification.send') }}">
            @csrf
            <button class="btn" type="submit">أعد إرسال رابط التحقق</button>
        </form>
    </div>
</div>
@endsection