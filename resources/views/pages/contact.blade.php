@extends('layouts.app')

@section('content')

{{-- Font Awesome داخل نفس الملف --}}
<style>
    @import url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css');

    .static-page {
        background: #f7f9f8;
        padding: 30px;
        border-radius: 12px;
        max-width: 700px;
        margin: auto;
        direction: rtl;
    }

    .static-page h1 {
        color: #2f6f5e;
        margin-bottom: 10px;
    }

    .contact-list {
        list-style: none;
        padding: 0;
        margin-top: 20px;
    }

    .contact-list li {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
        font-size: 16px;
        color: #333;
    }

    .contact-list i {
        width: 36px;
        height: 36px;
        background: #2f6f5e;
        color: #fff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-left: 12px;
        font-size: 16px;
    }
</style>

<div class="static-page">
    <h1>تواصل معنا</h1>

    <p>يسعدنا تواصلك معنا في أي وقت.</p>

    <ul class="contact-list">
        <li>
            <i class="fa-solid fa-envelope"></i>
            support@mamsa.sa
        </li>
        <li>
            <i class="fa-solid fa-phone"></i>
            000000000
        </li>
    </ul>
</div>

@endsection
