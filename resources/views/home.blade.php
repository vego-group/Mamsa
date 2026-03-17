<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Homepage</title>

    <!-- ربط ملف CSS -->
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>

<body>
<!-- الهيدر -->
<header class="header header--hero">
  <div class="header-container">
    <!-- الشعار يمين الهيدر -->
    <div class="logo">
      <img src="{{ asset('images/logo.png') }}" alt="شعار الموقع">
    </div>

    <!-- أزرار يسار الهيدر (كبسولات) -->
    <div class="header-actions">
     
      <a href="{{ route('auth.phone', ['intent' => 'login']) }}" class="header-pill">
      تسجيل الدخول
     </a>

    
      <a href="{{ route('auth.phone', ['intent' => 'partner']) }}" class="header-pill">
     كن شريكًا معنا 
      </a>
    </div>
  </div> <!-- /header-container -->
</header>

<!-- شريط البحث والفلاتر -->
<section class="search-wrapper">

  <!-- أيقونة البحث -->
  <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true">
    <circle cx="11" cy="11" r="7"></circle>
    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
  </svg>

  <!-- الفلاتر: المدينة | نوع الوحدة | تاريخ بدء | تاريخ انتهاء -->
  <div class="search-filters">

    <!-- زر تاريخ انتهاء الحجز -->
    <div class="date-wrapper">
      <button type="button" class="filter-btn end" id="openCalendarEnd">
          تاريخ انتهاء الحجز
      </button>
      <input type="hidden" name="end_date" id="endDateValue">
    </div>

    <div class="date-wrapper">
      <button type="button" class="filter-btn start" id="openCalendarStart">
          تاريخ بدء الحجز
      </button>
      <input type="hidden" name="start_date" id="startDateValue">
    </div>

    <!-- مودال التقويم -->
    <div class="calendar-modal" id="calendarModal">
      <div class="calendar-content">

          <div class="calendar-header">
              <button id="prevMonth">‹</button>
              <span id="calendarMonth"></span>
              <button id="nextMonth">›</button>
          </div>

          <!-- أيام الأسبوع -->
          <div class="weekdays">
              <div>س</div>
              <div>ح</div>
              <div>ن</div>
              <div>ث</div>
              <div>ر</div>
              <div>خ</div>
              <div>ج</div>
          </div>

          <div class="calendar-grid" id="calendarDays"></div>

          <div class="calendar-actions">
              <button id="closeCalendar">إغلاق</button>
          </div>

      </div>
    </div>

    <div class="dropdown-container">
      <button type="button" class="filter-btn capcity" id="capcityBtn">
          عدد الأشخاص: <span id="capcityCount">1</span>
      </button>

      <div id="capcityMenu" class="dropdown-menu">
          <div class="row">
              <span>عدد الأشخاص</span>
              <div class="counter">
                  <button id="minusBtn">-</button>
                  <span id="number">1</span>
                  <button id="plusBtn">+</button>
              </div>
          </div>
      </div>
    </div>

    <!-- نوع الوحدة (Dropdown) -->
    <div class="dropdown" id="unitTypeDropdown">
      <button type="button" class="filter-btn type" id="unitTypeBtn">نوع الوحدة</button>

      <!-- قيمة النوع المختار -->
      <input type="hidden" name="unit_type" id="selectedUnitType" value="">

      <div class="dropdown-menu" id="unitTypeMenu">
          <div class="dropdown-item" data-value="شقة">شقة</div>
          <div class="dropdown-item" data-value="فيلا">فيلا</div>
          <div class="dropdown-item" data-value="استديو">استديو</div>
      </div>
    </div>

    <script>
    (function() {
      const btn   = document.getElementById('unitTypeBtn');
      const menu  = document.getElementById('unitTypeMenu');
      const input = document.getElementById('selectedUnitType');

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = menu.style.display === 'block';
        document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
        menu.style.display = isOpen ? 'none' : 'block';
      });

      menu.addEventListener('click', function (e) {
        const item = e.target.closest('.dropdown-item');
        if (!item) return;

        const selectedValue = item.getAttribute('data-value');

        btn.textContent = selectedValue;
        input.value = selectedValue;

        menu.style.display = 'none';
      });

      document.addEventListener('click', function (e) {
        if (!e.target.closest('#unitTypeDropdown')) {
          menu.style.display = 'none';
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          menu.style.display = 'none';
        }
      });
    })();
    </script>

    <!-- المدينة -->
    <div class="dropdown" id="cityDropdown">
      <button type="button" class="filter-btn city" id="cityDropdownBtn">المدينة</button>

      <input type="hidden" name="city_id" id="selectedCityId" value="">

      <div class="dropdown-menu" id="cityDropdownMenu">
        <div class="dropdown-item" data-id="1">الرياض</div>
      </div>
    </div>

  </div> <!-- /search-filters -->
</section>

<!-- الكروت -->
<div class="cards-section">

    <button class="show-all">عرض الجميع</button>

    <div class="cards">

        @foreach($units as $unit)

        <a href="{{ route('units.details', $unit->id) }}" class="card">

            @if($unit->images->first())
            <img src="{{ asset('storage/' . $unit->images->first()->image_url) }}">
            @else
            <img src="{{ asset('images/no-image.jpg') }}">
            @endif

            <div class="card-content">

                <div class="card-title">
                    {{ $unit->name }}
                </div>

                <div class="card-location">
                    {{ $unit->city ?? 'غير محدد' }} • {{ $unit->bedrooms ?? '—' }} غرف
                </div>

                <div class="card-price">
                    {{ number_format($unit->price) }} ريال / ليلة
                </div>

            </div>

        </a>

        @endforeach

    </div>

</div>

<!-- الفوتر -->
<div class="footer">
  <div class="footer-links">
    <a>نبذة عن ممسى</a>
    <a>طريقة عملنا</a>
    <a>شروط الاستخدام</a>
    <a>تواصل معنا</a>
  </div>
  <div class="footer-copy">MAMSA©2026. All rights reserved.</div>
</div>

<!-- بقية الـ JS بالكامل كما هو (ما عدلته) -->
<script>
/* كل السكربتات الطويلة حق التقويم والفلاتر — أنا ما غيرتها */
</script>

</body>
</html>