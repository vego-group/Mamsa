# إعداد نطاقات الاختبار الثلاثة على Vercel وربطها بـ staging

**إلى:** م. مناهل (Project Manager) + فريق الـ Front-End
**من:** فريق الـ Back-End
**التاريخ:** 2026-09-03
**الحالة:** الـ DNS والـ CORS **جاهزان ومُتحقَّق منهما**. الباقي كله على Vercel.

---

## 0. الخلاصة في ثلاث نقاط

1. **النطاقات الثلاثة موجودة فعلاً وشغّالة** على Vercel — لا يوجد عمل DNS مطلوب.
2. ⚠️ **لكن الثلاثة حالياً مربوطة بـ `api.mamsaa.com` (الإنتاج) وليس staging.**
   `test.mamsaa.com` يعرض الآن **بيانات وصور الإنتاج الحقيقية**. أي حجز أو تجربة
   عليه الآن = **حجز حقيقي على قاعدة بيانات الإنتاج**. هذه أهم نقطة في المستند.
3. ⚠️ **الـ base URL ليس `/api/v1` للثلاثة.** واحد فقط يستخدم `/api/v1`،
   والاثنان الآخران يستخدمان **الجذر بدون أي لاحقة**. التفاصيل في القسم 2.

---

## 1. الوضع الحالي — ما تم فحصه اليوم

### 1.1 الـ DNS ✅ تمّ

| النطاق | يشير إلى | الحالة |
|---|---|---|
| `test.mamsaa.com` | Vercel (`…vercel-dns-017.com`) | ✅ يعمل — HTTP 200 |
| `test-partner.mamsaa.com` | Vercel | ✅ يعمل — يحوّل إلى `/login` |
| `test-admin.mamsaa.com` | Vercel | ✅ يعمل — يحوّل إلى `/login` |

### 1.2 المشكلة الفعلية ❌ — الـ base URL

فحصنا ملفات الـ JavaScript المبنية داخل كل نطاق، والنتيجة:

| النطاق | الـ API المدموج داخل البناء | المفروض |
|---|---|---|
| `test.mamsaa.com` | ❌ `https://api.mamsaa.com/api/v1` | `https://staging.mamsaa.com/api/v1` |
| `test-partner.mamsaa.com` | ❌ `https://api.mamsaa.com` | `https://staging.mamsaa.com` |
| `test-admin.mamsaa.com` | ❌ `https://api.mamsaa.com` | `https://staging.mamsaa.com` |

> **ملاحظة مهمة:** `test.mamsaa.com` مرتبط بنفس مشروع Vercel الخاص بـ
> `www.mamsaa.com` (نفس عنوان الـ CNAME). لذلك هو يبني نفس فرع الإنتاج بنفس
> متغيّراته. لكي يصبح بيئة اختبار حقيقية يحتاج **مشروع Vercel منفصل** (أو على
> الأقل ربط النطاق بفرع مختلف مع متغيّرات بيئة مختلفة).

---

## 2. ⚠️ الفخّ الأهم: الـ base URL يختلف حسب التطبيق

الـ Back-End يقدّم **ثلاثة واجهات مختلفة على نفس النطاق**، ولكل واحدة مسار مختلف:

| الواجهة | المسار | نوع الدخول | يستخدمها |
|---|---|---|---|
| `/api/v1/*` | ببادئة | Bearer token | تطبيق الضيف + الموبايل |
| `/*` (الجذر) | **بدون بادئة** | Cookie session | لوحة الشركاء |
| `/admin/*` | الجذر | Cookie session | لوحة الأدمن |

### إثبات عملي على staging (شغّلوه بأنفسكم)

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://staging.mamsaa.com/api/v1/units   # 200 ✅
curl -s -o /dev/null -w '%{http_code}\n' https://staging.mamsaa.com/me             # 401 ✅ (موجود، يحتاج تسجيل دخول)
curl -s -o /dev/null -w '%{http_code}\n' https://staging.mamsaa.com/api/v1/me      # 404 ❌
curl -s -o /dev/null -w '%{http_code}\n' https://staging.mamsaa.com/admin/me       # 401 ✅
curl -s -o /dev/null -w '%{http_code}\n' https://staging.mamsaa.com/api/v1/admin/me # 404 ❌
```

> ❌ **لا تضعوا `/api/v1` في الـ base URL الخاص بلوحة الشركاء أو لوحة الأدمن.**
> لو وضعتموها، **كل** الطلبات سترجع 404 وسيبدو الأمر وكأن الـ API معطّل.

---

## 3. المطلوب على Vercel — الجدول النهائي

لكل مشروع: **Settings → Environment Variables**

| مشروع Vercel | النطاق | `NEXT_PUBLIC_API_BASE_URL` |
|---|---|---|
| تطبيق الضيف (bench) | `test.mamsaa.com` | `https://staging.mamsaa.com/api/v1` |
| لوحة الشركاء (bench) | `test-partner.mamsaa.com` | `https://staging.mamsaa.com` |
| لوحة الأدمن (bench) | `test-admin.mamsaa.com` | `https://staging.mamsaa.com` |

بالإضافة إلى:

```env
NEXT_PUBLIC_USE_MOCK=false
```

### خطوات التنفيذ

1. تأكّدوا أن كل نطاق اختبار مربوط بـ **مشروع أو فرع مستقل** عن الإنتاج
   (خصوصاً `test.mamsaa.com` — حالياً على نفس مشروع الإنتاج).
2. اضبطوا `NEXT_PUBLIC_API_BASE_URL` من الجدول أعلاه.
3. **أعيدوا البناء (Redeploy) مع إلغاء تفعيل Build Cache.**

> 🔴 متغيّرات `NEXT_PUBLIC_*` **تُدمج داخل الملفات وقت البناء**، وليست وقت التشغيل.
> تغيير القيمة بدون إعادة بناء **لا يغيّر شيئاً إطلاقاً** — وهذا أكثر سبب
> يجعل الفريق يظن أن الإعداد لم ينجح.

---

## 4. جانب الـ Back-End — ✅ منتهي ومُتحقَّق منه

لا يوجد أي عمل مطلوب من ناحيتنا. النطاقات الثلاثة **مضافة بالفعل** على
قائمة CORS الخاصة بـ staging مع دعم الكوكيز:

```
Origin: https://test.mamsaa.com          → 204 ✅  allow-credentials: true
Origin: https://test-partner.mamsaa.com  → 204 ✅  allow-credentials: true
Origin: https://test-admin.mamsaa.com    → 204 ✅  allow-credentials: true
```

والكوكي يعود بالخصائص الصحيحة للعمل عبر النطاقات:

```
mamsaa-session=…; domain=staging.mamsaa.com; secure; httponly; samesite=none
```

بمعنى أن **تسجيل الدخول بالكوكيز من نطاقات الاختبار سيعمل** بمجرد تعديل
الـ base URL وإعادة البناء.

**شرط واحد فقط:** يجب أن يُرسل الـ Front-End الطلبات مع الكوكيز:

```js
fetch(url, { credentials: 'include' })   // أو axios: withCredentials: true
```

---

## 5. إعداد البيئة المحلية (Local)

المنافذ التالية **مسموح بها بالفعل** على staging:

```
http://localhost:3000
http://localhost:3001
http://localhost:3002
https://local.mamsaa.com:3002
```

### ملفات `.env.local` المقترحة

**تطبيق الضيف:**
```env
NEXT_PUBLIC_API_BASE_URL=https://staging.mamsaa.com/api/v1
NEXT_PUBLIC_USE_MOCK=false
```

**لوحة الشركاء:**
```env
NEXT_PUBLIC_API_BASE_URL=https://staging.mamsaa.com
NEXT_PUBLIC_USE_MOCK=false
```

**لوحة الأدمن:**
```env
NEXT_PUBLIC_API_BASE_URL=https://staging.mamsaa.com
NEXT_PUBLIC_USE_MOCK=false
```

> اقتراح تنظيمي (ليس شرطاً من الـ Back-End): الضيف على `3000`،
> الشركاء على `3001`، الأدمن على `3002` — لأن `3002` هو المنفذ الذي سبق
> اعتماده واختباره للأدمن.

> لو احتجتم منفذاً غير هذه المنافذ، أبلغونا لإضافته إلى قائمة CORS.

---

## 6. تصحيح معلومة قديمة

المستند القديم `NEXTJS_PROD_STAGING_SETUP.md` يذكر:

```env
NEXT_PUBLIC_API_BASE_URL=https://staging-api.mamsaa.com   # ❌ خطأ
```

**هذا النطاق غير موجود أصلاً.** العنوان الصحيح لـ staging هو:

```env
NEXT_PUBLIC_API_BASE_URL=https://staging.mamsaa.com
```

---

## 7. كيف تتأكدون أن الإعداد نجح

بعد إعادة البناء، افتحوا كل نطاق ثم **DevTools → Network**:

| ما تتحقّقون منه | النتيجة الصحيحة |
|---|---|
| عنوان أي طلب API | يبدأ بـ `https://staging.mamsaa.com` |
| لا يوجد أي طلب إلى | `api.mamsaa.com` |
| طلبات الشركاء/الأدمن | **ليست** 404 |
| كوكي الجلسة | يُرسل ويُحفظ بعد تسجيل الدخول |

أمر سريع للتأكد من أن البناء لم يعد يحتوي على عنوان الإنتاج:

```bash
curl -s https://test.mamsaa.com | grep -o 'api\.mamsaa\.com'   # المفروض: لا نتيجة
```

---

## 8. طلب عاجل ⚠️

إلى أن تتم إعادة البناء، **يُرجى إبلاغ الفريق بعدم استخدام `test.mamsaa.com`
لأي اختبار** — لأنه متصل بقاعدة بيانات الإنتاج، وأي حجز عليه سيكون حجزاً
حقيقياً بعملية دفع حقيقية.

---

## للاستفسار

أي سؤال أو أي منفذ/نطاق إضافي تحتاجونه على قائمة CORS — تواصلوا معنا مباشرة.
