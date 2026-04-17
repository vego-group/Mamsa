@extends('layouts.app')

@section('content')

@php
  $intent = request('intent','login');
@endphp

@if($errors->any())
  <div class="container mt-40">
      <div class="alert alert-warning">
          @foreach($errors->all() as $e)
              <div>{{ $e }}</div>
          @endforeach
      </div>
  </div>
@endif

<div class="center">
  <div class="auth-card" style="max-width:520px; width:100%;">

      <h3 class="title">
          {{ $intent === 'Admin' ? 'ابدأ كشريك' : 'تسجيل الدخول' }}
      </h3>

      <p class="muted mb-20">
         {{ $intent === 'Admin' 
          ? 'أدخل رقم جوالك لبدء التسجيل كشريك وسنرسل لك رمز تحقق.' 
          : 'أدخل رقم جوالك لتسجيل الدخول وسنرسل لك رمز تحقق.' }}
      </p>

      <form method="POST" action="{{ route('auth.otp.request') }}">
          @csrf
          <input type="hidden" name="intent" value="{{ $intent }}">

          <div class="form-group">
              <label class="label">رقم الجوال</label>
                <input class="input" 
                        type="tel" 
                        name="phone"
                        placeholder="05xxxxxxxx"
                        pattern="05[0-9]{8}"
                        title="يجب أن يبدأ الرقم بـ 05 ويتكون من 10 أرقام"
                        value="{{ old('phone') }}"
                        required>
            </div>

          <button class="btn btn-block" type="submit">
              أرسل الرمز
          </button>

      </form>

  </div>
</div>

@endsection