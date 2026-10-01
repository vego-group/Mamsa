# الملفين متبعتين كاملين · لوج الوصول: **لسه ما اشتغلش** · تحذير ICU: لسه في اللوج بس

**التاريخ:** 01/10/2026
**لمين:** فريق Next.js — لوحة الشريك ولوحة الأدمن
**المرفقات: ٢** — `permits-frontend-contract-phases-1-3.md` و`permits-frontend-contract-phases-4-6.md`، **ملصوقين كاملين في نفس الملف ده تحت الرد (مرفق ١ ومرفق ٢)**. (الرد ده نفسه مش محسوب ضمن المرفقات.)

---

## ١. الملفات — آسفين، دي غلطتنا

إنتو محقّين: للمرة التالتة نشاور على ملف ما وصلش. القاعدة عندنا من النهارده إن **الملف ما يتحسبش واصل غير لما محتواه يتلصق في الرسالة**، ومسار في الـrepo أو على جهاز حد مش تسليم. وقبل ما نبعت أي رد بنعدّ المرفقات ونكتب عددها في أول الرد، زي ما هو مكتوب فوق.

| الملف | الأقسام اللي طلبتوها | السطور | md5 |
|---|---|---|---|
| `permits-frontend-contract-phases-1-3.md` | §2.1 و§9 | 790 | `ba261313a0031f89b08fb53899eb1762` |
| `permits-frontend-contract-phases-4-6.md` | §1.4 و§1.5 و§1.6 | 704 | `b09fdd8654cb5af06c3e8cccab6690e2` |

دي نفس النسخة اللي اتعمل عليها النشر على الإنتاج (`e732b02` / `prod-2026-10-01-doors`)، **وما اتغيّرش فيها حاجة من ساعتها**. لو الـmd5 عندكم طلع مختلف بعد ما تحفظوا الملف، يبقى في حاجة اتقطعت في السكة، فقولولنا.

---

## ٢. لوج الوصول على staging — **لأ، لسه ما اشتغلش**

ده الرد المباشر على «قولوا اتعمل ولا لأ»: **ما اتعملش لسه، لا على staging ولا على الإنتاج.**

**اللي اتعمل لحد دلوقتي:** الكود اتكتب ومحطوط على جهازنا بس، ولسه ما اتعملّوش commit ولا اختبار ولا نشر. هو عبارة عن middleware بيكتب سطر JSON لكل طلب يوصل للتطبيق:

```json
{"t":"2026-10-01T23:05:12.345Z","ip":"…","m":"GET","path":"/dashboard/units","status":200,"ms":84,"route":"…","uid":12,"ua":"…"}
```

يعني فيه الـIP والوقت (UTC بالملّي ثانية) والمدة وكود الرد، وهي الحاجات اللي طلبتوها، ومعاهم المسار والمستخدم. **ما بيسجّلش** الـquery string ولا الـbody ولا الـheaders، وبيتمسح بعد 14 يوم لأن فيه IPs.

**اللي فاضل بالترتيب:**
1. نكتب الاختبارات ونشغّل الـsuite كامل.
2. ننشره على **staging** ونفعّله هناك (`ACCESS_LOG_ENABLED=true`)، وبعدين نتأكد بطلب حقيقي إن السطر اتكتب.
3. **الإنتاج:** الكود جاهز يتنشر، بس **التشغيل هناك محتاج موافقة صريحة من المالك**. «لو ينفع» مش كفاية عندنا لأي حاجة تتعمل على الإنتاج. هنطلبها أول ما staging يتأكد.

هنبعتلكم سطر «اتعمل» **بعد** ما نشوف السطر مكتوب على السيرفر، مش قبلها.

**⚠️ حدّ لازم تعرفوه:** اللوج ده بيشوف **الطلبات اللي بتوصل لـPHP بس**. لو حصل تاني `CONNECT_TIMEOUT` زي اللي حصل في 23:05–23:11، الطلب مش هيبان في اللوج خالص. بس ساعتها **غياب السطر هو نفسه الدليل**: يبقى الطلب وقع قبل ما يوصل للتطبيق (شبكة أو استضافة)، مش في الكود. والاستضافة (Hostinger shared) ما بتديناش access log بتاع السيرفر على الديسك.

---

## ٣. تحذير ICU — موافقين، وهو **لسه في اللوج بس**

إنتو صح: التحذير اللي حطّيناه بيتكتب في `laravel.log`، ومحدش بيفتحه. **دلوقتي مش في مكان حد بيقراه.**

**اللي ناويين نعمله** (لسه ما اتبناش):
- فحص يومي مجدول بيتأكد إن السيرفر عنده ترتيب عربي (`ar`) مش `root`.
- لو مش موجود، **بيبعت إيميل** لمستلمين تنبيهات العمليات، بنفس قناة تنبيهات الشكاوى وتراخيص المباني اللي شغّالة فعلاً.
- التحذير اللي في اللوج هيفضل موجود جنبه.

**الحالة النهارده:** الإنتاج وstaging الاتنين بيحمّلوا `ar` (ICU 64.2)، يعني الترتيب عندنا هو هو اللي عندكم. التنبيه ده غرضه بس إن أي تحديث للسيرفر يشيل بيانات العربي **ما يعدّيش من غير ما حد ياخد باله**.

واختباركم لبيانات ICU العربي في Node هو نفس الفكرة من ناحيتكم، وده كويس: كده لو أي طرف اتكسر هنعرف.

---

## ٤. مطلوب منكم

- **عدّوا المرفقات**: لازم يكونوا **٢**، وطابقوا الـmd5. لو ناقص حاجة قولوا فوراً.
- مافيش أي تغيير في العقد محتاج تنفيذ من ناحيتكم.


---
---

# مرفق ١ من ٢ — `permits-frontend-contract-phases-1-3.md`

# عقد التصاريح للواجهات — المراحل ١ و٢ و٣

**مكتوب من الكود المنشور على الإنتاج:** `prod-2026-09-27`
**الأمثلة:** طلبات حقيقية على staging والإنتاج (نفس كود التصاريح بالظبط)
**تاريخ:** 22/09/2026 · **آخر تحديث 01/10/2026** (الإنتاج: `prod-2026-10-01-doors`)

> 📌 **الملف ده هو الأساس.** المراحل ٤ و٦ اتنشرت على الإنتاج يوم 27/09، وعقدها في
> [`permits-frontend-contract-phases-4-6.md`](permits-frontend-contract-phases-4-6.md).
> **اقروا الاتنين** — الملف ده بيوصف المسارات والحقول الأساسية، والملحق بيوصف اللي اتزاد فوقها.

---

## ✅ اقرأوا ده الأول — الأربع بنود دي **اتنفّذت وبقت حيّة على الإنتاج**

الجدول ده كان مكتوب «❌ مش منفّذة» لحد 26/09. **اتنفّذوا كلهم ونزلوا الإنتاج يوم 27/09.**
سايبينه هنا عشان اللي قرا النسخة القديمة يعرف إن الحالة اتغيّرت:

| البند | الحالة دلوقتي على الإنتاج |
|---|---|
| `permit.address` و`addressMatch` في `GET /admin/approvals/{id}` | ✅ **موجودين** — التفصيل في §٢ من الملحق. و`addressMatch.district` **دايماً `null` عمداً** |
| كائن `group` في `GET /admin/approvals/{id}` | ✅ **موجود** · `null` للوحدة المستقلة · §٢.٣ في الملحق |
| `group_size` في القائمة العامة | ✅ **موجود** · **اقروا تعريفه في §٤.١ من الملحق** — مش نفس `group.size` بتاع الأدمن |
| `tourismLicenseNumber` كمفتاح إضافي في قراءة الأدمن (A2) | ✅ **موجود** — الاتنين بيتبعتوا، نفس القيمة · §٧ في الملحق |

**وكمان اتنفّذ:** `unit_id` + `body` + `href` في إشعار `permit_expiring` (§٥ تحت) ·
`apartment_no` في `booking.unit` (§٣.٤ تحت) · `permitAddress` قابل للكتابة على الوحدة ·
`POST /units/{id}/submit` بياخد `{count, permits}` · و`GET /config` على الأسطح التلاتة.

كل اللي تحت **متحقَّق من الكود ومجرَّب بطلب حقيقي**.

---

## ٠. الأغلفة — لكل سطح غلافه

| السطح | نجاح | خطأ |
|---|---|---|
| `/admin/*` | الكائن مباشرة، أو `{ items, total, page, pageSize, sortBy, sortDir }` | `{ message, code, fields?, meta? }` |
| `/units/*` (الشريك) | الكائن مباشرة، أو `{ data, meta }` | `{ error: { code, message, fields?, meta? } }` |
| `/api/v1/*` (الضيف) | `{ success, data }` أو `{ data }` | `{ success: false, message, code, meta? }` |

**الكود واحد عبر الأسطح، الغلاف بتاع السطح.** يعني `BOOKING_EXCEEDS_PERMIT_VALIDITY` نفسه بيطلع بتلات أشكال حسب السطح.

---

## ١. لوحة الأدمن — `/admin/...`

### ١.١ `GET /admin/units/{id}` — حقول جديدة

**الصلاحية:** `units.view`

الحقول المضافة في المراحل ١–٣ (الباقي زي ما هو):

```json
{
  "id": "24",
  "code": "MRN9B6RN",
  "name": "شقة تجريبية — معتمدة (غير مُدرجة للعملاء)",
  "status": "approved",
  "mamsaOwned": false,
  "partnerId": "33",
  "partnerName": "شريك تجريبي",

  "tourismPermitNo": "TL-TEST-0001",
  "permitFileUrl": null,
  "licenseType": null,
  "licensedUnitsCount": null,
  "groupSize": 1,

  "permitExpiresAt": "2026-10-22",
  "permitStatus": "expiring",
  "pendingRenewalId": null
}
```

| الحقل | النوع | ملاحظة |
|---|---|---|
| `permitExpiresAt` | `YYYY-MM-DD` أو `null` | `null` = مفيش تاريخ مسجّل = **مفيش سقف على التقويم** |
| `permitStatus` | `valid` \| `expiring` \| `expired` \| `unknown` | القاعدة في القسم ٤ |
| `pendingRenewalId` | `string` أو `null` | لو مش `null`: فيه طلب تجديد في الطابور. **مهم للشاشة**: وحدة `permitStatus: "expired"` ومعاها `pendingRenewalId` يعني الإعلان هادي **بس حد اتصرّف بالفعل**، والعلاج في طابور المراجع نفسه |
| `permitFileUrl` | رابط `/documents` موقّع، أو `null` | صلاحيته ساعتان |

### ١.٢ `PATCH /admin/units/{id}` — كتابة حقول التصريح

**الصلاحية:** `units.manage`

```json
{
  "tourismLicenseNumber": "TL-2026-0044",   // اختياري · نص ≤ 50
  "tourismLicenseFileId": "file_01m…",      // اختياري · رفعة مخزّنة من نوع license_pdf
  "licenseType": "tourist_facility",        // اختياري · tourist_facility | private_hospitality | null
  "licensedUnitsCount": 8,                  // اختياري · 1..100 · إلزامي مع tourist_facility
  "permitExpiresAt": "2027-10-22"           // اختياري · YYYY-MM-DD ميلادي بالظبط
}
```

**⚠️ `permitExpiresAt` ميلادي.** التصاريح مطبوعة هجري — **التحويل شغل الواجهة**، الباك اند بياخد ميلادي بس وبيرفض أي صيغة تانية بـ`VALIDATION_ERROR`.

**كل الحقول الخمسة بتروح لكاتب التصاريح**، يعني في مبنى بتصريح واحد **بتتكتب على كل شقق المبنى**، مش على الصف اللي اتنده بس.

### ١.٣ أخطاء الكتابة على سطح الأدمن

```jsonc
// 422 — الزوج غير متسق
{ "message": "تصريح المرفق السياحي يتطلب عدد الوحدات المرخّصة",
  "code": "LICENSED_UNITS_COUNT_REQUIRED" }

// 422 — عدد على تصريح خاص
{ "message": "تصريح الضيافة الخاصة يغطي وحدة واحدة فقط",
  "code": "LICENSED_UNITS_COUNT_NOT_APPLICABLE",
  "meta": { "max_licensed_units_count": 1 } }

// 422 — تخفيض مبنى قائم
{ "message": "هذا الإعلان مبنى متعدد الوحدات، ولا يمكن تحويله إلى تصريح ضيافة خاصة. تواصل مع الدعم لتعديل عدد وحدات المبنى.",
  "code": "LICENSE_DOWNGRADE_BLOCKED_BY_QUANTITY",
  "meta": { "group_size": 3, "shrink_supported": false } }

// 422 — العدد المرخّص أقل من الشقق الموجودة
{ "message": "عدد الوحدات الحالي أكبر من العدد المرخّص",
  "code": "QUANTITY_EXCEEDS_LICENSED_UNITS",
  "meta": { "group_size": 5, "licensed_units_count": 4 } }

// 422 — صيغة
{ "message": "…", "code": "VALIDATION_ERROR", "fields": { "permitExpiresAt": "…" } }
```

### ١.٤ `GET /admin/permits?status=` — اللي محتاج انتباه

**الصلاحية:** `units.view`
**الباراميترات:** `status=expiring` (الافتراضي) \| `expired` \| `valid` · `page` · `pageSize` (≤100) · `sortBy=expiresAt` · `sortDir`

- `expiring` = التاريخ من النهارده لحد النهارده + **٣٠ يوم**
- `expired` = التاريخ قبل النهارده
- `valid` = بعد الـ٣٠ يوم
- **التصاريح بدون تاريخ (`NULL`) مش في أي قائمة من التلاتة** — مفيش تاريخ يعني مفيش حاجة تتراقب

```json
{
  "items": [
    {
      "id": "30",
      "status": "current",
      "scope": "unit",
      "unitId": "24",
      "unitName": "شقة تجريبية — معتمدة (غير مُدرجة للعملاء)",
      "partnerName": "شريك تجريبي",
      "mamsaOwned": false,
      "unitsCovered": 1,
      "tourismPermitNo": "TL-TEST-0001",
      "permitFileUrl": null,
      "licenseType": null,
      "licensedUnitsCount": null,
      "permitExpiresAt": "2026-10-22",
      "permitStatus": "expiring",
      "permitAddress": {
        "city": "الرياض",
        "district": "النرجس",
        "building": "12",
        "unitNo": "3"
      },
      "listingAddress": {
        "city": "الرياض",
        "district": "العليا",
        "address": "حي العليا، الرياض",
        "lat": 24.7136,
        "lng": 46.6753
      },
      "submittedAt": "2026-09-22T15:41:02Z",
      "reviewedAt": null,
      "reviewedBy": null,
      "createdBy": 33,
      "rejectionReason": null
    }
  ],
  "total": 1, "page": 1, "pageSize": 10, "sortBy": null, "sortDir": null
}
```

| حقل | معناه |
|---|---|
| `scope` | `unit` = التصريح على وحدة واحدة · `group` = على مبنى كامل |
| `unitsCovered` | كام وحدة التصريح ده بيغطيها (1 للمستقلة، حجم المجموعة للمبنى) |
| `unitId` / `unitName` | **الوحدة الأولى** اللي التصريح يغطيها — في مبنى دي وحدة واحدة من كذا |
| `permitAddress` | العنوان المكتوب **على الورقة** — كل حقوله ممكن تكون `null` |
| `listingAddress` | عنوان **الإعلان** — دي المقارنة اللي المراجع بيعملها بعينه |

**✅ `addressMatch` بقى موجود** — بس في `GET /admin/approvals/{id}` (§٢ في الملحق)، **مش هنا**.
وهو بيقارن **المدينة بس**؛ الحي بيفضل بعين المراجع. في الشاشة دي المقارنة لسه يدوية.

### ١.٥ `GET /admin/permit-renewals` — طابور التجديدات

**الصلاحية:** `approvals.view`
**الباراميترات:** `status=pending` (الافتراضي) \| `current` \| `rejected` \| `superseded` · `page` · `pageSize` · `sortBy=submittedAt`

نفس شكل الصف اللي فوق، **وزيادة `currentPermit`**:

```json
{
  "items": [{
    "id": "31",
    "status": "pending",
    "scope": "unit",
    "unitId": "24",
    "unitName": "شقة تجريبية — معتمدة (غير مُدرجة للعملاء)",
    "partnerName": "شريك تجريبي",
    "mamsaOwned": false,
    "unitsCovered": 1,
    "tourismPermitNo": "777",
    "permitFileUrl": null,
    "licenseType": null,
    "licensedUnitsCount": null,
    "permitExpiresAt": "2027-10-22",
    "permitStatus": "expiring",
    "permitAddress": { "city": "الرياض", "district": "النرجس", "building": "12", "unitNo": "3" },
    "listingAddress": { "city": "الرياض", "district": "العليا", "address": "حي العليا، الرياض", "lat": 24.7136, "lng": 46.6753 },
    "submittedAt": "2026-09-22T15:49:38Z",
    "reviewedAt": null,
    "reviewedBy": null,
    "createdBy": 33,
    "rejectionReason": null,
    "currentPermit": {
      "id": "30",
      "tourismPermitNo": "TL-TEST-0001",
      "permitExpiresAt": "2026-10-22"
    }
  }],
  "total": 1, "page": 1, "pageSize": 10, "sortBy": null, "sortDir": null
}
```

**`permitStatus` هنا محسوب على الوحدة** (يعني على تصريحها الحالي)، مش على صف التجديد. يعني `"expiring"` هنا معناها «تصريح الوحدة الحالي قرب ينتهي» — وده سبب وجود طلب التجديد.

**`currentPermit`** هو اللي هيتبدّل. المراجع بيقارن `permitExpiresAt` بتاع الطلب مع `currentPermit.permitExpiresAt`.

### ١.٦ `POST /admin/permit-renewals/{id}/approve`

**الصلاحية:** `approvals.manage` · **Body:** فاضي

```json
// 200
{ "id": "32", "status": "current", "scope": "unit", "unitId": "24",
  "unitName": "…", "unitsCovered": 1,
  "tourismPermitNo": "TL-TEST-0001",
  "permitExpiresAt": "2027-10-22",
  "permitStatus": "valid",
  "reviewedBy": 29, "reviewedAt": "2026-09-22T15:52:10Z",
  "permitAddress": {…}, "listingAddress": {…}, "createdBy": 33, "rejectionReason": null }
```

**اللي بيحصل:** التصريح القديم `superseded`، الجديد `current`، الأعمدة بتتنسخ على **كل** وحدات النطاق، وسقف التقويم بيتمد — **كله في transaction واحدة**. و`approval_status` بتاع الوحدة **ما بيتغيرش**، والشريك بياخد إشعار `UnitReviewResult`.

### ١.٧ `POST /admin/permit-renewals/{id}/reject`

**الصلاحية:** `approvals.manage`

```json
{ "reason": "الملف غير واضح، أعد رفعه بجودة أعلى",   // إلزامي · ≤ 500 · الشريك بيشوفه
  "notes": "ملاحظة داخلية للمراجع" }                 // اختياري · ≤ 1000 · داخلي، الشريك ما بيشوفهوش
```

```json
// 200
{ "id": "31", "status": "rejected", "permitExpiresAt": "2027-10-22",
  "reviewedBy": 29, "reviewedAt": "2026-09-22T15:50:04Z",
  "rejectionReason": "الملف غير واضح، أعد رفعه بجودة أعلى", … }
```

**التصريح القديم بيفضل شغّال لتاريخه**، والشريك يقدر يقدّم تاني.

### ١.٨ أخطاء التجديد على سطح الأدمن

```jsonc
// 409 — قرار اتاخد قبل كده
{ "message": "طلب التجديد لم يعد قيد المراجعة", "code": "RENEWAL_NOT_PENDING" }

// 404
{ "message": "طلب التجديد غير موجود", "code": "NOT_FOUND" }

// 422 — رفض من غير سبب
{ "message": "يجب إدخال سبب الرفض", "code": "VALIDATION_ERROR",
  "fields": { "reason": "يجب إدخال سبب الرفض" } }

// 403 — صلاحية
{ "message": "…", "code": "INSUFFICIENT_PERMISSION" }
```

### ١.٩ `GET /admin/approvals/{id}` — اللي اتغيّر فيه

**ما اتغيّرش أي حقل في الرد نفسه.** الحقول الجديدة بتوصل جوّه `unit` لأنه نفس الـpresenter بتاع `GET /admin/units/{id}` — يعني `unit.permitExpiresAt` و`unit.permitStatus` و`unit.pendingRenewalId` و`unit.groupSize` موجودين.

**✅ اتزاد بعد كده (27/09):** `permit` (فيه `address` و`expiresAt` و`status`) · `addressMatch` ·
كائن `group` — **تلاتتهم على مستوى الرد، مش جوّه `unit`**. العقد الكامل في §٢ من الملحق.

---

## ٢. لوحة الشريك — `/units/...`

### ٢.١ `GET /units` و`GET /units/{id}` — حقول جديدة

الرد كامل لـ`GET /units/u_24` (من staging، مختصر في الصور بس):

```json
{
  "id": "u_24",
  "code": "MRN9B6RN",
  "name": "شقة تجريبية — معتمدة (غير مُدرجة للعملاء)",
  "type": "apartment",
  "status": "approved",
  "pricePerNight": 480,
  "cancellationPolicy": "moderate",
  "bedrooms": 2, "beds": null, "capacity": 4, "bathrooms": 2,
  "rating": null, "reviewsCount": 0,
  "city": "riyadh", "district": "العليا",
  "description": "## عن الوحدة\n…",
  "amenities": ["wifi", "kitchen", "parking", "ac"],
  "checkIn": "15:00", "checkOut": "12:00",
  "lat": 24.7136, "lng": 46.6753,
  "address": "حي العليا، الرياض",

  "tourismLicenseNumber": "TL-TEST-0001",
  "tourismLicenseFileId": null,
  "permitExpiresAt": "2026-10-22",
  "permitStatus": "expiring",
  "permitExpiresAtHijri": null,
  "permitAddress": { "city": null, "district": null, "building": null, "unitNo": null },
  "licenseType": null,
  "licensedUnitsCount": null,
  "groupSize": 1,

  "ownershipDocFileId": null,
  "photos": [ { "id": "file_01m…", "url": "https://…", "isCover": true, "width": 1280, "height": 720, "variants": {…} } ],
  "rejectionReason": null,
  "publicUrl": "https://…"
}
```

**الجديد:** `permitExpiresAt` · `permitStatus`. (`licenseType` و`licensedUnitsCount` و`groupSize` كانوا موجودين من قبل.)

> **تصحيح 30/09 على العينة:** اللقطة الأصلية اتاخدت قبل ما `permitAddress` يدخل الرد، فماكانش
> ظاهر فيها. **السطرين دول اتضافوا للعينة بإيدنا** عشان تعكس الرد الحالي — مش جزء من اللقطة:
> - `permitAddress` — **موجود دايماً، الأربع مفاتيح، كل واحد `null` لو مش متسجّل.** مابيتشالش.
>   مثبّت باختبار. التفاصيل §٣.٢ في الملحق.
> - `permitExpiresAtHijri` — 🆕 30/09، الهجري زي ما اتكتب، **موجود دايماً** و`null` لو مافيش.
>   §٣.١ في الملحق.

**✅ `groupId` و`apartmentNo` بقوا في الرد ده كمان** (الإنتاج 28/09) — جنب `groupSize`:

```json
{ "groupSize": 3, "groupId": "01M36C95Y28DDDCCMD0RPYJ8EF", "apartmentNo": "2" }
```

**الاتنين `null` للوحدة المستقلة** — مش مبنى من باب واحد. جمّعوا القائمة بـ`groupId`، ورتّبوا
جوّه المجموعة بـ`apartmentNo`.

> **ترتيب `GET /units` — تصحيح 01/10:** الصفوف بترجع **الأحدث الأول** (`created_at` تنازلي) — **مش**
> «بترتيب الإنشاء» زي ما كان مكتوب هنا. **والصفوف اللي اتعملت في نفس الثانية** (مبنى كامل) **بتترتب
> بالـ`id` تنازلي** — ترتيب ثابت، فالترقيم (pagination) مابيكررش صف ولا بيسقطه حتى لو حدود الصفحة
> وقعت جوّه مبنى. *(ده اتضاف 01/10 — قبله، الصفوف المتعادلة ماكانش ليها ترتيب ثابت.)*
>
> **الترتيب ده مضمون: الأحدث أولاً، وعند التعادل بالـ`id` تنازلي.** (staging والإنتاج من 01/10 — `prod-2026-10-01-units`)
>
> **ومع ذلك، ماتبنوش على ترتيب الرد أي حاجة:** جمّعوا بـ`groupId` ورتّبوا بـ`apartmentNo` بنفسكم.
> ترتيب الرد ثابت، بس مش ترتيب الأبواب.
>
> **«رتّبوا بـ`apartmentNo`» = القاعدة الوحيدة في الملحق §١.٦** (قرار 01/10): فاضي أولاً ← اللي كله
> أرقام تصاعدي بقيمته ← الباقي بالترتيب العربي ← التعادل بالـ`id`. والباك اند بيطبّقها هو كمان في ردوده.

### ٢.٢ `PATCH /units/{id}` — كتابة حقول التصريح

نفس الحقول الخمسة بتاعة الأدمن بالظبط (`tourismLicenseNumber`, `tourismLicenseFileId`, `licenseType`, `licensedUnitsCount`, `permitExpiresAt`).

```json
// 200 — الرد هو الوحدة كاملة بشكلها فوق
{ "id": "u_24", "status": "pending", "tourismLicenseNumber": "TL-TEST-0001",
  "permitExpiresAt": "2026-10-22", "permitStatus": "expiring", … }
```

**🔴 لاحظوا `status` في المثال ده بقى `pending` رغم إن اللي اتبعت حقول تصريح بس.** ده القسم ٥ — أي تعديل على وحدة `approved` بيرجّعها للمراجعة.

**الأخطاء** — نفس أكواد ١.٣ بس بغلاف الشريك:

```jsonc
{ "error": { "code": "LICENSED_UNITS_COUNT_REQUIRED", "message": "…" } }
{ "error": { "code": "QUANTITY_EXCEEDS_LICENSED_UNITS", "message": "…",
             "meta": { "group_size": 5, "licensed_units_count": 4 } } }
// صيغة: 400 (مش 422) بغلاف الشريك
{ "error": { "code": "VALIDATION", "message": "…", "fields": { "permitExpiresAt": "…" } } }
// وحدة قيد المراجعة مقفولة للتعديل
{ "error": { "code": "UNIT_LOCKED", "message": "لا يمكن تعديل وحدة قيد المراجعة" } }   // 409
```

### ٢.٣ `POST /units/{id}/permit-renewals` — تقديم تجديد

```json
{
  "permitExpiresAt": "2027-10-22",          // ✅ إلزامي · YYYY-MM-DD · لازم في المستقبل
  "tourismLicenseNumber": " ٧٧٧ ",          // اختياري · بيتورّث من التصريح الحالي لو غاب
  "tourismLicenseFileId": "file_01m…",      // اختياري · بيتورّث لو غاب
  "permitAddress": {                        // اختياري، وكل حقوله اختيارية
    "city": "الرياض", "district": "النرجس", "building": "12", "unitNo": "3"
  }
}
```

```json
// 201
{
  "id": "31",
  "status": "pending",
  "permitExpiresAt": "2027-10-22",
  "tourismLicenseNumber": "777",
  "tourismLicenseFileId": null,
  "submittedAt": "2026-09-22T15:49:38Z",
  "reviewedAt": null,
  "rejectionReason": null
}
```

**` ٧٧٧ ` رجعت `"777"`** — الأرقام العربية/الهندية بتتحوّل لـASCII، والمسافات والفواصل بتتشال، والحروف بتبقى capital. الشرطات بتفضل (`TL-DEMO-8UNITS` رقم واحد).

**الإعلان ما بيقفش ولا بيرجع للمراجعة** — بيفضل يبيع على التصريح القديم لحد ما القرار يتاخد.

#### 🆕 `permitAddress` في التجديد — **بيتورّث حقل حقل** (موثّق ومختبَر 30/09)

| اللي اتبعت | العنوان في التجديد |
|---|---|
| `permitAddress` **غايب خالص** | **بيتورّث كله** من التصريح الحالي |
| **الأربع قيم `null`** | **بيتورّث كله** — `null` هنا معناها «زي ما هو»، **مش** «امسح» |
| `""` في أي حقل | نفس `null` — بيتورّث |
| **حقل واحد بقيمة** (`{"district": "الملقا"}`) | **الحقل ده بس بيتغيّر**، والتلاتة التانيين بيتورّثوا |

⚠️ **ده عكس `PATCH /units/{id}`**، اللي فيه `null` **بتمسح**. التجديد مصمّم إن الخانة الفاضية
ماتتقريش «امسح» — شريك سايب خانة فاضية في فورم التجديد مايخسرش عنوانه.

**فمافيش طريقة تمسح بيها العنوان من التجديد.** لو التصريح الجديد فعلاً مالوش مبنى مثلاً، الشريك
يمسحه بـ`PATCH /units/{id}` (`"permitAddressBuilding": null`) بعد ما التجديد يتوافق عليه.

**وملّي الفورم بالعنوان الحالي (زي ما بتعملوا) صح ومش ضروري في نفس الوقت** — لو بعتّوه زي ما هو
بيتكتب نفسه، ولو ماتبعتوش بيتورّث. الاتنين بيوصلوا لنفس النتيجة.

> **استثناء واحد في نفس الطلب:** `permitExpiresAtHijri` بـ`null` **بيمسح** (معناها «اتكتب ميلادي») —
> ده الحقل الوحيد في التجديد اللي `null` فيه إجابة مش غياب. الملحق §٣.١.

#### 🆕 وحدة **قيد المراجعة** وتصريحها انتهى — **التجديد شغّال** (موثّق ومختبَر 30/09)

| المسار | وحدة `pending` |
|---|---|
| `PATCH /units/{id}` (الويزارد) | ❌ **409 `UNIT_LOCKED`** |
| `POST /units/{id}/permit-renewals` | ✅ **201** — ومابيطلّعش الوحدة من المراجعة |

**`UNIT_LOCKED` على التعديل بس** — التجديد مالوش أي شرط على حالة الوحدة. فللوحدة الـ`pending`
**التجديد هو الطريق الوحيد**، وهو شغّال.

**للواجهة:** زرار التجديد لازم يظهر للوحدة الـ`pending` كمان، مش المعتمدة بس — على الأقل لما
`permitStatus` يبقى `expired` أو `expiring`. (للمسوّدة والمرفوضة: التعديل مش مقفول، فالشريك يقدر
يغيّر `permitExpiresAt` من الويزارد على طول.)

**عند المراجع (لوحة الأدمن):**
- `GET /admin/approvals/{id}` → `permit.status` هيقول **`expired`**، **والتجديد المستني في
  `unit.pendingRenewalId`** — مش جوّه `permit`. شاشة بتعرض `permit.status` بس هترفض شريك صلّح فعلاً.
- **التجديد والوحدة قرارين منفصلين**، وأي ترتيب يوصل:
  - **التجديد الأول** (`POST /admin/permit-renewals/{id}/approve`) ← الوحدة تفضل `pending` ← اعتماد
    الوحدة ← **بتبيع**.
  - **الوحدة الأول** ← `approved` **بس مابتبيعش** (اعتماد الوحدة مابيفحصش تاريخ التصريح؛ الانتهاء
    بيتحسب وقت البيع) ← اعتماد التجديد ← **بتبيع**.

### ٢.٤ `GET /units/{id}/permit-renewals` — سجل التجديدات

```json
[
  {
    "id": "31",
    "status": "pending",
    "permitExpiresAt": "2027-10-22",
    "tourismLicenseNumber": "777",
    "tourismLicenseFileId": null,
    "submittedAt": "2026-09-22T15:49:38Z",
    "reviewedAt": null,
    "rejectionReason": null
  }
]
```

مصفوفة مباشرة (مش مغلّفة)، الأحدث أولاً، وبتشمل `pending` و`rejected` و`superseded`. **`review_notes` مش في الرد ده إطلاقاً** — داخلية للمراجع.

### ٢.٥ أخطاء التجديد على سطح الشريك — كلها `422` بغلاف الشريك

```jsonc
{ "error": { "code": "NO_PERMIT_TO_RENEW",
             "message": "لا يوجد تصريح حالي لتجديده — أضف التصريح أولاً" } }

{ "error": { "code": "RENEWAL_ALREADY_PENDING",
             "message": "يوجد طلب تجديد قيد المراجعة بالفعل" } }

{ "error": { "code": "PERMIT_EXPIRED",
             "message": "تاريخ انتهاء التصريح الجديد في الماضي",
             "meta": { "permit_expires_at": "2020-01-01" } } }

{ "error": { "code": "PERMIT_EXPIRY_REQUIRED",
             "message": "تاريخ انتهاء التصريح الجديد مطلوب" } }
```

### ٢.٦ `POST /units/{id}/submit` — بوابة الإرسال

الرد عند الفشل **`400`** بكود `VALIDATION` (غلاف الشريك)، و`fields` فيها كل النواقص:

```json
{
  "error": {
    "code": "VALIDATION",
    "message": "بيانات غير مكتملة",
    "fields": {
      "beds": "عدد السراير مطلوب",
      "address": "العنوان مطلوب",
      "location": "الموقع يجب أن يكون داخل حدود المملكة",
      "tourismLicenseFileId": "ملف الرخصة مطلوب",
      "permitExpiresAt": "تصريح الوحدة منتهي — جدّده قبل الإرسال للمراجعة"
    }
  }
}
```

**حقل `permitExpiresAt` في `fields` بيظهر في حالتين:**

| الرسالة | إمتى |
|---|---|
| `تصريح الوحدة منتهي — جدّده قبل الإرسال للمراجعة` | التاريخ **فات** — **دايماً**، مهما كان العلم |
| `تاريخ انتهاء التصريح مطلوب` | مفيش تاريخ — **بس لما `PERMIT_EXPIRY_REQUIRED=true`** (القسم ٦) |

### ٢.٧ `POST /units/{id}/apartments` — `SOURCE_UNIT_INCOMPLETE` بشكله بالظبط

```json
// 422
{
  "error": {
    "code": "SOURCE_UNIT_INCOMPLETE",
    "message": "أكمل بيانات الوحدة الأصلية قبل إضافة وحدات إليها",
    "fields": {
      "beds": "عدد السراير مطلوب",
      "tourismLicenseFileId": "ملف الرخصة مطلوب"
    },
    "meta": { "unit_id": "u_24" }
  }
}
```

**`fields` هنا بمفاتيح الويزارد** (نفس مفاتيح `submit`)، و**`meta.unit_id` هو الباب اللي اتبعت في الطلب** بصيغة `u_{id}` — عشان الواجهة توجّه الشريك للإعلان اللي فعلاً ناقص، مش للشقق اللي لسه ما اتعملتش. *(تصحيح 30/09: كان مكتوب «الوحدة الأصل». أي باب في المبنى مقبول، والفحص بيتعمل على الباب المبعوت — الملحق §١.٣.)*

**الترتيب:** حارس التصريح بيشتغل **قبل** فحص الاكتمال. يعني وحدة غير مصنّفة بتاخد `MULTI_UNIT_REQUIRES_FACILITY_LICENSE` الأول، ولما تتصنّف تاخد `SOURCE_UNIT_INCOMPLETE` لو ناقصة.

---

## ٣. تطبيق الضيف — `/api/v1/...`

**⚠️ ولا حقل تصريح واحد بيوصل تطبيق الضيف.** لا رقم، لا تاريخ، لا حالة، لا عنوان تصريح. متحقَّق: `GET /api/v1/units/{id}` ما فيهوش `permitExpiresAt` ولا `permitStatus` ولا `tourism_permit_no`.

التصريح بيأثر على الضيف بطريقة واحدة بس: **الأيام اللي مش مسموح بيعها بتبقى مقفولة**.

### ٣.١ `GET /api/v1/units` — كارت القائمة

```json
{
  "id": 2,
  "name": "شقة مودرن بإطلالة على الواجهة",
  "available_count": 1,
  "price": 450,
  "owner": { "id": 4, "name": "محمد الشريك الفردي", "type": "individual", "is_verified": true, "avatar_url": null }
}
```

- **`available_count`**: عدد الشقق المتاحة في المبنى. **مبنى بيرجع كارت واحد** (ممثّل واحد)، و`available_count` هو اللي بيقول إنه مبنى.
  - بتواريخ: المتاح **في التواريخ دي**. بدون تواريخ: كل المتاح.
  - **✅ `group_size` بقى موجود** جنبه — فالكارت يقدر يقول «٤ من ٦». **اقروا تعريفه في §٤.١
    من الملحق**: بيعدّ الأبواب **القابلة للبيع**، مش كل الأبواب.
- **إعلان تصريحه انتهى مش في القائمة أصلاً**، وبتواريخ: إعلان إقامته تنتهي بعد تصريحه مش في القائمة **للتواريخ دي**.

### ٣.٢ `GET /api/v1/units/{id}` — **وبيقبل `listing_id` كمان**

- `200` عادي.
- ✅ **من 28/09 المسار ده وكل اللي تحته (`reviews` · `availability` · `blocked-dates`) بيقبلوا
  `listing_id` في مكان الـ`id`**، وبيرجّعوا **ممثّل المبنى**. ده الرابط الثابت للمبنى —
  التفصيل في §٤.١ج من الملحق.
- **`404`** `{"message":"الوحدة غير متاحة"}` لو التصريح انتهى — **نفس رد الوحدة غير المعتمدة بالظبط**، فالواجهة ما تحتاجش تفرّق.

### ٣.٣ `POST /api/v1/units/{id}/availability`

```json
// 409 — إقامة بعد انتهاء التصريح
{
  "success": false,
  "message": "تصريح هذه الوحدة لا يغطي هذه التواريخ",
  "code": "BOOKING_EXCEEDS_PERMIT_VALIDITY",
  "meta": { "permit_expires_at": "2026-10-02" }
}
```

**الـprobe بيتفق مع الـcreate دايماً** — لو الـprobe قال تمام، الحجز مش هيترفض بالسبب ده.

### ٣.٤ `POST /api/v1/bookings`

```json
// 409
{
  "success": false,
  "message": "تصريح هذه الوحدة لا يغطي هذه التواريخ",
  "code": "BOOKING_EXCEEDS_PERMIT_VALIDITY",
  "meta": { "permit_expires_at": "2026-10-02", "end_date": "2026-10-14" }
}
```

`409` — زي `UNIT_UNAVAILABLE` و`UNIT_BLOCKED` بالظبط، فالتعامل عندكم زي ما هو.

**عند النجاح — `201`، والشقة اللي اتخصصت فعلاً:**

```json
{
  "id": 87,
  "code": null,
  "status": "pending_payment",
  "start_date": "2026-09-24",
  "end_date": "2026-09-26",
  "nights": 2,
  "total_amount": 960,
  "unit": {
    "id": 12,
    "name": "…",
    "code": "MRNKURY7",
    "listing_id": "u12",
    "type": "apartment",
    "price": 480,
    "capacity": 4,
    "city": "…", "district": "…", "lat": …, "lng": …,
    "images": [...],
    "…"
  }
}
```

**🔴 تلات حاجات مهمة هنا:**

1. **`booking.unit.id` هي الشقة اللي اتخصصت**، وممكن **تختلف** عن `unit_id` اللي بعتّوه: في مبنى، الـid اللي في الكارت هو الممثّل، والسيرفر بيختار أول شقة فاضية ومرخّصة. **اعرضوا `booking.unit` من الرد، مش الكارت.**
2. **`unit_id` مش موجود في جذر الرد أصلاً** — **غايب، مش `null`**. (المثال فوق كان بيقول
   `null` وده كان غلط في التوثيق، اتصحّح 28/09.) استعملوا `booking.unit.id`.
   **ولربط الحجز بالإعلان استعملوا `booking.unit.listing_id`، مش الـ`id`** — الـid بيتغيّر
   مع كل حجز في المبنى، و`listing_id` واحد لكل شقق المبنى. شوف §٤.١ب في الملحق.
3. **✅ `booking.unit.apartment_no` بقى موجود** (27/09) — ومقفول على الرد ده وحده. في
   `GET /api/v1/units` و`/units/{id}` **المفتاح غايب أصلاً**، مش `null`. §٤.٢ في الملحق.

### ٣.٥ `GET /api/v1/units/{id}/blocked-dates`

```json
{
  "from": "2026-09-22",
  "to": "2026-11-01",
  "blocked": [
    { "start": "2026-10-02", "end": "2026-11-01", "reason": "permit_expiry" }
  ]
}
```

- **أول يوم مقفول هو يوم الانتهاء نفسه**، والمدى بيمتد لآخر النافذة المطلوبة.
- **`reason` موجود على مدى التصريح بس.** المديات التانية (حجوزات، إغلاق يدوي) **بترجع من غير `reason` إطلاقاً** — فتعاملوا معاه كـoptional.
- **التقويم بيقفل لوحده** من غير أي تعديل عندكم، لأنه نفس شكل أي مدى مقفول.

---

## ٤. `permitStatus` — القاعدة بالظبط

بتتحسب من تاريخ **التصريح الحالي** للوحدة، بتوقيت السيرفر، بمقارنة تواريخ (مش أوقات):

| القيمة | القاعدة |
|---|---|
| `unknown` | **مفيش تاريخ مسجّل** (`permitExpiresAt: null`) — ومعناها كمان **مفيش سقف على التقويم** |
| `expired` | التاريخ **قبل** النهارده |
| `expiring` | التاريخ بين النهارده والنهارده + **٣٠ يوم** (شامل الطرفين) |
| `valid` | بعد كده |

**٣٠ هي `PERMIT_WARNING_DAYS`**، وقيمتها ٣٠ على الاتنين staging والإنتاج.

**⚠️ «تصريح الباب بيكسب على تصريح المبنى»:** لو شقة جوّه مبنى عندها تصريح خاص بيها، **تصريحها هو اللي بيتحسب**، مش تصريح المبنى. يعني في نفس المبنى ممكن تلاقوا أبواب بـ`permitStatus` مختلفة.

---

## ٥. التذكيرات — الإشعار اللي البانر بيقرا منه

**المصدر:** `GET /notifications` على لوحة الشريك (و`GET /admin/notifications` للأدمن). **مفيش endpoint خاص بالتصاريح.**

```json
{
  "data": [
    {
      "id": "f8832d95-2f6c-4ce3-b409-324a075d2547",
      "type": "permit_expiring",
      "title": "تصريح وحدتك \"شقة تجريبية — معتمدة (غير مُدرجة للعملاء)\" ينتهي خلال 30 يوم",
      "body": "",
      "read": false,
      "createdAt": "2026-09-22T15:48:51Z",
      "href": null
    }
  ],
  "meta": { "page": 1, "limit": 10, "total": 21 }
}
```

**`type: "permit_expiring"` هو اللي تفلتروا بيه.** ويوم الانتهاء نفسه العنوان بيبقى:
`انتهى تصريح وحدتك "…" — الإعلان متوقف عن الظهور`

**✅ الحاجتان الناقصتان اتصلحوا (27/09):**
- **`body` بقى فيه نص** و**`href` بقى `/units/{id}/permit/renew`** — فالبانر عنده تفصيل وزرار.
- الإشعار بقى شايل **`unit_id`** كمان، فالواجهة تعرف الوحدة من غير ما تقرا نص العنوان.

الحمولة الكاملة كما اتكتبت فعلاً في `notifications` موجودة في **§٦ من الملحق**.
و`href` بيبقى **`null`** في إشعار قديم اتكتب قبل 27/09 ومش شايل `unit_id` — المفتاح موجود
وقيمته فاضية، فاختبروا الحالة دي.

**العتبات:** ٦٠ · ٣٠ · ١٤ · ٧ · ١ · **٠** (يوم الانتهاء). **مرة واحدة لكل تصريح لكل عتبة، للأبد.**
**القنوات:** إشعار داخلي في كل العتبات · إيميل لو فيه عنوان · **SMS في ٧ و١ و٠ بس**.
**وتقديم تجديد بيوقّف باقي التذكيرات.**
**الوقت:** يومياً **09:00** بتوقيت الرياض.

---

## ٦. الـFlags اللي بتأثر على الواجهة

| العلم | staging | الإنتاج | أثره على الواجهة |
|---|---|---|---|
| `PERMIT_EXPIRY_REQUIRED` | **`false`** | **`false`** | لما يبقى `true`: `permitExpiresAt` يبقى **إلزامي في الإرسال للمراجعة**. ⚠️ ومع القسم ٥ التالي ده معناه إن **أي تعديل** على أي إعلان قديم تاريخه ناقص هيترفض لحد ما يتملّى |
| `PERMIT_WARNING_DAYS` | `30` | `30` | نافذة `expiring` وأول عتبة تذكير |
| `MULTI_UNIT_ENABLED` | **`true`** | **`false`** | على الإنتاج: `POST /units/{id}/apartments` بيرجّع **`MULTI_UNIT_DISABLED`** دايماً. **زرار «أضف وحدات» المفروض يبقى مخفي على الإنتاج** |
| `LEGACY_UNIT_WRITES` | `false` | `false` | كل مسارات الكتابة على الوحدات في `/api/v1/partner` و`/api/v1/admin` بترجع **`410 ENDPOINT_RETIRED`**. القراءة شغالة |

🔴 **من 27/09 الأعلام دي بتتقرا وقت التشغيل من `GET /config`** على الأسطح التلاتة، من غير
مصادقة — بدل `NEXT_PUBLIC_*` اللي Next.js بيحرقه وقت البناء. التفصيل في **§٥ من الملحق**،
وده اللي بيخلّي قلب `MULTI_UNIT_ENABLED` على السيرفر يوصلكم **من غير build جديد**.

---

## ٧. كل الأكواد الجديدة

| الكود | Status | السطح | معناه |
|---|---|---|---|
| `BOOKING_EXCEEDS_PERMIT_VALIDITY` | `409` | الضيف | الإقامة بتنتهي بعد تاريخ انتهاء التصريح. `meta.permit_expires_at` (و`meta.end_date` في الحجز) |
| `PERMIT_EXPIRED` | `422` | الشريك | التاريخ المُرسل في التجديد في الماضي. `meta.permit_expires_at` |
| `PERMIT_EXPIRY_REQUIRED` | `422` | الشريك | تجديد من غير تاريخ |
| `NO_PERMIT_TO_RENEW` | `422` | الشريك | الوحدة ما عندهاش تصريح أصلاً |
| `RENEWAL_ALREADY_PENDING` | `422` | الشريك | فيه تجديد منتظر بالفعل |
| `RENEWAL_NOT_PENDING` | `409` | الأدمن | القرار اتاخد قبل كده |
| `LICENSED_UNITS_COUNT_REQUIRED` | `422` | الاتنين | `tourist_facility` من غير عدد |
| `LICENSED_UNITS_COUNT_NOT_APPLICABLE` | `422` | الاتنين | عدد ≠ 1 على تصريح خاص. `meta.max_licensed_units_count` |
| `LICENSE_DOWNGRADE_BLOCKED_BY_QUANTITY` | `422` | الاتنين | تحويل مبنى قائم لتصريح خاص. `meta.group_size`, `meta.shrink_supported` |
| `QUANTITY_EXCEEDS_LICENSED_UNITS` | `422` | الاتنين | العدد المرخّص أقل من الشقق الموجودة/المطلوبة. `meta` |
| `MULTI_UNIT_REQUIRES_FACILITY_LICENSE` | `422` | الاتنين | توسيع بدون تصريح مرفق. `meta.license_type`, `meta.max_units` |
| `MULTI_UNIT_DISABLED` | `422` | الاتنين | العلم مقفول (**الإنتاج دلوقتي**) |
| `SOURCE_UNIT_INCOMPLETE` | `422` | الشريك | الأصل ناقص. `fields` + `meta.unit_id` |
| `ENDPOINT_RETIRED` | `410` | `/api/v1` القديم | مسار كتابة متقاعد |
| `INSUFFICIENT_PERMISSION` | `403` | الأدمن | صلاحية ناقصة |

---

## ٨. حاجات لازم الواجهة تعملها ومش باينة من الـendpoints

1. **🔴 أي تعديل على وحدة `approved` بيرجّعها `pending` وبيشيلها من المتجر.** ده بينطبق على **حقول التصريح كمان** — في مثال ٢.٢ فوق، `PATCH` بحقلين تصريح بس رجّعت `"status": "pending"`. **حذّروا الشريك قبل الحفظ.**
   - **الاستثناء الوحيد: التجديد.** `POST /units/{id}/permit-renewals` **ما بيغيرش** حالة الوحدة — وده سبب وجوده.
2. **الوحدة قيد المراجعة مقفولة للتعديل** — `PATCH` بيرجّع `409 UNIT_LOCKED`.
3. **`permitExpiresAt` ميلادي، والتصاريح مطبوعة هجري.** التحويل عندكم.
4. **`null` في `permitExpiresAt` مش خطأ** — دي حالة كل الإعلانات القديمة (على الإنتاج: **٣ تصاريح، كلها بدون تاريخ**). ما تعرضوهاش كتحذير؛ `permitStatus: "unknown"`.
5. **التاريخ سقف مش تنبيه.** التوفر بيقلّ **قبل** التاريخ، مش يومه: إقامة بتنتهي بعد التاريخ مرفوضة من دلوقتي. **الخروج يوم الانتهاء نفسه مسموح** (آخر ليلة هي اليوم اللي قبله).
6. **الانتهاء محسوب مش مكتوب** — `approval_status` **ما بيتغيرش** لما التصريح ينتهي. وحدة تصريحها خلص تفضل `approved` في اللوحتين وتختفي من المتجر بس. **ما تعرضوش «مرفوضة».**
7. **في مبنى، الحجز ممكن يروح لشقة تانية** — اعرضوا `booking.unit` من رد الحجز.
8. **`permitAddress` كل حقوله ممكن تكون `null`** حتى لو التصريح موجود — الحقول دي اختيارية بالكامل.
9. **على الإنتاج `MULTI_UNIT_ENABLED=false`** — أخفوا التوسيع هناك.
10. **`meta` في غلاف الأدمن اختياري** — بيظهر بس لما فيه أرقام.

---

## ٩. الحالة — كل اللي كان «جاي» اتنفّذ

| | الحالة |
|---|---|
| **المرحلة ٤** — وضع أ: `permits[]` في `/apartments` و`/submit`، التفرّد، `PERMITS_COUNT_MISMATCH` · `DUPLICATE_PERMIT_NUMBER` · `PERMIT_MODE_MIXED` | ✅ **الإنتاج** (مقفولة بالعَلَم) |
| **المرحلة ٦** — `addressMatch` · `group` · `group_size` · `tourismLicenseNumber` | ✅ **الإنتاج** |
| **إشعار البانر** — `body` + `href` + `unit_id` | ✅ **الإنتاج** |
| **`apartment_no` في رد الحجز** | ✅ **الإنتاج** |
| **`POST /units/{id}/submit` بعدد — خطوة واحدة** | ✅ **الإنتاج** (مقفولة بالعَلَم) |
| **`GET /config`** — الأعلام وقت التشغيل | ✅ **الإنتاج** |
| `groupId`/`apartmentNo` في `GET /units` و`/units/{id}` بتاعة الشريك | ✅ **الإنتاج** (28/09) |

**كل حاجة في الملف ده وفي الملحق منشورة على الإنتاج.**

**✅ البندين اللي كانوا مفتوحين اتقرّروا (01/10) واتنفّذوا على staging والإنتاج** (`prod-2026-10-01-doors`):
الوحدة الأصلية دايماً `"1"` (الملحق §١.٥)، وترتيب واحد للأبواب (الملحق §١.٦). **مافيش بند مفتوح.**


---
---

# مرفق ٢ من ٢ — `permits-frontend-contract-phases-4-6.md`

# عقد التصاريح للواجهات — المراحل ٤ و٦ (الملحق)

**مكتوب من الكود المنشور على الإنتاج:** `prod-2026-09-27` · نُشر 27/09/2026
**الأمثلة:** ردود حقيقية من `api.mamsaa.com` و`staging.mamsaa.com`، مش من الذاكرة
**البيئة:** ✅ **حيّ على الإنتاج وعلى staging** · **آخر تحديث 01/10/2026** (`prod-2026-10-01-doors`)

> الملف ده **ملحق** لـ [`permits-frontend-contract-phases-1-3.md`](permits-frontend-contract-phases-1-3.md).
> **الاتنين بيوصفوا الإنتاج دلوقتي.** أي حاجة مش مذكورة هنا، خدوها من هناك.
>
> 🔴 **فرق واحد بين البيئتين، وهو مهم:** `multiUnitEnabled` = **`true` على staging**
> و**`false` على الإنتاج**. يعني كل اللي تحت **منشور** على الإنتاج، بس **وضع أ** و**`/submit`
> بعدد** مقفولين بالعَلَم هناك لحد ما الواجهة تجهز. اقروا العَلَم من `GET /config` (§٥)،
> مش من `NEXT_PUBLIC_*`.

---

## ٠. اللي اتغيّر في سطر واحد

| البند | السطح | الحالة |
|---|---|---|
| `POST /units/{id}/submit` بياخد `{count, permits}` — إنشاء + تقديم في نداء واحد | الشريك | ✅ الإنتاج |
| `permit` + `addressMatch` + `group` في `GET /admin/approvals/{id}` | الأدمن | ✅ الإنتاج |
| `group_size` جنب `available_count` | الضيف | ✅ الإنتاج |
| `apartment_no` في `booking.unit` | الضيف | ✅ الإنتاج |
| `permitAddress*` + `permitExpiresAt` قابلين للكتابة على الوحدة نفسها | الشريك + الأدمن | ✅ الإنتاج |
| `tourismLicenseNumber` كمفتاح إضافي في قراءة الأدمن | الأدمن | ✅ الإنتاج |
| `GET /config` — الأعلام وقت التشغيل | التلاتة | ✅ الإنتاج |
| `unit_id` + `body` + `href` في إشعار `permit_expiring` | الشريك | ✅ الإنتاج |

---

## ١. 🔴 الإنشاء بعدد — خطوة واحدة

```
POST /units/{id}/submit
```

الـ body **اختياري بالكامل**، وشكله نفس شكل `/apartments` بالظبط:

```jsonc
// (أ) تقديم عادي — زي ما هو، من غير أي تغيير
(لا body)

// (ب) مبنى ترخيص مرفق — عدد بس
{ "count": 4 }

// (ج) وضع أ — تصريح لكل شقة جديدة
{
  "count": 3,                          // الإجمالي المطلوب، مش الإضافة
  "permits": [                         // طولها = عدد الشقق الجديدة (٣ − ١ = ٢)
    { "number": "MA-ONESTEP-2",
      "fileId": "file_01m36c5pdd…",    // ✅ إلزامي
      "apartmentNo": "2",              // اختياري — **اسم الباب الجديد** (§١.٤)
      "expiresAt": "2027-09-23",       // اختياري · YYYY-MM-DD
      "address": { "city": "الرياض", "district": "النرجس", "building": "12", "unitNo": "2" } },
    { "number": "MA-ONESTEP-3", "fileId": "file_01m36c5pde…", "apartmentNo": "3", "expiresAt": "2027-09-23" }
  ]
}
```

**القواعد اللي ماتغيّرتش عن `/apartments`:** `count` إجمالي، و`permits.length` = الجديد، و`apartmentNo` بيسمّي الباب، ونفس أكواد الأخطاء. الـ **pre-flight واحد مشترك** بين المسارين في الكود، عشان ما يبقاش فيه توسيع يعدّي من باب ويترفض من الباب التاني.

### ١.١ الرد — من staging النهارده، وحدة `u_68` من باب لتلاتة

```json
{
  "unit": { "…": "الوحدة الأصل بعد التقديم، status = pending" },
  "groupId": "01M36C95Y28DDDCCMD0RPYJ8EF",
  "groupSize": 3,
  "units": [
    { "id": "u_68", "apartmentNo": "1", "status": "pending" },
    { "id": "u_69", "apartmentNo": "2", "status": "pending" },
    { "id": "u_70", "apartmentNo": "3", "status": "pending" }
  ],
  "message": "سيصلك إشعار خلال 24–48 ساعة"
}
```

`groupId` و`groupSize` و`units` **بتطلع دايماً**، حتى في التقديم العادي (`groupSize: 1`, `groupId: null`, باب واحد). كده الواجهة بتقرا نفس الشكل في الحالتين.

### ١.٢ حالة الفشل — ولا نص مبنى

كل حاجة جوّه **transaction واحدة**: التوسيع، وكتابة التصاريح، وتحويل كل باب لـ `pending`. أي رفض في أي خطوة بيرجّع الوحدة الأصل **draft** ومافيش ولا شقة اتخلقت.

| اللي بيحصل | الرد | بعد الرفض |
|---|---|---|
| وضع أ من غير `permits` | `422 PERMIT_MODE_MIXED` | draft · صفر شقق |
| `permits.length` ≠ الجديد | `422 PERMITS_COUNT_MISMATCH` + `meta.requested` | draft · صفر شقق |
| رقم تصريح مستعمل في مكان تاني | `422 DUPLICATE_PERMIT_NUMBER` + `meta.permit_number` + `meta.claimed_by_unit_id` | draft · صفر شقق |
| رقم مكرر **جوّه نفس الطلب** | `422 DUPLICATE_PERMIT_NUMBER` | draft · صفر شقق |
| شقة جديدة تصريحها **منتهي** | `400 VALIDATION` + `fields.permitExpiresAt` — **من غير رقم الكارت** ↓ | draft · صفر شقق |
| الوحدة الأصل ناقصة بيانات | `400 VALIDATION` + `fields` | draft · صفر شقق |
| الوحدة مش draft/rejected | `409 UNIT_NOT_SUBMITTABLE` | كما هي |

**⚠️ مفتاح التاريخ المنتهي مالوش رقم كارت** (متحقَّق 30/09 على `/submit` و`/apartments`):
المفتاح **`fields.permitExpiresAt`**، **مش** `fields["permits.1.expiresAt"]`، ومافيش `meta`. الفحص
بيتعمل على الشقة **بعد** ما بتتخلق، فالسيرفر مابيعرفش أنهي كارت جابها. **الواجهة تحدد الكارت:** كل
كارت `expiresAt` بتاعه **أقدم من النهارده بتوقيت الرياض** — ده نفس شرط السيرفر بالظبط
(`expiry < today` في `Asia/Riyadh`؛ التصريح اللي بينتهي النهارده لسه صالح لحد منتصف الليل). **ماتقارنوش
بتوقيت جهاز الشريك** — شريك برّه السعودية أو ساعة جهازه غلط هيختلف مع السيرفر في حالة منتصف الليل
بالظبط. (باقي أخطاء الكارت ليها رقمها: الملف `fields["permits.{i}.fileId"]`، والرقم المكرر
`meta.permit_number`.)

السطر قبل الأخير هو السبب اللي خلّى الفحص يتكرر على **كل باب** مش على الأصل بس: الأصل ممكن يكون تصريحه سليم وباب جديد يكون جايب تاريخ منتهي، والباب ده كان هيوصل لطابور المراجعة تحت تصريح ميت.

### ١.٣ 🆕 `POST /units/{id}/apartments` — **أي باب في المبنى**، مش الأصل بس (موثّق ومختبَر 30/09)

كارت المبنى بيظهر في صفحة كل باب، فزرار «إضافة شقق» موجود على أي باب. **المسار بيقبل أي باب في
المبنى** — مافيش «وحدة أصل» ثابتة بعد ما المبنى يتعمل.

| | السلوك |
|---|---|
| **أي باب** يملكه الشريك | ✅ مقبول — حتى لو الباب نفسه `pending` |
| `count` | **إجمالي المبنى**، مش إضافة — من أي باب نفس المعنى |
| نفس `count` الحالي من باب تاني | `added: 0` · «المبنى يحتوي بالفعل على هذا العدد» |
| `groupId` في الرد | **نفس المبنى** مهما كان الباب |

**🔴 الشقق الجديدة بتتنسخ من الباب اللي اتبعت — مش من الأصل.**

السعر، والوصف، والصور، والمميزات، ومواعيد الدخول والخروج، والعنوان — كله من **الباب المفتوح**.
المتشال من النسخ: الهوية بس (`id`، `code`، `calendar_token`، رقم الشقة) والتاريخ (حالة المراجعة،
iCal). وفي **وضع أ** (تصريح لكل شقة) المستندات **مابتتنسخش** — كل شقة جديدة بتيجي بتصريحها.

**لو كل الأبواب متطابقة** (الطبيعي — كلهم نسخ من بعض): مافيش فرق أي باب اتبعت.
**لو الشريك عدّل باب لوحده** (مثلاً سعر ٤٠٢ مختلف): الشقق الجديدة هتاخد سعر **الباب اللي هو فاتحه**.

**`SOURCE_UNIT_INCOMPLETE` بيتفحص على الباب اللي اتبعت** — والرفض بيسمّيه هو
(`error.meta.unit_id`)، حتى لو الأصل كامل.

**للواجهة:** ابعتوا **الباب المفتوح** — مش محتاجين تحددوا أصل. ويُفضَّل الزرار أو التأكيد يقول
صراحةً إن الشقق الجديدة هتاخد بيانات **الشقة دي** (رقمها من `apartmentNo`)، عشان الشريك اللي
عدّل باب لوحده مايتفاجئش.

> **الإنتاج:** `MULTI_UNIT_ENABLED=false` — أي `count` أكبر من ١ بيرجّع **`422 MULTI_UNIT_DISABLED`**
> من أي باب، ومافيش مباني على الإنتاج أصلاً. السلوك ده شغّال على **staging**، وعلى الإنتاج يوم ما
> العلم يتفتح.

### ١.٤ 🆕 `apartmentNo` في الكارت = **اسم الباب الجديد** (تغيير 01/10 — staging)

**قبل 01/10:** الأبواب الجديدة كانت بتاخد **أرقام تلقائية الأول**، وبعدين الكروت بتتطابق عليها —
فـ`apartmentNo` كان **مفتاح مطابقة**، مش اسم. كارت بـ`"7"` جنب أبواب `2` و`3` ماكانش بيطابق حاجة،
والرد كان **`422 PERMITS_COUNT_MISMATCH`** بعدد متساوي (`requested: 2, permits_provided: 2`) — رسالة
بتناقض نفسها. **مكانش شرط مقصود**؛ كان أثر جانبي لترتيب الخطوات.

**من 01/10:** `apartmentNo` **بيسمّي الباب اللي الكارت جايبه.** رقم، أو `"402"`، أو `"الدور الثالث"`.

| الكروت | الأبواب |
|---|---|
| وحدة مستقلة، `count: 3`، كارت من غير رقم + كارت `"7"` | **`1` · `2` · `7`** |
| مبنى فيه `1` و`2`، `count: 4`، كارت `"7"` + كارت من غير رقم | **`1` · `2` · `3` · `7`** |
| `"الدور الثالث"` | باب اسمه `الدور الثالث` |

**الأرقام التلقائية بتكمّل من المبنى، مش من الاسم:** «٧» مابيخلّيش الباب اللي بعده «٨».
**الكارت المسمّي بياخد بابه، والكروت من غير رقم بتاخد الأبواب التلقائية بالترتيب.**

**الرفض — على الكارت نفسه، قبل ما أي حاجة تتكتب:**

| السبب | لوحة الشريك | الأدمن |
|---|---|---|
| الاسم **موجود في المبنى** | `400 VALIDATION` · `fields["permits.{i}.apartmentNo"]` = «رقم الشقة 2 موجود بالفعل في المبنى» | `422 VALIDATION_ERROR` · نفس المفتاح |
| **كارتين بنفس الاسم** | نفس الشكل على **الكارت التاني** = «رقم الشقة 7 مكرر في نفس الطلب» | نفس الشكل |

المسافات حوالين الاسم مابتفرّقش (`" 7 "` = `"7"`).

**🆕 الأرقام العربية والفارسية بتتكتب ASCII (قرار 01/10 — ✅ staging · ✅ الإنتاج `prod-2026-10-01-doors`):** `"٧"` بيتخزّن `"7"`، و`"٤٠٢"` →
`"402"`، و`"B-١٢"` → `"B-12"`. **الحروف بتفضل زي ما اتكتبت** (`"الدور الثالث"`). فـ`"٧"` و`"7"`
**نفس الباب** — فحص التكرار بيمسكهم، والترتيب بيعاملهم رقم. نفس تحويل الأرقام اللي في رقم
التصريح (§٢.٣ في ملف المراحل ١–٣)، بس **من غير** شيل المسافات الداخلية ولا تكبير الحروف — اسم
الباب بيتعرض للناس زي ما اتكتب. **ماكانش فيه ولا اسم باب بأرقام عربية متخزّن** على staging
(والإنتاج مافيهوش مباني)، فمافيش بيانات قديمة اتأثرت.

**`PERMITS_COUNT_MISMATCH` بقى معناه حاجة واحدة:** عدد الكروت ≠ عدد الأبواب الجديدة — والـ`meta`
بتقول الرقمين المختلفين فعلاً.

> **البيئات:** على **staging** و**الإنتاج** من 01/10 (`prod-2026-10-01-units`). بس على الإنتاج
> `MULTI_UNIT_ENABLED=false`، فالمسارات دي بترجّع `MULTI_UNIT_DISABLED` هناك لحد ما العلم يتفتح.

### ١.٥ ✅ الوحدة الأصلية ورقمها — لما وحدة مفردة تبقى مبنى (01/10)

**السؤال اللي طلع من جولة FE-P2:** وحدة مفردة (من غير `apartmentNo`)، الشريك ضاف باب وسمّاه `"1"`،
فالوحدة الأصلية بقت **باب `"2"`** (`u_94` على staging) من غير ما الشريك يطلب.

| السؤال | الإجابة — من الكود ومن staging |
|---|---|
| **الرقم بيتكتب فعلاً؟** | **أيوه — في `units.apartment_no` بتاع الوحدة الأصلية نفسها.** `UnitCloner::assign()` بيكتبه لحظة ما الوحدة تبقى مبنى. على staging: `u_94` → `apartment_no = "2"`، و`updated_at` اتغيّر لحظة التوسيع |
| **من إمتى؟** | **الوحدة الأصلية طول عمرها بتاخد رقم مكتوب** لما تبقى مبنى — كان دايماً `"1"`. **الجديد من تعديل 01/10:** لو الشريك سمّى باب جديد `"1"`، الأصلية بتاخد أول رقم فاضي (`"2"`). **ده أثر التعديل بتاعنا.** |
| **بيظهر للضيوف؟** | **مش في صفحة الوحدة، ولا نتايج البحث، ولا الـsitemap، ولا الإيميلات** — مستبعد من الرد العام عن قصد. **بيظهر في مكان واحد: الوحدة جوّه الحجز** (تفاصيل الحجز، و`/payments/initiate` → `booking.unit.apartment_no`) — عشان الضيف يعرف أنهي شقة. **وبيتقري من صف الوحدة وقت العرض، مش متخزّن مع الحجز** — فحجز قديم على الوحدة الأصلية **هيبدأ يعرض الرقم الجديد** بعد ما تبقى مبنى |
| **الشريك يقدر يعدّله؟** | **لأ — ولا الأدمن.** مافيش أي حقل قابل للتعديل له. الكاتب الوحيد هو `UnitCloner` وقت إنشاء المبنى |
| **لو عدّله لاسم موجود؟** | **مش وارد النهارده** — مافيش تعديل أصلاً |
| **فيه قيد في قاعدة البيانات؟** | **لأ** — مافيش `unique (unit_group_id, apartment_no)`. التفرّد مضمون من الكود بس: فحص الأسماء قبل الكتابة (§١.٤)، والقفل (`lockForUpdate`) على صفوف المبنى وقت الإنشاء |

**و`null` في مبنى:** **مابيحصلش.** الوحدة الأصلية بتاخد رقم أول ما تبقى مبنى. `apartmentNo: null`
معناها **وحدة مستقلة** (`groupId: null`) — مش «الأصلية جوّه مبنى».

**✅ القرار (المالك 01/10): الخيار (أ) — اتنفّذ على staging** (`f906804`، متحقَّق بنداء حقيقي)
**وعلى الإنتاج** (`prod-2026-10-01-doors`، 01/10). على الإنتاج المسار مقفول بالعلم، فبيشتغل يوم ما يتفتح.

> **الوحدة الأصلية دايماً `"1"`** لما تبقى مبنى، مهما كانت أسماء الكروت. **وكارت اسمه `"1"`
> (أو `"١"`) بيترفض على الكارت نفسه:**
>
> | السطح | الرد |
> |---|---|
> | لوحة الشريك | `400 VALIDATION` · `fields["permits.{i}.apartmentNo"]` = «رقم الشقة 1 محجوز للوحدة الأصلية» |
> | الأدمن | `422 VALIDATION_ERROR` · نفس المفتاح والرسالة |
>
> **ولا حاجة بتتكتب قبل الرفض.**

**ليه (أ):** الرقم بيتقري وقت العرض وبيظهر للضيف في تفاصيل الحجز و`/payments/initiate`، فحجز قديم
على الوحدة الأصلية كان هيعرض رقم مختلف لضيف شافه خلاص. والقرار مجاني النهارده لأن الإنتاج مافيهوش
ولا مبنى.

**الحالة اللي ظهرت في جولة FE-P2 (`u_94` بقى `"2"`) مابقتش ممكنة** — الطلب ده نفسه بيترفض دلوقتي
على الكارت. `u_94` نفسه على staging فاضل `"2"` زي ما هو (بيانات اختبار؛ القرار مش بيعدّل قديم).

**مبنى موجود بالفعل:** الأصلية رقمها متكتب من زمان، فكارت باسم موجود بياخد «موجود بالفعل في المبنى»
— نفس فحص §١.٤.

### ١.٦ ✅ ترتيب الأبواب جوّه المبنى — **قاعدة واحدة** (قرار المالك 01/10)

> **البيئات:** ✅ **staging** من 01/10 (`f906804` — متحقَّق بنداء حقيقي: الرد رجع
> `1, 2, 7, الملحق, B-12`). ✅ **الإنتاج** من 01/10 (`prod-2026-10-01-doors`) — **الترتيب العربي
> متحقَّق على الإنتاج نفسه** (`ar`، ICU 64.2): `فاضي, 2, ٧, 10, الدور الثالث, الملحق, B-12, Penthouse`.
> المباني مقفولة على الإنتاج بالعلم، فالقاعدة بتظهر في الردود يوم ما يتفتح.

**القاعدة الوحيدة — الباك اند والواجهة الاتنين:**

1. **`null`/فاضي أولاً**
2. **اللي كله أرقام** — تصاعدي **بقيمته** (`"2"` قبل `"10"`)، والأرقام العربية بتتعامل كرقم
3. **الباقي** — بالترتيب العربي (`localeCompare('ar')` عند الواجهة · `Collator('ar')` عندنا)
4. **التعادل** (`"01"` و`"1"`، أو اسمين متطابقين) — بالـ`id` تصاعدي

```
"1", "2", "7", "10", "402", "الدور الثالث", "الملحق", "B-12"
```

**الباك اند بقى بيطبّقها في الـ٦ أماكن** اللي كانت `orderBy('apartment_no')` (ترتيب نصّي: `"10"`
قبل `"2"`):

| المكان | فين بيبان |
|---|---|
| `POST /units/{id}/submit` · `POST /units/{id}/apartments` | `units[]` في الرد |
| `POST /admin/units/{id}/apartments` | `units[]` في الرد |
| `GET /admin/approvals/{id}` | `group.apartments[]` |
| `UnitCloner` (داخلي) | الرد بتاع العمليتين فوق |

**⚠️ ملاحظة تقنية متحقَّقة — العربي قبل اللاتيني:** الترتيب العربي في ICU بيحط الأسماء العربية
**قبل** اللاتينية، وده اللي `localeCompare('ar')` بيعمله في المتصفح. **staging والإنتاج (ICU 64.2)
بيحمّلوا الترتيب العربي فعلاً** — متحقَّق. لو سيرفر في يوم اتحدّث من غير بيانات اللغة العربية، ICU
بيرجع لترتيب افتراضي **بيحط اللاتيني الأول** من غير ما يقع — فالباك اند بيسجّل تحذير في اللوج وقتها
بدل ما الترتيب يتغيّر بصمت.

**ومع ذلك، سطر «ماتبنوش على ترتيب الرد» فاضل** (ملف المراحل ١–٣ §٢.١) — مش ازدواجية: القاعدة واحدة،
وكل طرف بينفّذها عنده.

---

## ٢. شاشة المراجعة — `GET /admin/approvals/{id}`

**الصلاحية:** `approvals.view` · **تلات مفاتيح جديدة على مستوى الرد** (مش جوّه `unit`).

### ٢.١ رد حقيقي من staging — شقة في مبنى **وضع أ**

```json
{
  "permit": {
    "address": { "city": "الرياض", "district": "النرجس", "building": "12", "unitNo": "2" },
    "expiresAt": "2027-09-23",
    "status": "valid"
  },
  "addressMatch": { "city": true, "district": null },
  "group": {
    "id": "01M36C95Y28DDDCCMD0RPYJ8EF",
    "size": 3,
    "mode": "per_unit",
    "apartments": [
      { "id": "68", "apartmentNo": "1", "status": "pending", "permitNumber": "TL-ONESTEP-1", "permitExpiresAt": null },
      { "id": "69", "apartmentNo": "2", "status": "pending", "permitNumber": "MA-ONESTEP-2", "permitExpiresAt": "2027-09-23" },
      { "id": "70", "apartmentNo": "3", "status": "pending", "permitNumber": "MA-ONESTEP-3", "permitExpiresAt": "2027-09-23" }
    ]
  }
}
```

### ٢.٢ `addressMatch` — **المدينة بس**، و`null` معناها «ماتقارنش»

```jsonc
{ "city": true | false | null, "district": null }
```

- **`city`** بيتقارن بعد ما الطرفين يعدّوا على نفس خريطة المدن المعتمدة، فـ«الرياض» و`riyadh` بيرجعوا قيمة واحدة والمقارنة يبقى ليها معنى.
- **`district` دايماً `null`، عمداً.** الحي نص حر على الناحيتين — «النرجس» و«حي النرجس» نفس المكان وسلسلتين مختلفتين. مقارنة زي دي بتطلّع «مش متطابق» غلط، ومراجع شاف كدبة واحدة بيبطّل يقرا الحقل خالص.
- **`city: null`** معناها **ما اتقارنش** (التصريح مالوش عنوان، أو الوحدة مالهاش مدينة) — **مش** «متطابق». ارسموها رمادي، مش أخضر.

### ٢.٣ `group` — أو `null`

`null` للوحدة المستقلة، **مش** مبنى من باب واحد: الشاشة تخفي القسم بدل ما تعرض مبنى بشقة.

| المفتاح | المعنى |
|---|---|
| `id` | معرّف المجموعة (ULID) |
| `size` | **كل** الأبواب الموجودة في المجموعة، بأي حالة |
| `mode` | `single_permit` أو `per_unit` |
| `apartments[]` | `{ id, apartmentNo, status, permitNumber, permitExpiresAt }` لكل باب |

`permitNumber` لكل باب هو **إزاي المراجع يشوف إن وضع أ فعلاً تصاريح مختلفة**: في `single_permit` الرقم واحد متكرر، وفي `per_unit` لازم يختلف.

> ⚠️ `group.size` هنا **غير** `group_size` في القائمة العامة. شوف ٤.١.

---

## ٣. العنوان والتاريخ — قابلين للكتابة على الوحدة نفسها

كانوا يتكتبوا في **التجديد بس**، يعني وحدة ماجدّدتش عمرها ما تقدر تسجّل عنوان تصريحها، والمراجع مالوش حاجة يقارن بيها.

```jsonc
PATCH /units/{id}                  (الشريك)
PATCH /admin/units/{id}            (الأدمن)
POST  /units        · POST /admin/units   (نفس المفاتيح عند الإنشاء)

{
  "permitExpiresAt": "2027-09-23",      // YYYY-MM-DD · null بيمسح
  "permitAddressCity": "الرياض",         // ≤ 100
  "permitAddressDistrict": "النرجس",     // ≤ 150
  "permitAddressBuilding": "12",         // ≤ 50
  "permitAddressUnitNo": "2"             // ≤ 50
}
```

مفاتيح **مسطّحة عند الكتابة**، و**مجمّعة عند القراءة** (`permitAddress: { city, district, building, unitNo }`) — ده اللي كان موجود أصلاً في القراءة وما اتغيّرش.

كلهم `sometimes` + `nullable`: المفتاح الغايب ما بيتغيّرش، والمفتاح بـ`null` بيتمسح.

**تنبيه المجموعة:** التصريح مملوك للنطاق. في `single_permit` الكتابة على أي باب بتغيّر **تصريح المبنى كله** وكل الأبواب بتعكسه. في `per_unit` الكتابة بتخصّ الباب ده وحده.

### ٣.١ 🆕 `permitExpiresAtHijri` — التاريخ الهجري **زي ما اتكتب** (30/09)

> **الحالة:** ✅ **على الإنتاج من 30/09** (`prod-2026-09-30-hijri`) · ✅ **على staging من 30/09**
> (متحقَّق بنداءات حقيقية على MySQL — البايتات المخزّنة مطابقة للي اتكتب حرف بحرف، مسافات الأول
> والآخر شاملة). على الإنتاج: المفتاح ظاهر في ردود الشريك والأدمن بـ`null` على التلات وحدات.

**ليه:** التصاريح مطبوعة هجري والتحويل عندكم، والباك اند كان بيخزّن **ناتج التحويل بس**. جداول
أم القرى بتختلف بيوم بعد 2029-08-10، وجدول الوزارة مش معروف. فلو طلع مختلف، التواريخ المخزّنة
كلها غلط بيوم **ومافيش أصل نعيد منه** — الحل كان هيبقى الرجوع للورق تصريح تصريح. دلوقتي الأصل
بيتخزّن، والتصحيح يبقى أمر واحد.

**اختياري بالكامل.** ماحدش لازم يبعته، ومافيش حاجة بتتكسر لو ماوصلش.

| المسار | المفتاح |
|---|---|
| `PATCH/POST /units/{id}` · `PATCH/POST /admin/units/{id}` | `permitExpiresAtHijri` |
| `POST /units/{id}/permit-renewals` (التجديد) | `permitExpiresAtHijri` |
| `POST /units/{id}/apartments` · `POST /admin/units/{id}/apartments` | `permits.*.expiresAtHijri` |

```jsonc
{
  "permitExpiresAt": "2027-09-23",        // اللي الـAPI بيحسب عليه — زي ما هو
  "permitExpiresAtHijri": "٢٢/٠٤/١٤٤٩ هـ"  // اختياري · نص ≤ 50 · بيتخزّن حرفياً
}
```

**القواعد:**

- **بيتخزّن حرفياً.** ولا تنضيف ولا توحيد أرقام ولا حتى قصّ المسافات — الإطار بيقصّ المسافات من
  كل المدخلات افتراضياً، والمفتاحين دول **مستثنيين** من ده.
- **مابيدخلش في أي حساب.** سقف الانتهاء، والتذكيرات، ومنع الحجز — كلها على `permitExpiresAt`
  بس. الهجري **أصل للمراجعة وإعادة التحويل**، مش قيمة شغّالة.
- **`null` معناها «اتكتب ميلادي»** — أو إن التاريخ أقدم من 30/09. ودي معلومة في حد ذاتها: التاريخ
  ده مالوش أصل هجري يتراجع.
- **ماينفعش من غير الميلادي جنبه.** الباك اند مابيحوّلش، فهجري لوحده مالوش معنى:
  - لوحة الشريك → **400 `VALIDATION`** · `fields.permitExpiresAtHijri`
  - الأدمن → **422 `VALIDATION_ERROR`** · `fields.permitExpiresAtHijri`
  - الشقق → `fields["permits.0.expiresAtHijri"]`
- **اسم المكتبة ونسختها مابيتخزّنوش في الصف** — مثبّتين على مستوى المنصة وتاريخهم عندكم في
  `docs/audit/hijri-converter-pinned.md`.

**🔴 إمتى الهجري المخزّن بيفضل، وإمتى بيتمسح** — القاعدة الوحيدة اللي محتاجة تتفهم:

| الطلب | اللي بيحصل للهجري | ليه |
|---|---|---|
| بعت `permitExpiresAtHijri` (نص أو `null`) | **بيتخزّن زي ما اتبعت** | هو إجابة صريحة |
| **غيّر التاريخ من غير هجري** | **بيتمسح (`null`)** | الهجري القديم مش أصل التاريخ الجديد |
| بعت **نفس** التاريخ من غير هجري | بيفضل | الويزارد بيعيد إرسال كل الحقول في كل خطوة |
| عدّل حقل تاني بس (العنوان، الرقم) | بيفضل | ماحدش لمس التاريخ |
| **تجديد** بتاريخ جديد من غير هجري | **`null` على التجديد** — مش موروث من التصريح القديم | التجديد بيورث كل حاجة **إلا** ده |

الصف التاني والأخير هم اللي ليهم اختبار بيقع لو القاعدة اتكسرت — لأنهم الحالة الوحيدة اللي الغلط
فيها **صامت**: هجري قديم جنب ميلادي جديد، في العمود اللي موجود أصلاً عشان يصلّح التواريخ الغلط.

**القراءة** — بنفس الاسم جنب `permitExpiresAt` في كل مكان بيظهر فيه للإنسان:

| المسار | المفتاح |
|---|---|
| `GET /units` · `GET /units/{id}` (الشريك) | `permitExpiresAtHijri` — **موجود دايماً**، `null` لو مافيش |
| `GET /units/{id}/permit-renewals` + رد إنشاء التجديد | `permitExpiresAtHijri` |
| `GET /admin/units/{id}` · `GET /admin/permits` | `permitExpiresAtHijri` (و`currentPermit.permitExpiresAtHijri` في التجديدات) |
| `GET /admin/approvals/{id}` | `permit.expiresAtHijri` · وكل شقة `apartments[].permitExpiresAtHijri` |

**الضيف (`/api/v1`) مابيشوفوش** — مالوش لازمة عنده.

**الواجهات هتبدأ تبعته في مرحلة صغيرة بعد FE-P2.** لحد ساعتها كل التواريخ الجديدة `null` في
العمود ده، وده سليم.

### ٣.٢ شكل القراءة — `permitAddress` **موجود دايماً**

```jsonc
GET /units/{id}      ·      GET /units   (كل صف)

"permitAddress": { "city": null, "district": null, "building": null, "unitNo": null }
```

- **المفتاح موجود دايماً** — حتى لو الوحدة مالهاش تصريح خالص. **مابيتشالش** ومابيرجعش `null` لوحده.
- **دايماً الأربع مفاتيح**، كل واحد `null` لو مش متسجّل.
- ده مقصود في الكود (*«Always the same four keys so a client never branches»*)، **ومن 30/09 عليه
  اختبار** بيثبته على وحدة مالهاش تصريح، في العرض المفرد وفي القايمة.

فتقدروا تعاملوه كـ**مطلوب** بدل اختياري: `permitAddress.city` تتقري على طول من غير `?.` على
`permitAddress` نفسه.

⚠️ **ولشاشة التجديد في المرحلة ٥ تحديداً:** الكتابة في **التجديد مجمّعة**، مش مسطّحة زي تعديل الوحدة:

```jsonc
PATCH /units/{id}                     → "permitAddressCity": "الرياض"          // مسطّح
POST  /units/{id}/permit-renewals     → "permitAddress": { "city": "الرياض" }   // مجمّع
```

الجملة فوق («مسطّحة عند الكتابة») صحيحة على **تعديل الوحدة**؛ التجديد ليه شكله. **القراءة مجمّعة
في الاتنين.**

---

## ٤. الضيف — `/api/v1/*`

### ٤.١ `group_size` جنب `available_count`

```json
{ "id": 30, "available_count": 5, "group_size": 5 }
```

**التعريف بالظبط — اقروه، مش زي `group.size` بتاع الأدمن:**

| | بيعدّ إيه |
|---|---|
| `group_size` (عام) | الأبواب **المعتمدة والمتاحة** في المبنى — يعني القابلة للبيع |
| `available_count` (عام) | منها، اللي **فاضي** في التواريخ المطلوبة |
| `group.size` (أدمن) | **كل** الأبواب، بأي حالة، معتمدة أو مرفوضة أو تحت المراجعة |

فـ«٤ من ٦» على الكارت معناها ٤ فاضيين من ٦ معروضين — ونفس المبنى ممكن يطلع للأدمن `size: 8` لو فيه بابين لسه `pending`. ده مش تضارب، دول سؤالين مختلفين.

الوحدة المستقلة `group_size: 1` دايماً (مبنى من باب واحد).

**عدد مفاتيح الرد العام بقى ٣٣ بدل ٣٢** — اتأكّد على الإنتاج بعد النشر، والواجهات حدّثت
الاختبار عندها (تأكيدكم 27/09).

### ٤.١ب 🔴 `listing_id` — إزاي تربط الشقة بالمبنى

**موجود من زمان في كل ردود الوحدات والحجوزات، بس ما كانش موصوفاً.** وهو الإجابة على
«الضيف عنده حجز على شقة، والصفحة بتعرض الممثّل، والاتنين `id` مختلف».

```php
listing_id = unit_group_id  ?:  'u' . id
```

| | القيمة |
|---|---|
| مبنى | **ULID المجموعة** — نفسه على **كل** شقق المبنى وعلى الكارت |
| وحدة مستقلة | `u<id>` |
| **موجود دايماً؟** | ✅ في الكارت · في `GET /units/{id}` · في `booking.unit` · وفي `/payments/initiate` |

**رد حقيقي من staging — حجزين على نفس الليالي في المبنى نفسه:**

```
حجز #95  طلبنا unit_id=30  →  اتخصص unit.id=30  شقة 401  listing_id=01M19EZ…
حجز #96  طلبنا unit_id=30  →  اتخصص unit.id=39  شقة 402  listing_id=01M19EZ…
                                  ↑ الـid اتغيّر        ↑ listing_id ثابت
```

```ts
// ❌ بيقع في المبنى
bookings.find(b => b.unit.id === unit.id)
// ✅
bookings.find(b => b.unit.listing_id === unit.listing_id)
```

**وسبب وجوده من الأصل هو نفس المشكلة:** الـ`id` بيتغيّر مع كل حجز في المبنى، فمبنى في
المفضلة كان بيقرا «مش مفضّل» بعد أول حجز.

> ℹ️ **و`GET /api/v1/units/{id}` لشقة مش الممثّل بيرجّع `200`**، صفحة وحدة عادية بنفس
> `listing_id` و`group_size` و`available_count`. الـendpoint مابيفحصش الممثّلية — بيفحص
> النوع و`approved` و`available` والتصريح بس. **بس خزّنوا `listing_id` وافتحوا الممثّل:**
> الشقة الواحدة ممكن تتقفل والمبنى يفضل شغّال، وساعتها الرابط المحفوظ بيرجّع 404.

### ٤.١ج 🔴 رابط ثابت للمبنى — المسارات بتقبل `listing_id`

**المشكلة:** الـ`id` في أي رابط عام هو **الباب اللي كان ممثّل المبنى يومها**. الباب ده ممكن
يترفض أو يتقفل أو تصريحه ينتهي **والمبنى يفضل بيبيع** — وساعتها كل رابط الضيف حفظه بيرجّع 404
لحاجة لسه مفتوحة. ماكانش فيه اسم ثابت للمبنى أصلاً.

**من 28/09 (منشور على الإنتاج):** `listing_id` مقبول **في أي مكان الـ`id`** مقبول فيه:

```
GET  /api/v1/units/{listing_id}
GET  /api/v1/units/{listing_id}/reviews
GET  /api/v1/units/{listing_id}/blocked-dates
POST /api/v1/units/{listing_id}/availability
```

**بيرجّع ممثّل المبنى** — **أقل id بين الأبواب القابلة للبيع** (النوع مدعوم · `approved` ·
`available` · التصريح مش منتهي)، محسوب **بنفس شرط المتجر مش بنسخة منه**، فالرابط بيفتح نفس
الكارت اللي البحث بيعرضه.

**والرابط بيعيش لما الباب يتقفل** — ده الغرض كله:

```
الباب الممثّل اتقفل  →  GET /units/{id الباب}   → 404   (الرابط القديم مات)
                        GET /units/{listing_id} → 200   ← انتقل للباب اللي بعده لوحده
```

| الحالة | الرد |
|---|---|
| `listing_id` لمبنى فيه باب واحد مفتوح على الأقل | **200** · الممثّل |
| `listing_id` لمبنى **كل** أبوابه مقفولة | **404** — الجواب الأمين، مافيش حاجة تتعرض |
| `u<id>` لوحدة مستقلة | **200** (أو 404 لو الوحدة نفسها مقفولة — نفس حكم الـid بالظبط) |
| **id رقمي موجود** | **زي ما هو بالحرف** — بيتحل الأول وقبل أي حاجة تانية |
| id رقمي مش موجود | **404** زي ما كان |

**التوصية:** خزّنوا `listing_id` في الروابط والمفضلة و«احجز مرة أخرى»، **مش** الـ`id`.

**رد حقيقي من الإنتاج 28/09:**

```
قبل:  GET /api/v1/units/u34  → 404        بعد:  GET /api/v1/units/u34  → 200
                                                 resolved to id=34  listing_id=u34
```

### ٤.١ج-٢ 🔴 التلات أشكال المقبولة، وترتيب الحل

المسار الواحد بقى بيقبل تلات أشكال. **الترتيب مكتوب في الكود ومختبَر، مش مستنتَج:**

| # | الشكل | بيرجّع إيه |
|---|---|---|
| **١** | **id رقمي موجود** | **الوحدة نفسها، زي ما كان بالحرف** |
| ٢ | `u<id>` أو ULID المجموعة | ممثّل الإعلان |
| ٣ | `code` الإعلان (`MRNKURY7`) | إعلانه — ولو الكود لباب في مبنى، بيرجّع **المبنى** |

**الرقمي بيتجرّب الأول وبيكسب نهائياً**، فمافيش شكل بعده يقدر ياخد مكان id.

**حقيقتان بيقفوا وراه:**

1. **`units.code` عليه UNIQUE index** — متحقَّق على الإنتاج: `units_code_unique` · `Non_unique = 0`.
   فالخطوة ٣ ماينفعش تطابق أكتر من صف.
2. **الكود مابيتقراش من body أي طلب إطلاقاً** — مافيش مسار بيسمح لعميل يحدد كود؛ السيرفر
   بس اللي بيولّده. **بس صيغته مش واحدة** (تصحيح 29/09 — النسخة الأولى من الفقرة دي قالت إن
   كل كود `MRN` + ٥ خانات، وده غلط):
   - `UnitWriter::uniqueCode()` — **إنشاء إعلان** (لوحة الشريك والأدمن) → `MRN` + ٥ خانات: `MRNXDX5D`.
   - `UnitCloner::apartmentCode()` (كان اسمها `uniqueCode()` لحد 29/09) — **إضافة شقق لمبنى** → **٨ خانات عشوائية من غير بادئة**، ممكن
     تبدأ برقم: `ELDZ5BZ9` · `1G4ADB2F` (أمثلة حقيقية من staging). مسار شغّال، ورا
     `MULTI_UNIT_ENABLED`: **مقفول على الإنتاج (`false`)، مفتوح على staging (`true`)**.
   - (`POST /api/v1/partner/units` القديم كان بيولّد نفس الصيغة، **ومتقاعد من 22/09** — 410
     `ENDPOINT_RETIRED`، `LEGACY_UNIT_WRITES=false` على البيئتين، **ومتحقَّق بنداء حقيقي على
     staging 29/09** بحساب شريك اختباري: 410، وعدد الوحدات ٤٢ قبل وبعد. الأكواد اللي عملها تراث ثابت.)
   فكود **كله أرقام** ممكن نظرياً من مولّد الشقق (احتمال ≈ ٣٫٥ في ١٠٠ ألف لكل كود). العدّ 29/09:
   **الإنتاج ٠ من ٣** بالصيغة التانية · **staging ٢٧ من ٤٢** · صفر رقمي على الاتنين. **والترتيب هو
   الضمان، مش الصيغة:** كود رقمي بيساوي id موجود بيخسر للـid ومابيتوجّهش غلط أبداً.

   > 🔴 **يوم ما `MULTI_UNIT_ENABLED` يتفتح على الإنتاج، كل شقة تتضاف لمبنى هتاخد كود بالصيغة
   > التانية — بالتصميم، مش تسريب.** الـ٠ اللي على الإنتاج النهارده سببه إن العلم مقفول، مش إن
   > المولّد اتقفل.

⚠️ ومع ذلك **الترتيب متختبَر مش مفترَض**: فيه اختبار بيكتب كود وحدة = id وحدة تانية **بالتحايل
على المولّد**، وبيثبت إن الـid لسه بيكسب. شيل فرع «الرقمي أولاً» → ستة اختبارات بتقع.

> ✅ **الخطوة ٣ (الكود) على الإنتاج من 29/09** — تاج `prod-2026-09-29-code`. اتحجزت لحد ما
> إعادة التوجيه 308 تنزل عند الواجهات (عشان `/units/{code}` من غير توجيه = شكل رابع لنفس الصفحة)،
> ونزلت على الإنتاج بإشارة المالك نفس اليوم.
>
> **متحقَّق على الإنتاج بعد النشر:** `/units/MRNXDX5D` و`/units/35` و`/units/u35` بيرجّعوا
> **نفس الـbody بالبايت** (id 35 · `listing_id: "u35"`). و`MRNL3B3X` (وحدة 37،
> `approved/unavailable`) **لسه 404** — `show()` بيقفل على الحالة بغض النظر عن الشكل اللي
> وصلت بيه، زي ما بيقفل على `/units/37` بالظبط.

### ٤.١د المفضلة بقت تعيش هي كمان

`POST /user/favorites/{unitId}` كانت بتخزّن على **أقل id في المبنى** (مش الباب اللي في الكارت)
وده كان صح من الأصل. **بس القراءة كانت بتفلتر على الصف المحفوظ نفسه** — فلما الباب ده يتقفل،
**المبنى كان بيختفي من المفضلة وهو لسه بيبيع**.

**من 28/09:** `GET /user/favorites` بتحل كل مفضلة **عبر مبناها** لأي باب قابل للبيع حالياً،
وبتسقطها **بس** لو كل الأبواب مقفولة.

- **مافيش تغيير مطلوب منكم** — التخزين زي ما هو والسلوك بقى صح.
- **وبقى ينفع تحفظوا بالمفتاح:** `POST /user/favorites/{listing_id}` شغّال.
- الرد بيرجّع الكروت بنفس ترتيب الحفظ، وكل كارت فيه `listing_id`.

### ٤.٣ `/payments/initiate` — الوحدة اللي بيتدفع عنها

`booking.unit` كان فيه أربع مفاتيح وصفية بس (`name`, `city`, `district`, `image_url`)
ومافيش أي هوية — فشاشة الدفع تقدر تسمّي المبنى ومش تقدر تقول أنهي شقة بتتدفع.

**اتزاد تلات مفاتيح (إضافة بحتة، مافيش مفتاح اتشال):**

```jsonc
POST /api/v1/payments/initiate   { "booking_id": 96 }
→ data.booking.unit = {
    "id": 39,                                  // الشقة اللي اتخصصت
    "listing_id": "01M19EZRB4ARP4BDGJ4ET7P03F",// المبنى
    "apartment_no": "402",                     // null للوحدة المستقلة، والمفتاح موجود دايماً
    "name": "…", "city": "…", "district": "…", "image_url": "…"
  }
```

### ٤.٢ `apartment_no` في `booking.unit` بس

الضيف ما كانش يعرف **أنهي باب** اتحجزله، لأن السيرفر هو اللي بيختاره من المبنى.

```jsonc
GET /api/v1/bookings/{id}
{ "unit": { "id": 69, "unit_name": "…", "apartment_no": "2" } }
```

المفتاح **مقفول على الرد ده وحده**. في `GET /api/v1/units` و`/units/{id}` **المفتاح مش موجود أصلاً** (مش `null` — **غايب**)، لأن الكارت بيعرض المبنى مش الباب.

**متحقَّق على البيئتين:** على staging `booking.unit.apartment_no = "1"` لحجز في مبنى، وعلى
الإنتاج المفتاح موجود بقيمة `null` لأن وحداته مستقلة — والقائمة العامة في الحالتين
`'apartment_no' in item → False`.

---

## ٥. `GET /config` — الأعلام وقت التشغيل

تلات مسارات، **من غير مصادقة**، نفس الرد بالظبط:

```
GET https://api.mamsaa.com/api/v1/config          ← الضيف         (الإنتاج)
GET https://api.mamsaa.com/config                 ← لوحة الشريك    (الإنتاج)
GET https://api.mamsaa.com/admin/config           ← لوحة الأدمن    (الإنتاج)

GET https://staging.mamsaa.com/api/v1/config      ← ونفس التلاتة على staging
```

رد حقيقي من **الإنتاج** (التلات مسارات متطابقين حرف بحرف):

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

وعلى **staging** نفس الشكل بالظبط، و`multiUnitEnabled: true`. **ده الفرق الوحيد بين البيئتين.**

**ليه موجود:** التلات تطبيقات بتقرا دول من `NEXT_PUBLIC_*`، وNext.js بيحرقهم **وقت البناء**. يعني قلب `MULTI_UNIT_ENABLED` على السيرفر ما كانش بيعمل حاجة لحد ما تلات تطبيقات يتبنوا ويتنشروا من تاني — وده بالظبط العَلَم اللي المفروض مشغّل يقلبه لما البيانات تجهز.

**ليه عام من غير توكن:** دول مفاتيح تشغيل مش أسرار — «هل المنصة بتقبل مباني؟» ظاهر لأي حد بيفتح الواجهة. مافيش في الرد اسم سيرفر ولا مفتاح ولا عدد. وشاشة الدخول نفسها محتاجاهم قبل ما يبقى فيه توكن.

**المسار حيّ على الإنتاج** من 27/09 — مُختبر بـHTTPS حقيقي على التلات أسطح، والتلاتة `200`.

> اقروه مرة عند إقلاع التطبيق واحتفظوا بيه، وما تبنوش عليه شاشة لكل طلب.

---

## ٦. إشعار `permit_expiring` — الحمولة كاملة

```json
{
  "type": "permit_expiring",
  "permit_id": 41,
  "unit_id": 69,
  "unit_name": "مبنى وضع أ — خطوة واحدة",
  "threshold": 30,
  "expires_at": "2027-09-23",
  "title": "…",
  "body": "لا يمكن استقبال حجوزات تنتهي إقامتها بعد 2027-09-23.",
  "href": "/units/69/permit/renew"
}
```

`body` و`href` هما المفتاحان اللي قائمة الإشعارات بترسمهم فعلاً — من غيرهم الإشعار كان بيوصل عنوان من غير تفصيل ومن غير طريق. و`href` بيبقى `null` لو الإشعار قديم ومش شايل `unit_id`.

---

## ٧. `tourismLicenseNumber` في قراءة الأدمن

قراءة الأدمن دلوقتي بتطلّع **الاتنين**:

```json
{ "tourismPermitNo": "TL-DEMO-8UNITS", "tourismLicenseNumber": "TL-DEMO-8UNITS" }
```

نفس القيمة، حرف بحرف. الكونسول بيكتب `tourismLicenseNumber` وبيقرا `tourismPermitNo` من زمان — فالاتنين بيتبعتوا عشان تتحوّلوا لواحد من غير يوم قطع. **الجديد `tourismLicenseNumber`**، وهو اللي المفروض تستقروا عليه.

---

## ٨. اللي **ما اتغيّرش**

- أغلفة الأسطح التلاتة، وأكواد الأخطاء الموجودة، وكل مسارات المراحل ١–٣.
- التصريح لسه مملوك **للنطاق** (وحدة أو مجموعة)، وأعمدة الوحدة **نسخ للقراءة**.
- الانتهاء لسه **سقف محسوب، مش حالة مكتوبة**: `approval_status` ما بيتغيّرش، و`null` ما بيسقّفش حاجة، والخروج **في يوم** الانتهاء مسموح.
- استثناءات تكرار الرقم لسه `50047139` وبس، و**مضبوطة على البيئتين** من 27/09
  (`config('permits.uniqueness_exceptions') === ['50047139']` على الإنتاج). دي موجودة لأن
  وحدتين حقيقيتين على الإنتاج بتشتركا في نفس رقم التصريح، مش قاعدة عامة.
