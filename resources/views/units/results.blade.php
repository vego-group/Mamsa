@extends('layouts.app')

@section('content')

<h2 style="text-align:right; margin-top:20px">نتائج البحث</h2>

<div class="cards">
    @forelse($units as $unit)
        <a href="{{ route('units.details', $unit->id) }}" class="card">

            @if($unit->images->first())
                <img src="{{ asset('storage/' . $unit->images->first()->image_url) }}">
            @else
                <img src="{{ asset('images/no-image.jpg') }}">
            @endif

            <div class="card-content">

                <div class="card-title">{{ $unit->name }}</div>

                <div class="card-location">
                    {{ $unit->city ?? 'غير محدد' }} • {{ $unit->bedrooms ?? '-' }} غرف
                </div>

                <div class="card-price">
                    {{ number_format($unit->price) }} ريال / ليلة
                </div>

            </div>

        </a>
    @empty
        <p style="text-align:right; color:#555">لا توجد وحدات مطابقة لبحثك</p>
    @endforelse
</div>

@endsection