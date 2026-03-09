<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <title>{{ $title ?? 'لوحة الإدارة' }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-[#f4f6f8] text-gray-900">

    {{-- Grid RTL: السايدبار يأخذ العمود الأول (يمين) والمحتوى العمود التالي (يسار) --}}
    <div class="min-h-screen grid grid-cols-12 gap-0" dir="rtl">

        {{-- Sidebar (يمين دائماً) --}}
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
                          {{ request()->routeIs('admin.dashboard') ? 'bg-white/10' : 'hover:bg-white/10' }}
                          focus:outline-none focus-visible:outline-none focus:ring-0 outline-none">
                    الرئيسية
                </a>

                {{-- المستخدمون (super_admin فقط) --}}
                @role('super_admin')
                {{-- نعتبر التبويب النشط إذا كنا على أي مسار users.* أو إذا كان الطلب الحالي على /admin/users مع tab=admins --}}
                @php
                    $isUsersActive = request()->routeIs('admin.users.*')
                        || (request()->routeIs('admin.dashboard') && request('tab') === 'admins'); // احتياط إن أضفتِ روابط مع tab
                @endphp
                <a href="{{ route('admin.users.index', ['tab' => 'admins']) }}"
                   class="block rounded-full px-5 py-3 transition {{ $isUsersActive ? 'bg-white/10' : 'hover:bg-white/10' }}">
                    المستخدمون
                </a>
                @endrole

                {{-- الوحدات --}}
                @if(Route::has('admin.units.index'))
                <a href="{{ route('admin.units.index') }}"
                   class="block rounded-full px-5 py-3 transition {{ request()->routeIs('admin.units.*') ? 'bg-white/10' : 'hover:bg-white/10' }}">
                    الوحدات
                </a>
                @endif

                {{-- الحجوزات --}}
                @if(Route::has('admin.bookings.index'))
                <a href="{{ route('admin.bookings.index') }}"
                   class="block rounded-full px-5 py-3 transition {{ request()->routeIs('admin.bookings.*') ? 'bg-white/10' : 'hover:bg-white/10' }}">
                    الحجوزات
                </a>
                @endif

                {{-- التقارير --}}
                @if(Route::has('admin.reports.index'))
                <a href="{{ route('admin.reports.index') }}"
                   class="block rounded-full px-5 py-3 transition {{ request()->routeIs('admin.reports.*') ? 'bg-white/10' : 'hover:bg-white/10' }}">
                    التقارير
                </a>
                @endif
            </nav>

            <div class="mt-auto pt-6 opacity-80 text-sm">
                مرحبًا، {{ auth()->user()->name }}
            </div>
        </aside>

        {{-- المحتوى (يسار) --}}
        <main class="col-span-12 md:col-span-9 xl:col-span-10 p-4 md:p-6">
            @yield('content')
        </main>
    </div>
</body>
</html>