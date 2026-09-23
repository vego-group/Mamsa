# عقد التصاريح للواجهات — المراحل ٤ و٦ (الملحق)

**مكتوب من الكود المنشور على staging:** commit `5d29d18` · النشر 23/09/2026
**الأمثلة:** ردود حقيقية مأخوذة من `staging.mamsaa.com` النهارده، مش من الذاكرة
**البيئة:** ✅ **staging فقط.** ❌ الإنتاج لسه على المراحل ١–٣ (`prod-2026-09-22-refunds`)

> الملف ده **ملحق** لـ [`permits-frontend-contract-phases-1-3.md`](permits-frontend-contract-phases-1-3.md).
> ده بيوصف **staging**، وده بيوصف **الإنتاج**. أي حاجة مش مذكورة هنا، خدوها من هناك.

---

## ٠. اللي اتغيّر في سطر واحد

| البند | السطح | الحالة |
|---|---|---|
| `POST /units/{id}/submit` بياخد `{count, permits}` — إنشاء + تقديم في نداء واحد | الشريك | ✅ staging |
| `permit` + `addressMatch` + `group` في `GET /admin/approvals/{id}` | الأدمن | ✅ staging |
| `group_size` جنب `available_count` | الضيف | ✅ staging |
| `apartment_no` في `booking.unit` | الضيف | ✅ staging |
| `permitAddress*` + `permitExpiresAt` قابلين للكتابة على الوحدة نفسها | الشريك + الأدمن | ✅ staging |
| `tourismLicenseNumber` كمفتاح إضافي في قراءة الأدمن | الأدمن | ✅ staging |
| `GET /config` — الأعلام وقت التشغيل | التلاتة | ✅ staging |
| `unit_id` + `body` + `href` في إشعار `permit_expiring` | الشريك | ✅ staging |

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
      "apartmentNo": "2",              // اختياري — لو موجود بيحدد الباب
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
| شقة جديدة تصريحها **منتهي** | `400 VALIDATION` + `fields.permitExpiresAt` | draft · صفر شقق |
| الوحدة الأصل ناقصة بيانات | `400 VALIDATION` + `fields` | draft · صفر شقق |
| الوحدة مش draft/rejected | `409 UNIT_NOT_SUBMITTABLE` | كما هي |

السطر قبل الأخير هو السبب اللي خلّى الفحص يتكرر على **كل باب** مش على الأصل بس: الأصل ممكن يكون تصريحه سليم وباب جديد يكون جايب تاريخ منتهي، والباب ده كان هيوصل لطابور المراجعة تحت تصريح ميت.

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

**عدد مفاتيح الرد العام بقى ٣٣ بدل ٣٢** — لو عندكم تأكيد على العدد، حدّثوه.

### ٤.٢ `apartment_no` في `booking.unit` بس

الضيف ما كانش يعرف **أنهي باب** اتحجزله، لأن السيرفر هو اللي بيختاره من المبنى.

```jsonc
GET /api/v1/bookings/{id}
{ "unit": { "id": 69, "unit_name": "…", "apartment_no": "2" } }
```

المفتاح **مقفول على الرد ده وحده**. في `GET /api/v1/units` و`/units/{id}` **المفتاح مش موجود أصلاً** (مش `null` — **غايب**)، لأن الكارت بيعرض المبنى مش الباب. متحقَّق النهارده على staging: `'apartment_no' in item → False`.

---

## ٥. `GET /config` — الأعلام وقت التشغيل

تلات مسارات، **من غير مصادقة**، نفس الرد بالظبط:

```
GET https://staging.mamsaa.com/api/v1/config      ← الضيف
GET https://staging.mamsaa.com/config             ← لوحة الشريك
GET https://staging.mamsaa.com/admin/config       ← لوحة الأدمن
```

رد حقيقي من staging النهارده (التلاتة متطابقين):

```json
{
  "flags": {
    "multiUnitEnabled": true,
    "permitExpiryRequired": false,
    "legacyUnitWritesEnabled": false
  },
  "permitWarningDays": 30
}
```

**ليه موجود:** التلات تطبيقات بتقرا دول من `NEXT_PUBLIC_*`، وNext.js بيحرقهم **وقت البناء**. يعني قلب `MULTI_UNIT_ENABLED` على السيرفر ما كانش بيعمل حاجة لحد ما تلات تطبيقات يتبنوا ويتنشروا من تاني — وده بالظبط العَلَم اللي المفروض مشغّل يقلبه لما البيانات تجهز.

**ليه عام من غير توكن:** دول مفاتيح تشغيل مش أسرار — «هل المنصة بتقبل مباني؟» ظاهر لأي حد بيفتح الواجهة. مافيش في الرد اسم سيرفر ولا مفتاح ولا عدد. وشاشة الدخول نفسها محتاجاهم قبل ما يبقى فيه توكن.

**قيم الإنتاج دلوقتي** (مقروءة من إعدادات الإنتاج اليوم، مش من `.env` بالعين): `multiUnitEnabled: false` · `permitExpiryRequired: false` · `legacyUnitWritesEnabled: false` · `permitWarningDays: 30`. المسار نفسه **لسه مش منشور على الإنتاج** — هييجي مع النشرة الموحّدة.

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
- استثناءات تكرار الرقم لسه `50047139` وبس. على **staging** القيمة دي مضبوطة، وعلى **الإنتاج** المفتاح لسه **مش موجود أصلاً** (فحص اليوم: `config('permits.uniqueness_exceptions') === null`) لأن ملف الإعدادات اللي فيه المفتاح ما اتنشرش هناك — وده شرط مسبق مكتوب في خطة النشر.
