@extends('layouts.app')

@section('content')

@php
  $intent = request('intent','login');
@endphp

<div class="center">
  <div class="auth-card" style="max-width:520px; width:100%; text-align:center;">

    <h3 class="title">أدخل رمز التحقق</h3>

    <p class="muted mb-20">
        تم إرسال رمز التحقق إلى رقمك
    </p>

    {{-- ✅ Errors --}}
    @if($errors->any())
        <div class="alert alert-warning">
            @foreach($errors->all() as $e)
                <div>{{ $e }}</div>
            @endforeach
        </div>
    @endif

    {{-- ================= OTP ================= --}}
    <form id="otpForm" method="POST" action="{{ route('auth.otp.verify') }}">
        @csrf

        <input type="hidden" name="intent" value="{{ $intent }}">
        <input type="hidden" name="phone" value="{{ $phone }}">
        <input type="hidden" name="code" id="code">

        <div id="otpContainer" style="display:grid; grid-template-columns:repeat(6,1fr); gap:10px; direction:ltr; margin-bottom:20px;">
            @for($i=0;$i<6;$i++)
                <input class="input otp-input"
                       maxlength="1"
                       inputmode="numeric"
                       style="text-align:center; font-size:20px; font-weight:bold;">
            @endfor
        </div>

        <button id="submitBtn" class="btn btn-block" type="submit">
            تحقق
        </button>
    </form>

    {{-- 🔥 RESEND --}}
    <div style="margin-top:15px;">
        <form method="POST" action="{{ route('auth.otp.resend') }}">
            @csrf
            <input type="hidden" name="phone" value="{{ $phone }}">
            <input type="hidden" name="intent" value="{{ $intent }}">

            <button id="resendBtn"
                    type="submit"
                    style="background:none;border:none;color:#9ca3af;font-weight:800;cursor:not-allowed;"
                    disabled>
                إعادة إرسال خلال <span id="timer">30</span>s
            </button>
        </form>
    </div>

  </div>
</div>

{{-- ================= JS MAGIC ================= --}}
<script>
const inputs = document.querySelectorAll('.otp-input');
const codeHidden = document.getElementById('code');
const form = document.getElementById('otpForm');

let timer = 30;
const timerEl = document.getElementById('timer');
const resendBtn = document.getElementById('resendBtn');

/* ======================
   AUTO FOCUS + INPUT
====================== */
inputs[0].focus();

inputs.forEach((inp, idx) => {

    inp.addEventListener('input', e => {
        e.target.value = e.target.value.replace(/\D/g,'').slice(0,1);

        if(e.target.value && idx < inputs.length - 1){
            inputs[idx+1].focus();
        }

        updateCode();
    });

    /* Backspace يرجع */
    inp.addEventListener('keydown', e => {
        if(e.key === "Backspace" && !inp.value && idx > 0){
            inputs[idx-1].focus();
        }
    });

    /* Paste دعم */
    inp.addEventListener('paste', e => {
        let paste = (e.clipboardData || window.clipboardData).getData('text');
        if(!paste) return;

        paste = paste.replace(/\D/g,'').slice(0,6);

        inputs.forEach((box, i) => {
            box.value = paste[i] || '';
        });

        updateCode();

        e.preventDefault();
    });
});

/* ======================
   UPDATE CODE + AUTO SUBMIT
====================== */
function updateCode(){
    let code = Array.from(inputs).map(i => i.value || '').join('');
    codeHidden.value = code;

    if(code.length === 6){
        setTimeout(() => {
            form.submit(); // 🔥 auto submit
        }, 300);
    }
}

/* ======================
   COUNTDOWN
====================== */
const interval = setInterval(() => {
    timer--;
    timerEl.textContent = timer;

    if(timer <= 0){
        clearInterval(interval);

        resendBtn.disabled = false;
        resendBtn.style.color = '#2F6F63';
        resendBtn.style.cursor = 'pointer';
        resendBtn.innerHTML = 'إعادة إرسال الرمز';
    }
}, 1000);
</script>

@endsection