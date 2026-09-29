# الـ410 اتثبت بنداء حقيقي على staging · والمولّدين بقوا باسمين · وسطر الـ`MULTI_UNIT_ENABLED` في العقد

**التاريخ:** 29/09/2026
**رداً على:** قرار (ج) + الطلبات التلاتة
**الكوميت:** `c5c5bac` على `feat/complaints-refunds` — **مافيش نشر**

---

## ١. الخلاصة

1. **النداء الحقيقي على staging رجّع 410 `ENDPOINT_RETIRED`** — بحساب شريك اختباري، وعدد
   الوحدات ٤٢ قبل وبعد. التوكن اتقفل بعدها ومابقاش شغّال.
2. **كان فيه تلات دوال `uniqueCode()` مش اتنين.** دلوقتي فيه اتنين باسمين مختلفين، وكل واحدة
   فيها تعليق بيسمّي صيغتها وصيغة التانية.
3. **سطر `MULTI_UNIT_ENABLED` اتسجّل في العقد** — وفي دليل البناء كمان.

---

## ٢. النداء الحقيقي على staging

**الحساب:** id 33 · `+966555000002` · «شريك تجريبي» · دور `Individual`.
من البلوك الاصطناعي `+96655500000X` — **مش** حساب عميل حقيقي. دخلنا بمسار الدخول العادي
(`request-otp` ← `verify-otp`) زي أي شريك.

```
POST https://staging.mamsaa.com/api/v1/partner/units    body: {}
Authorization: Bearer <توكن الحساب الاختباري>
User-Agent: mamsa-backend-verification-2026-09-29

HTTP 410
{"success":false,
 "message":"هذا المسار لم يعد مدعوماً. استخدم لوحة الشريك أو لوحة المشرف.",
 "code":"ENDPOINT_RETIRED"}
```

| | القيمة |
|---|---|
| عدد الوحدات قبل النداء | **42** |
| عدد الوحدات بعده | **42** |

**ولوج التقاعد سجّل النداء** — بالـUser-Agent بتاعنا، فمحدش هيقراه على إنه الشريك نفسه بينادي المسار:

```
[2026-09-29 23:20:59] staging.WARNING: retired endpoint called {"route":"api.partner.units.store","method":"POST","user_id":33,"roles":["Individual"],"ip":"197.32.184.145","agent":"mamsa-backend-verification-2026-09-29","at":"2026-09-29T23:20:59+03:00"}
```

**التنظيف:**

| الخطوة | النتيجة |
|---|---|
| `POST /api/v1/auth/logout` بنفس التوكن | **200** |
| نفس النداء على `/partner/units` بنفس التوكن بعد الخروج | **401** — التوكن مات |
| النسخ المحلية من التوكن ورد الدخول | اتمسحت |

**ليه ده كافي كإثبات للإنتاج:** العلم `false` على البيئتين، والكود نفسه، وسلسلة الـmiddleware
نفسها (`auth:sanctum` → `role` → `RetiredEndpoint`). ومع تلات المصادر اللي اتقرت من الإنتاج
مباشرة (`route:list`، الـconfig المتكاش، ملف الـmiddleware)، الصورة كاملة من غير ما نلمس الإنتاج
ولا حساب عميل.

---

## ٣. الدوال — **كانوا تلاتة**

قبل ما نعدّل، دوّرنا على كل تعريف للاسم بدل ما نفترض إنهم اتنين. لقينا تالت:

| الدالّة | كانت بتعمل إيه | نوعها |
|---|---|---|
| `UnitWriter::uniqueCode()` | `MRN` + ٥ خانات | public · بتتنادى من الأدمن ولوحة الشريك |
| `UnitCloner::uniqueCode()` | **٨ خانات عشوائية، من غير بادئة** | private · نداء واحد في نفس الملف |
| `Dashboard\UnitController::uniqueCode()` | **بتنادي `UnitWriter::uniqueCode()` وبس** | private · نداء واحد |

التالتة هي نفس الفخ بالظبط: لو حد قراها لوحدها، كان ممكن يفتكرها صيغة تالتة.

**ولأن كل واحدة من الاتنين الخاصين ليها نداء واحد بس، عملنا إعادة التسمية اللي فضّلتوها:**

- **`UnitCloner::uniqueCode()` ← `apartmentCode()`** — نداء واحد في نفس الملف، ومافيش اختبار
  كان بيشير ليها.
- **الغلاف اللي في `Dashboard\UnitController` اتشال** — النداء الوحيد بقى بينادي
  `UnitWriter::uniqueCode()` مباشرة.
- **كل دالّة من الاتنين الباقيين عليها تعليق** بيسمّي صيغتها وصيغة التانية:

```php
// UnitWriter::uniqueCode()
/**
 * FORMAT: `MRN` + five random characters — `MRNXDX5D`. What a NEW listing
 * gets, from the partner dashboard or the admin console.
 *
 * NOT the only code format in the table: UnitCloner::apartmentCode() gives
 * every apartment added to a building eight random characters with no
 * prefix. Never infer "is this a code" from the MRN prefix.
 */
public static function uniqueCode(string $prefix = 'MRN'): string
```

```php
// UnitCloner::apartmentCode()
/**
 * FORMAT: eight random characters, NO prefix — `1G4ADB2F`. Can start with,
 * or in principle be entirely, digits. Every apartment added to a building
 * gets one of these.
 *
 * NOT the same format as UnitWriter::uniqueCode() (`MRN` + five), which a
 * new listing gets. This was also called uniqueCode() until 2026-09-29, and
 * the shared name alone caused a wrong claim to the frontend about where
 * the second format came from. Routing does not depend on the format —
 * Unit::resolveRouteBinding() tries a numeric id first — so neither
 * generator needs to change; they need to stay distinguishable.
 *
 * `code` is UNIQUE; a 100-row loop is where a random collision finally happens.
 */
private static function apartmentCode(): string
```

**السلوك ماتغيّرش:** نفس الصيغتين، نفس المسارات. الاختبارات **٨١٢ / ٣٣٧١ assertion، كلها خضرا**.

**ملاحظة على Pint:** بيشتكي من `UnitCloner.php` و`UnitWriter.php` — **اتأكدنا إن الشكوى موجودة في
النسخة اللي قبل التعديل**، فمش مننا. سيبناها عشان الفرق يفضل على غرضه.

**ودي ملفات كود إنتاج** (`UnitCloner.php` · `UnitWriter.php` · `Dashboard/UnitController.php` ·
تعليق `Unit.php`)، فهتنزل مع أول نشرة جاية — من غير أي تغيير في السلوك.

---

## ٤. السطر في العقد

**في الملحق §٤.١ج-٢**، بعد فقرة الصيغ مباشرة:

> 🔴 **يوم ما `MULTI_UNIT_ENABLED` يتفتح على الإنتاج، كل شقة تتضاف لمبنى هتاخد كود بالصيغة
> التانية — بالتصميم، مش تسريب.** الـ٠ اللي على الإنتاج النهارده سببه إن العلم مقفول، مش إن
> المولّد اتقفل.

**وفي دليل البناء كمان** — لأنه الملف اللي فريق Next.js بيشتغل منه:

> 🔴 **يوم ما `MULTI_UNIT_ENABLED` يتفتح على الإنتاج، كل شقة تتضاف لمبنى هتاخد كود بالصيغة التانية
> — بالتصميم، مش تسريب.** الـ٠ اللي على الإنتاج النهارده سببه العلم، مش المولّد.

**واتزاد في الملحق** جنب سطر التقاعد إنه **متحقَّق بنداء حقيقي على staging 29/09**: 410، والوحدات
٤٢ قبل وبعد. واسم `UnitCloner::apartmentCode()` اتحدّث فيه، مع ملاحظة إن اسمها كان `uniqueCode()`
لحد 29/09.

---

## ٥. اللي اتغيّر، بالظبط

| الملف | التغيير |
|---|---|
| `app/Support/Units/UnitCloner.php` | `uniqueCode()` ← `apartmentCode()` + تعليق الصيغة |
| `app/Support/Units/UnitWriter.php` | تعليق الصيغة على `uniqueCode()` |
| `app/Http/Controllers/Dashboard/UnitController.php` | الغلاف اتشال، والنداء بقى مباشر |
| `app/Models/Unit.php` | التعليق بيسمّي `apartmentCode()` |
| الملحق `permits-frontend-contract-phases-4-6.md` | سطر `MULTI_UNIT_ENABLED` + إثبات staging + الاسم الجديد |
| دليل البناء `MAMSA-NEXTJS-BUILD-permits.md` | سطر `MULTI_UNIT_ENABLED` |

**مافيش نشر، ومافيش حاجة مطلوبة من فريق Next.js.**
