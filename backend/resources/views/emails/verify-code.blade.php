<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>رمز تأكيد البريد الإلكتروني</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:Tahoma,Arial,sans-serif;direction:rtl;text-align:right;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f766e;padding:24px;text-align:center;">
                            <h1 style="margin:0;color:#ffffff;font-size:22px;">مَمسَى</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 28px;color:#1f2937;">
                            <p style="margin:0 0 16px;font-size:16px;">مرحباً،</p>
                            <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#4b5563;">
                                استخدم الرمز التالي لتأكيد بريدك الإلكتروني وإكمال تسجيلك كشريك في مَمسَى:
                            </p>
                            <div style="text-align:center;margin:0 0 24px;">
                                <span style="display:inline-block;letter-spacing:8px;font-size:32px;font-weight:bold;color:#0f766e;background:#f0fdfa;border:1px dashed #0f766e;border-radius:10px;padding:14px 24px;">
                                    {{ $code }}
                                </span>
                            </div>
                            <p style="margin:0 0 8px;font-size:14px;color:#6b7280;">
                                هذا الرمز صالح لمدة {{ $expMinutes }} دقائق فقط.
                            </p>
                            <p style="margin:0;font-size:14px;color:#6b7280;">
                                إذا لم تطلب هذا الرمز، يمكنك تجاهل هذه الرسالة بأمان.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f9fafb;padding:18px;text-align:center;color:#9ca3af;font-size:12px;">
                            © {{ date('Y') }} مَمسَى. جميع الحقوق محفوظة.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
