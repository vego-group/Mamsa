@extends('layouts.app')

@section('content')

<div class="center">
    <div class="card text-center" style="max-width:600px;">

        <h3 class="title mb-20">شكرًا لك</h3>

        <p class="muted mb-40">
            تم استلام طلبك وسنقوم بالتحقق من المعلومات وإبلاغك بالتحديثات قريبًا.
        </p>

        <a href="{{ route('home') }}" class="btn">
            العودة للرئيسية
        </a>

    </div>
</div>

@endsection