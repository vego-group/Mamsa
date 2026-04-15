@extends('layouts.app')

@section('content')

@php
    $price = $unit->price;

    $nights = max(1, \Carbon\Carbon::parse($checkin)->diffInDays($checkout));

    $total = $price * $nights;
@endphp

<style>
:root{
  --green:#2F6F63;
  --green2:#1f4f46;
  --bg:#f4f6f5;
  --border:#e5e7eb;
}

body{
  background:var(--bg);
}

.checkout-wrapper{
  min-height:calc(100vh - 120px);
  display:flex;
  align-items:center;
  justify-content:center;
  padding:40px 16px;
}

.checkout-card{
  width:100%;
  max-width:520px;
  background:#fff;
  border-radius:28px;
  padding:34px;
  box-shadow:0 30px 80px rgba(0,0,0,.08);
  border:1px solid var(--border);
}

.title{
  text-align:center;
  font-size:24px;
  font-weight:900;
  color:var(--green);
  margin-bottom:10px;
}

.price-box{
  text-align:center;
  margin-bottom:25px;
}
.price{
  font-size:32px;
  font-weight:1000;
  color:var(--green);
}
.sub{
  font-size:13px;
  color:#777;
}

.apple-btn{
  width:100%;
  background:#000;
  color:#fff;
  padding:16px;
  border-radius:18px;
  font-weight:900;
  text-align:center;
  cursor:pointer;
  transition:.2s;
  margin-bottom:15px;
}
.apple-btn:hover{
  transform:translateY(-2px);
}

.divider{
  text-align:center;
  font-size:12px;
  color:#aaa;
  margin:15px 0;
  position:relative;
}
.divider:before,
.divider:after{
  content:'';
  position:absolute;
  top:50%;
  width:40%;
  height:1px;
  background:#eee;
}
.divider:before{ left:0 }
.divider:after{ right:0 }

.methods{
  display:flex;
  gap:12px;
  margin-bottom:15px;
}

.method{
  flex:1;
  border:2px solid #eee;
  border-radius:18px;
  padding:16px;
  text-align:center;
  font-weight:800;
  cursor:pointer;
  transition:.2s;
  background:#fff;
}

.method.active{
  border-color:var(--green);
  background:rgba(47,111,99,.06);
}

.card-form{
  display:none;
  margin-top:10px;
}

.input{
  width:100%;
  padding:14px;
  border-radius:14px;
  border:1px solid var(--border);
  margin-top:10px;
  font-size:14px;
}

.row{
  display:flex;
  gap:10px;
}

.pay-btn{
  width:100%;
  margin-top:20px;
  padding:18px;
  border-radius:20px;
  border:0;
  background:linear-gradient(135deg,var(--green),var(--green2));
  color:#fff;
  font-weight:900;
  font-size:16px;
  cursor:pointer;
  transition:.2s;
}

.pay-btn:hover{
  transform:translateY(-2px);
  box-shadow:0 12px 30px rgba(47,111,99,.25);
}
.input-label{
  display:block;
  font-size:13px;
  font-weight:700;
  margin-top:12px;
  margin-bottom:6px;
  color:#444;
}

.field{
  flex:1;
}
</style>

<div class="checkout-wrapper">

  <div class="checkout-card">

    <h2 class="title">إتمام الدفع</h2>

    <div class="price-box">
      <div class="price">{{ number_format($total) }} ريال</div>
      <div class="sub">
        {{ $nights }} ليلة × {{ number_format($price) }} ريال
      </div>
    </div>

    {{-- Apple Pay --}}
    <div class="apple-btn" onclick="goToPayment('apple')">
       Apple Pay
    </div>

    <div class="divider">أو ادفع باستخدام</div>

    {{-- طرق الدفع --}}
    <div class="methods">
      <div class="method" id="mada" onclick="selectMethod('mada')">
        مدى
      </div>

      <div class="method" id="visa" onclick="selectMethod('visa')">
        Visa / MasterCard
      </div>
    </div>

    {{-- نموذج البطاقة --}}
<div class="card-form" id="cardForm">

  <label class="input-label">رقم البطاقة</label>
  <input class="input" placeholder="0000 0000 0000 0000">

  <div class="row">

    <div class="field">
      <label class="input-label">MM/YY</label>
      <input class="input" placeholder="MM/YY">
    </div>

    <div class="field">
      <label class="input-label">CVV</label>
      <input class="input" placeholder="CVV">
    </div>

  </div>

  <label class="input-label">الاسم</label>
  <input class="input" placeholder="الاسم كما يظهر بالبطاقة">

</div>

    {{-- زر الدفع --}}
    <button class="pay-btn" onclick="goToPayment(selectedMethod)">
        ادفع الآن
    </button>

  </div>

</div>

<script>

let selectedMethod = null;

function selectMethod(type){
  selectedMethod = type;

  document.querySelectorAll('.method').forEach(el=>{
    el.classList.remove('active')
  });

  document.getElementById(type).classList.add('active');
  document.getElementById('cardForm').style.display = 'block';
}

function goToPayment(method){
    if(!method){
        alert('اختر طريقة الدفع أولاً');
        return;
    }

    window.location.href =
    `/payment?unit={{ $unit->id }}&checkin={{ $checkin }}&checkout={{ $checkout }}&total={{ $total }}&method=${method}`;
}

</script>

@endsection