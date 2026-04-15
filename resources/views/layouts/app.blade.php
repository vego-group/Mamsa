<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>مَمْسَى</title>

    <link rel="stylesheet" href="{{ asset('css/mamsa.css') }}">
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>

<body class="
{{ 
    request()->is('complete-profile*') || 
    request()->is('email-verify*') || 
    request()->is('Admin*') 
    ? 'hide-profile' : '' 
}}">

{{-- HEADER ثابت --}}
@include('partials.header')

<main class="py-60">
    @yield('content')
</main>

{{-- FOOTER ثابت --}}
@include('partials.footer')

</body>
</html>