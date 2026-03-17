<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Homepage</title>

    {{-- ربط ملف CSS --}}
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body>

    {{-- الهيدر المشترك فقط --}}
    @include('partials.header')

    {{-- شريط البحث والفلاتر --}}
    <section class="search-wrapper">
      {{-- أيقونة البحث --}}
      <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="11" cy="11" r="7"></circle>
        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
      </svg>

      {{-- الفلاتر: المدينة | نوع الوحدة | تاريخ بدء | تاريخ انتهاء --}}
      <div class="search-filters">

        {{-- زر تاريخ انتهاء الحجز --}}
        <div class="date-wrapper">
          <button type="button" class="filter-btn end" id="openCalendarEnd">تاريخ انتهاء الحجز</button>
          <input type="hidden" name="end_date" id="endDateValue">
        </div>

        {{-- زر تاريخ بدء الحجز --}}
        <div class="date-wrapper">
          <button type="button" class="filter-btn start" id="openCalendarStart">تاريخ بدء الحجز</button>
          <input type="hidden" name="start_date" id="startDateValue">
        </div>

        {{-- مودال التقويم --}}
        <div class="calendar-modal" id="calendarModal">
          <div class="calendar-content">
            <div class="calendar-header">
              <button id="prevMonth">‹</button>
              <span id="calendarMonth"></span>
              <button id="nextMonth">›</button>
            </div>

            <div class="weekdays">
              <div>س</div><div>ح</div><div>ن</div><div>ث</div><div>ر</div><div>خ</div><div>ج</div>
            </div>

            <div class="calendar-grid" id="calendarDays"></div>

            <div class="calendar-actions">
              <button id="closeCalendar">إغلاق</button>
            </div>
          </div>
        </div>

        {{-- عدد الأشخاص --}}
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

        {{-- نوع الوحدة --}}
        <div class="dropdown" id="unitTypeDropdown">
          <button type="button" class="filter-btn type" id="unitTypeBtn">نوع الوحدة</button>
          <input type="hidden" name="unit_type" id="selectedUnitType" value="">
          <div class="dropdown-menu" id="unitTypeMenu">
            <div class="dropdown-item" data-value="شقة">شقة</div>
            <div class="dropdown-item" data-value="فيلا">فيلا</div>
            <div class="dropdown-item" data-value="استديو">استديو</div>
          </div>
        </div>

        {{-- المدينة --}}
        <div class="dropdown" id="cityDropdown">
          <button type="button" class="filter-btn city" id="cityDropdownBtn">المدينة</button>
          <input type="hidden" name="city_id" id="selectedCityId" value="">
          <div class="dropdown-menu" id="cityDropdownMenu">
            <div class="dropdown-item" data-id="1">الرياض</div>
          </div>
        </div>

      </div> {{-- /search-filters --}}
    </section>

    {{-- الكروت --}}
    <div class="cards-section">
      <button class="show-all">عرض الجميع</button>
      <div class="cards">
        <div class="card"></div>
        <div class="card"></div>
        <div class="card"></div>
        <div class="card"></div>
      </div>
    </div>

    {{-- الفوتر --}}
    <div class="footer">
      <div class="footer-links">
        <a>نبذة عن ممسى</a>
        <a>طريقة عملنا</a>
        <a>شروط الاستخدام</a>
        <a>تواصل معنا</a>
      </div>
      <div class="footer-copy">MAMSA©2026. All rights reserved.</div>
    </div>

    <script>
      /* unitType dropdown و city dropdown و calendar و counter ... */
      /* اتركي سكربتاتك كما كانت، لم تتغير */
    </script>
</body>
</html>