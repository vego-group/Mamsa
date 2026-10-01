# قبل النشرة الموحّدة — اللي يخصّ الواجهات · 27/09/2026

**الكود:** `ffe2157` · **staging:** ✅ كل حاجة تحت · **الإنتاج:** ❌ لسه على المراحل ١–٣

**البروفة اتعملت النهارده على نسخة مسترجعة من الإنتاج، والتلاتة عشر فحصاً عدّوا.**
تقرير البروفة كامل في `REPORT-rehearsal-2026-09-27.md` — بس ده ملف عمليات. الملف اللي في إيدكم
دلوقتي فيه **اللي يخصّكم إنتوا** بس.

**مافيش حاجة اتنشرت على الإنتاج.** مستنيين ردكم على تقرير البروفة.

---

## ٠. اقروا ده الأول — تغيير واحد بيأثر على لوحة الأدمن

```
POST /admin/units/{id}/apartments      بقى ورا MULTI_UNIT_ENABLED
```

كان **مستثنى** من العَلَم، وبقى داخل جوّاه. المالك طلب ده يوم 27/09، والسبب إن مسار كتابة
مفتوح مافيش واجهة بتناديه هو مفتاح محدش هيلاقيه لما يحتاج يقفله.

**اللي بيحصل على الإنتاج بعد النشرة** (`MULTI_UNIT_ENABLED=false` وهيفضل كده):

```jsonc
POST /admin/units/35/apartments  { "count": 2, "permits": [...] }
→ 422
{ "code": "MULTI_UNIT_DISABLED", "message": "إضافة أكثر من وحدة غير مفعّلة حالياً" }
```

مُختبر في البروفة على بيانات الإنتاج الحقيقية: الرد 422، والوحدات فضلت ٣، ومافيش مجموعة اتعملت.

**اللي لازم تعملوه:** لوحة الأدمن تقرا العَلَم من `GET /admin/config` (§٣) وتخفي/تقفل خطوة «عدد
الشقق» لما يكون `false` — بدل ما الأدمن يملّي فورم كامل ويتقاله لأ. **ماتبنوش على ترتيب الأخطاء**:
الحارس بيجي بعد التحقق من الحقول، فالمستخدم اللي معاه ملف غلط هيشوف `VALIDATION_ERROR` قبل
`MULTI_UNIT_DISABLED`. العَلَم من `/config` هو الجواب، مش شكل الخطأ.

**الاستثناء الوحيد اللي فضل:** مراجع بيعتمد شقة **موجودة بالفعل** — ده شغّال دايماً، والعَلَم
ما بيمسّهوش. يعني شاشة الاعتماد ما تتغيّرش.

---

## ١. التأكيدات الأربعة اللي طلبتوها — بدليل من تشغيل حقيقي

مش من الكود ولا من الاختبارات. ده اللي طلع فعلاً.

### ١.١ `permit_expiring` — `unit_id` + `body` + `href` ✅

خلّينا تصريح على staging ينتهي بعد **٣٠ يوم بالظبط** (عتبة حقيقية: 60/30/14/7/1 ويوم الانتهاء)،
وشغّلنا الجوب. الصف اللي اتكتب في `notifications`، حرف بحرف:

```json
{
  "type": "permit_expiring",
  "permit_id": 38,
  "threshold": 30,
  "expires_at": "2026-10-27",
  "unit_id": 68,
  "unit_name": "مبنى وضع أ — خطوة واحدة",
  "title": "تصريح وحدتك \"مبنى وضع أ — خطوة واحدة\" ينتهي خلال 30 يوم",
  "body": "لا يمكن استقبال حجوزات تنتهي إقامتها بعد 2026-10-27.",
  "href": "/units/68/permit/renew"
}
```

ارسموا `title` عنوان و`body` تفصيل و`href` زرار. و`href` بيبقى **`null`** في إشعار قديم مش شايل
`unit_id` — موجود كمفتاح وقيمته فاضية، فاختبروا الحالة دي.

### ١.٢ `apartment_no` في `booking.unit` ✅ — بقيمة حقيقية

حجزنا على وحدة **جوّه مبنى** (الشقة «1» في مجموعة) على staging:

```
POST /api/v1/bookings     → 201
GET  /api/v1/bookings/88  → 200

booking.unit = { "id": 57, "apartment_no": "1" }
```

وفي نفس اللحظة، القائمة العامة:

```
GET /api/v1/units  →  المفتاح apartment_no مش موجود أصلاً   ·   عدد المفاتيح 33
```

**المفتاح في رد الحجز، وغايب من القائمة.** وعلى وحدة **مستقلة** المفتاح موجود وقيمته `null` —
اتأكدنا من ده على نسخة الإنتاج. يعني:

| | `apartment_no` |
|---|---|
| `GET /api/v1/bookings/{id}` على مبنى | `"1"` |
| `GET /api/v1/bookings/{id}` على وحدة مستقلة | `null` (المفتاح موجود) |
| `GET /api/v1/units` و`/units/{id}` | **المفتاح غايب** |

حجز التأكيد اتمسح بعد القراءة.

### ١.٣ `permitAddress` في كتابة الوحدة — ✅ اللوحتين

**لوحة الأدمن** (على نسخة الإنتاج، الوحدة 35):

```jsonc
PATCH /admin/units/35
{ "tourismLicenseNumber": "50047139", "permitAddressCity": "الرياض",
  "permitAddressDistrict": "النرجس", "permitExpiresAt": "2027-12-31" }
→ 200
permitAddress   = { "city": "الرياض", "district": "النرجس", "building": "7", "unitNo": null }
permitExpiresAt = "2027-12-31"      permitStatus = "valid"
```

**لوحة الشريك** (على staging، الوحدة 68):

```jsonc
PATCH /units/u_68
{ "permitAddressCity": "الرياض", "permitAddressDistrict": "النرجس",
  "permitAddressBuilding": "12", "permitAddressUnitNo": "1", "permitExpiresAt": "2028-01-31" }
→ 200
permitAddress = { "city": "الرياض", "district": "النرجس", "building": "12", "unitNo": "1" }
```

**مفاتيح مسطّحة عند الكتابة، مجمّعة عند القراءة.** كلهم `sometimes` + `nullable`: الغايب
ما بيتغيّرش، و`null` بيمسح.

#### 🔴 حاجة لقيناها وإحنا بنجرّب — لازمة في الشاشة

وحدة حالتها **`pending`** بترفض أي تعديل من الشريك:

```
PATCH /units/u_68  (والوحدة قيد المراجعة)  →  409  { "code": "UNIT_LOCKED" }
```

مش سلوك جديد، بس مش مكتوب في عقد سابق. **اقفلوا زرار الحفظ والوحدة `pending`** بدل ما الشريك
يملّي الفورم ويتقاله 409.

### ١.٤ شكل رد `/config` — الشكل النهائي ✅

**من نسخة الإنتاج نفسها**، التلات مسارات، من غير مصادقة، رد متطابق حرف بحرف:

```json
{
  "flags": {
    "multiUnitEnabled": false,
    "permitExpiryRequired": false,
    "legacyUnitWritesEnabled": false
  },
  "permitWarningDays": 30
}
```

**الشكل مستقر:** مفتاحان على المستوى الأول، وتلات أعلام جوّه `flags`. أي عَلَم جديد بيتزاد
**جوّه `flags`** ومش بيغيّر الشكل — فاقروا بالمفتاح، مش بالترتيب.

---

## ٢. القيم اللي هتشوفوها في كل بيئة

| | staging | الإنتاج (قبل وبعد النشرة) |
|---|---|---|
| `multiUnitEnabled` | **`true`** | **`false`** — وهيفضل |
| `permitExpiryRequired` | `false` | `false` — وهيفضل |
| `legacyUnitWritesEnabled` | `false` | `false` |
| `permitWarningDays` | `30` | `30` |

يعني **UI وضع أ يتبني وينتشر وهو مخفي على الإنتاج**، ويفتح بقلب العَلَم على السيرفر — من غير
build منكم. ده الغرض من `/config` من الأول.

⚠️ `/config` نفسه **لسه مش منشور على الإنتاج** — هييجي مع النشرة الموحّدة. لحد ساعتها نداءه على
الإنتاج بيرجّع 404، فخلّوا القيمة الاحتياطية (`NEXT_PUBLIC_*`) شغّالة لما النداء يفشل.

---

## ٣. إزاي تقروا الأعلام

```ts
// مرة واحدة عند إقلاع التطبيق. من غير توكن.
const FALLBACK = {
  multiUnitEnabled: process.env.NEXT_PUBLIC_MULTI_UNIT_ENABLED === 'true',
  permitExpiryRequired: process.env.NEXT_PUBLIC_PERMIT_EXPIRY_REQUIRED === 'true',
  legacyUnitWritesEnabled: false,
}

async function loadConfig() {
  try {
    const r = await fetch(`${API}/config`, { cache: 'no-store' })
    if (!r.ok) return { flags: FALLBACK, permitWarningDays: 30 }   // 404 على الإنتاج حالياً
    return await r.json()
  } catch {
    return { flags: FALLBACK, permitWarningDays: 30 }              // عَلَم مقفول أأمن من شاشة فاضية
  }
}
```

| التطبيق | المسار |
|---|---|
| الضيف | `GET /api/v1/config` |
| لوحة الشريك | `GET /config` |
| لوحة الأدمن | `GET /admin/config` |

**حطّوه في context/store وما تنادوهش في كل شاشة.** و`permitWarningDays` استعملوه بدل ما تكتبوا
`30` في تلات تطبيقات — ده **نفس الرقم** اللي الجوب في ١.١ اشتغل بيه، وتثبيته عندكم هو إزاي
الاتنين بيختلفوا.

---

## ٤. تفرقة لازم تتوضّح — `group_size` مش `group.size`

الاتنين رقم، والاتنين اسمهم قريب، وبيجاوبوا سؤالين مختلفين:

| | بيعدّ إيه | فين |
|---|---|---|
| `group_size` | الأبواب **المعتمدة والمتاحة** — القابلة للبيع | رد الضيف العام |
| `available_count` | منها، اللي **فاضي** في التواريخ المطلوبة | رد الضيف العام |
| `group.size` | **كل** الأبواب بأي حالة (معتمد/مرفوض/قيد المراجعة) | `GET /admin/approvals/{id}` |

مبنى فيه ٨ أبواب، ٥ معتمدين و٣ قيد المراجعة → الضيف بيشوف `group_size: 5` والأدمن بيشوف
`size: 8`. **ده مش تضارب.** «٤ من ٦» على الكارت معناها ٤ فاضيين من ٦ معروضين.

---

## ٥. فخّان في الأخطاء

**١ — `VALIDATION` رقمه 400 على سطح الشريك، و`VALIDATION_ERROR` رقمه 422 على سطح الأدمن.**
التوقّع الغلط ده بيخلّي أخطاء الحقول تظهر كـ«خطأ غير متوقع».

```
/units/*   →  400  { "error": { "code": "VALIDATION", "fields": {...} } }
/admin/*   →  422  { "code": "VALIDATION_ERROR", "fields": {...} }
```

**٢ — مفاتيح `fields` في التوسيع فيها رقم الكارت:**

```json
{ "fields": { "permits.1.fileId": "ملف التصريح مطلوب لكل وحدة" } }
```

وزّعوها على **الكارت الصح** (`permits[1]`)، مش على الفورم كله.

---

## ٦. اللي مش بيتغيّر عندكم بعد النشرة

- كل عقود المراحل ١–٣ زي ما هي — مافيش مفتاح اتشال ولا اتغيّر معناه.
- `MULTI_UNIT_ENABLED` و`PERMIT_EXPIRY_REQUIRED` **يفضلوا `false` على الإنتاج**، فـUI وضع أ
  وحقل تاريخ الانتهاء الإلزامي **مش هيظهروا** لحد قلب العَلَم.
- شاشة الاعتماد ما اتغيّرتش في سلوكها، بس بقى فيها `permit` و`addressMatch` و`group` (الملحق §٢).
- **عدد مفاتيح الرد العام ٣٣** (كان ٣٢، زاد بـ`group_size`) — لو عندكم تأكيد على العدد، حدّثوه.

---

## ٧. مسارات هتظهر على الإنتاج بعد النشرة

المسارات **٢٣٣ → ٢٥٠**. الجداد اللي تخصّكم:

```
GET   /api/v1/config                          الضيف
GET   /config                                 لوحة الشريك
GET   /admin/config                           لوحة الأدمن

POST  /api/v1/bookings/{booking}/complaint     الضيف — تقديم شكوى
GET   /api/v1/bookings/{booking}/complaint     الضيف — شكوى حجز
GET   /api/v1/user/complaints                  الضيف — قائمة شكاويه
GET   /me/complaints · /me/complaints/{id}     لوحة الشريك
GET   /complaints/attachments/{attachment}     مرفق موقَّع

GET   /admin/complaints · /admin/complaints/{id}
PATCH /admin/complaints/{id}/status · /admin/complaints/{id}/approval
POST  /admin/complaints/{id}/approve · /reject · /refund
POST  /admin/units/{id}/apartments             ← ورا العَلَم دلوقتي (§٠)
```

عقود الشكاوى عندكم من قبل: `MAMSA-CONTRACT-guest-app-complaints.md` و
`MAMSA-CONTRACT-partner-dashboard-complaints.md` و`MAMSA-ADMIN-DASHBOARD-PROMPT-complaints.md`.
مافيش حاجة اتغيّرت فيهم.

---

## ٨. الملفات المرفقة

| الملف | إيه فيه |
|---|---|
| `permits-frontend-contract-phases-1-3.md` | **عقد الإنتاج** — مرفق تاني، من غير تغيير في محتواه (بعتناه معاه يوم 23/09 كمان) |
| `permits-frontend-contract-phases-4-6.md` | عقد وضع أ + المرحلة ٦ (**staging**) |
| `MAMSA-NEXTJS-BUILD-permits.md` | دليل البناء — §٨ وضع أ (شكل الفورم لكل شقة) و§٩ الأعلام |
| `REPORT-rehearsal-2026-09-27.md` | تقرير البروفة (عمليات — مش لازم عليكم) |
| `PLAN-production-full-release.md` | خطة النشر (عمليات) |

---

## ٩. اللي محتاجينه منكم

**ولا حاجة توقفكم.** بس لو ينفع:

1. **تأكيد إن لوحة الأدمن هتقرا `/config`** وتخفي خطوة العدد لما `multiUnitEnabled: false` —
   دي الحاجة الوحيدة اللي سلوكها بيتغيّر عليكم (§٠).
2. **تأكيد إن الشاشة بتقفل الحفظ على وحدة `pending`** (§١.٣) — أو نبعتلكم الحالات اللي بترجّع
   `UNIT_LOCKED` كلها لو محتاجينها.
3. لو عندكم اختبار بيثبّت **٣٢ مفتاح** في الرد العام، حدّثوه لـ**٣٣**.
