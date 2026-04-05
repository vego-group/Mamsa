@extends('layouts.app')

@section('content')

<div class="center">
  <div class="card" style="max-width:500px;width:100%;">

    <h3 class="title">تحقق من بريدك</h3>

    <p class="muted mb-20">
      أدخل رمز التحقق المرسل إلى بريدك
    </p>

    <form method="POST" action="{{ route('auth.email.verify.submit') }}">
      @csrf

      <input type="text" name="code" class="input" placeholder="ادخل الكود" required>

      <button class="btn btn-block mt-20">
        تحقق
      </button>
    </form>

  </div>
</div>

@endsection