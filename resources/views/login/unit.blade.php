@extends('layouts.app')

@section('content')

<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>

<style>
  :root{
    --mamsa:#2F6F63;
    --mamsa2:#1f4f46;
    --bg:#f6f8f7;
    --text:#111827;
    --muted:#6b7280;
    --border:#e5e7eb;
    --shadow:0 18px 55px rgba(0,0,0,.08);
    --shadow2:0 10px 28px rgba(0,0,0,.07);
    --radius:22px;
  }

  .page-wrap{
    max-width: 1400px;
    margin: 28px auto 80px;
    padding: 0 16px;
  }

  .section{ margin-bottom: 18px; }

  .flex-between{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
  }

  .title{
    margin:0;
    font-size: 26px;
    font-weight: 800;
    color: var(--mamsa);
    letter-spacing: .2px;
  }

  .badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding: 10px 14px;
    border-radius: 999px;
    border:1px solid var(--border);
    background:#fff;
    color: var(--muted);
    font-weight: 700;
    font-size: 12px;
    box-shadow: 0 6px 18px rgba(0,0,0,.04);
  }
  .dot{
    width:10px;height:10px;border-radius:50%;
    background:#f59e0b;
    box-shadow: 0 0 0 4px rgba(245,158,11,.15);
  }
  .dot.ok{
    background:#22c55e;
    box-shadow: 0 0 0 4px rgba(34,197,94,.14);
  }

  .split{
    display:grid;
    grid-template-columns: 2fr 1fr;
    gap: 18px;
  }
  @media (max-width: 980px){
    .split{ grid-template-columns: 1fr; }
  }

  .card{
    background: rgba(255,255,255,.9);
    border:1px solid rgba(229,231,235,.95);
    border-radius: var(--radius);
    box-shadow: var(--shadow2);
    padding: 24px;
    overflow:hidden;
  }

  .card-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom: 12px;
  }

  .card-title{
    margin:0;
    font-size: 16px;
    font-weight: 800;
    color: var(--text);
  }

  .hint{
    font-size: 12px;
    color: var(--muted);
    margin: 0;
  }

  .grid{
    display:grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 12px;
  }
  @media (max-width: 560px){
    .grid{ grid-template-columns: 1fr; }
  }

  .form-group{
    margin-bottom: 12px;
  }
  .label{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    margin: 0 0 7px;
    font-weight: 800;
    font-size: 13px;
    color: var(--text);
  }
  .label small{
    font-weight: 700;
    color: var(--muted);
    font-size: 11px;
  }

  .input{
    width:100%;
    border:1px solid var(--border);
    background:#fff;
    border-radius: 14px;
    padding: 12px 12px;
    font-size: 14px;
    outline:none;
    transition: .15s ease;
  }
  .input:focus{
    border-color: var(--mamsa);
    box-shadow: 0 0 0 4px rgba(47,111,99,.15);
  }

  .row{
    display:flex; gap:10px; align-items:center;
  }

  .stepper{
    display:flex;
    align-items:center;
    gap:8px;
    border:1px solid var(--border);
    border-radius: 14px;
    padding: 8px 10px;
    background:#fff;
  }
  .stepper button{
    width:34px;height:34px;
    border-radius: 12px;
    border:1px solid var(--border);
    background:#fff;
    cursor:pointer;
    font-size: 18px;
    font-weight: 900;
    color: var(--mamsa);
    transition:.12s ease;
  }
  .stepper button:hover{ background: rgba(47,111,99,.06); border-color: rgba(47,111,99,.4); }
  .stepper input{
    width: 64px;
    text-align:center;
    border:0;
    outline:none;
    font-weight: 900;
    font-size: 14px;
    color: var(--text);
    background:transparent;
  }

  .btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    border:0;
    cursor:pointer;
    border-radius: 16px;
    padding: 14px 16px;
    font-weight: 900;
    color:#fff;
    background: linear-gradient(135deg, var(--mamsa), var(--mamsa2));
    box-shadow: 0 14px 34px rgba(47,111,99,.20);
    transition: .15s ease;
  }
  .btn:hover{ filter: brightness(.98); transform: translateY(-1px); }
  .btn:active{ transform: translateY(0); }
  .btn-block{ width:100%; }

  .btn-ghost{
    background:#fff;
    color: var(--mamsa);
    border:1px solid rgba(47,111,99,.35);
    box-shadow: none;
  }

  .mini{
    font-size: 12px;
    font-weight: 800;
    color: var(--muted);
  }

  /* Dropzone */
  .dropzone{
    border:2px dashed #d1d5db;
    border-radius: 18px;
    padding: 14px;
    text-align:center;
    cursor:pointer;
    background: linear-gradient(180deg, rgba(47,111,99,.06), rgba(255,255,255,0));
    transition:.15s ease;
    position:relative;
    user-select:none;
  }
  .dropzone:hover{
    border-color: rgba(47,111,99,.55);
  }
  .dropzone.dragover{
    border-color: var(--mamsa);
    background: rgba(47,111,99,.08);
  }
  .dropzone .dz-title{
    font-weight: 900;
    color: var(--text);
    font-size: 13px;
  }
  .dropzone .dz-sub{
    margin-top: 4px;
    font-size: 12px;
    color: var(--muted);
  }

  .thumbs{
    display:grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap:10px;
    margin-top: 12px;
  }
  @media (max-width: 560px){
    .thumbs{ grid-template-columns: repeat(2, minmax(0,1fr)); }
  }

  .thumb{
    position:relative;
    border:1px solid var(--border);
    border-radius: 16px;
    overflow:hidden;
    background:#f9fafb;
  }
  .thumb img{
    width:100%;
    height: 96px;
    object-fit: cover;
    display:block;
  }
  .thumb .actions{
    position:absolute;
    top:8px; left:8px; right:8px;
    display:flex;
    justify-content:space-between;
    gap:8px;
  }
  .iconbtn{
    width:30px; height:30px;
    border-radius: 999px;
    border:0;
    cursor:pointer;
    background: rgba(0,0,0,.55);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight: 900;
    transition:.12s ease;
  }
  .iconbtn:hover{ background: rgba(0,0,0,.7); }
  .badge-main{
    position:absolute;
    bottom:8px;
    right:8px;
    background: rgba(47,111,99,.92);
    color:#fff;
    font-size: 11px;
    font-weight: 900;
    padding: 6px 10px;
    border-radius: 999px;
  }
  .main-ring{
    outline: 3px solid rgba(47,111,99,.65);
    outline-offset: -3px;
  }

  /* Map */
  .map-box{
    height: 330px;
    border-radius: 18px;
    overflow:hidden;
    border:1px solid var(--border);
  }
  .coords{
    display:flex;
    justify-content:space-between;
    gap:12px;
    margin-top: 10px;
    font-size: 12px;
    color: var(--muted);
  }
  .coords span b{ color: var(--text); }

  /* Preview */
  .preview-hero{
    height: 220px;
    border-radius: 18px;
    overflow:hidden;
    border:1px solid var(--border);
    background: #f3f4f6;
    display:flex;
    align-items:center;
    justify-content:center;
    color: var(--muted);
    font-weight: 800;
  }
  .preview-hero img{
    width:100%;
    height:100%;
    object-fit: cover;
    display:block;
  }

  .preview-body{
    margin-top: 12px;
    display:flex;
    flex-direction:column;
    gap:10px;
  }

  .preview-title{
    margin:0;
    font-size: 18px;
    font-weight: 1000;
    color: var(--text);
  }

  .pillrow{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
  }
  .pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding: 8px 12px;
    border-radius: 999px;
    border:1px solid var(--border);
    background:#fff;
    color:#374151;
    font-size: 12px;
    font-weight: 800;
  }

  .pricebox{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
  }
  .price{
    font-size: 18px;
    font-weight: 1000;
    color: var(--mamsa);
    margin:0;
  }
  .muted{ color: var(--muted); font-size: 12px; margin:0; }

  .preview-desc{
    margin:0;
    color:#374151;
    font-size: 13px;
    line-height: 1.9;
    background: rgba(17,24,39,.03);
    border:1px solid rgba(229,231,235,.85);
    border-radius: 16px;
    padding: 12px 12px;
  }

  .mini-grid{
    display:grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap:8px;
  }
  .mini-grid img{
    width:100%;
    height: 64px;
    object-fit: cover;
    border-radius: 14px;
    border:1px solid var(--border);
    background:#f9fafb;
  }

  .soft-alert{
    border:1px solid rgba(245,158,11,.35);
    background: rgba(245,158,11,.08);
    color: #92400e;
    border-radius: 16px;
    padding: 10px 12px;
    font-size: 12px;
    font-weight: 800;
  }
</style>

<div class="page-wrap">

  <div class="flex-between section">
      <h2 class="title">إضافة عقار</h2>
      
  </div>

  <div class="split">
{{-- ================= PERMIT SECTION ================= --}}
<div class="card section">

  <div class="card-head">
    <h3 class="card-title">بيانات التصريح</h3>
    <p class="hint">يرجى إدخال بيانات التصريح قبل إضافة العقار</p>
  </div>

  {{-- فرد --}}
  @if(auth()->user()->AdminDetails?->type === 'individual')

    <div class="grid">

      <div class="form-group">
        <label class="label">رقم تصريح السياحة</label>
        <input type="text" name="tourism_permit_no" class="input" required>
      </div>

      <div class="form-group">
        <label class="label">
          ملف التصريح (PDF فقط)
          <small>نقبل فقط PDF</small>
        </label>
        <input type="file"
               name="tourism_permit_file"
               accept="application/pdf"
               class="input"
               required>
      </div>

    </div>

  @endif

  {{-- شركة --}}
  @if(auth()->user()->AdminDetails?->type === 'company')

    <div class="grid">

      <div class="form-group">
        <label class="label">رقم الترخيص</label>
        <input type="text" name="company_license_no" class="input" required>
      </div>

      <div class="form-group">
        <label class="label">
          ملف التصريح (PDF فقط)
          <small>نقبل فقط PDF</small>
        </label>
        <input type="file"
               name="tourism_permit_file"
               accept="application/pdf"
               class="input"
               required>
      </div>

    </div>

  @endif

</div>
      {{-- ================= LEFT: FORM ================= --}}
      <div class="card">

          <div class="card-head">
            <h3 class="card-title">تفاصيل العقار</h3>
            <p class="hint">املأ البيانات وسيظهر كل شيء في المعاينة تلقائيًا.</p>
          </div>

          <form id="unitForm" method="POST"
                action="{{ route('Admin.units.store') }}"
                enctype="multipart/form-data">

              @csrf

              <input type="hidden" name="lat" id="lat">
              <input type="hidden" name="lng" id="lng">
              <input type="hidden" name="main_index" id="main_index" value="0">

              <div class="grid">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="label">اسم العقار <small>مثال: شاليه الموج</small></label>
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

                @if(auth()->user()->Admin->type === 'individual')

                  <div class="form-group">
                      <label>رقم الهوية</label>
                      <input name="national_id" required class="input">
                  </div>

                  <div class="form-group">
                      <label>رقم ترخيص السياحة</label>
                      <input name="tourism_permit_no" required class="input">
                  </div>

                  @endif

                <div class="form-group" id="bedroomsWrap" style="display:none;">
                    <label class="label">عدد غرف النوم</label>

                    <div class="stepper">
                      <button type="button" id="bedMinus">−</button>
                      <input type="text" name="bedrooms" id="bedrooms" value="1" inputmode="numeric">
                      <button type="button" id="bedPlus">+</button>
                    </div>

                    
                </div>

                <div class="form-group">
                    <label class="label">السعر (ريال)</label>
                    <input type="number" name="price" id="price" class="input" min="0" step="1" required>
                </div>

                <div class="form-group">
                    <label class="label">السعة (كم شخص)</label>
                    <input type="number" name="capacity" id="capacity" class="input" min="1" step="1" required>
                </div>

                <div class="form-group">
                 <label class="label">المدينة</label>
                 <select name="city" id="city" class="input" required>
                  <option value="">اختر المدينة</option>
                  <option value="riyadh" selected>الرياض</option>
                 </select>
                </div>

<div class="form-group">
  <label class="label">الحي</label>
  <select name="district" id="district" class="input" required>
      <option value="">اختر الحي</option>
  </select>
  <div class="mini" style="margin-top:6px;">
      أو اضغط على الخريطة وسيتم تعبئة الحي تلقائيًا.
  </div>
</div>


                <div class="form-group" style="grid-column:1/-1;">
                    <label class="label">الوصف</label>
                    <textarea name="description" id="description" rows="4" class="input"
                              placeholder="اكتب وصف جذاب: مميزات، إطلالة، قرب خدمات..."></textarea>
                </div>
              </div>

              {{-- الصور --}}
              <div class="form-group" style="margin-top:8px;">
                  <div class="flex-between" style="margin-bottom:8px;">
                    <label class="label" style="margin:0;">صور العقار</label>
                    <span class="mini" id="imgCount">0 صورة</span>
                  </div>

                  <div id="dropzone" class="dropzone">
                      <div class="dz-title">اسحب الصور هنا أو اضغط للرفع</div>
                      <div class="dz-sub">رفع متعدد — حدد صورة رئيسية — احذف قبل الحفظ</div>
                      <input id="images" type="file" name="images[]" multiple accept="image/*" hidden>
                  </div>

                  <div class="thumbs" id="thumbs"></div>

                  <div class="soft-alert" style="margin-top:10px;">
                    ملاحظة: سيتم إرسال الصور عند الحفظ. يمكنك تغيير الصورة الرئيسية من ★.
                  </div>
              </div>

              <div style="margin-top:14px;">
                <button type="submit" class="btn btn-block">
                    حفظ العقار
                </button>
              </div>

          </form>

      </div>

      {{-- ================= RIGHT: MAP + PREVIEW ================= --}}
      <div>

          <div class="card section">
              <div class="card-head">
                <h4 class="card-title">الموقع على الخريطة</h4>
                <p class="hint">اضغط على الخريطة لاختيار موقع العقار.</p>
              </div>

              <div id="map" class="map-box"></div>

              <div class="coords">
                <span><b>Lat:</b> <span id="latText">—</span></span>
                <span style="direction:ltr;"><b>Lng:</b> <span id="lngText">—</span></span>
              </div>
          </div>

          <div class="card">
              <div class="card-head">
                <h4 class="card-title">المعاينة</h4>
                <p class="hint">هذه بطاقة عرض كما ستظهر للمستخدم.</p>
              </div>

              <div class="preview-hero" id="previewHero">
                الصورة الرئيسية ستظهر هنا
              </div>

              <div class="preview-body">
                <div class="pricebox">
                  <h3 class="preview-title" id="pvName">اسم العقار</h3>
                  <div style="text-align:right;">
                    <p class="price" id="pvPrice">0 ريال</p>
                    <p class="muted">سعر/ليلة</p>
                  </div>
                </div>

                <div class="pillrow">
                  <span class="pill" id="pvType">—</span>
                  <span class="pill" id="pvBedrooms" style="display:none;">—</span>
                  <span class="pill" id="pvCapacity">السعة: —</span>
                </div>

                <p class="muted">
                  <b style="color:var(--text);">الموقع:</b>
                  <span id="pvLocation">الرياض — (اختار الحي)</span>
                </p>

                <p class="preview-desc" id="pvDesc">الوصف سيظهر هنا...</p>

                <div>
                  <p class="muted" style="margin-bottom:8px;"><b style="color:var(--text);">معاينة الصور:</b></p>
                  <div class="mini-grid" id="pvGrid"></div>
                </div>
              </div>

          </div>

      </div>

  </div>

</div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

<script>
/* ======================
   Draft (UI only)
====================== */
function setDraft(saved){
  const dot = document.getElementById('draftDot');
  const text = document.getElementById('draftText');
  if(saved){
    dot.classList.add('ok');
    text.textContent = 'محفوظ ✅';
  }else{
    dot.classList.remove('ok');
    text.textContent = 'غير محفوظ';
  }
}

/* ======================
   Bedrooms toggle + stepper
====================== */
const unitTypeEl = document.getElementById('unit_type');
const bedroomsWrap = document.getElementById('bedroomsWrap');
const bedroomsEl = document.getElementById('bedrooms');
const bedMinus = document.getElementById('bedMinus');
const bedPlus = document.getElementById('bedPlus');

function toggleBedrooms(){
  const v = unitTypeEl.value;
  const show = (v === 'apartment' || v === 'villa');
  bedroomsWrap.style.display = show ? 'block' : 'none';
  if(!show) bedroomsEl.value = '';
  updatePreview();
  setDraft(false);
}

bedMinus.addEventListener('click', () => {
  const cur = parseInt(bedroomsEl.value || '1', 10);
  const next = Math.max(1, cur - 1);
  bedroomsEl.value = String(next);
  updatePreview();
  setDraft(false);
});
bedPlus.addEventListener('click', () => {
  const cur = parseInt(bedroomsEl.value || '1', 10);
  const next = Math.min(20, cur + 1);
  bedroomsEl.value = String(next);
  updatePreview();
  setDraft(false);
});
bedroomsEl.addEventListener('input', () => {
  bedroomsEl.value = String((bedroomsEl.value || '').replace(/\D/g,'')).slice(0,2);
  if(bedroomsEl.value === '') bedroomsEl.value = '1';
  updatePreview();
  setDraft(false);
});

/* ======================
   Inputs → Preview
====================== */
const nameEl = document.getElementById('unit_name');
const priceEl = document.getElementById('price');
const capacityEl = document.getElementById('capacity');
const cityEl = document.getElementById('city');
const districtEl = document.getElementById('district');
const descEl = document.getElementById('description');

/* ======================
   Cities & Districts
====================== */

const cityDistricts = {
  riyadh: [
    "الملقا","الياسمين","النرجس","العقيق","الصحافة",
    "العارض","حطين","الندى","الرحمانية","المروج",
    "العليا","قرطبة","الروابي","اليرموك","الشفا",
    "بدر","السويدي","ظهرة لبن","العزيزية","الجزيرة",
    "الربوة","السلي","النسيم","الندوة","الواحة",
    "المرسلات","الغدير","الازدهار","الفلاح","المصيف",
    "المحمدية","الورود","الطويق","الدرعية","عرقة",
    "المونسية","اشبيلية","القيروان","المهدية","الحزم",
    "الشميسي","ام الحمام","الملز","الروضة","السليمانية",
    "الخالدية","الناصرية","الريان","الزهراء","العريجاء",
    "النخيل","الرائد","المعذر","الملك فهد","الملك فيصل",
    "المنار","غرناطة","المنصورة"
  ]
};

function loadDistricts(cityKey){
  districtEl.innerHTML = '<option value="">اختر الحي</option>';

  if(!cityDistricts[cityKey]) return;

  cityDistricts[cityKey].forEach(d => {
    const opt = document.createElement('option');
    opt.value = d;
    opt.textContent = d;
    districtEl.appendChild(opt);
  });
}

cityEl.addEventListener('change', function(){
  loadDistricts(this.value);
  updatePreview();
  setDraft(false);
});

// تحميل أحياء الرياض عند فتح الصفحة
loadDistricts('riyadh');

const pvName = document.getElementById('pvName');
const pvPrice = document.getElementById('pvPrice');
const pvType = document.getElementById('pvType');
const pvBedrooms = document.getElementById('pvBedrooms');
const pvCapacity = document.getElementById('pvCapacity');
const pvLocation = document.getElementById('pvLocation');
const pvDesc = document.getElementById('pvDesc');
const pvHero = document.getElementById('previewHero');
const pvGrid = document.getElementById('pvGrid');

function formatSAR(n){
  const num = Number(n || 0);
  try{ return new Intl.NumberFormat('ar-SA').format(num) + ' ريال'; }
  catch(e){ return num + ' ريال'; }
}

function updatePreview(){
  pvName.textContent = (nameEl.value || '').trim() || 'اسم العقار';
  pvPrice.textContent = formatSAR(priceEl.value);

  const mapType = { apartment:'شقة', villa:'فيلا', studio:'استوديو' };
  pvType.textContent = mapType[unitTypeEl.value] || '—';

  const showRooms = (unitTypeEl.value === 'apartment' || unitTypeEl.value === 'villa');
  if(showRooms){
    pvBedrooms.style.display = 'inline-flex';
    pvBedrooms.textContent = 'غرف: ' + (bedroomsEl.value || '—');
  }else{
    pvBedrooms.style.display = 'none';
    pvBedrooms.textContent = '';
  }

  pvCapacity.textContent = 'السعة: ' + (capacityEl.value ? capacityEl.value : '—');

  const city = cityEl.value || 'الرياض';
  const dist = districtEl.value || '(اختاري الحي)';
  pvLocation.textContent = city + ' — ' + dist;

  const d = (descEl.value || '').trim();
  pvDesc.textContent = d ? d : 'الوصف سيظهر هنا...';
}

[nameEl, priceEl, capacityEl, descEl].forEach(el => {
  el.addEventListener('input', () => { updatePreview(); setDraft(false); });
});
districtEl.addEventListener('change', () => { updatePreview(); setDraft(false); });
unitTypeEl.addEventListener('change', toggleBedrooms);

/* ======================
   Images: multi + main + delete
====================== */
const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('images');
const thumbs = document.getElementById('thumbs');
const imgCount = document.getElementById('imgCount');
const mainIndexInput = document.getElementById('main_index');

let filesState = []; // [{file, url}]
let mainIndex = 0;

function syncImgCount(){
  imgCount.textContent = filesState.length + (filesState.length === 1 ? ' صورة' : ' صور');
}

function renderHero(){
  if(filesState.length === 0){
    pvHero.innerHTML = 'الصورة الرئيسية ستظهر هنا';
    return;
  }
  const url = filesState[mainIndex]?.url || filesState[0].url;
  pvHero.innerHTML = `<img src="${url}" alt="main">`;
}

function renderGrid(){
  pvGrid.innerHTML = '';
  filesState.slice(0,8).forEach(x => {
    const img = document.createElement('img');
    img.src = x.url;
    pvGrid.appendChild(img);
  });
}

function rebuildFileInput(){
  const dt = new DataTransfer();
  filesState.forEach(x => dt.items.add(x.file));
  fileInput.files = dt.files;
}

function renderThumbs(){
  thumbs.innerHTML = '';
  filesState.forEach((x, idx) => {
    const wrap = document.createElement('div');
    wrap.className = 'thumb ' + (idx === mainIndex ? 'main-ring' : '');
    wrap.innerHTML = `
      <img src="${x.url}" alt="">
      <div class="actions">
        <button type="button" class="iconbtn" title="حذف" data-action="remove" data-idx="${idx}">×</button>
        <button type="button" class="iconbtn" title="اجعلها رئيسية" data-action="main" data-idx="${idx}">★</button>
      </div>
      ${idx === mainIndex ? `<div class="badge-main">رئيسية</div>` : ``}
    `;
    thumbs.appendChild(wrap);
  });

  mainIndexInput.value = String(mainIndex);
  syncImgCount();
  renderHero();
  renderGrid();
}

function addFiles(fileList){
  const arr = Array.from(fileList || []);
  for(const f of arr){
    if(!f.type.startsWith('image/')) continue;
    const url = URL.createObjectURL(f);
    filesState.push({file:f, url});
  }
  if(filesState.length && mainIndex >= filesState.length) mainIndex = 0;
  rebuildFileInput();
  renderThumbs();
  setDraft(false);
}

function removeAt(idx){
  const item = filesState[idx];
  if(item?.url) URL.revokeObjectURL(item.url);
  filesState.splice(idx,1);
  if(filesState.length === 0) mainIndex = 0;
  else if(mainIndex >= filesState.length) mainIndex = filesState.length - 1;
  rebuildFileInput();
  renderThumbs();
  setDraft(false);
}

function setMain(idx){
  mainIndex = idx;
  renderThumbs();
  setDraft(false);
}

dropzone.addEventListener('click', () => fileInput.click());
dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
dropzone.addEventListener('drop', (e) => {
  e.preventDefault();
  dropzone.classList.remove('dragover');
  addFiles(e.dataTransfer.files);
});
fileInput.addEventListener('change', (e) => addFiles(e.target.files));

thumbs.addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-action]');
  if(!btn) return;
  const action = btn.dataset.action;
  const idx = Number(btn.dataset.idx);
  if(action === 'remove') removeAt(idx);
  if(action === 'main') setMain(idx);
});

/* ======================
   Leaflet Map + reverse district fill
====================== */
const latEl = document.getElementById('lat');
const lngEl = document.getElementById('lng');
const latText = document.getElementById('latText');
const lngText = document.getElementById('lngText');

let map = L.map('map').setView([24.7136, 46.6753], 12);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 18,
  attribution: '© OpenStreetMap'
}).addTo(map);

let marker;

function ensureDistrictOptionAndSelect(name){
  if(!name) return;
  const normalized = String(name).trim();
  if(!normalized) return;

  const exists = Array.from(districtEl.options).some(o => o.value === normalized || o.text === normalized);
  if(!exists){
    const opt = document.createElement('option');
    opt.value = normalized;
    opt.textContent = normalized;
    districtEl.appendChild(opt);
  }
  districtEl.value = normalized;
  updatePreview();
  setDraft(false);
}

async function reverseFillDistrict(lat, lng){
  try{
    const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&accept-language=ar`;
    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
    if(!res.ok) return;

    const data = await res.json();
    const a = data?.address || {};

    const districtName =
      a.suburb ||
      a.neighbourhood ||
      a.quarter ||
      a.city_district ||
      a.district ||
      '';

    if(districtName){
      ensureDistrictOptionAndSelect(districtName);
    }
  }catch(e){
    // ignore
  }
}

function setMarker(lat, lng){
  if(marker) map.removeLayer(marker);
  marker = L.marker([lat, lng]).addTo(map);
  map.setView([lat, lng], 14);

  latEl.value = lat;
  lngEl.value = lng;

  latText.textContent = Number(lat).toFixed(6);
  lngText.textContent = Number(lng).toFixed(6);

  reverseFillDistrict(lat, lng);
  setDraft(false);
}

map.on('click', function(e){
  setMarker(e.latlng.lat, e.latlng.lng);
});

/* ======================
   Init
====================== */
toggleBedrooms();
updatePreview();
renderThumbs();

document.getElementById('unitForm').addEventListener('submit', () => {
  setDraft(true);
});
</script>

@endsection