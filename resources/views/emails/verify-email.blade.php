<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
</head>
<body style="margin:0;padding:0;background:#f5f7f6;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0">
<tr>
<td align="center">

<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;margin:40px auto;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.08);">

{{-- HEADER --}}
<tr>
<td style="background:#2F6F63;padding:30px;text-align:center;color:#ffffff;">
<h1 style="margin:0;font-size:24px;">مَمْسَى</h1>
<p style="margin:5px 0 0;font-size:14px;">منصة إدارة العقارات</p>
</td>
</tr>

{{-- BODY --}}
<tr>
<td style="padding:40px;text-align:center;">

<h2 style="color:#2F6F63;margin-bottom:15px;">
مرحباً {{ $user->name ?? '' }} 👋
</h2>

<p style="color:#555;font-size:15px;line-height:1.8;margin-bottom:30px;">
اضغط الزر بالأسفل لتأكيد بريدك الإلكتروني وتفعيل حسابك في منصة مَمْسَى.
</p>

<a href="{{ $url }}"
   style="display:inline-block;background:#2F6F63;color:#ffffff;
   padding:14px 30px;border-radius:12px;text-decoration:none;
   font-weight:bold;font-size:15px;">
   تأكيد البريد الإلكتروني
</a>

<p style="margin-top:30px;font-size:12px;color:#888;">
إذا لم تقم بإنشاء حساب، يمكنك تجاهل هذه الرسالة.
</p>

</td>
</tr>

{{-- FOOTER --}}
<tr>
<td style="background:#f0f2f1;padding:20px;text-align:center;font-size:12px;color:#777;">
© {{ date('Y') }} مَمْسَى — جميع الحقوق محفوظة
</td>
</tr>

</table>

</td>
</tr>
</table>

</body>
</html>
