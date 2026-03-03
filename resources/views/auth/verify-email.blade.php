@extends('layouts.app')

@section('content')

<div class="center">
    <div class="card card-lg text-center" style="max-width:500px;">

        <h2 class="title">
            تأكيد البريد الإلكتروني
        </h2>

        <p class="muted mb-20">
            أرسلنا رابط تأكيد إلى بريدك الإلكتروني.<br>
            يرجى الضغط على الرابط لتفعيل حسابك.
        </p>

        @if (session('status') == 'verification-link-sent')
            <div class="alert alert-success">
                تم إرسال رابط جديد إلى بريدك الإلكتروني.
            </div>
        @endif

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-block">
                إعادة إرسال رابط التحقق
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-20">
            @csrf
            <button type="submit" class="btn btn-outline btn-block">
                تسجيل الخروج
            </button>
        </form>

    </div>
</div>

@endsection