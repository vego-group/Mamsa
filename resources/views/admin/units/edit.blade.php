@extends('layouts.admin', ['title' => 'تعديل عقار'])

@section('content')
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-semibold text-[#2f4b46]">تعديل: {{ $unit->name }}</h1>
  <a href="{{ route('admin.units.index') }}" class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200">رجوع</a>
</div>

@if($errors->any())
  <div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-xl p-3">
    <strong>الرجاء تصحيح الأخطاء التالية:</strong>
    <ul class="mt-2 list-disc pr-5">@foreach($errors->all() as $err) <li>{{ $err }}</li> @endforeach</ul>
  </div>
@endif

<form action="{{ route('admin.units.update', $unit->id) }}" method="POST" enctype="multipart/form-data"
      class="bg-white rounded-2xl border border-gray-200 p-4 md:p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
  @csrf
  @method('PUT')

  <div>
    <label class="block mb-1 text-sm text-gray-700">اسم العقار *</label>
    <input type="text" name="name" value="{{ old('name', $unit->name) }}"
           class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]" required>
  </div>

  <div>
    <label class="block mb-1 text-sm text-gray-700">الكود</label>
    <input type="text" value="{{ $unit->code }}" readonly
           class="w-full rounded-lg border-gray-300 bg-gray-50 text-gray-700">
  </div>

  <div>
    <label class="block mb-1 text-sm text-gray-700">نوع العقار</label>
    <select name="type" class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
      <option value="">—</option>
      <option value="apartment" @selected(old('type',$unit->type)==='apartment')>شقة</option>
      <option value="villa"     @selected(old('type',$unit->type)==='villa')>فيلا</option>
      <option value="studio"    @selected(old('type',$unit->type)==='studio')>استوديو</option>
    </select>
  </div>

  <div>
    <label class="block mb-1 text-sm text-gray-700">عدد غرف النوم</label>
    <input type="number" name="bedrooms" value="{{ old('bedrooms', $unit->bedrooms) }}"
           class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
  </div>

  <div>
    <label class="block mb-1 text-sm text-gray-700">السعة (كم شخص)</label>
    <input type="number" name="capacity" value="{{ old('capacity', $unit->capacity) }}"
           class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
  </div>

  <div>
    <label class="block mb-1 text-sm text-gray-700">السعر</label>
    <input type="number" step="0.01" name="price" value="{{ old('price', $unit->price) }}"
           class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
  </div>

  <div>
    <label class="block mb-1 text-sm text-gray-700">المدينة</label>
    <input type="text" name="city" value="{{ old('city', $unit->city) }}"
           class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
  </div>

  <div>
    <label class="block mb-1 text-sm text-gray-700">الحي</label>
    <input type="text" name="district" value="{{ old('district', $unit->district) }}"
           class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
  </div>

  <div>
    <label class="block mb-1 text-sm text-gray-700">Lat</label>
    <input type="number" step="0.0000001" name="lat" value="{{ old('lat', $unit->lat) }}"
           class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
  </div>

  <div>
    <label class="block mb-1 text-sm text-gray-700">Lng</label>
    <input type="number" step="0.0000001" name="lng" value="{{ old('lng', $unit->lng) }}"
           class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
  </div>

  <div>
    <label class="block mb-1 text-sm text-gray-700">الحالة *</label>
    <select name="status" class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]" required>
      <option value="available"   @selected(old('status', $unit->status)==='available')>متاحة</option>
      <option value="unavailable" @selected(old('status', $unit->status)==='unavailable')>غير متاحة</option>
      <option value="reserved"    @selected(old('status', $unit->status)==='reserved')>محجوزة</option>
    </select>
  </div>

  <div class="md:col-span-2">
    <label class="block mb-1 text-sm text-gray-700">الوصف</label>
    <textarea name="description" rows="4" class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">{{ old('description', $unit->description) }}</textarea>
  </div>

  <div class="md:col-span-2">
    <label class="block mb-1 text-sm text-gray-700">رابط تقويم خارجي (اختياري)</label>
    <input type="url" name="calendar_external_url" value="{{ old('calendar_external_url', $unit->calendar_external_url) }}"
           placeholder="https://calendar.google.com/calendar/ical/..."
           class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
  </div>

  @php
    $pk = \Illuminate\Support\Facades\Schema::hasColumn('unit_images','image_id') ? 'image_id' : 'id';
  @endphp

  <div class="md:col-span-2">
    <label class="block mb-1 text-sm text-gray-700">الصور الحالية</label>
    @if($unit->images->count())
      <div class="flex flex-wrap gap-3">
        @foreach($unit->images as $img)
          <label class="text-center">
            <img src="{{ asset('storage/'.$img->image_url) }}" alt="" class="w-[120px] h-[90px] object-cover rounded-lg border border-gray-200">
            <div class="flex items-center justify-center gap-1 mt-1">
              <input class="rounded border-gray-300" type="checkbox" name="delete_images[]" value="{{ $img->{$pk} }}" id="del{{ $img->{$pk} }}">
              <label class="text-xs text-gray-600" for="del{{ $img->{$pk} }}">حذف</label>
            </div>
          </label>
        @endforeach
      </div>
    @else
      <div class="text-gray-500">لا توجد صور.</div>
    @endif
  </div>

  <div class="md:col-span-2">
    <label class="block mb-1 text-sm text-gray-700">إضافة صور جديدة</label>
    <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp"
           class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
    <div class="text-xs text-gray-500 mt-1">الحد الأقصى للصورة: 4MB</div>
  </div>

  <div class="md:col-span-2 flex items-center gap-3 pt-2">
    <button type="submit" class="px-6 py-2.5 rounded-lg bg-[#2f4b46] text-white hover:bg-[#2a433f]">حفظ التغييرات</button>
    <a href="{{ route('admin.units.index') }}" class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200">إلغاء</a>
  </div>
</form>
@endsection