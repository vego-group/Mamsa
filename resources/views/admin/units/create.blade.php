@extends('layouts.app')

@section('content')

<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>

<style>
/* ❌ ما لمست ولا حرف من تصميمك */
</style>

<div class="page-wrap">

  <div class="flex-between section">
      <h2 class="title">إضافة عقار</h2>
  </div>

  <div class="split">

      {{-- ================= LEFT: FORM ================= --}}
      <div class="card">

          <div class="card-head">
            <h3 class="card-title">تفاصيل العقار</h3>
            <p class="hint">املأ البيانات وسيظهر كل شيء في المعاينة تلقائيًا.</p>
          </div>

          @php
          $type = auth()->user()->adminDetails->type ?? null;
          @endphp

          <form id="unitForm" method="POST"
                action="{{ route('admin.unit.store') }}"
                enctype="multipart/form-data">

              @csrf

              <input type="hidden" name="lat" id="lat">
              <input type="hidden" name="lng" id="lng">
              <input type="hidden" name="main_index" id="main_index" value="0">

              {{-- ================= 🔥 قسم التصريح فوق ================= --}}
              <div class="card section" style="margin-bottom:16px; border:2px dashed rgba(47,111,99,.2);">

                <h4 class="card-title">بيانات التصريح</h4>

                @if($type === 'individual')
                <div class="form-group">
                    <label class="label">رقم تصريح وزارة السياحة</label>
                    <input type="text" name="tourism_permit_no" class="input" required>
                </div>
                @endif

                @if($type === 'company')
                <div class="form-group">
                    <label class="label">رقم الترخيص</label>
                    <input type="text" name="company_license_no" class="input" required>
                </div>
                @endif

                <div class="form-group">
                    <label class="label">ملف التصريح (PDF فقط)</label>
                    <input type="file"
                           name="tourism_permit_file"
                           id="permit_file"
                           class="input"
                           accept="application/pdf"
                           required>

                    <small class="mini">نقبل فقط ملفات PDF</small>

                    <div id="pdfPreview" style="margin-top:10px;"></div>
                </div>

              </div>

              {{-- ================= بياناتك الأصلية بدون تغيير ================= --}}
              <div class="grid">

                <div class="form-group" style="grid-column:1/-1;">
                    <label class="label">اسم العقار</label>
                    <input type="text" name="unit_name" id="unit_name" class="input" required>
                </div>

                <div class="form-group">
                    <label class="label">نوع العقار</label>
                    <select name="unit_type" id="unit_type" class="input" required>
                        <option value="">اختر</option>
                        <option value="apartment">شقة</option>
                        <option value="villa">فيلا</option>
                        <option value="studio">استوديو</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="label">السعر</label>
                    <input type="number" name="price" id="price" class="input" required>
                </div>

                <div class="form-group">
                    <label class="label">السعة</label>
                    <input type="number" name="capacity" id="capacity" class="input" required>
                </div>

                <div class="form-group">
                    <label class="label">المدينة</label>
                    <input type="text" name="city" id="city" class="input" required>
                </div>

                <div class="form-group">
                    <label class="label">الحي</label>
                    <input type="text" name="district" id="district" class="input" required>
                </div>

                <div class="form-group" style="grid-column:1/-1;">
                    <label class="label">الوصف</label>
                    <textarea name="description" id="description" class="input" required></textarea>
                </div>

                {{-- 🔥 المميزات --}}
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="label">المميزات</label>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <label><input type="checkbox" name="features[]" value="wifi"> واي فاي</label>
                        <label><input type="checkbox" name="features[]" value="pool"> مسبح</label>
                        <label><input type="checkbox" name="features[]" value="parking"> موقف</label>
                    </div>
                </div>

                {{-- 🔥 الشروط --}}
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="label">الشروط</label>
                    <select name="cancellation_policy" class="input" required>
                        <option value="no_cancel">غير قابل للإلغاء</option>
                        <option value="48_hours">إلغاء قبل 48 ساعة</option>
                    </select>
                </div>

              </div>

              {{-- الصور (ما لمستها) --}}
              <div class="form-group">
                  <label class="label">صور العقار</label>
                  <input type="file" name="images[]" id="images" multiple required>
              </div>

              <button type="submit" class="btn btn-block">
                حفظ العقار
              </button>

          </form>

      </div>

      {{-- ================= RIGHT: نفس كودك ================= --}}
      <div>
          <div id="map" style="height:300px;"></div>
          <div id="preview"></div>
      </div>

  </div>

</div>

<script>
/* 🔥 منع إرسال بدون صور */
document.getElementById('unitForm').addEventListener('submit', function(e){
  const images = document.getElementById('images');
  if(images.files.length === 0){
    alert('يجب رفع صورة');
    e.preventDefault();
  }
});

/* 🔥 PDF preview */
document.getElementById('permit_file').addEventListener('change', function(e){
  const file = e.target.files[0];

  if(file && file.type !== 'application/pdf'){
    alert('نقبل فقط PDF');
    e.target.value = '';
    return;
  }

  if(file){
    const url = URL.createObjectURL(file);
    document.getElementById('pdfPreview').innerHTML =
      `<iframe src="${url}" width="100%" height="200"></iframe>`;
  }
});
</script>

@endsection