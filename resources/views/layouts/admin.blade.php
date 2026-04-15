<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <title>{{ $title ?? 'لوحة الإدارة' }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-[#f4f6f8] text-gray-900">

    {{-- Grid RTL --}}
    <div class="min-h-screen grid grid-cols-12 gap-0" dir="rtl">

        {{-- Sidebar --}}
        <aside class="col-span-12 md:col-span-3 xl:col-span-2 bg-[#2f4b46] text-white p-6
                       rounded-l-[28px] overflow-hidden flex flex-col">

            <div class="mb-8 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-white/20"></div>
                <div class="font-semibold text-lg">Mamsa</div>
            </div>

          <nav class="space-y-3">

    {{-- الرئيسية --}}
    <a href="{{ route('Admin.dashboard') }}"
       class="block rounded-full px-5 py-3 transition
       {{ request()->routeIs('Admin.dashboard') ? 'bg-white/10' : 'hover:bg-white/10' }}">
        الرئيسية
    </a>

    {{-- المستخدمون (SuperAdmin فقط) --}}
    @if(auth()->user()->hasRole('SuperAdmin'))
        <a href="{{ route('Admin.users.index') }}"
           class="block rounded-full px-5 py-3 transition
           {{ request()->routeIs('Admin.users.*') ? 'bg-white/10' : 'hover:bg-white/10' }}">
            المستخدمون
        </a>
    @endif

    {{-- الوحدات --}}
    <a href="{{ route('Admin.units.index') }}"
       class="block rounded-full px-5 py-3 transition
       {{ request()->routeIs('Admin.units.*') ? 'bg-white/10' : 'hover:bg-white/10' }}">
        الوحدات
    </a>

    {{-- الحجوزات --}}
    <a href="{{ route('Admin.bookings.index') }}"
       class="block rounded-full px-5 py-3 transition
       {{ request()->routeIs('Admin.bookings.*') ? 'bg-white/10' : 'hover:bg-white/10' }}">
        الحجوزات
    </a>

    {{-- التقارير --}}
    <a href="{{ route('Admin.reports.index') }}"
       class="block rounded-full px-5 py-3 transition
       {{ request()->routeIs('Admin.reports.*') ? 'bg-white/10' : 'hover:bg-white/10' }}">
        التقارير
    </a>

    {{-- الطلبات (SuperAdmin فقط) --}}
    @if(auth()->user()->hasRole('SuperAdmin'))
        <a href="{{ route('Admin.requests.index') }}"
           class="block rounded-full px-5 py-3 transition
           {{ request()->routeIs('Admin.requests.*') ? 'bg-white/10' : 'hover:bg-white/10' }}">
            الطلبات
        </a>
    @endif

    {{-- الحساب --}}
    <a href="{{ route('Admin.account.index') }}"
       class="block rounded-full px-5 py-3 transition
       {{ request()->routeIs('Admin.account.index') ? 'bg-white/10' : 'hover:bg-white/10' }}">
        الحساب
    </a>

    {{-- تسجيل خروج --}}
 <form method="POST" action="{{ route('logout') }}" class="mt-4">
    @csrf
    <button type="submit"
        class="w-full flex items-center gap-2 rounded-full px-5 py-3 bg-red-600 hover:bg-red-700 text-white text-sm transition">

        {{-- أيقونة خروج مطابقة للصورة (SVG) --}}
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
             stroke-width="2.5" stroke="currentColor" class="w-5 h-5">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M18 12H9m9 0l-3-3m3 3l-3 3" />
        </svg>

        {{-- نص تسجيل الخروج --}}
        <span>تسجيل خروج</span>
    </button>
</form>

            <div class="mt-auto pt-6 opacity-80 text-sm">
                مرحبًا، {{ auth()->user()->name }}
            </div>

        </aside>

        {{-- المحتوى --}}
        <main class="col-span-12 md:col-span-9 xl:col-span-10 p-4 md:p-6">

            {{-- 🔥 رسالة المدير غير النشط --}}
           @if(auth()->check()
    && auth()->user()->hasRole('Admin') 
    && intval(auth()->user()->is_active) !== 1)

    <div class="mb-4 bg-yellow-100 border border-yellow-300 text-yellow-900 px-4 py-3 rounded-xl text-sm font-bold">
        ⚠️ تنبيه: حسابك حالياً في حالة <strong>غير نشط</strong> — صلاحياتك محدودة،
        ولا يمكنك الإضافة أو التعديل أو الحذف حتى يتم تفعيل الحساب.
    </div>

@endif
            @yield('content')
        </main>

    </div>

<script>
    if (window.history && window.history.pushState) {
        window.history.pushState(null, null, window.location.href);
        window.onpopstate = function () {
            window.history.pushState(null, null, window.location.href);
        };
    }
</script>
@if(auth()->check())
<script>
    // منع الرجوع إلى صفحة تسجيل الدخول
    if (window.history && window.history.pushState) {
        window.history.pushState(null, null, window.location.href);
        window.onpopstate = function () {
            window.history.go(1);
        };
    }
</script>
@endif
</body>
</html>