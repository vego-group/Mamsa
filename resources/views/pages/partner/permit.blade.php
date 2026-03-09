@extends('layouts.app')

@section('content')

<div class="container py-60">

    <div class="card" style="max-width:700px;margin:auto;">

        <h2 class="title mb-40">
            {{ $profile->type === 'company' ? 'تصريح الشركة' : 'تصريح الفرد' }}
        </h2>

        <form method="POST" action="{{ route('partner.license.store') }}">
            @csrf

            @if($profile->type === 'company')

                <div class="form-group">
                    <label class="label">رقم الرخصة</label>
                    <input type="text" name="company_license_no" class="input" required>
                </div>

                <div class="form-group">
                    <label class="label">رقم السجل التجاري</label>
                    <input type="text" name="cr_number" class="input" required>
                </div>

            @else

                <div class="form-group">
                    <label class="label">رقم الهوية</label>
                    <input type="text" name="national_id" class="input" required>
                </div>

                <div class="form-group">
                    <label class="label">رقم تصريح السياحة</label>
                    <input type="text" name="tourism_permit_no" class="input" required>
                </div>

            @endif

            <button type="submit" class="btn btn-block">
                متابعة
            </button>

        </form>

    </div>

</div>

@endsection