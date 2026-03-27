<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Homepage</title>
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body>


{{-- نفتح فورم الفلاتر --}}
<form method="GET" action="{{ route('units.filter') }}" id="filterForm">
<section class="search-wrapper">

    {{-- أيقونة البحث --}}
    {{-- الفلاتر --}}
    <div class="search-filters">

        {{-- تاريخ انتهاء --}}
        <div class="date-wrapper">
            <button type="button" class="filter-btn end" id="openCalendarEnd">تاريخ انتهاء الحجز</button>
            <input type="hidden" name="end_date" id="endDateValue">
        </div>

        {{-- تاريخ بدء --}}
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

        {{-- حقل مخفي لعدد الأشخاص --}}
        <input type="hidden" name="capacity" id="capacityHidden" value="1">

        {{-- نوع الوحدة --}}
        <div class="dropdown" id="unitTypeDropdown">
            <button type="button" class="filter-btn type" id="unitTypeBtn">نوع الوحدة</button>
            <input type="hidden" name="unit_type" id="selectedUnitType">

            <div class="dropdown-menu" id="unitTypeMenu">
                <div class="dropdown-item" data-value="شقة">شقة</div>
                <div class="dropdown-item" data-value="فيلا">فيلا</div>
                <div class="dropdown-item" data-value="استديو">استديو</div>
            </div>
        </div>

        {{-- المدينة --}}
        <div class="dropdown" id="cityDropdown">
            <button type="button" class="filter-btn city" id="cityDropdownBtn">المدينة</button>
            <input type="hidden" name="city_id" id="selectedCityId">

            <div class="dropdown-menu" id="cityDropdownMenu">
                <div class="dropdown-item" data-id="1">الرياض</div>
            </div>
        </div>

    </div>{{-- /search-filters --}}
<button type="button" onclick="submitFilters()" class="search-icon-btn">
    <svg class="search-icon" viewBox="0 0 24 24">
        <circle cx="11" cy="11" r="7"></circle>
        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
    </svg>
</button>  
</section>

{{-- قسم الكروت --}}
<div class="cards-section">

    {{-- زر إرسال الفلاتر --}}
    <button type="submit" class="show-all">
        عرض الجميع
    </button>

    <div class="cards">
        @foreach($units as $unit)
            <a href="{{ route('units.details', $unit->id) }}" class="card">

                @if($unit->images->first())
                    <img src="{{ asset('storage/' . $unit->images->first()->image_url) }}">
                @else
                    <img src="{{ asset('images/no-image.jpg') }}">
                @endif

                <div class="card-content">

                    <div class="card-title">{{ $unit->name }}</div>

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
</form> {{-- إغلاق الفورم --}}

    {{-- سكربتات JS (بدون تكرار) --}}
    <script>
        /* نفس سكربتاتك الخاصة بالتقويم، بدون أي تكرار */
    </script>

    <script>
        /* سكربت عدد الأشخاص */
    </script>

</body>
</html>
<script>
document.addEventListener("DOMContentLoaded", () => {

    // عناصر المودال والتقويم
    const modal        = document.getElementById("calendarModal");
    const monthLabel   = document.getElementById("calendarMonth");
    const daysContainer= document.getElementById("calendarDays");

    // أزرار وحقول البداية/النهاية
    const openStart  = document.getElementById("openCalendarStart");
    const openEnd    = document.getElementById("openCalendarEnd");
    const startInput = document.getElementById("startDateValue");
    const endInput   = document.getElementById("endDateValue");

    let currentDate   = new Date();
    let selectedInput = null;

    // اليوم الحالي
    let today = new Date();
    today.setHours(0,0,0,0);

    // للمقارنة
    let minEndDate = null;

    // دوال مساعدة
    function fmt(date) {
        return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
    }

    function addDays(date, n) {
        const d = new Date(date);
        d.setDate(d.getDate() + n);
        d.setHours(0,0,0,0);
        return d;
    }

    // فتح مودال "بدء الحجز"
    openStart.addEventListener("click", () => {
        selectedInput = { btn: openStart, input: startInput };
        modal.style.display = "flex";

        if (startInput.value) {
            currentDate = new Date(startInput.value);
        } else {
            currentDate = new Date();
        }

        renderCalendar();
    });

    // فتح مودال "انتهاء الحجز"
    openEnd.addEventListener("click", () => {
        selectedInput = { btn: openEnd, input: endInput };
        modal.style.display = "flex";

        if (startInput.value) {
            minEndDate = new Date(startInput.value);
            minEndDate.setHours(0,0,0,0);
        } else {
            minEndDate = today;
        }

        if (endInput.value) {
            currentDate = new Date(endInput.value);
        }

        renderCalendar();
    });

    // إغلاق المودال
    document.getElementById("closeCalendar").addEventListener("click", () => {
        modal.style.display = "none";
    });

    modal.addEventListener("click", (e) => {
        if (e.target === modal) modal.style.display = "none";
    });

    // ================================
    //           التقويم
    // ================================
    function renderCalendar() {

        const y = currentDate.getFullYear();
        const m = currentDate.getMonth();

        monthLabel.textContent = `${y}-${String(m+1).padStart(2,'0')}`;
        daysContainer.innerHTML = "";

        const firstDay = new Date(y, m, 1).getDay();
        const numDays  = new Date(y, m+1, 0).getDate();

        // فراغات قبل أول يوم
        for (let i = 0; i < firstDay; i++) {
            daysContainer.appendChild(document.createElement("div"));
        }

        // الأيام
        for (let d = 1; d <= numDays; d++) {

            const cell = document.createElement("div");
            cell.textContent = d;

            const thisDate = new Date(y, m, d);
            thisDate.setHours(0,0,0,0);

            let disabled = false;

            // منع الأيام الماضية
            if (thisDate < today) disabled = true;

            // إذا نختار "انتهاء": امنع قبل البداية ويوم البداية
            if (selectedInput && selectedInput.input === endInput && minEndDate) {
                if (thisDate < minEndDate) disabled = true;
                if (thisDate.getTime() === minEndDate.getTime()) disabled = true;
            }

            if (disabled) {
                cell.style.opacity = "0.4";
                cell.style.cursor  = "not-allowed";
                cell.style.background = "#eee";
            } else {
                // عند اختيار يوم
                cell.addEventListener("click", () => {

                    const dateStr = fmt(thisDate);

                    // 1) المستخدم اختار "بداية"
                    if (selectedInput.input === startInput) {

                        // لو النهاية موجودة والبداية الجديدة أكبر منها → اضبط النهاية لليوم التالي
                        if (endInput.value) {
                            const end = new Date(endInput.value);
                            end.setHours(0,0,0,0);

                            if (thisDate >= end) {
                                const next = addDays(thisDate, 1);
                                endInput.value = fmt(next);
                                openEnd.textContent = endInput.value;
                            }
                        } else {
                            // لو ما فيه نهاية → خلي النهاية اليوم التالي تلقائيًا
                            const next = addDays(thisDate,1);
                            endInput.value = fmt(next);
                            openEnd.textContent = endInput.value;
                        }

                        startInput.value = dateStr;
                        openStart.textContent = dateStr;
                        modal.style.display = "none";
                        return;
                    }

                    // 2) المستخدم اختار "انتهاء"
                    if (selectedInput.input === endInput) {

                        if (startInput.value) {
                            const start = new Date(startInput.value);
                            start.setHours(0,0,0,0);

                            // لو النهاية أقل أو نفس يوم البداية → اضبط النهاية = يوم بعد البداية
                            if (thisDate <= start) {
                                const next = addDays(start,1);
                                endInput.value = fmt(next);
                                openEnd.textContent = endInput.value;
                                modal.style.display = "none";
                                return;
                            }
                        } else {
                            // لو البداية غير محددة → خلي البداية يوم قبل النهاية
                            const prev = addDays(thisDate,-1);
                            startInput.value = fmt(prev);
                            openStart.textContent = startInput.value;
                        }

                        endInput.value = dateStr;
                        openEnd.textContent = dateStr;
                        modal.style.display = "none";
                        return;
                    }

                });
            }

            daysContainer.appendChild(cell);
        }

    }

    // الشهر السابق
    document.getElementById("prevMonth").addEventListener("click", () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
    });

    // الشهر التالي
    document.getElementById("nextMonth").addEventListener("click", () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
    });

});
</script>

<script>
let capcityMenu = document.getElementById("capcityMenu");
let capcityBtn = document.getElementById("capcityBtn");
let number = document.getElementById("number");
let capcityCount = document.getElementById("capcityCount");

let plusBtn = document.getElementById("plusBtn");
let minusBtn = document.getElementById("minusBtn");

capcityBtn.onclick = () => {
    capcityMenu.style.display = capcityMenu.style.display === "block" ? "none" : "block";
};

plusBtn.onclick = () => {
    number.textContent = Number(number.textContent) + 1;
    capcityCount.textContent = number.textContent;
};

minusBtn.onclick = () => {
    if (Number(number.textContent) > 1) {
        number.textContent = Number(number.textContent) - 1;
        capcityCount.textContent = number.textContent;
    }
};

document.addEventListener("click", function (e) {
    if (!capcityBtn.contains(e.target) && !capcityMenu.contains(e.target)) {
        capcityMenu.style.display = "none";
    }
});
</script>
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
    <script>
  (function() {
    const btn   = document.getElementById('cityDropdownBtn');
    const menu  = document.getElementById('cityDropdownMenu');
    const input = document.getElementById('selectedCityId');

    // فتح/إغلاق القائمة
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      const isOpen = menu.style.display === 'block';
      menu.style.display = isOpen ? 'none' : 'block';
    });

    // اختيار مدينة
    menu.addEventListener('click', function (e) {
      const item = e.target.closest('.dropdown-item');
      if (!item) return;

      const id   = item.getAttribute('data-id');
      const name = item.textContent.trim();

      // تحديث الزر + قيمة الحقل المخفي
      btn.textContent = name;
      input.value = id;

      // إغلاق القائمة
      menu.style.display = 'none';
    });

    // إغلاق القائمة عند الضغط خارجها
    document.addEventListener('click', function (e) {
      if (!e.target.closest('#cityDropdown')) {
        menu.style.display = 'none';
      }
    });

    // إغلاق بالكيبورد (Esc)
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        menu.style.display = 'none';
      }
    });
  })();
</script>
<script>
function submitFilters() {
    document.getElementById('filterForm').submit();
}
</script>
    </body>
</html>