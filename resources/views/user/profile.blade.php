@extends('layouts.app')

@section('content')

<div class="profile-page">

    <h1 class="profile-title">الملف الشخصي</h1>

    <form action="{{ route('user.update') }}" method="POST">
        @csrf
        @method('PUT')

        <div class="profile-grid">

            <div class="field-box">
                <label>اسم المستخدم</label>
                <input type="text" name="name" value="{{ auth()->user()->name }}">
            </div>

            <div class="field-box">
                <label>رقم الهاتف</label>
                <input type="text" name="phone" value="{{ auth()->user()->phone }}">
            </div>

            <div class="field-box">
                <label>الإيميل</label>
                <input type="email" name="email" value="{{ auth()->user()->email }}">
            </div>

          
            {{-- الحقول المخفية <div id="passwordArea" class="password-hidden">

                <div class="field-box">
                    <label>تعيين كلمة المرور</label>
                    <input type="password" name="password">
                </div>

                <div class="field-box">
                    <label>تأكيد كلمة المرور الجديدة</label>
                    <input type="password" name="password_confirmation">
                </div>

            </div>--}}
            

        </div>

        <button type="submit" class="save-btn">حفظ التعديلات</button>

        {{-- تسجيل خروج --}}
        <a class="logout-btn"
           href="{{ route('logout') }}"
           onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
           تسجيل خروج
        </a>

    </form>

    <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none;">
        @csrf
    </form>

</div>


<script>
function togglePassword() {
    let box = document.getElementById('passwordArea');
    box.style.display = box.style.display === "none" ? "block" : "none";
}
</script>

@endsection