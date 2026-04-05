@extends('layouts.app')

@section('content')
<div class="min-h-[60vh] grid place-items-center bg-muted px-4 py-10">
  <form method="POST" action="{{ route('login.attempt') }}" class="max-w-md w-full space-y-5">
    @csrf

    <h1 class="text-2xl font-bold text-primary text-center">تسجيل الدخول (اختبار)</h1>

    @if ($errors->any())
      <div class="text-red-600 text-sm bg-red-50 border border-red-200 rounded-xl p-3">
        {{ $errors->first() }}
      </div>
    @endif

    <label class="block">
      <span class="block mb-1 text-sm text-textc/80">البريد الإلكتروني</span>
      <input type="email" name="email" required class="input w-full px-4 py-2"
             placeholder="you@example.com" autocomplete="username">
    </label>

    <label class="block">
      <span class="block mb-1 text-sm text-textc/80">كلمة المرور</span>
      <input type="password" name="password" required class="input w-full px-4 py-2"
             placeholder="••••••••" autocomplete="current-password">
    </label>

    <label class="inline-flex items-center gap-2">
      <input type="checkbox" name="remember" class="rounded">
      <span class="text-sm text-textc/80">تذكرني</span>
    </label>

    <div class="flex items-center justify-between gap-3 pt-2">
      <a href="{{ route('home') }}" class="btn btn-ghost">رجوع</a>
      <button type="submit" class="btn btn-primary">دخول</button>
    </div>
  </form>
</div>
@endsection