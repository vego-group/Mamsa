# للتنفيذ عندكم — أسماء الشقق في الكروت · أخطاء الكروت · ترتيب القايمة · مهلة الـrunner

**التاريخ:** 01/10/2026
**لمين:** فريق Next.js — لوحة الشريك (ولوحة الأدمن في §٣)
**الباك اند:** ✅ **staging** و✅ **الإنتاج** — تاج `prod-2026-10-01-units`

> **على الإنتاج:** تسمية الشقق منشورة، بس `MULTI_UNIT_ENABLED=false` — فإضافة الشقق مقفولة هناك لحد
> ما العلم يتفتح. **ترتيب القايمة شغّال على الإنتاج من دلوقتي.**

---

## ٠. الملخّص — اللي محتاج يتنفّذ

| # | إيه | فين | إلزامي؟ |
|---|---|---|---|
| ١ | حقل `apartmentNo` في الكارت = **اسم الباب الجديد** (رقم أو نص) | ويزارد الشريك + الأدمن | ✅ |
| ٢ | توجيه أخطاء الكارت للكارت الصح (`permits.{i}.*`) | نفس الأماكن | ✅ |
| ٣ | فحص التكرار على الواجهة قبل الإرسال | نفس الأماكن | مُستحسن |
| ٤ | رسالة تأكيد «هتتنسخ من بيانات الشقة X» | زرار «إضافة شقق» | مُستحسن |
| ٥ | ترتيب الأبواب بـ`apartmentNo` (ترتيب طبيعي) | قايمة الوحدات | ✅ (لو مش موجود) |
| ٦ | إظهار/إخفاء إضافة الشقق حسب `multiUnitEnabled` | كارت المبنى | ✅ |
| ٧ | مهلة الـtest runner ≥ ٣٠ ثانية | الاختبارات | ✅ |

---

## ١. `apartmentNo` في الكارت = **اسم الباب الجديد**

### المعنى الجديد

**قبل:** الأبواب الجديدة كانت بتاخد أرقام تلقائية، و`apartmentNo` في الكارت كان **مفتاح مطابقة** —
`"7"` جنب أبواب `2` و`3` كان بيرجّع `PERMITS_COUNT_MISMATCH`.

**دلوقتي:** `apartmentNo` **بيسمّي الباب اللي الكارت جايبه.** أي نص: `"7"` · `"402"` · `"الدور الثالث"`.

| الطلب | الأبواب |
|---|---|
| وحدة مستقلة · `count: 3` · كارت من غير اسم + كارت `"7"` | `1` · `2` · `7` |
| مبنى فيه `1` و`2` · `count: 4` · كارت `"7"` + كارت من غير اسم | `1` · `2` · `3` · `7` |
| كارت `"الدور الثالث"` | باب اسمه `الدور الثالث` |

**القواعد:**
- **اختياري.** الكارت من غير اسم بياخد رقم تلقائي.
- **الأرقام التلقائية بتكمّل من المبنى، مش من الاسم** — `"7"` مابيخلّيش الباب اللي بعده `"8"`.
- **نص ≤ ٢٠ حرف.** المسافات حوالين الاسم بتتشال (`" 7 "` = `"7"`).
- **ماينفعش اسم موجود في المبنى، ولا اسمين متكررين في نفس الطلب.**

### الحقل في الكارت

```tsx
// في كل كارت تصريح (وضع أ)
<TextField
  name={`permits.${i}.apartmentNo`}
  label="رقم / اسم الشقة"
  placeholder="اختياري — مثلاً 402 أو الدور الثالث"
  maxLength={20}
  help="لو سبته فاضي، الشقة هتاخد رقم تلقائي"
/>
```

```ts
// في الطلب: ابعتوه زي ما الشريك كتبه، أو ماتبعتوهوش لو فاضي
const permits = cards.map(c => ({
  number: c.number,
  fileId: c.fileId,
  ...(c.expiresAt && { expiresAt: c.expiresAt }),
  ...(c.apartmentNo?.trim() && { apartmentNo: c.apartmentNo.trim() }),
}))
```

---

## ٢. توجيه أخطاء الكارت — **للكارت الصح**

### الأخطاء الممكنة على الكارت

| الخطأ | الرد | الكارت |
|---|---|---|
| **اسم موجود في المبنى** | `400 VALIDATION` · `fields["permits.{i}.apartmentNo"]` | رقم `{i}` |
| **اسمين متكررين** | `400 VALIDATION` · `fields["permits.{i}.apartmentNo"]` على **التاني** | رقم `{i}` |
| الملف | `400 VALIDATION` · `fields["permits.{i}.fileId"]` | رقم `{i}` |
| هجري من غير ميلادي | `400 VALIDATION` · `fields["permits.{i}.expiresAtHijri"]` | رقم `{i}` |
| رقم تصريح مكرر | `422 DUPLICATE_PERMIT_NUMBER` · `meta.permit_number` | **دوّروا بالرقم** |
| **تاريخ منتهي** | `400 VALIDATION` · `fields.permitExpiresAt` — **من غير رقم** | **دوّروا بالتاريخ بتوقيت الرياض** |
| عدد الكروت ≠ الأبواب الجديدة | `422 PERMITS_COUNT_MISMATCH` · `meta.requested` / `meta.permits_provided` | الفورم كله |

**على الأدمن:** نفس المفاتيح، بس `422 VALIDATION_ERROR` و`fields` في أول الرد (من غير `error.`).

### دالّة واحدة بتوجّه كل الأخطاء

```ts
type CardErrors = Record<number, Partial<Record<'apartmentNo'|'fileId'|'expiresAt'|'expiresAtHijri'|'number', string>>>

const todayRiyadh = () =>
  new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Riyadh' }).format(new Date()) // "YYYY-MM-DD"

export function mapCardErrors(err: ApiError, cards: Card[]): { cards: CardErrors; form?: string } {
  const out: CardErrors = {}
  const put = (i: number, field: keyof CardErrors[number], msg: string) =>
    ((out[i] ??= {})[field] = msg)

  const fields = err.fields ?? {}   // لوحة الشريك: error.fields · الأدمن: fields

  for (const [key, msg] of Object.entries(fields)) {
    // ١. مفاتيح فيها رقم الكارت: permits.{i}.{field}
    const m = key.match(/^permits\.(\d+)\.(\w+)$/)
    if (m) { put(Number(m[1]), m[2] as any, msg); continue }

    // ٢. التاريخ المنتهي — من غير رقم: كل كارت تاريخه قبل النهارده بتوقيت الرياض
    if (key === 'permitExpiresAt') {
      const today = todayRiyadh()
      cards.forEach((c, i) => { if (c.expiresAt && c.expiresAt < today) put(i, 'expiresAt', msg) })
    }
  }

  // ٣. رقم تصريح مكرر — دوّروا بالرقم نفسه، بنفس توحيد السيرفر
  if (err.code === 'DUPLICATE_PERMIT_NUMBER' && err.meta?.permit_number) {
    const n = String(err.meta.permit_number)          // السيرفر بيرجّعه موحّد أصلاً
    cards.forEach((c, i) => {
      if (normalizePermitNumber(c.number) === n) put(i, 'number', err.message)
    })
  }

  // ٤. خطأ على الفورم كله
  const form = err.code === 'PERMITS_COUNT_MISMATCH' ? err.message : undefined

  return { cards: out, form }
}
```

### توحيد رقم التصريح — **نفس السيرفر بالظبط**

`meta.permit_number` بيرجع **موحّد**. عشان تلاقوا الكارت، وحّدوا رقم الكارت بنفس القواعد اللي في
`PermitNumber::normalize()` على السيرفر:

```ts
// مطابق لـ PermitNumber::normalize() — متقري من الكود، مش متخمّن
export function normalizePermitNumber(raw?: string | null): string | null {
  if (raw == null) return null
  const v = raw
    .replace(/[٠-٩]/g, d => String(d.charCodeAt(0) - 0x0660))   // أرقام عربية-هندية
    .replace(/[۰-۹]/g, d => String(d.charCodeAt(0) - 0x06F0))   // أرقام فارسية
    .replace(/[\s\u00A0\u200B\u200C\u200D\uFEFF,،]+/g, '')       // مسافات (حتى الخفية) وفواصل
    .toUpperCase()
  return v === '' ? null : v
}
// " ٧٧٧ " → "777" · "tl-demo-8units" → "TL-DEMO-8UNITS" · الشرطات بتفضل
```

---

## ٣. فحص التكرار قبل الإرسال — **مُستحسن**

السيرفر بيرفض، بس الأحسن الشريك يشوف الخطأ وهو بيكتب:

```ts
export function apartmentNoClientErrors(cards: Card[], existingDoorNames: string[]): Record<number, string> {
  const errors: Record<number, string> = {}
  const taken = new Set(existingDoorNames.map(n => n.trim()))
  const seen = new Set<string>()

  cards.forEach((c, i) => {
    const name = c.apartmentNo?.trim()
    if (!name) return
    if (taken.has(name))      errors[i] = `رقم الشقة ${name} موجود بالفعل في المبنى`
    else if (seen.has(name))  errors[i] = `رقم الشقة ${name} مكرر في نفس الطلب`
    seen.add(name)
  })
  return errors
}
```

**`existingDoorNames`:** الـ`apartmentNo` بتاع كل الأبواب اللي ليها نفس `groupId` من `GET /units`.
(وحدة مستقلة لسه ماتحوّلتش لمبنى = مافيش أسماء موجودة.)

**نفس الرسايل اللي السيرفر بيرجّعها** — فالشريك يشوف نفس الكلام في الحالتين.

---

## ٤. رسالة التأكيد على «إضافة شقق» — **مُستحسن**

**الشقق الجديدة بتتنسخ من الباب المفتوح** (السعر، الوصف، الصور، المميزات…) — مش من أول باب في المبنى.

```tsx
<ConfirmDialog
  title="إضافة شقق"
  body={`الشقق الجديدة هتتنسخ من بيانات الشقة ${unit.apartmentNo ?? ''} (السعر، الوصف، الصور، المميزات). تقدر تعدّل كل شقة بعدين.`}
/>
```

**ابعتوا `id` الباب المفتوح** — أي باب في المبنى مقبول.

---

## ٥. ترتيب القايمة — **رتّبوا بـ`apartmentNo` بنفسكم**

### اللي اتغيّر على السيرفر

`GET /units` بقى ترتيبه **مضمون: الأحدث أولاً، وعند التعادل بالـ`id` تنازلي.** الترقيم (pagination)
مابقاش بيكرر صف ولا بيسقطه.

### ومع ذلك: ماتبنوش على ترتيب الرد أي حاجة

ترتيب الرد ثابت، **بس مش ترتيب الأبواب.** وبما إن الأسماء بقت نص حر، **الترتيب لازم يكون طبيعي**
(`2` قبل `10`، والأرقام قبل النص):

```ts
const collator = new Intl.Collator('ar', { numeric: true, sensitivity: 'base' })

const buildings = Object.groupBy(units, u => u.groupId ?? `solo:${u.id}`)

for (const key in buildings) {
  buildings[key]!.sort((a, b) => collator.compare(a.apartmentNo ?? '', b.apartmentNo ?? ''))
}
// "1", "2", "3", "7", "10", "402", "الدور الثالث"
```

> **ليه `numeric: true`:** من غيرها الترتيب بيبقى نصّي — `"10"` قبل `"2"`، و`"402"` قبل `"7"`.

---

## ٦. إظهار إضافة الشقق حسب العلم

**على الإنتاج المسار مقفول** (`MULTI_UNIT_ENABLED=false`) — أي `count` أكبر من ١ بيرجّع
`422 MULTI_UNIT_DISABLED`. **ماتعرضوش الزرار** بدل ما الشريك يضغط ويترفض:

```ts
// مرة واحدة عند الإقلاع — من غير مصادقة
const { flags } = await fetch(`${DASHBOARD_API}/config`).then(r => r.json())

const canAddApartments = flags.multiUnitEnabled   // staging: true · الإنتاج: false دلوقتي
```

يوم ما العلم يتفتح على الإنتاج، الزرار هيظهر لوحده — من غير نشر عندكم.

---

## ٧. مهلة الـtest runner — **٣٠ ثانية على الأقل**

قسنا: **الاتصال بالسيرفر ماوقعش ولا مرة** (حتى بـ٢٠ طلب في نفس الوقت)، **بس الرد وصل لـ٥–٨ ثواني**
تحت الضغط — وده على endpoint خفيف. الـrunners اللي مهلتها ٥ ثواني بتقطع الطلب وهو مستني على السيرفر.

```ts
// Jest
jest.setTimeout(30_000)

// Playwright
test.setTimeout(60_000)
const api = await request.newContext({ baseURL, timeout: 30_000 })

// fetch / axios
axios.create({ baseURL, timeout: 30_000 })
```

**وقلّلوا التوازي** في الجولات اللي بتكتب (إنشاء مباني، رفع ملفات).

**لو الانقطاع فضل بمهلة ٣٠ ثانية**، ابعتولنا: الوقت، ونوع الخطأ بالظبط (`ETIMEDOUT` / `ECONNRESET` /
DNS / timeout من الـrunner)، والمهلة والتوازي، وهل بيحصل على الإنتاج.

---

## ٨. قايمة الاختبار — على staging

**الدخول:** حساب الشريك الاختباري `555000002` بالرمز الثابت (في الشات، **مش في أي ملف**).
**استخدموا رقم تصريح جديد في كل تشغيل** — رقم الوحدة المتمسحة بيفضل محجوز (عيب معروف، هيتصلح قبل الإطلاق).

| # | السيناريو | المتوقّع |
|---|---|---|
| ١ | مسوّدة · `count: 3` · كارت من غير اسم + كارت `"7"` | `200` · أبواب `1 · 2 · 7` · الكارت `"7"` على الباب `7` |
| ٢ | مبنى فيه `1` و`2` · `count: 4` · كارت `"7"` + كارت من غير اسم | `200` · `1 · 2 · 3 · 7` |
| ٣ | كارت `"الدور الثالث"` | `200` · باب بالاسم ده |
| ٤ | كارت باسم باب موجود (`"2"`) | `400` · `fields["permits.0.apartmentNo"]` على الكارت ده · **ولا باب اتعمل** |
| ٥ | كارتين بنفس الاسم (`"7"` و`" 7 "`) | `400` · الخطأ على **الكارت التاني** |
| ٦ | فحص الواجهة (§٣) قبل الإرسال لـ٤ و٥ | الخطأ يظهر **من غير نداء** |
| ٧ | `GET /units?limit=2` على كل الصفحات مرتين | **نفس الوحدات بنفس الترتيب** · من غير تكرار ولا سقوط |
| ٨ | ترتيب الأبواب في كارت المبنى | `1, 2, 3, 7, 10, 402, الدور الثالث` |
| ٩ | `flags.multiUnitEnabled = false` (محاكاة) | زرار إضافة الشقق **مش ظاهر** |

**ماتلمسوش:** `u_88` / `u_89` / `u_90` (وحدة اختبار من الباك اند)، ورقم وحدة `25` (`TL-TEST-0002-B`).
**ماتستخدموش `50047139` لاختبار التكرار** — مستثنى من الفحص.

---

## ٩. المرجع في العقد

| الموضوع | المكان |
|---|---|
| `apartmentNo` = اسم الباب | الملحق **§١.٤** |
| أي باب ينفع يضيف شقق · النسخ من الباب المفتوح | الملحق **§١.٣** |
| التاريخ المنتهي من غير رقم كارت | الملحق **§١.٢** |
| ترتيب `GET /units` | ملف المراحل ١–٣ **§٢.١** |
| `GET /config` والأعلام | دليل البناء **§٩** |
