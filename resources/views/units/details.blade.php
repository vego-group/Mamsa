@extends('layouts.app')

@section('content')

<!-- Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>




<div class="unit-layout">

    <!-- ================= RIGHT (المحتوى) ================= -->
    <div class="unit-main">

        <!-- Title -->
        <div style="display:flex;flex-direction:column;gap:6px;">

            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">

                <h1 style="margin:0;font-size:26px;font-weight:900;">
                    {{ $unit->unit_name }}
                </h1>

                @php
                    $avg = round($unit->reviews->avg('rating'),1);
                    $count = $unit->reviews->count();
                @endphp

                @if($count)
                    <div style="display:flex;align-items:center;gap:6px;color:#f59e0b;font-weight:900;">
                        ⭐ {{ $avg }}
                        <span style="color:#555;font-size:13px;">
                            ({{ $count }} تقييم)
                        </span>
                    </div>
                @endif

            </div>

            <!-- معلومات -->
            <div style="color:#777;font-size:14px;display:flex;gap:10px;flex-wrap:wrap;">
                <span>{{ $unit->city }}</span>
                <span>•</span>
                <span>{{ $unit->bedrooms }} غرف</span>
                <span>•</span>
                <span>{{ $unit->capacity }} أشخاص</span>
            </div>

        </div>

        <!-- Gallery -->
        <div class="unit-gallery">

        @if($unit->images->count())

        <div class="gallery-grid">

            {{-- الصورة الكبيرة --}}
            <div class="main-wrapper">
                <img class="main-photo"
     src="{{ asset('storage/'.$unit->images->first()->image_url) }}">

                {{-- 🔥 زر فوق الصورة --}}
                <button class="show-photos-btn" onclick="openGallery()">
                    عرض جميع الصور
                </button>
            </div>

            {{-- الصور الصغيرة --}}
            <div class="gallery-side">

              @foreach($unit->images->skip(1)->take(4) as $img)
    <img src="{{ asset('storage/'.$img->image_url) }}" class="gallery-thumb">
@endforeach

            </div>

        </div>

        @else

        <img src="{{ asset('images/default.jpg') }}" class="main-photo">

        @endif

        </div>

        <!-- Description -->
        <div class="unit-description">
            <h2>وصف الوحدة</h2>
            <p>{{ $unit->description }}</p>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab" data-tab="features">المميزات</button>
            <button class="tab active" data-tab="location">الموقع</button>
            <button class="tab" data-tab="reviews">التقييمات</button>
            <button class="tab" data-tab="policy">سياسة الإلغاء</button>
        </div>


        <!-- Features -->
        <div class="tab-content" id="features">

            @if($unit->features->count())

                <div class="features-grid">
                    @foreach($unit->features as $feature)
                        <div class="feature"> {{ $feature->name }}</div>
                    @endforeach
                </div>

            @else
                <p style="color:#777;">لا توجد مميزات</p>
            @endif

        </div>


        <!-- Location -->
        <div class="tab-content active" id="location">
            <div id="map" style="height:350px;border-radius:15px;"></div>
        </div>

       

        
<!-- ================= Reviews ================= -->

@php
$avg = round($unit->reviews->avg('rating'),1);
$total = $unit->reviews->count();
@endphp

<div class="tab-content" id="reviews">

    {{-- ⭐ ملخص التقييم --}}
    @if($total)
    <div style="background:#f8f8f8;padding:20px;border-radius:16px;margin-bottom:20px;text-align:right;">
        <div style="display:flex;align-items:center;gap:15px;justify-content:flex-end;">
            <div style="font-size:32px;font-weight:900;">
                {{ $avg }}
            </div>

            <div style="color:#f59e0b;font-size:18px;">
                ★★★★★
            </div>

            <div style="color:#777;">
                {{ $total }} تقييم
            </div>
        </div>
    </div>
    @endif


    {{-- ➕ زر إضافة تقييم --}}
    @if($canReview)
        <button onclick="toggleReviewForm()" class="booking-btn" style="margin-bottom:15px;">
            + أضف تقييم
        </button>

        {{-- ⭐ الفورم الاحترافي --}}
        <div id="reviewForm" style="display:none;margin-bottom:20px;">

            <form method="POST" action="{{ route('review.store') }}">
                @csrf

                <input type="hidden" name="unit_id" value="{{ $unit->id }}">
                <input type="hidden" name="rating" id="rating">

                <!-- النجوم -->
                <div style="font-size:26px;color:#f59e0b;margin-bottom:10px;">
                    @for($i=1;$i<=5;$i++)
                        <span onclick="setRating({{ $i }})"
                              id="star{{ $i }}"
                              style="cursor:pointer;">☆</span>
                    @endfor
                </div>

                <!-- التعليق -->
                <textarea name="comment"
                          class="input"
                          placeholder="اكتب تجربتك..."
                          required></textarea>

                <button class="booking-btn" style="margin-top:10px;">
                    إرسال التقييم
                </button>

            </form>

        </div>
    @endif


    {{-- ⭐ عرض التقييمات --}}
    @if($unit->reviews->count())

        <div style="display:flex;flex-direction:column;gap:15px;">

            @foreach($unit->reviews->sortByDesc('created_at') as $review)

                <div class="review-card" style="text-align:right;">

                    <!-- اسم المستخدم -->
                    <div style="font-weight:700;margin-bottom:5px;">
                        {{ $review->user->name ?? 'مستخدم' }}
                    </div>

                    <!-- النجوم -->
                    <div class="review-stars">
                        @for($i=1;$i<=5;$i++)
                            {{ $i <= $review->rating ? '★' : '☆' }}
                        @endfor
                    </div>

                    <!-- التعليق -->
                    <div class="review-text">
                        {{ $review->comment ?? 'بدون تعليق' }}
                    </div>

                    <!-- الوقت -->
                    <div class="review-author">
                        {{ $review->created_at->diffForHumans() }}
                    </div>

                </div>

            @endforeach

        </div>

    @else

        <p style="color:#777;text-align:right;margin-top:20px;">
            لا يوجد تقييمات بعد 
        </p>

    @endif

</div>

        <!-- Policy -->
        <div class="tab-content" id="policy">

            <h3 style="margin-bottom:10px;">سياسة الإلغاء</h3>

            @if($unit->cancellation_policy == 'no_cancel')
                <p> لا يمكن إلغاء الحجز بعد الدفع</p>
            @elseif($unit->cancellation_policy == '48_hours')
                <p> يمكنك الإلغاء قبل 48 ساعة من موعد الوصول</p>
            @endif

            <hr style="margin:15px 0">

            <h4>مواعيد الدخول والخروج</h4>

            <p>
                الدخول: {{ $unit->checkin_time ?? '—' }}<br>
                الخروج: {{ $unit->checkout_time ?? '—' }}
            </p>

        </div>

    </div>


    <!-- ================= LEFT (كارد الحجز) ================= -->
    <form method="GET" action="{{ route('checkout') }}">

        <div class="booking-card">

            <div class="price-row">
                <span class="price">{{ $unit->price }} ريال</span>
                <span class="per-night">/ ليلة</span>
            </div>

            <input type="hidden" name="unit" value="{{ $unit->id }}">

            <div class="booking-dates">
                <div class="date-box">
                    <label>الوصول</label>
                    <input type="date" id="checkin" name="checkin" required>
                </div>

                <div class="date-box">
                    <label>المغادرة</label>
                    <input type="date" id="checkout" name="checkout" required>
                </div>
            </div>

            <div class="nights-box">
                عدد الليالي: <span id="nights">1</span>
            </div>
            <div style="font-size:13px;color:#777;margin-top:10px">

                وقت الدخول:
                <b>{{ $unit->checkin_time ?? '—' }}</b><br>

                وقت المغادرة:
                <b>{{ $unit->checkout_time ?? '—' }}</b>

            </div>

            <div class="total-price">
                الإجمالي: <span id="total">{{ $unit->price }}</span> ريال
            </div>

            <button type="submit" class="booking-btn">
                احجز الآن
            </button>
            
            <div class="booking-note">
             لن يتم خصم أي مبلغ الآن     
           </div>

        </div>

    </form>

</div>


<!-- ================= GALLERY MODAL ================= -->
<div id="photoGallery" class="gallery-modal">

    <span class="close-gallery" onclick="closeGallery()">×</span>

    <div class="gallery-content">

        <button class="gallery-nav prev" onclick="prevPhoto()">‹</button>

        <img id="galleryImage">

        <button class="gallery-nav next" onclick="nextPhoto()">›</button>

    </div>

</div>


<script>

/* ================= Tabs ================= */

// 🔥 نخلي التاب الافتراضي "features"
document.addEventListener("DOMContentLoaded", () => {

    let defaultTab = document.querySelector('[data-tab="features"]');

    if(defaultTab){
        defaultTab.click();
    }

});


document.querySelectorAll(".tab").forEach(tab => {

    tab.addEventListener("click", () => {

        document.querySelectorAll(".tab").forEach(t => t.classList.remove("active"))
        document.querySelectorAll(".tab-content").forEach(c => c.classList.remove("active"))

        tab.classList.add("active")
        document.getElementById(tab.dataset.tab).classList.add("active")

        if(tab.dataset.tab === "location"){
            setTimeout(loadMap,300)
        }

    })

})

/* ================= Review ================= */

function toggleReviewForm(){
    let f = document.getElementById('reviewForm');
    f.style.display = (f.style.display === 'none') ? 'block' : 'none';
}

function setRating(rating){
    document.getElementById('rating').value = rating;

    for(let i=1;i<=5;i++){
        document.getElementById('star'+i).innerText =
            i <= rating ? '★' : '☆';
    }
}


/* ================= Map ================= */

let mapLoaded = false

function loadMap(){

    if(mapLoaded) return
    mapLoaded = true

    var map = L.map('map').setView(
        [{{ $unit->lat ?? 24.7136 }}, {{ $unit->lng ?? 46.6753 }}],
        13
    )

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
        maxZoom:19
    }).addTo(map)

    L.marker(
        [{{ $unit->lat ?? 24.7136 }}, {{ $unit->lng ?? 46.6753 }}]
    ).addTo(map)

}


/* ================= Booking ================= */

const checkin = document.getElementById("checkin")
const checkout = document.getElementById("checkout")
const nights = document.getElementById("nights")

const pricePerNight = {{ $unit->price }}

const totalBox = document.querySelector(".total-price")

function calculateBooking(){

    if(!checkin.value || !checkout.value) return

    let start = new Date(checkin.value)
    let end = new Date(checkout.value)

    if(end <= start){
        alert("تاريخ المغادرة يجب أن يكون بعد الوصول")
        checkout.value=""
        nights.innerText = 1
        totalBox.innerHTML = "الإجمالي: "+pricePerNight+" ريال"
        return
    }

    let diff = Math.max(1, (end-start)/(1000*60*60*24))

    nights.innerText = diff

    let total = diff * pricePerNight
    totalBox.innerHTML = "الإجمالي: "+total+" ريال"

}

let today = new Date().toISOString().split("T")[0]
checkin.min = today
checkout.min = today

checkin.addEventListener("change", () => {
    checkout.min = checkin.value
    calculateBooking()
})

checkout.addEventListener("change", calculateBooking)


/* ================= Gallery ================= */

let images = [
@foreach($unit->images as $img)
"{{ asset('storage/'.$img->image_url) }}",
@endforeach
]

let currentPhoto = 0

function openGallery(){
    document.getElementById("photoGallery").style.display="flex"
    document.getElementById("galleryImage").src = images[currentPhoto]
}

function closeGallery(){
    document.getElementById("photoGallery").style.display="none"
}

function nextPhoto(){
    currentPhoto = (currentPhoto + 1) % images.length
    document.getElementById("galleryImage").src = images[currentPhoto]
}

function prevPhoto(){
    currentPhoto = (currentPhoto - 1 + images.length) % images.length
    document.getElementById("galleryImage").src = images[currentPhoto]
}

function goToCheckout(){

    const checkin = document.getElementById("checkin").value;
    const checkout = document.getElementById("checkout").value;

    if(!checkin || !checkout){
        alert("اختار التواريخ أول");
        return;
    }

    window.location.href = `/checkout?unit={{ $unit->id }}&checkin=${checkin}&checkout=${checkout}`;
}

</script>

@endsection