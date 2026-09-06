# تشغيل لوحة الشريك محلياً على staging

**التاريخ:** 07/09/2026
**المستودع:** `vego-group/mamsa-partner-dashboard` — فرع `staging`
**الهدف:** لوحة الشريك شغّالة على `http://localhost:3001` وموصولة ببيانات staging الحقيقية
**مُجرَّب:** الخطوات دي اتنفذت كاملة والنتائج تحت حقيقية مش متوقعة

---

## 1. الإعداد — أربع خطوات

```bash
git clone git@github.com:vego-group/mamsa-partner-dashboard.git
cd mamsa-partner-dashboard
git checkout staging
npm install
```

`.env.local` في جذر المشروع:

```bash
# تشغيل محلي على staging.
# قاعدة الجذر — من غير /api/v1 (الـ API بتاع اللوحة root-mounted).
NEXT_PUBLIC_USE_MOCK=false
NEXT_PUBLIC_API_BASE_URL=https://staging.mamsaa.com
NEXT_PUBLIC_ENABLE_BANK_DETAILS=true
```

```bash
npm run dev -- -p 3001
```

**المنفذ 3001 مش اختياري.** staging بيسمح بـ `http://localhost:3001` في `CORS_ALLOWED_ORIGINS`؛ أي منفذ تاني هيترفض.

---

## 2. ⚠️ أهم سطر في الملف: من غير `/api/v1`

الباك اند فيه **تلات أسطح** على نفس النطاق:

| السطح | القاعدة | المصادقة |
|---|---|---|
| تطبيق الضيف | `staging.mamsaa.com/api/v1` | Bearer / Sanctum |
| **لوحة الشريك** | **`staging.mamsaa.com` — الجذر** | **كوكي جلسة** |
| لوحة الأدمن | `staging.mamsaa.com/admin` | كوكي جلسة |

لو حطيت `/api/v1` في `NEXT_PUBLIC_API_BASE_URL`، **كل نداء هيرجع 404** — مش 401 ولا خطأ واضح. المسارات ببساطة مش موجودة هناك.

### إزاي تتأكد إنك على السطح الصح

شغّل ده بعد ما السيرفر يقوم:

```bash
curl -s http://localhost:3001/api/proxy/me
```

**المطلوب:**
```json
{"error":{"code":"UNAUTHENTICATED","message":"يرجى تسجيل الدخول"}}
```

ده مغلّف API لوحة الشريك نفسه. **لو شفت `{"message":"Unauthenticated."}`** فده رد لارافيل الافتراضي — يعني القاعدة غلط وأنت بتضرب على `/api/v1`.

الاتنين بيرجّعوا `401`، فالكود لوحده مش دليل. **الجسم هو الدليل.**

---

## 3. بروكسي التطوير — إزاي بيشتغل

الكود مش بينادي `staging.mamsaa.com` من المتصفح مباشرة. في وضع التطوير كل النداءات بتروح لـ:

```
/api/proxy/[...path]   →   https://staging.mamsaa.com/[...path]
```

وده مقصود: البروكسي بيعيد كتابة `Origin` و`Referer` لنطاق staging (الباك اند بيتحقق من الأصل في عمليات التعديل)، وبيعدّل كوكيز الجلسة عشان تشتغل على `localhost` — بيشيل `Domain=` و`Secure` وبيحوّل `SameSite=None` لـ `Lax`.

**يعني:** أي مسار في العقد بتناديه كـ `/api/proxy/<path>` محلياً، و`<path>` مباشرة في الإنتاج. الكود بيتصرف في الفرق ده لوحده — `USE_PROXY = process.env.NODE_ENV === "development"`.

---

## 4. الدخول — بيانات staging

```
الهاتف:  +966500000002        ← شريك فردي (اسمه: محمد الشريك الفردي)
كود OTP: 273638               ← ثابت على staging (OTP_FIXED_CODE)
```

مفيش رسالة SMS بتتبعت — staging بيستخدم `SMS_DRIVER=log`.

حسابات تانية لو احتجتها:

| الهاتف | الحساب |
|---|---|
| `+966500000002` | شريك فردي — **فيه بيانات شكاوى، استخدمه** |
| `+966500000003` | شركة الأفق للعقارات |
| `+966512345678` | شريك تجريبي للوحة |

---

## 5. 🎯 حالة شكوى كاملة جاهزة للبناء عليها

الشريك `+966500000002` (معرّف `4`) عنده **شكوى مرّت بالدورة كاملة** — مش قايمة فاضية:

```
الشكوى #1  ·  الحجز 83
الحالة       resolved_refunded
الاسترداد    500.00 ريال — مُسوّى فعلاً
خصم الشريك   391.30 ريال   ← ده اللي بيظهر في deductedHalalas
قيد الليدجر  refund_reversal  −391.30  "خصم بسبب شكوى على الحجز 83"
```

يعني `GET /me/complaints` هيرجّع صف حقيقي، و`/me/complaints/1` هيرجّع `deductedHalalas: 39130` مش `null`.

**دي الحالة اللي الشاشة محتاجة تتصمم عليها** — القايمة الفاضية بتخفي كل الأسئلة الصعبة.

راجع `MAMSA-CONTRACT-partner-dashboard-complaints.md` للعقد الكامل.

---

## 6. التحقق — النتائج دي حقيقية من التشغيل

```
✓ Ready in 8.9s

/            200
/overview    200
/login       200

/api/proxy/me                          401  ← {"error":{"code":"UNAUTHENTICATED",…}}
/api/proxy/overview                    401
/api/proxy/notifications/unread-count  401
/api/proxy/me/complaints               401  ← مسار الشكاوى الجديد، حيّ
```

الـ `401` هنا **علامة صحة** قبل الدخول: يعني وصلت للـ API الصح وهو رفض لأنك مش مسجّل.

---

## 7. حاجات هتقابلك

### أول تحميل بطيء

`✓ Compiled / in 15.2s`. ده تجميع عند الطلب في وضع التطوير، مش تعليق. لو الصفحة بانت واقفة أول مرة، استنى دقيقة.

### ⚠️ باج معروف في البروكسي — رد 204 بيرجع 500

`src/app/api/proxy/[...path]/route.ts:73`:

```ts
return new Response(responseBody, { status: res.status, … });
```

الجسم بيتمرر دايماً، و`204` حالة **بلا جسم** — فـ `new Response(body, {status: 204})` بترمي:

```
TypeError: Response constructor: Invalid response status code 204
```

**أي رد 204 من الباك اند بيتحول لـ 500 في التطوير المحلي بس.** مش بيأثر على الإنتاج (البروكسي وضع تطوير فقط).

الإصلاح سطر واحد لو قابلتوه:

```ts
const body = [204, 205, 304].includes(res.status) ? null : responseBody;
return new Response(body, { status: res.status, headers: responseHeaders });
```

سايبينه لكم لأنه في مستودعكم مش بتاعنا.

### الكوكيز مش بتتخزن

اتأكد إنك على `http://localhost:3001` بالظبط — مش `127.0.0.1:3001`. الاتنين أصلين مختلفين عند المتصفح، و`127.0.0.1` مش في قائمة CORS.

---

## 8. المتطلبات

```
Node 20.x        (مُجرَّب على v20.20.2)
Next.js 14.2.35  (من package.json)
```

---

## 9. ملخص سريع

```bash
git clone git@github.com:vego-group/mamsa-partner-dashboard.git
cd mamsa-partner-dashboard && git checkout staging && npm install

cat > .env.local <<'ENV'
NEXT_PUBLIC_USE_MOCK=false
NEXT_PUBLIC_API_BASE_URL=https://staging.mamsaa.com
NEXT_PUBLIC_ENABLE_BANK_DETAILS=true
ENV

npm run dev -- -p 3001

# تحقق:
curl -s http://localhost:3001/api/proxy/me
# لازم يطلع: {"error":{"code":"UNAUTHENTICATED","message":"يرجى تسجيل الدخول"}}

# ادخل بـ  +966500000002  /  273638
```
