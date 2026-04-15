@extends('layouts.app')

@section('content')

<div class="center">
  <div class="card" style="max-width:500px;width:100%;">

    <h3 class="title">تحقق من بريدك</h3>

    <p class="muted mb-20">
      أدخل رمز التحقق المرسل إلى بريدك
    </p>

    {{-- ✅ عرض الأخطاء --}}
    @if($errors->any())
      <div class="alert alert-warning">
        @foreach($errors->all() as $e)
          <div>{{ $e }}</div>
        @endforeach
      </div>
    @endif

    {{-- ================= OTP FORM ================= --}}
    <form id="emailOtpForm" method="POST" action="{{ route('auth.email.verify.submit') }}">
      @csrf

      <input type="hidden" name="code" id="code">

      {{-- مربعات OTP --}}
      <div style="display:grid; grid-template-columns:repeat(6,1fr); gap:8px; direction:ltr; margin-bottom:20px;">
        @for($i=0;$i<6;$i++)
          <input class="input otp-input"
                 maxlength="1"
                 inputmode="numeric"
                 pattern="[0-9]*"
                 style="text-align:center; font-size:20px; font-weight:bold;">
        @endfor
      </div>

      <button class="btn btn-block">
        تحقق
      </button>
    </form>

    {{-- 🔥 زر إعادة إرسال --}}
    <div style="margin-top:12px; text-align:center;">
      <form method="POST" action="{{ route('auth.email.resend') }}">
        @csrf

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

{{-- سكربت OTP 🔥 --}}
<script>
const inputs = document.querySelectorAll('.otp-input');
const codeHidden = document.getElementById('code');
const form = document.getElementById('emailOtpForm');

let timer = 30;
const timerEl = document.getElementById('timer');
const resendBtn = document.getElementById('resendBtn');

/* ======================
   Focus أول ما تفتح الصفحة
====================== */
inputs[0].focus();

/* ======================
   إدخال + تنقل
====================== */
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

    /* لصق الكود */
    inp.addEventListener('paste', e => {
        let paste = (e.clipboardData || window.clipboardData).getData('text');

        paste = paste.replace(/\D/g,'').slice(0,6);

        inputs.forEach((box, i) => {
            box.value = paste[i] || '';
        });

        updateCode();

        e.preventDefault();
    });
});

/* ======================
   تحديث الكود + إرسال تلقائي
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
   Countdown إعادة الإرسال
====================== */
const interval = setInterval(() => {
    timer--;
    timerEl.textContent = timer;

    if(timer <= 0){
        clearInterval(interval);

        resendBtn.disabled = false;
        resendBtn.style.color = '#2F6F63';
        resendBtn.style.cursor = 'pointer';
        resendBtn.innerHTML = 'إعادة إرسال الكود';
    }
}, 1000);
</script>

@endsection