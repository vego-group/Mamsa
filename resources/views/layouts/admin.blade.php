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
                <a href="{{ route('admin.dashboard') }}"
                   class="block rounded-full px-5 py-3 transition
                   {{ request()->routeIs('admin.dashboard') ? 'bg-white/10' : 'hover:bg-white/10' }}">
                    الرئيسية
                </a>

                {{-- المستخدمون (super admin فقط) --}}
                @role('super_admin')
                    @php
                        $isUsersActive = request()->routeIs('admin.users.*')
                            || (request()->routeIs('admin.dashboard') && request('tab') === 'admins');
                    @endphp

                    <a href="{{ route('admin.users.index', ['tab' => 'admins']) }}"
                       class="block rounded-full px-5 py-3 transition
                       {{ $isUsersActive ? 'bg-white/10' : 'hover:bg-white/10' }}">
                        المستخدمون
                    </a>
                @endrole

                {{-- الوحدات --}}
                @if(Route::has('admin.units.index'))
                <a href="{{ route('admin.units.index') }}"
                   class="block rounded-full px-5 py-3 transition
                   {{ request()->routeIs('admin.units.*') ? 'bg-white/10' : 'hover:bg-white/10' }}">
                    الوحدات
                </a>
                @endif

                {{-- الحجوزات --}}
                @if(Route::has('admin.bookings.index'))
                <a href="{{ route('admin.bookings.index') }}"
                   class="block rounded-full px-5 py-3 transition
                   {{ request()->routeIs('admin.bookings.*') ? 'bg-white/10' : 'hover:bg-white/10' }}">
                    الحجوزات
                </a>
                @endif

                {{-- التقارير --}}
                @if(Route::has('admin.reports.index'))
                <a href="{{ route('admin.reports.index') }}"
                   class="block rounded-full px-5 py-3 transition
                   {{ request()->routeIs('admin.reports.*') ? 'bg-white/10' : 'hover:bg-white/10' }}">
                    التقارير
                </a>
                @endif

            </nav>

            <div class="mt-auto pt-6 opacity-80 text-sm">
                مرحبًا، {{ auth()->user()->name }}
            </div>
<form action="{{ route('logout') }}" method="POST" class="mt-4">
    @csrf
    <button type="submit"
        class="flex items-center gap-2 text-white hover:text-red-400 transition">
        
        <!-- أيقونة تسجيل الخروج -->
        <svg xmlns="http://www.w3.org/2000/svg" 
             fill="none" viewBox="0 0 24 24" stroke-width="1.5" 
             stroke="currentColor" class="w-6 h-6">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 
                   2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 
                   21h6a2.25 2.25 0 002.25-2.25V15m3 
                   0l3-3m0 0l-3-3m3 3H9" />
        </svg>

        <span class="text-sm">تسجيل الخروج</span>
    </button>
</form>

        </aside>

        {{-- المحتوى --}}
        <main class="col-span-12 md:col-span-9 xl:col-span-10 p-4 md:p-6">

            {{-- 🔥 رسالة المدير غير النشط --}}
           @if(auth()->check()
    && auth()->user()->hasRole('admin') 
    && intval(auth()->user()->is_active) !== 1)

    <div class="mb-4 bg-yellow-100 border border-yellow-300 text-yellow-900 px-4 py-3 rounded-xl text-sm font-bold">
        ⚠️ تنبيه: حسابك حالياً في حالة <strong>غير نشط</strong> — صلاحياتك محدودة،
        ولا يمكنك الإضافة أو التعديل أو الحذف حتى يتم تفعيل الحساب.
    </div>

@endif
            @yield('content')
        </main>

    </div>

</body>
</html>