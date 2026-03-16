<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>مَمْسَى</title>

    <link rel="stylesheet" href="{{ asset('css/mamsa.css') }}">
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
</head>

<body>

{{-- HEADER --}}
<header class="header header--hero">
  <div class="header-container">

    {{-- الشعار --}}
    <div class="logo">
      <a href="{{ route('home') }}">
        <img src="{{ asset('images/logo.png') }}" alt="شعار الموقع">
      </a>
    </div>

    {{-- الأزرار --}}
    <div class="header-actions">

      <a href="{{ route('auth.phone', ['intent' => 'login']) }}" class="header-pill">
        تسجيل الدخول
      </a>

      <a href="{{ route('auth.phone', ['intent' => 'partner']) }}" class="header-pill">
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

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
</body>
</html>