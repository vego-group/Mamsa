@extends('layouts.admin', ['title' => 'إضافة وحدة'])

@section('content')
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-semibold text-[#2f4b46]">إضافة وحدة</h1>
        <a href="{{ route('admin.units.index') }}"
           class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-sm">
            رجوع
        </a>
    </div>

    @if($errors->any())
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-xl p-3">
            <strong>الرجاء تصحيح الأخطاء التالية:</strong>
            <ul class="mt-2 list-disc pr-5">
                @foreach($errors->all() as $err) <li>{{ $err }}</li> @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.units.store') }}" method="POST" enctype="multipart/form-data"
          class="bg-white rounded-2xl border border-gray-200 p-4 md:p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        @csrf

        <div class="md:col-span-1">
            <label class="block mb-1 text-sm text-gray-700">اسم الوحدة *</label>
            <input type="text" name="name" value="{{ old('name') }}"
                   class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]" required>
        </div>

        <div class="md:col-span-1">
            <label class="block mb-1 text-sm text-gray-700">الكود </label>
            <input type="text" value="{{ $generatedCode }}" readonly
                   class="w-full rounded-lg border-gray-300 bg-gray-50 text-gray-700">
            <input type="hidden" name="code" value="{{ $generatedCode }}">
        </div>

        <div class="md:col-span-1">
            <label class="block mb-1 text-sm text-gray-700">الحالة *</label>
            <select name="status"
                    class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]" required>
                @foreach($statuses as $k=>$v)
                    <option value="{{ $k }}" @selected(old('status')===$k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>

        <div class="md:col-span-1">
            <label class="block mb-1 text-sm text-gray-700">السعر</label>
            <input type="number" step="0.01" name="price" value="{{ old('price') }}"
                   class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
        </div>

        <div class="md:col-span-2">
            <label class="block mb-1 text-sm text-gray-700">الوصف</label>
            <textarea name="description" rows="4"
                      class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">{{ old('description') }}</textarea>
        </div>

        <div class="md:col-span-2">
            <label class="block mb-1 text-sm text-gray-700">الصور (يمكن اختيار أكثر من صورة)</label>
            <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp"
                   class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
            <div class="text-xs text-gray-500 mt-1">الحد الأقصى للصورة: 4MB</div>
        </div>

        <div class="md:col-span-2 flex items-center gap-3 pt-2">
            <button type="submit"
                    class="px-6 py-2.5 rounded-lg bg-[#2f4b46] text-white hover:bg-[#2a433f]">
                حفظ
            </button>
            <a href="{{ route('admin.units.index') }}"
               class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200">
                إلغاء
            </a>
        </div>
    </form>
@endsection
