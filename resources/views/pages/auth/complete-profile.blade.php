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

        <div class="form-group">
            <label class="label">الاسم الكامل</label>
            <input type="text"
                   name="name"
                   class="input"
                   value="{{ old('name',auth()->user()->name) }}"
                   required>
        </div>

        <div class="form-group">
            <label class="label">البريد الإلكتروني</label>
            <input type="email"
                   name="email"
                   class="input"
                   value="{{ old('email',auth()->user()->email) }}"
                   {{ $intent==='partner' ? 'required' : '' }}>
        </div>

        <button type="submit" class="btn btn-block">
            حفظ
        </button>

    </form>

  </div>
</div>

@endsection