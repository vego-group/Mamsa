@extends('layouts.admin', ['title' => 'إضافة عقار'])

@section('content')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous" />

<style>
  :root{ --mamsa:#2F6F63; --mamsa2:#1f4f46; --bg:#f6f8f7; --text:#111827; --muted:#6b7280; --border:#e5e7eb; --shadow2:0 10px 28px rgba(0,0,0,.07); }
  .wrap{max-width:1200px;margin:10px auto 40px;padding:0 14px;}
  .grid-page{display:grid;grid-template-columns:1.05fr .95fr;gap:18px}
  @media (max-width:1100px){.grid-page{grid-template-columns:1fr}}
  .card{background:#fff;border:1px solid var(--border);border-radius:22px;box-shadow:var(--shadow2);padding:16px}
  .h-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
  .title{margin:0;font-weight:900;font-size:26px;color:var(--mamsa)}
  .badge{display:inline-flex;gap:8px;align-items:center;border:1px solid var(--border);background:#fff;border-radius:999px;padding:8px 12px;color:var(--muted);font-weight:800;font-size:12px}
  .dot{width:10px;height:10px;border-radius:999px;background:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.15)}
  .dot.ok{background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.14)}
  .map{height:320px;border-radius:18px;overflow:hidden;border:1px solid var(--border)}
  .coords{display:flex;justify-content:space-between;gap:8px;margin-top:8px;color:var(--muted);font-size:12px}
  .preview-hero{height:230px;border-radius:18px;overflow:hidden;border:1px solid var(--border);background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:var(--muted);font-weight:900}
  .preview-hero img{width:100%;height:100%;object-fit:cover;display:block}
  .pv-card h3{margin:0;font-weight:1000;color:#111827;font-size:18px}
  .pv-price{margin:0;font-size:18px;font-weight:1000;color:var(--mamsa)}
  .pv-muted{color:var(--muted);font-size:12px;margin:0}
  .pv-pills{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
  .pill{display:inline-flex;gap:8px;align-items:center;border:1px solid var(--border);background:#fff;border-radius:999px;padding:6px 10px;font-weight:800;font-size:12px;color:#374151}
  .pv-desc{margin-top:10px;background:rgba(17,24,39,.03);border:1px solid rgba(229,231,235,.85);border-radius:16px;padding:12px;color:#374151;font-size:13px;line-height:1.8}
  .pv-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:8px}
  .pv-grid img{width:100%;height:64px;object-fit:cover;border-radius:12px;border:1px solid var(--border);background:#f9fafb}
  .row-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  @media (max-width:540px){.row-2{grid-template-columns:1fr}}
  .label{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;font-weight:900;color:#111827;font-size:13px}
  .sub{color:var(--muted);font-size:11px}
  .input,.select,textarea{width:100%;border:1px solid var(--border);border-radius:14px;padding:12px 12px;font-size:14px;outline:none;background:#fff;transition:.15s ease}
  .input:focus,.select:focus,textarea:focus{border-color:var(--mamsa);box-shadow:0 0 0 4px rgba(47,111,99,.15)}
  .stepper{display:flex;align-items:center;gap:6px}
  .stepper button{width:36px;height:36px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--mamsa);font-size:18px;font-weight:900;cursor:pointer}
  .stepper input{width:70px;text-align:center;border:1px solid var(--border);border-radius:12px;padding:10px 8px}
  .drop{border:2px dashed #d1d5db;border-radius:18px;padding:14px;text-align:center;background:linear-gradient(180deg, rgba(47,111,99,.06), rgba(255,255,255,0));cursor:pointer}
  .drop.dragover{border-color:rgba(47,111,99,.55);background:rgba(47,111,99,.08)}
  .dz-title{font-weight:900;color:#111827}
  .dz-sub{margin-top:5px;color:var(--muted);font-size:12px}
  .thumbs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:10px}
  .th{position:relative;border:1px solid var(--border);border-radius:16px;overflow:hidden;background:#f9fafb}
  .th img{width:100%;height:96px;object-fit:cover;display:block}
  .th-act{position:absolute;top:8px;left:8px;right:8px;display:flex;justify-content:space-between;gap:8px}
  .icon{width:30px;height:30px;border:0;border-radius:999px;background:rgba(0,0,0,.55);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;cursor:pointer}
  .main-badge{position:absolute;bottom:8px;right:8px;background:rgba(47,111,99,.92);color:#fff;border-radius:999px;padding:6px 10px;font-size:11px;font-weight:900}
  .main-ring{outline:3px solid rgba(47,111,99,.65);outline-offset:-3px}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:16px;padding:14px 16px;font-weight:900;color:#fff;background:linear-gradient(135deg,var(--mamsa),var(--mamsa2));box-shadow:0 14px 34px rgba(47,111,99,.20);width:100%}
</style>

<div class="wrap">
  <div class="h-row" style="margin-bottom:14px">
    <h2 class="title">إضافة عقار</h2>
    <span class="badge"><span id="dot" class="dot"></span><span id="draftTxt">Draft</span></span>
  </div>

  <div class="grid-page">
    {{-- يسار: خريطة + معاينة --}}
    <div>
      <div class="card">
        <div class="h-row" style="margin-bottom:8px"><h3 class="m-0 font-extrabold">الموقع على الخريطة</h3></div>
        <div id="map" class="map"></div>
        <div class="coords"><div>Lng: <b id="lngText">—</b></div><div>Lat: <b id="latText">—</b></div></div>
      </div>

      <div class="card pv-card" style="margin-top:14px">
        <div id="pvHero" class="preview-hero">الصورة الرئيسية ستظهر هنا</div>
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-top:12px">
          <h3 id="pvName">اسم العقار</h3>
          <div style="text-align:right"><p id="pvPrice" class="pv-price">0 ريال</p><p class="pv-muted">سعر/ليلة</p></div>
        </div>
        <div class="pv-pills">
          <span id="pvType" class="pill">—</span>
          <span id="pvBedrooms" class="pill" style="display:none">—</span>
          <span id="pvCapacity" class="pill">السعة: —</span>
        </div>
        <p class="pv-muted" style="margin-top:6px"><b style="color:#111827">الموقع:</b> <span id="pvLocation">الرياض — (اختاري الحي)</span></p>
        <p id="pvDesc" class="pv-desc">الوصف سيظهر هنا...</p>
        <div style="margin-top:10px">
          <p class="pv-muted" style="margin-bottom:6px"><b style="color:#111827">معاينة الصور:</b></p>
          <div id="pvGrid" class="pv-grid"></div>
        </div>
      </div>
    </div>

    {{-- يمين: النموذج --}}
    <div>
      <div class="card">
        @if($errors->any())
          <div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-xl p-3">
            <strong>الرجاء تصحيح الأخطاء التالية:</strong>
            <ul class="mt-2 list-disc pr-5">@foreach($errors->all() as $e) <li>{{ $e }}</li> @endforeach</ul>
          </div>
        @endif

        <form id="unitForm" action="{{ route('admin.units.store') }}" method="POST" enctype="multipart/form-data">
          @csrf

          <label class="label">اسم العقار</label>
          <input class="input" type="text" name="name" id="name" required>

          <div class="row-2">
            <div>
              <label class="label">نوع العقار</label>
              <select class="select" name="type" id="type">
                <option value="apartment">شقة</option><option value="villa">فيلا</option><option value="studio">استوديو</option>
              </select>
            </div>
            <div>
              <label class="label">عدد غرف النوم</label>
              <div class="stepper">
                <button type="button" id="bedMinus">−</button>
                <input type="text" class="input" name="bedrooms" id="bedrooms" value="2">
                <button type="button" id="bedPlus">+</button>
              </div>
            </div>
          </div>

          <div class="row-2">
            <div>
              <label class="label">السعة (كم شخص)</label>
              <div class="stepper">
                <button type="button" id="capMinus">−</button>
                <input type="text" class="input" name="capacity" id="capacity" value="3">
                <button type="button" id="capPlus">+</button>
              </div>
            </div>
            <div>
              <label class="label">السعر</label>
              <input class="input" type="number" name="price" id="price" step="0.01" min="0" placeholder="0">
            </div>
          </div>

          <div class="row-2">
            <div>
              <label class="label">المدينة</label>
              <input class="input" type="text" name="city" id="city" value="الرياض">
            </div>
            <div>
              <label class="label">الحي</label>
              <input class="input" type="text" name="district" id="district" placeholder="الملك فهد">
            </div>
          </div>

          {{-- إحداثيات محفوظة --}}
          <input type="hidden" name="lat" id="lat"><input type="hidden" name="lng" id="lng">

          {{-- الحالة (مطلوبة) --}}
          <label class="label">الحالة *</label>
          <select class="select" name="status" id="status" required>
            <option value="">—</option>
            <option value="available">متاحة</option>
            <option value="unavailable">غير متاحة</option>
            <option value="reserved">محجوزة</option>
          </select>

          <label class="label">وصف العقار</label>
          <textarea class="input" rows="4" name="description" id="description" placeholder="جميل..."></textarea>

          <label class="label">الكود</label>
          <input class="input bg-gray-50" type="text" value="{{ $generatedCode }}" readonly>
          <input type="hidden" name="code" value="{{ $generatedCode }}">

          <label class="label">رابط تقويم خارجي <span class="sub">(iCal/Google/Outlook)</span></label>
          <input class="input" type="url" name="calendar_external_url" id="calendar_external_url" placeholder="https://calendar.google.com/calendar/ical/...">

          <label class="label">صور العقار</label>
          <div id="drop" class="drop">
            <div class="dz-title">اسحب الصور هنا أو اضغط للرفع</div>
            <div class="dz-sub">رفع متعدد • حددي صورة رئيسية • احذفي قبل الحفظ</div>
            <input type="file" id="images" name="images[]" multiple accept="image/*" hidden>
          </div>
          <div id="thumbs" class="thumbs"></div>

          <div style="margin-top:12px"><button class="btn" type="submit">حفظ العقار</button></div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
<script>
  // Draft UI
  const dot=document.getElementById('dot'), draftTxt=document.getElementById('draftTxt');
  const setDraft=(s)=>{ if(s){dot.classList.add('ok'); draftTxt.textContent='Saved';}else{dot.classList.remove('ok'); draftTxt.textContent='Draft';} };

  // Elements
  const el = {
    name: id('name'), price: id('price'), desc: id('description'), type: id('type'),
    bedrooms: id('bedrooms'), capacity: id('capacity'), city: id('city'), district: id('district'),
    cal: id('calendar_external_url'), pvName: id('pvName'), pvPrice: id('pvPrice'), pvDesc: id('pvDesc'),
    pvType: id('pvType'), pvBedrooms: id('pvBedrooms'), pvCapacity: id('pvCapacity'), pvLocation: id('pvLocation'),
    pvHero: id('pvHero'), pvGrid: id('pvGrid'), lat: id('lat'), lng: id('lng'), latText: id('latText'), lngText: id('lngText')
  };
  function id(x){ return document.getElementById(x); }
  function formatSAR(n){ const num=Number(n||0); try{return new Intl.NumberFormat('ar-SA').format(num)+' ريال';}catch{return (num||0)+' ريال';} }
  function updatePreview(){
    if(!el.pvName) return;
    el.pvName.textContent=(el.name.value||'').trim()||'اسم العقار';
    el.pvPrice.textContent=formatSAR(el.price.value);
    el.pvDesc.textContent=(el.desc.value||'').trim()||'الوصف سيظهر هنا...';
    const mapType={apartment:'شقة',villa:'فيلا',studio:'استوديو'};
    el.pvType.textContent=mapType[el.type.value]||'—';
    const show=(el.type.value==='apartment'||el.type.value==='villa');
    el.pvBedrooms.style.display=show?'inline-flex':'none';
    el.pvBedrooms.textContent=show?('غرف: '+(el.bedrooms.value||'—')):'';
    el.pvCapacity.textContent='السعة: '+(el.capacity.value||'—');
    const city=el.city.value||'الرياض', dist=el.district.value||'(اختاري الحي)';
    el.pvLocation.textContent=city+' — '+dist;
  }
  ['name','price','description','type','bedrooms','capacity','city','district','calendar_external_url']
    .forEach(k => id(k).addEventListener('input', ()=>{updatePreview(); setDraft(false);} ));

  id('bedMinus').onclick=()=>{ const v=Math.max(0,parseInt(el.bedrooms.value||'0')-1); el.bedrooms.value=v; updatePreview(); setDraft(false); };
  id('bedPlus').onclick =()=>{ const v=Math.min(20,parseInt(el.bedrooms.value||'0')+1); el.bedrooms.value=v; updatePreview(); setDraft(false); };
  id('capMinus').onclick=()=>{ const v=Math.max(1,parseInt(el.capacity.value||'1')-1); el.capacity.value=v; updatePreview(); setDraft(false); };
  id('capPlus').onclick =()=>{ const v=Math.min(50,parseInt(el.capacity.value||'1')+1); el.capacity.value=v; updatePreview(); setDraft(false); };

  // Leaflet map
  const map = L.map('map').setView([24.713642,46.675297],12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:18,attribution:'© OpenStreetMap'}).addTo(map);
  let marker;
  function setMarker(lat,lng){
    if(marker) map.removeLayer(marker);
    marker=L.marker([lat,lng]).addTo(map);
    map.setView([lat,lng],14);
    el.lat.value=lat; el.lng.value=lng;
    el.latText.textContent=Number(lat).toFixed(6);
    el.lngText.textContent=Number(lng).toFixed(6);
    setDraft(false);
  }
  map.on('click',e=> setMarker(e.latlng.lat,e.latlng.lng));

  // Images
  const drop=id('drop'), fileInput=id('images'), thumbs=id('thumbs'); let state=[], mainIndex=0;
  function rebuildInput(){ const dt=new DataTransfer(); state.forEach(s=>dt.items.add(s.file)); fileInput.files=dt.files; }
  function renderHero(){ el.pvHero.innerHTML = state.length ? `<img src="${state[mainIndex].url}" alt="">` : 'الصورة الرئيسية ستظهر هنا'; }
  function renderGrid(){ el.pvGrid.innerHTML=''; state.slice(0,8).forEach(s=>{ const img=new Image(); img.src=s.url; el.pvGrid.appendChild(img); }); }
  function renderThumbs(){
    thumbs.innerHTML=''; state.forEach((s,idx)=>{ const div=document.createElement('div');
      div.className='th '+(idx===mainIndex?'main-ring':'');
      div.innerHTML=`<img src="${s.url}" alt=""><div class="th-act">
        <button type="button" class="icon" data-act="rm" data-idx="${idx}">×</button>
        <button type="button" class="icon" data-act="main" data-idx="${idx}">★</button>
      </div>${idx===mainIndex?`<div class="main-badge">رئيسية</div>`:''}`;
      thumbs.appendChild(div);
    }); renderHero(); renderGrid();
  }
  function addFiles(list){ Array.from(list||[]).forEach(f=>{ if(!f.type.startsWith('image/')) return; state.push({file:f,url:URL.createObjectURL(f)}); });
    if(state.length && mainIndex>=state.length) mainIndex=0; rebuildInput(); renderThumbs(); setDraft(false);
  }
  function removeAt(i){ const it=state[i]; if(it?.url) URL.revokeObjectURL(it.url); state.splice(i,1);
    if(!state.length) mainIndex=0; else if(mainIndex>=state.length) mainIndex=state.length-1; rebuildInput(); renderThumbs(); setDraft(false);
  }
  drop.addEventListener('click',()=> fileInput.click());
  drop.addEventListener('dragover',e=>{ e.preventDefault(); drop.classList.add('dragover'); });
  drop.addEventListener('dragleave',()=> drop.classList.remove('dragover'));
  drop.addEventListener('drop',e=>{ e.preventDefault(); drop.classList.remove('dragover'); addFiles(e.dataTransfer.files); });
  fileInput.addEventListener('change',e=> addFiles(e.target.files));
  thumbs.addEventListener('click',e=>{ const btn=e.target.closest('button[data-act]'); if(!btn) return;
    const act=btn.dataset.act, i=+btn.dataset.idx; if(act==='rm') removeAt(i); if(act==='main'){ mainIndex=i; renderThumbs(); setDraft(false); }
  });

  // Init
  updatePreview(); renderThumbs(); document.getElementById('unitForm').addEventListener('submit',()=> setDraft(true));
</script>
@endsection