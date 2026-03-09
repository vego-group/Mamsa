@extends('layouts.app')

@section('content')

@php
$profile = auth()->user()->partner;
@endphp

<div class="page-wrap">

    <div class="card" style="max-width:700px;margin:auto;">

        <h2 class="title mb-40">
            بيانات التصريح
        </h2>

        @if($profile && $profile->verification_status === 'pending')
            <div class="alert alert-warning">
                تم إرسال البيانات وهي الآن قيد المراجعة.
            </div>
        @endif

        <form method="POST" action="{{ route('partner.license.store') }}">
            @csrf

            <input type="hidden" name="type" value="company">

            <div class="form-group">
                <label class="label">رقم الرخصة</label>
                <input name="company_license_no"
                       required
                       class="input">
            </div>

            <div class="form-group">
                <label class="label">رقم السجل التجاري</label>
                <input name="cr_number"
                       required
                       class="input">
            </div>

            <button type="submit" class="btn btn-block">
                إرسال للمراجعة →
            </button>

        </form>

    </div>

</div>

@endsection