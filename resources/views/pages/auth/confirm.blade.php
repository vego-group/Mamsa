@extends('layouts.app')

@section('content')

@php
  $intent = request('intent','login');
@endphp

<div class="center">
  <div class="card" style="max-width:520px; width:100%;">

    <h3 class="title">أدخل رمز التحقق</h3>

    <p class="muted mb-20">
        تم إرسال رمز التحقق إلى رقمك.
    </p>

    @if($errors->any())
        <div class="alert alert-warning">
            @foreach($errors->all() as $e)
                <div>{{ $e }}</div>
            @endforeach
        </div>
    @endif

    <form id="otpForm" method="POST" action="{{ route('auth.otp.verify') }}">
        @csrf

        <input type="hidden" name="intent" value="{{ $intent }}">
        <input type="hidden" name="phone" value="{{ $phone }}">
        <input type="hidden" name="code" id="code">

        <div style="display:grid; grid-template-columns:repeat(6,1fr); gap:8px; direction:ltr; margin-bottom:20px;">
            @for($i=0;$i<6;$i++)
                <input class="input otp-input"
                       maxlength="1"
                       inputmode="numeric"
                       pattern="[0-9]*"
                       style="text-align:center;">
            @endfor
        </div>

        <button class="btn btn-block" type="submit">
            تحقق
        </button>

    </form>

  </div>
</div>

<script>
const inputs = document.querySelectorAll('.otp-input');
const codeHidden = document.getElementById('code');

inputs.forEach((inp, idx) => {
    inp.addEventListener('input', e => {
        e.target.value = e.target.value.replace(/\D/g,'').slice(0,1);

        if(e.target.value && idx < inputs.length - 1){
            inputs[idx+1].focus();
        }

        codeHidden.value = Array.from(inputs).map(i => i.value || '').join('');
    });
});
</script>

@endsection