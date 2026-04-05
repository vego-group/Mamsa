{{-- resources/views/partials/header.blade.php --}}

<header class="header header--hero">
  <div class="header-container">

    {{-- الشعار يمين --}}
    <div class="logo">
      <img src="{{ asset('images/logo.png') }}" alt="شعار الموقع">
    </div>

    {{-- الحساب / الأزرار يسار --}}
    <div class="login">
@php
$hideProfileIcon = 
    request()->is('complete-profile*') || 
    request()->is('email-verify*') || 
    request()->is('admin*') || 
    request()->get('intent') === 'admin';
@endphp

      {{-- إذا المستخدم مسجل دخول --}}
      @auth
        @if(!$hideProfileIcon)

        {{-- قائمة المستخدم --}}
        <div class="user-menu-wrapper">

          {{-- زر الأيقونة --}}
          <button type="button" class="user-icon-btn" onclick="toggleUserMenu()">
              <img src="{{ asset('images/login.png') }}" alt="حسابي">
          </button>

          {{-- القائمة المنسدلة --}}
          <div class="user-dropdown" id="userDropdown">
              <a href="{{ route('user.profile') }}">صفحتي الشخصية</a>
              <a href="{{ route('user.bookings') }}">حجوزاتي</a>

              <form id="logout-form-header" action="{{ route('logout') }}" method="POST" style="margin:0;">
                  @csrf
                  <button type="submit" class="dropdown-logout">تسجيل خروج</button>
              </form>
          </div>

        </div>

        @endif
      @endauth


      {{-- إذا المستخدم زائر --}}
      @guest
        @php
          $isLoginRoute = request()->routeIs('login') || request()->routeIs('auth.phone');
        @endphp

        {{-- نخفي الأزرار في صفحة تسجيل الدخول --}}
        @unless($isLoginRoute)
          <div class="header-actions">

            <a href="{{ route('auth.phone', ['intent' => 'login']) }}" class="header-pill">
              تسجيل الدخول
           </a>

            <a href="{{ route('auth.phone', ['intent' => 'admin']) }}" class="header-pill">
              كن شريكًا معنا
            </a>

          </div>
        @endunless
      @endguest

    </div>

  </div>
</header>

{{-- سكربت القائمة المنسدلة --}}
<script>
function toggleUserMenu() {
    const menu = document.getElementById('userDropdown');
    menu.classList.toggle('show');
}

// إغلاق القائمة عند الضغط خارجها
document.addEventListener('click', function(e) {
    const wrapper = document.querySelector('.user-menu-wrapper');
    const menu = document.getElementById('userDropdown');

    if (wrapper && !wrapper.contains(e.target)) {
        menu.classList.remove('show');
    }
});
</script>