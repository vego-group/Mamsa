<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-white antialiased">

    {{-- Header --}}
    <header class="w-full">
        <div class="bg-[#2F4F45] rounded-b-[40px] px-6 pt-10 pb-20 shadow-sm relative overflow-hidden">
            <div class="absolute -top-24 -left-24 w-72 h-72 bg-white/10 rounded-full blur-2xl"></div>
            <div class="absolute -bottom-28 -right-28 w-80 h-80 bg-white/10 rounded-full blur-2xl"></div>

            <div class="w-full flex items-center justify-start">
                <div class="text-right">
                    <div class="flex items-center justify-end gap-4">
                        {{-- بدون كلمة ممسى (خليته فاضي مثل ما عندك) --}}
                        <div class="text-white font-semibold text-2xl leading-none"></div>

                        <img
                            src="{{ asset('images/logo.png') }}"
                            alt="Mamssa Logo"
                            class="w-33 h-33 object-contain"
                        >
                    </div>

                    <div class="text-white/90 mt-2 text-base mr-6">
                        منصة مَمْسَى لحجز الوحدات
                    </div>
                </div>
            </div>
        </div>
    </header>

    {{-- Main: Card --}}
    <main class="w-full flex justify-center px-6 pt-12 pb-16">
        {{-- كبرنا عرض الكارد --}}
        <div class="w-full max-w-xl">
            {{-- كبرنا البادينق --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-8 sm:p-10">
                @yield('content')
            </div>
        </div>
    </main>

</body>
</html>
