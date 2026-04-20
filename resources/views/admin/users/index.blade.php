@extends('layouts.admin', ['title' => 'إدارة المستخدمين'])

@section('content')
    <h1 class="text-2xl font-semibold text-[#2f4b46] mb-4">إدارة المستخدمين</h1>

    {{-- فلاش --}}
    @if(session('success'))
        <div class="mb-4 bg-green-50 text-green-700 border border-green-200 rounded-xl p-3">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 bg-red-50 text-red-700 border border-red-200 rounded-xl p-3">{{ session('error') }}</div>
    @endif

    @php
        $AdminsTotal = method_exists($Admins, 'total') ? $Admins->total() : $Admins->count();
        $usersTotal  = method_exists($users, 'total')  ? $users->total()  : $users->count();
        $AdminsPendingInPage = $Admins->getCollection()->whereStrict('is_active', null)->count();
        $activeTab = request('tab', 'Admins'); // Admins | users
    @endphp

    {{-- عدادات --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <div class="text-sm text-gray-500">عدد المدراء</div>
            <div class="text-2xl font-semibold text-[#2f4b46]">{{ number_format($AdminsTotal) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <div class="text-sm text-gray-500">عدد المستخدمين العاديين</div>
            <div class="text-2xl font-semibold text-[#2f4b46]">{{ number_format($usersTotal) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <div class="text-sm text-gray-500">مدراء (قيد التفعيل) في الصفحة الحالية</div>
            <div class="text-2xl font-semibold text-[#2f4b46]">{{ number_format($AdminsPendingInPage) }}</div>
        </div>
    </div>

    {{-- بحث + إضافة مدير --}}
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
        <form method="GET" action="{{ route('Admin.users.index') }}" class="flex items-center gap-2">
            <input type="hidden" name="tab" value="{{ $activeTab }}">
            <input type="text" name="q" value="{{ request('q') }}"
                   placeholder="ابحث بالاسم / البريد / الجوال"
                   class="w-72 rounded-lg border-gray-300 focus:border-[#2f4b46] focus:ring-[#2f4b46] px-3 py-2 text-sm">
            <button type="submit"
                    class="px-4 py-2 rounded-lg bg-[#2f4b46] text-white hover:bg-[#2a433f] text-sm">
                بحث
            </button>
            @if(request('q'))
                <a href="{{ route('Admin.users.index', ['tab' => $activeTab]) }}"
                   class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-sm">
                    مسح البحث
                </a>
            @endif
        </form>

        <a href="{{ route('Admin.users.create') }}"
           class="inline-flex items-center px-4 py-2 rounded-lg bg-[#2f4b46] text-white hover:bg-[#2a433f] text-sm">
            + إضافة مدير
        </a>
    </div>

    {{-- Tabs --}}
    <div class="flex items-center gap-2 mb-4">
        <a href="{{ route('Admin.users.index', ['tab'=>'Admins', 'q'=>request('q')]) }}"
           class="px-4 py-2 rounded-lg text-sm {{ $activeTab==='Admins' ? 'bg-[#2f4b46] text-white' : 'bg-white text-gray-700 border border-gray-200 hover:bg-gray-50' }}">
            المدراء
        </a>
        <a href="{{ route('Admin.users.index', ['tab'=>'users', 'q'=>request('q')]) }}"
           class="px-4 py-2 rounded-lg text-sm {{ $activeTab==='users' ? 'bg-[#2f4b46] text-white' : 'bg-white text-gray-700 border border-gray-200 hover:bg-gray-50' }}">
            المستخدمون العاديون
        </a>
    </div>

    {{-- ======= جدول المدراء ======= --}}
    <section id="Admins-section" class="{{ $activeTab==='Admins' ? '' : 'hidden' }}">
        <h2 class="text-lg font-semibold mb-2">المدراء</h2>

        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden mb-6">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-700">
                <tr>
                    <th class="py-3 px-4 text-right">#</th>
                    <th class="py-3 px-4 text-right">الاسم</th>
                    <th class="py-3 px-4 text-right">البريد</th>
                    <th class="py-3 px-4 text-right">الجوال</th>
                    <th class="py-3 px-4 text-right">الحالة</th>
                    <th class="py-3 px-4 text-center">إجراءات</th>
                </tr>
                </thead>
                <tbody>
                @forelse($Admins as $u)
                    @php
                        $badge = is_null($u->is_active)
                            ? ['قيد التفعيل', 'bg-yellow-100 text-yellow-800 border-yellow-300']
                            : ($u->is_active
                                ? ['نشط', 'bg-green-100 text-green-700 border-green-300']
                                : ['معطّل', 'bg-red-100 text-red-700 border-red-300']);
                    @endphp
                    <tr class="border-t">
                        <td class="py-3 px-4">{{ $u->id }}</td>
                        <td class="py-3 px-4">{{ $u->name }}</td>
                        <td class="py-3 px-4">{{ $u->email }}</td>
                        <td class="py-3 px-4">{{ $u->phone ?? '-' }}</td>
                        <td class="py-3 px-4">
                            <span class="inline-flex px-3 py-1 rounded-full text-xs border {{ $badge[1] }}">
                                {{ $badge[0] }}
                            </span>
                        </td>
                        <td class="py-3 px-4 text-center">
                            <div class="inline-flex items-center gap-2">
                                {{-- تفعيل --}}
                                <form method="POST" action="{{ route('Admin.users.status', $u->id) }}"
                                      onsubmit="return confirm('تأكيد: ضبط الحالة إلى نشط؟');">
                                    @csrf
                                    <input type="hidden" name="status" value="active">
                                    <button class="px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700 text-xs">
                                        تفعيل
                                    </button>
                                </form>

                                {{-- تعطيل --}}
                                <form method="POST" action="{{ route('Admin.users.status', $u->id) }}"
                                      onsubmit="return confirm('تأكيد: ضبط الحالة إلى معطّل؟');">
                                    @csrf
                                    <input type="hidden" name="status" value="inactive">
                                    <button class="px-3 py-1.5 rounded-lg bg-red-600 text-white hover:bg-red-700 text-xs">
                                        تعطيل
                                    </button>
                                </form>

                                {{-- قيد --}}
                                <form method="POST" action="{{ route('Admin.users.status', $u->id) }}"
                                      onsubmit="return confirm('تأكيد: ضبط الحالة إلى قيد؟');">
                                    @csrf
                                    <input type="hidden" name="status" value="pending">
                                    <button class="px-3 py-1.5 rounded-lg bg-yellow-600 text-white hover:bg-yellow-700 text-xs">
                                     قيد التفعيل
                                    </button>
                                </form>

                                {{-- حذف مدير --}}
                                <form method="POST" action="{{ route('Admin.users.delete', $u->id) }}"
                                      onsubmit="return confirm('تحذير: حذف المدير نهائيًا؟ لا يمكن التراجع.');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="px-3 py-1.5 rounded-lg bg-gray-700 text-white hover:bg-gray-800 text-xs">
                                        حذف
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-gray-500">لا يوجد مدراء.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mb-10">
            {{ $Admins->appends(['q'=>request('q'), 'tab'=>'Admins'])->links() }}
        </div>
    </section>

    {{-- ======= جدول المستخدمين العاديين ======= --}}
    <section id="users-section" class="{{ $activeTab==='users' ? '' : 'hidden' }}">
        <h2 class="text-lg font-semibold mb-2">المستخدمون العاديون</h2>

        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-700">
                <tr>
                    <th class="py-3 px-4 text-right">#</th>
                    <th class="py-3 px-4 text-right">الاسم</th>
                    <th class="py-3 px-4 text-right">البريد</th>
                    <th class="py-3 px-4 text-right">الجوال</th>
                </tr>
                </thead>
                <tbody>
                @forelse($users as $u)
                    <tr class="border-t">
                        <td class="py-3 px-4">{{ $u->id }}</td>
                        <td class="py-3 px-4">{{ $u->name }}</td>
                        <td class="py-3 px-4">{{ $u->email }}</td>
                        <td class="py-3 px-4">{{ $u->phone ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-500">لا يوجد مستخدمون.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $users->appends(['q'=>request('q'), 'tab'=>'users'])->links() }}
        </div>
    </section>
@endsection
