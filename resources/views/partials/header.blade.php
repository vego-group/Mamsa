{{-- resources/views/partials/header.blade.php --}}
<header class="header header--hero">
  <div class="header-container">

    {{-- الشعار يمين --}}
    <div class="logo">
      <img src="{{ asset('images/logo.png') }}" alt="شعار الموقع">
    </div>

    {{-- الحساب / الأزرار يسار --}}
    <div class="login">

      @auth
        {{-- المستخدم مسجّل: أيقونة الحساب (توجّه للداشبورد أو لبوابة التوجيه الذكية) --}}
        <a href="{{ route('dashboard') }}" class="login-pill" aria-label="حسابي">
          <img src="{{ asset('images/login.png') }}" alt="حسابي">
        </a>
        {{-- لو تبين التوجيه الذكي بحسب الدور: استبدلي السطر أعلاه بهذا:
             <a href="{{ route('post.auth.redirect') }}" class="login-pill" aria-label="حسابي"> ... </a>
        --}}
      @endauth

      @guest
        @php
          // اعتبري كلاهما صفحة دخول: /login و /auth (OTP)
          $isLoginRoute = request()->routeIs('login') || request()->routeIs('auth.phone');
        @endphp

        {{-- لا نعرض الأزرار داخل صفحة الدخول نفسها --}}
        @unless($isLoginRoute)
          <div class="header-actions">
            {{-- زر تسجيل الدخول عبر OTP --}}
            <a href="{{ route('auth.phone') }}" class="header-pill">
              تسجيل الدخول
            </a>

            {{-- زر كن شريكاً معنا (نفس مسار الـ OTP مع intent=partner) --}}
            <a href="{{ route('auth.phone', ['intent' => 'partner']) }}" class="header-pill">
              كن شريكًا معنا
            </a>
          </div>
        @endunless
      @endguest

    </div>

  </div>
</header>