@extends('layouts.app')

@section('content')

@php
  $intent = request('intent','login');
@endphp

<div class="center">
  <div class="auth-card" style="max-width:520px; width:100%;">

    <h3 class="title">أكمل بيانات حسابك</h3>

    @if($errors->any())
        <div class="alert alert-warning">
            @foreach($errors->all() as $e)
                <div>{{ $e }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('auth.complete-profile.submit') }}">
        @csrf

        <input type="hidden" name="intent" value="{{ $intent }}">

        {{-- الاسم --}}
        <div class="form-group">
            <label class="label">الاسم</label>
            <input type="text"
                   name="name"
                   class="input"
                   value="{{ old('name',auth()->user()->name) }}"
                   required>
        </div>

        {{-- الايميل --}}
        <div class="form-group">
         <label class="label">
            البريد الإلكتروني
            @if($intent === 'Admin')
                <small style="color:#888;">مطلوب </small>
            @else
                <small style="color:#888;">اختياري</small>
            @endif
         </label>

         <input type="email"
           name="email"
           class="input"
           value="{{ old('email',auth()->user()->email) }}"
           {{ $intent === 'Admin' || auth()->user()->isAdmin() ? 'required' : '' }}>
        </div>

        {{-- 🔥 فقط للأدمن --}}
        @if($intent === 'Admin')

        <div class="form-group">
            <label class="label">نوع الحساب</label>
            <select name="type" id="type" class="input" required>
                <option value="">اختر النوع</option>
                <option value="individual" {{ old('type') == 'individual' ? 'selected' : '' }}>فرد</option>
                <option value="company" {{ old('type') == 'company' ? 'selected' : '' }}>شركة</option>
            </select>
        </div>

        {{-- فرد --}}
        <div id="individual_fields" style="display:none;">
            <div class="form-group">
                <label class="label">
                    رقم الهوية الوطنية
                    <small style="color:#888;">يجب أن يبدأ بـ 1 ويتكون من 10 أرقام</small>
                </label>

                <input type="text"
                       name="national_id"
                       id="national_id"
                       class="input"
                       placeholder="1XXXXXXXXX"
                       maxlength="10"
                       pattern="1[0-9]{9}"
                       inputmode="numeric">
            </div>
        </div>

        {{-- شركة --}}
        <div id="company_fields" style="display:none;">
            <div class="form-group">
                <label class="label">
                    رقم السجل التجاري
                    <small style="color:#888;">يتكون من 10 أرقام</small>
                </label>

                <input type="text"
                       name="cr_number"
                       id="cr_number"
                       class="input"
                       placeholder="XXXXXXXXXX"
                       maxlength="10"
                       pattern="[0-9]{10}"
                       inputmode="numeric">
            </div>
        </div>

        @endif

        <button type="submit" class="btn btn-block">
            حفظ
        </button>

    </form>

  </div>
</div>

{{-- 🔥 سكربت التبديل --}}
@if($intent === 'Admin')
<script>
function toggleFields(type){
    document.getElementById('individual_fields').style.display =
        (type === 'individual') ? 'block' : 'none';

    document.getElementById('company_fields').style.display =
        (type === 'company') ? 'block' : 'none';
}

const select = document.getElementById('type');

select?.addEventListener('change', function() {
    toggleFields(this.value);
});

// 🔥 منع إدخال حروف (هوية + سجل)
document.getElementById('national_id')?.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g,'').slice(0,10);
});

document.getElementById('cr_number')?.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g,'').slice(0,10);
});

// 🔥 مهم: لما الصفحة ترجع بعد خطأ
window.addEventListener('load', function() {
    if(select){
        toggleFields(select.value);
    }
});
</script>
@endif

@endsection