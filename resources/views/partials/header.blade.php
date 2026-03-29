{{-- resources/views/partials/header.blade.php --}}
<header class="header header--hero">
  <div class="header-container">

    {{-- الشعار يمين --}}
    <div class="logo" style="position: relative; display: inline-block;">

    {{-- زر مخفي يغطي الشعار بالكامل ويعيد للهوم --}}
    <a href="{{ route('home') }}" 
       style="
            position:absolute;
            top:0;
            left:0;
            width:100%;
            height:100%;
            z-index:10;
            opacity:0;
            cursor:pointer;
        ">
    </a>

    {{-- الشعار --}}
    <img src="{{ asset('images/logo.png') }}" alt="شعار الموقع">
</div>

    {{-- الحساب / الأزرار يسار --}}
    <div class="login">

      @auth
      {{-- قائمة المستخدم المنسدلة --}}
      <div class="user-menu-wrapper">

        {{-- زر الأيقونة --}}
        <button type="button" class="user-icon-btn" onclick="toggleUserMenu()">
            <img src="{{ asset('images/login.png') }}" alt="حسابي">
        </button>

        {{-- القائمة --}}
        <div class="user-dropdown" id="userDropdown">

            <a href="{{ route('user.profile') }}">صفحتي الشخصية</a>

            <a href="{{ route('user.bookings') }}">حجوزاتي</a>

            {{-- تسجيل خروج --}}
            <form id="logout-form-header" action="{{ route('logout') }}" method="POST" style="margin:0;">
                @csrf
                <button type="submit" class="dropdown-logout">تسجيل خروج</button>
            </form>

        </div>
      </div>
      @endauth


      @guest
        @php
          $isLoginRoute = request()->routeIs('login') || request()->routeIs('auth.phone');
        @endphp

        {{-- لا نعرض الأزرار داخل صفحة الدخول --}}
        @unless($isLoginRoute)
          <div class="header-actions">

            <a href="{{ route('auth.phone') }}" class="header-pill">
              تسجيل الدخول
            </a>
            <a href="{{ route('auth.phone', ['intent' => 'partner']) }}" class="header-pill">
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

    if (!wrapper.contains(e.target)) {
        menu.classList.remove('show');
    }
});
</script>