@extends('layouts.app')

@section('content')

<div class="container py-60">

    <div style="max-width:900px;margin:auto;">

        <h1 class="title mb-20">
            اختر نوع الشريك
        </h1>

        <p class="muted mb-40">
            حدد إذا كنت فرد أو شركة لإكمال التسجيل.
        </p>

        <form method="POST" action="{{ route('partner.type.store') }}">
            @csrf

            <div class="grid grid-2 mb-40">

                {{-- فرد --}}
                <label class="card" style="cursor:pointer;">
                    <input type="radio" name="type" value="individual" required style="margin-bottom:15px;">
                    <h3 style="font-size:18px;font-weight:600;">فرد</h3>
                </label>

                {{-- شركة --}}
                <label class="card" style="cursor:pointer;">
                    <input type="radio" name="type" value="company" required style="margin-bottom:15px;">
                    <h3 style="font-size:18px;font-weight:600;">شركة</h3>
                </label>

            </div>

            @error('type')
                <div class="alert alert-warning">
                    {{ $message }}
                </div>
            @enderror

            <button type="submit" class="btn btn-block">
                متابعة →
            </button>

        </form>

    </div>

</div>

@endsection