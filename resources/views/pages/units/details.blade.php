@extends('layouts.app')

@section('content')

<div class="unit-page">

<div class="unit-layout">

<!-- المحتوى الرئيسي -->
<div class="unit-main">

    <!-- العنوان -->
    <div class="unit-header">

        <h1 class="unit-title">
            {{ $unit->name }}
        </h1>

        <div class="unit-rating">
            <span class="stars">★★★★★</span>

            <span class="rating-number">
                4.8
            </span>

            <span class="reviews-count">
                (124 تقييم)
            </span>

            <span class="unit-meta">
                {{ $unit->city }} • {{ $unit->bedrooms }} غرف • {{ $unit->capacity }} أشخاص
            </span>
        </div>

    </div>


    <!-- الصور -->
    <div class="unit-gallery">

        @if($unit->images->count())

        <div class="gallery-grid">

            <img class="main-photo"
            src="{{ asset('storage/' . $unit->images->first()->image_url) }}">

            <div class="gallery-side">

                @foreach($unit->images->skip(1)->take(4) as $img)
                    <img src="{{ asset('storage/' . $img->image_url) }}" class="gallery-thumb">
                @endforeach

            </div>

        </div>

        @endif

        <button class="show-photos-btn" onclick="openGallery()">
            عرض جميع الصور
        </button>

    </div>


    <!-- وصف الوحدة -->
    <div class="unit-description">

        <h2>وصف الوحدة</h2>

        <p>
            {{ $unit->description }}
        </p>

    </div>


    <!-- التبويبات -->
    <div class="tabs">

        <button class="tab active" data-tab="features">
            المميزات والمواصفات
        </button>

        <button class="tab" data-tab="reviews">
            التقييمات
        </button>

        <button class="tab" data-tab="location">
            الموقع
        </button>

        <button class="tab" data-tab="policy">
            شروط الحجز والإلغاء
        </button>

    </div>


    <!-- المميزات -->
    <div class="tab-content active" id="features">

        <div class="features-grid">
            <div class="feature">واي فاي</div>
            <div class="feature">موقف سيارات</div>
            <div class="feature">مطبخ كامل</div>
            <div class="feature">تكييف</div>
            <div class="feature">تلفزيون</div>
            <div class="feature">غسالة</div>
        </div>

    </div>


    <!-- التقييمات -->
    <div class="tab-content" id="reviews">

        <div class="review-card">

            <div class="review-stars">★★★★★</div>

            <div class="review-text">
                مكان جميل جداً ونظيف وقريب من البوليفارد.
            </div>

            <div class="review-author">
                خالد أحمد
            </div>

        </div>

    </div>


    <!-- الموقع -->
    <div class="tab-content" id="location">

        <div id="map" style="height:350px;border-radius:15px;"></div>

    </div>


    <!-- السياسات -->
    <div class="tab-content" id="policy">
        الدخول: بعد 3 مساءً  
        الخروج: قبل 12 ظهراً  
        <br><br>
        الإلغاء مجاني قبل 24 ساعة.
    </div>


</div> <!-- /unit-main -->


<!-- كارد الحجز -->
<div class="booking-card">

    <div class="price-row">

        <span class="price">
            {{ $unit->price }} ريال
        </span>

        <span class="per-night">
            / ليلة
        </span>

    </div>

    <div class="booking-dates">

        <div class="date-box">
            <label>الوصول</label>
            <input type="date" id="checkin">
        </div>

        <div class="date-box">
            <label>المغادرة</label>
            <input type="date" id="checkout">
        </div>

    </div>

    <div style="font-size:13px;color:#777;margin-top:10px">
        وقت الدخول: <b>3:00 مساءً</b><br>
        وقت المغادرة: <b>12:00 ظهراً</b>
    </div>

    <div class="nights-box">
        عدد الليالي:
        <span id="nights">1</span>
    </div>

    <div class="total-price">
        الإجمالي: {{ $unit->price }} ريال
    </div>

    <button class="booking-btn">
        احجز الآن
    </button>

    <div class="booking-note">
        لن يتم خصم أي مبلغ الآن
    </div>

</div>


</div>
</div>


<!-- معرض الصور -->
<div id="photoGallery" class="gallery-modal">

    <span class="close-gallery" onclick="closeGallery()">×</span>

    <div class="gallery-content">

        <button class="gallery-nav prev" onclick="prevPhoto()">‹</button>

        <img id="galleryImage">

        <button class="gallery-nav next" onclick="nextPhoto()">›</button>

    </div>

</div>



<script>

/* =========================
        التبويبات
========================= */
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


/* =========================
          الخريطة
========================= */
let mapLoaded = false

function loadMap(){

    if(mapLoaded) return

    mapLoaded = true

    var map = L.map('map').setView(
        [{{ $unit->lat ?? 24.7136 }}, {{ $unit->lng ?? 46.6753 }}],
        13
    )

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom:19
    }).addTo(map)

    L.marker(
        [{{ $unit->lat ?? 24.7136 }}, {{ $unit->lng ?? 46.6753 }}]
    ).addTo(map)

}


/* =========================
        حساب الليالي
========================= */
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
        nights.value=1
        totalBox.innerHTML = "الإجمالي: "+pricePerNight+" ريال"

        return
    }

    let diff = (end-start)/(1000*60*60*24)

    nights.innerText = diff

    let total = diff * pricePerNight

    totalBox.innerHTML = "الإجمالي: "+total+" ريال"

}

let today = new Date().toISOString().split("T")[0]
checkin.min = today
checkout.min = today

checkin.addEventListener("change",calculateBooking)
checkout.addEventListener("change",calculateBooking)


/* =========================
      معرض الصور
========================= */

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
    currentPhoto++
    if(currentPhoto >= images.length){
        currentPhoto = 0
    }
    document.getElementById("galleryImage").src = images[currentPhoto]
}

function prevPhoto(){
    currentPhoto--
    if(currentPhoto < 0){
        currentPhoto = images.length-1
    }
    document.getElementById("galleryImage").src = images[currentPhoto]
}

</script>

@endsection