<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'مَمسَى')</title>
</head>
{{-- Locked template rules (email task doc §3): Arabic RTL, Gregorian DD/MM/YYYY,
     Latin digits, SAR only. Inline styles — email clients strip <style>. --}}
<body style="margin:0;padding:0;background:#f4f4f7;font-family:Tahoma,Arial,sans-serif;direction:rtl;text-align:right;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f766e;padding:24px;text-align:center;">
                            <h1 style="margin:0;color:#ffffff;font-size:22px;">مَمسَى</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 28px;color:#1f2937;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f9fafb;padding:18px;text-align:center;color:#9ca3af;font-size:12px;">
                            للاستفسار راسلنا على info&#64;mamsaa.com<br>
                            © {{ date('Y') }} مَمسَى. جميع الحقوق محفوظة.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
