@extends('layouts.Admin', ['title' => 'إضافة مدير'])

@section('content')
    <div class="bg-white rounded-2xl border border-gray-200 p-4 md:p-6">

        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-semibold text-[#2f4b46]">إضافة مدير</h1>
            <a href="{{ route('Admin.users.index', ['tab'=>'Admins']) }}"
               class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-sm">
                رجوع
            </a>
        </div>

        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl p-4 mb-4">
                <strong>الرجاء تصحيح الأخطاء التالية:</strong>
                <ul class="mt-2 list-disc pr-5">
                    @foreach ($errors->all() as $err) <li>{{ $err }}</li> @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('Admin.users.store') }}" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @csrf

            <div class="md:col-span-2">
                <label class="block mb-1 text-sm text-gray-700">الاسم *</label>
                <input type="text" name="name" value="{{ old('name') }}"
                       class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]" required>
            </div>

            <div>
                <label class="block mb-1 text-sm text-gray-700">البريد الإلكتروني *</label>
                <input type="email" name="email" value="{{ old('email') }}"
                       class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]" required>
            </div>

            <div>
                <label class="block mb-1 text-sm text-gray-700">رقم الجوال</label>
                <input type="text" name="phone" value="{{ old('phone') }}"
                       class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
            </div>

            
            <div>
                <label class="block mb-1 text-sm text-gray-700">الحالة</label>
                <select name="status"
                        class="w-full rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46]">
                    <option value="active"   {{ old('status','active')==='active' ? 'selected' : '' }}>نشط</option>
                    <option value="inactive" {{ old('status')==='inactive' ? 'selected' : '' }}>معطّل</option>
                    <option value="pending"  {{ old('status')==='pending' ? 'selected' : '' }}>قيد</option>
                </select>
            </div>

            <div class="md:col-span-2 flex items-center gap-3 pt-2">
                <button type="submit"
                        class="px-6 py-2.5 rounded-lg bg-[#2f4b46] text-white hover:bg-[#2a433f]">
                    حفظ
                </button>
                <a href="{{ route('Admin.users.index', ['tab'=>'Admins']) }}"
                   class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200">
                    إلغاء
                </a>
            </div>
        </form>
    </div>
@endsection