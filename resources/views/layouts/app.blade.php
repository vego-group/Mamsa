<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>مَمْسَى</title>

    <link rel="stylesheet" href="{{ asset('css/mamsa.css') }}">
</head>

<body>

{{-- HEADER --}}
<header class="header">
    <div class="container header-inner">

        {{-- الشعار --}}
        <a href="{{ route('home') }}">
            <img src="{{ asset('images/logo.png') }}" style="height:48px;">
        </a>

        {{-- الأزرار --}}
        <div class="nav-actions">
            <a href="{{ route('auth.phone', ['intent' => 'login']) }}" class="btn btn-outline">
                تسجيل الدخول
            </a>

            <a href="{{ route('auth.phone', ['intent' => 'partner']) }}" class="btn">
                كن شريكًا معنا
            </a>
        </div>

    </div>
</header>

{{-- CONTENT --}}
<main class="py-60">
    @yield('content')
</main>

{{-- FOOTER --}}
<footer style="background:#f3f4f6; border-top:1px solid #e5e7eb; padding:30px 0; text-align:center; font-size:14px; color:#6b7280;">
    © {{ date('Y') }} مَمْسَى — جميع الحقوق محفوظة
</footer>

</body>
</html>