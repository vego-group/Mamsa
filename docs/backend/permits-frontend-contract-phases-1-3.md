# عقد التصاريح للواجهات — المراحل ١ و٢ و٣

**مكتوب من الكود المنشور على الإنتاج:** `prod-2026-09-27`
**الأمثلة:** طلبات حقيقية على staging والإنتاج (نفس كود التصاريح بالظبط)
**تاريخ:** 22/09/2026 · **محدَّث 28/09/2026 بعد النشرة الموحّدة**

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
> **الترتيب ده مضمون: الأحدث أولاً، وعند التعادل بالـ`id` تنازلي.**
>
> **ومع ذلك، ماتبنوش على ترتيب الرد أي حاجة:** جمّعوا بـ`groupId` ورتّبوا بـ`apartmentNo` بنفسكم.
> ترتيب الرد ثابت، بس مش ترتيب الأبواب.

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

**`fields` هنا بمفاتيح الويزارد** (نفس مفاتيح `submit`)، و**`meta.unit_id` هو الوحدة الأصل** بصيغة `u_{id}` — عشان الواجهة توجّه الشريك للإعلان اللي فعلاً ناقص، مش للشقق اللي لسه ما اتعملتش.

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

**كل حاجة في الملف ده وفي الملحق منشورة على الإنتاج.** مافيش بند مفتوح.
