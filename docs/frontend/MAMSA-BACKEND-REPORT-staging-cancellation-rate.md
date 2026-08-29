# Mamsa — تقرير النشر على staging

**Re:** `REPLY_deploy_approval_and_five_sites_2026-08-29.md`
**التاريخ:** 29/08/2026 · الحالة: **staging منشور ومتحقّق منه** · production **لسه**

---

## 1. اللي اتنشر

ملفين بس (التستات والمستندات مابتتنشرش):

```
app/Http/Controllers/Api/V1/Admin/CancellationController.php
app/Support/AdminPanel/CancellationPresenter.php
```

قبل النشر اتأكدنا إن staging مطابق للكوميت السابق بالـ md5 — يعني مافيش تعديل يدوي على السيرفر هيتمسح. بعد النشر الـ md5 مطابق للريبو بالظبط.

نسخة رجوع: `~/backup-cancelrate-staging-20260829-140122.tgz`

---

## 2. الحقل على بيانات حقيقية

١١ حجز ملغي على staging. أول تلاتة:

```
#49   rate=0.1   netBase=1440   commission=144   share=1296   impact=-144
#51   rate=0.1   netBase=900    commission=90    share=810    impact=-90
#53   rate=0.1   netBase=520    commission=52    share=468    impact=-52
```

والتحقق اللي يهمكم — النسبة والمبلغ بيتفقوا:

```
rate × netBase = 144.00   vs commission 144.00   AGREE
rate × netBase =  90.00   vs commission  90.00   AGREE
rate × netBase =  52.00   vs commission  52.00   AGREE
```

---

## 3. السطح snake_case

```
HTTP 200
row keys: id, code, booking_code, guest_name, cancelled_by, property, city,
          date, refund, net_base, commission, partner_share,
          commission_rate, impact, refund_status
commission_rate on row 0: 0.1
```

⚠️ **ملاحظة على أسماء المفاتيح:** قائمة الشركاء الأكثر إلغاءً اسمها `high_risk` مش `high_risk_partners`. أنا نفسي قريتها غلط أول مرة في الفحص وطلعت صفر، وافتكرت للحظة إن التعديل كسرها. المفتاح الصح فيه ٣ عناصر.

---

## 4. الإصلاحين — متحقّق منهم على MySQL بعد النشر

`whereHas` بديل `HAVING`، نفس النتيجة بالظبط قبل وبعد:

```
قبل النشر (مقارنة الاستعلامين)   HAVING -> 4:4, 9:4, 5:2
                                whereHas -> 4:4, 9:4, 5:2   identical: YES

بعد النشر (من الـ endpoint نفسه)
  محمد الشريك الفردي     cancellations=4   rate=14.3%
  شريك تجريبي للوحة      cancellations=4   rate=44.4%
  شركة الأفق للعقارات    cancellations=2   rate=8%
```

و`DATE_FORMAT` (مسار MySQL مالمسناهوش أصلاً — التفريع بيمس sqlite بس):

```
trend 2026-06  guest=0 host=0
trend 2026-07  guest=1 host=7
trend 2026-08  guest=0 host=3
```

`summary.financial_impact = 833.89`

---

## 5. المواقع الخمسة — مقبول، والصياغة بتاعتكم أدق

"شغالة على production" كانت وصف ناقص. الفرق اللي كتبتوه — بين "فيه باج" و"مش قادرين نعرف لو فيه باج" — هو النقطة الصح، وسطح بيرمي 500 في بيئة الاختبار بيتخطّى بصمت وبيبان أخضر.

هتتعمل في الجولة الجاية بالشروط التلاتة: إصلاح، إثبات تطابق على MySQL حقيقي، وتست دخان على كل سطح.

**وعن سؤالكم الرابع — أيوه، فيه طريقة.** التغطية لوحدها مش هتمنع التكرار لأن السطح غير المختبر بيعدّي بالسكوت مش بالفشل. اللي بيمنعه إن الصمت ده يتحوّل لفشل:

- تست دخان لكل route إداري: نعدّي على `Route::getRoutes()` ونتأكد إن كل واحد بيرجّع حاجة غير 500. سطح جديد من غير تست بيسقّط التست تلقائيًا بدل ما يعدّي
- ده بيقفل الفئة كلها مرة واحدة بدل ما نطارد كل موقع لوحده

نقترحه في الجولة الجاية مع الإصلاحات. لو مش موافقين على الشكل ده قولوا.

---

## 6. الحالة

| | |
|---|---|
| staging | **منشور ومتحقّق منه** |
| production | **لسه** — مستنيين |
| التستات | 416 passed · 1828 assertions |
| أمرا الـ ledger | متوقفين لحد ما المالك يرفع الوقف |

---

## المطلوب

- [ ] موافقة على production
