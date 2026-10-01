# ربط نطاقات الاختبار بـ staging على Vercel

**التاريخ:** 07/09/2026
**الهدف:** النطاقات التلاتة تشتغل على `staging.mamsaa.com` بدل الإنتاج
**كل ما هو مذكور تحت متحقَّق منه اليوم** — مش منقول من إعداد سابق

| النطاق | البنش | القاعدة المطلوبة |
|---|---|---|
| `test.mamsaa.com` | تطبيق الضيف | `https://staging.mamsaa.com/api/v1` |
| `test-partner.mamsaa.com` | لوحة الشريك | `https://staging.mamsaa.com` |
| `test-admin.mamsaa.com` | لوحة الأدمن | `https://staging.mamsaa.com` |

---

## 0. الوضع الحالي — متحقَّق منه اليوم

### DNS: التلاتة موجودين وشغّالين

```
test.mamsaa.com          → 8995d04ee7c87923.vercel-dns-017.com
test-partner.mamsaa.com  → cbe3287b289e8c4c.vercel-dns-017.com
test-admin.mamsaa.com    → 9efef334eff5061f.vercel-dns-017.com
www.mamsaa.com           → 8995d04ee7c87923.vercel-dns-017.com     ← لاحظ
```

### 🔴 `test.mamsaa.com` و`www.mamsaa.com` على **نفس مشروع Vercel**

الـ CNAME واحد بالحرف: `8995d04ee7c87923`. على Vercel، تطابق الهاش ده معناه **مشروع واحد** — يعني `test.mamsaa.com` مش بنش، ده **نطاق تاني للإنتاج**.

**النتيجة العملية:** أي متغير بيئة تغيّروه هناك بيغيّر **الإنتاج**. وأي حجز يتعمل على `test.mamsaa.com` النهاردة هو **حجز إنتاج حقيقي** بمفاتيح دفع حقيقية.

> **قبل أي إعداد: `test.mamsaa.com` لازم يتفصل لمشروع Vercel مستقل.** التفاصيل في القسم ٢. النطاقين التانيين مشاريع مستقلة فعلاً (هاشات مختلفة) وجاهزين للإعداد.

### التلاتة محميين بـ Vercel SSO

```
https://test.mamsaa.com/  →  302  →  vercel.com/login?next=/sso-api…
```

يعني Deployment Protection مفعّلة. **ده كويس لبنش** — بيمنع أي زائر عادي من الوصول — بس معناه:

- لازم تكونوا مسجّلين دخول على Vercel بنفس الحساب عشان تفتحوا النطاق
- أي فحص آلي (curl / Playwright / uptime) هيقابل صفحة تسجيل الدخول مش التطبيق
- لو عايزين وصول برمجي، Vercel بيوفر تجاوز بـ token — بس **ما تقفلوش الحماية**؛ الحماية هي اللي بتمنع حد يعمل حجز على بنش

### جهة الباك اند — **جاهزة، مفيش شغل مطلوب**

الأصول التلاتة في قائمة CORS على staging بالفعل، ومتحقَّق منها بطلب حقيقي:

```
Origin: https://test.mamsaa.com          → access-control-allow-origin: https://test.mamsaa.com
Origin: https://test-partner.mamsaa.com  → access-control-allow-origin: https://test-partner.mamsaa.com
Origin: https://test-admin.mamsaa.com    → access-control-allow-origin: https://test-admin.mamsaa.com
```

والكوكيز على staging مضبوطة للاستخدام عبر النطاقات: `SESSION_SAME_SITE=none` و`SESSION_SECURE_COOKIE=true` و`CORS_SUPPORTS_CREDENTIALS=true`.

---

## 1. الإعداد لكل مشروع

### الخطوات في لوحة Vercel

لكل مشروع من التلاتة:

```
Project → Settings → Environment Variables
```

**١. اختاروا البيئة الصح.** لو النطاق مربوط بـ Production في المشروع ده، المتغير لازم يتحط على **Production** — مش Preview. حطّه على Preview وهو مربوط بـ Production معناه إنه مش هيتطبق، والبناء هيطلع بالقيمة القديمة من غير أي خطأ.

**٢. اضبطوا القيم:**

| المشروع | المتغير | القيمة |
|---|---|---|
| تطبيق الضيف | `NEXT_PUBLIC_API_BASE_URL` | `https://staging.mamsaa.com/api/v1` |
| | `NEXT_PUBLIC_USE_MOCK` | `false` |
| | `NEXT_PUBLIC_SITE_URL` | `https://test.mamsaa.com` |
| لوحة الشريك | `NEXT_PUBLIC_API_BASE_URL` | `https://staging.mamsaa.com` |
| | `NEXT_PUBLIC_USE_MOCK` | `false` |
| | `NEXT_PUBLIC_ENABLE_BANK_DETAILS` | `true` |
| لوحة الأدمن | `NEXT_PUBLIC_API_BASE_URL` | `https://staging.mamsaa.com` |
| | `NEXT_PUBLIC_USE_MOCK` | `false` |

⚠️ **تطبيق الضيف وحده هو اللي بياخد `/api/v1`.** لوحتي الشريك والأدمن على **الجذر** — وAPI الأدمن تحت `/admin/*`، بس البادئة دي جزء من **المسار** مش من القاعدة، والعميل بيضيف القاعدة لمسارات بتبدأ بـ `/admin` أصلاً.

**٣. أعيدوا النشر — إجباري.**

`NEXT_PUBLIC_*` **بتتحقن وقت البناء، مش وقت التشغيل.** تغيير المتغير من غير إعادة نشر مابيعملش أي حاجة — الموقع بيفضل شغّال بالقيمة القديمة المدفونة في الـ bundle، من غير أي رسالة خطأ.

```
Deployments → آخر deployment → ⋯ → Redeploy
```

🔴 **وشيلوا علامة "Use existing Build Cache"** — الكاش بيعيد استخدام الـ bundle المبني بالقيمة القديمة، فالنشر بينجح والقيمة ما تتغيرش. ده أكتر سبب لـ«غيّرنا المتغير وما اتغيرش حاجة».

---

## 2. فصل `test.mamsaa.com` عن الإنتاج

قبل أي إعداد عليه. الطريقتين:

**(أ) مشروع جديد — الأنضف**

```
1. Vercel → Add New → Project → نفس مستودع الفرونت
2. الفرع: staging   (مش main)
3. اضبطوا متغيرات البيئة من الجدول فوق
4. Settings → Domains → أضيفوا test.mamsaa.com
5. من مشروع الإنتاج: Settings → Domains → احذفوا test.mamsaa.com
```

الترتيب مهم: **أضيفوا للمشروع الجديد الأول**، وبعدين احذفوا من القديم — عشان ما يبقاش فيه فترة النطاق فيها مش مربوط بحاجة.

**(ب) تحويله لـ Preview على نفس المشروع** — أسرع، بس بيخلي بنش الضيف مربوط بدورة حياة نشر الإنتاج. **مش موصى بيه** لأن نفس الإعدادات بتفضل مشتركة، وهو أصل المشكلة.

---

## 3. التحقق — إزاي تتأكدوا إنه اشتغل فعلاً

**ما تعتمدوش على «النشر نجح».** البناء بينجح بالقيمة القديمة من غير أي شكوى.

### الفحص القاطع

افتحوا النطاق (وأنتم مسجّلين على Vercel)، ومن **Network tab** في المتصفح شوفوا نداءات الـ API رايحة على أنهي host:

```
✅ صح:   staging.mamsaa.com
❌ غلط:  api.mamsaa.com          ← لسه على الإنتاج
```

### فحص أسرع — من البيانات نفسها

الوحدات على staging غير الوحدات على الإنتاج:

```
staging.mamsaa.com/api/v1/units?per_page=1   →  id=2   code=NFPIFIKO
api.mamsaa.com/api/v1/units?per_page=1       →  id=37  code=MRNL3B3X
```

لو أول وحدة على البنش كودها `NFPIFIKO` يبقى على staging. لو `MRNL3B3X` يبقى لسه على الإنتاج — والمتغير ما اتطبقش.

**ده الفحص الوحيد اللي ما بيتخدعش**: قراءة الإعداد بتقول إيه المفروض، وقراءة البيانات بتقول إيه اللي بيحصل.

### علامة سطح غلط

لو لوحة الشريك أو الأدمن اتضبطت بالغلط على `/api/v1`:

```
GET https://staging.mamsaa.com/api/v1/me        → 404
GET https://staging.mamsaa.com/api/v1/admin/me  → 404
```

**404 مش 401.** المسارات ببساطة مش موجودة هناك، فكل النداءات بتفشل بنفس الشكل سواء كنت مسجّل دخول أو لأ — ومفيش رسالة بتقولك إن القاعدة غلط.

---

## 4. الدخول على البنشات

بيانات staging، مش الإنتاج:

| البنش | الهاتف | كود OTP |
|---|---|---|
| الشريك | `+966500000002` | `273638` |
| الضيف | `+966599000001` | `273638` |
| الأدمن | حسابات superadmin على staging | `273638` |

مفيش رسايل SMS بتتبعت — staging على `SMS_DRIVER=log`.

**بيانات جاهزة للتجربة على staging:**

```
الشكوى #1 على الحجز 83   →  مكتملة: استرداد مُسوّى وخصم شريك 391.30
الحجز #86 للضيف #10      →  completed وجوّه نافذة الشكوى (تقفل 09/09 الساعة 12:00)
```

---

## 5. قائمة التنفيذ

```
□ افصل test.mamsaa.com لمشروع Vercel مستقل        ← قبل أي حاجة
□ اضبط متغيرات البيئة للمشاريع التلاتة (البيئة الصح)
□ أعد النشر بدون Build Cache
□ تحقق من الـ Network tab: staging.mamsaa.com
□ تحقق من كود أول وحدة: NFPIFIKO
□ سجّل دخول على كل بنش وتأكد إن البيانات بيانات staging
□ اترك Deployment Protection مفعّلة
```

---

## ٦. الخطر اللي يستاهل التكرار

طول ما `test.mamsaa.com` على نفس مشروع `www.mamsaa.com`:

- بيقدّم **بناء الإنتاج**
- بيتكلم مع **API الإنتاج**
- بيستخدم **مفاتيح دفع حقيقية**
- وأي حجز عليه هو **حجز إنتاج**، بفلوس حقيقية وإشعارات حقيقية للشركاء

**ما تستخدموش `test.mamsaa.com` كبنش لحد ما يتفصل.** للتجربة دلوقتي، البنشات المحلية على `localhost:3000/3001/3002` موصولة بـ staging فعلاً وآمنة تماماً.
