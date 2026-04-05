@extends('layouts.app')

@section('content')

@php
  $intent = request('intent','login');
@endphp

<div class="center">
  <div class="card" style="max-width:520px; width:100%;">

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
            <label class="label">الاسم </label>
            <input type="text"
                   name="name"
                   class="input"
                   value="{{ old('name',auth()->user()->name) }}"
                   required>
        </div>

        {{-- الايميل --}}
        <div class="form-group">
         <label class="label">البريد الإلكتروني</label>
         <input type="email"
           name="email"
           class="input"
           value="{{ old('email',auth()->user()->email) }}"
           {{ $intent === 'admin' || auth()->user()->isAdmin() ? 'required' : '' }}>
        </div>

        {{-- 🔥 فقط للأدمن --}}
        @if($intent === 'admin')

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
                <label class="label">رقم الهوية الوطنية</label>
                <input type="text" name="national_id" class="input" placeholder="أدخل رقم الهوية">
            </div>
        </div>

        {{-- شركة --}}
        <div id="company_fields" style="display:none;">
            <div class="form-group">
                <label class="label">رقم السجل التجاري</label>
                <input type="text" name="cr_number" class="input" placeholder="أدخل السجل التجاري">
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
@if($intent === 'admin')
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

// 🔥 مهم: لما الصفحة ترجع بعد خطأ
window.addEventListener('load', function() {
    if(select){
        toggleFields(select.value);
    }
});
</script>
@endif

@endsection