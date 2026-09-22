# رد — التصاريح والوحدات المتعددة: الجزء ٢ بالدليل، ثم رأينا في الجزء ٣

**التاريخ:** 22/09/2026
**رداً على:** `mamsa-permits-multiunit-backend.md`
**الحالة:** 📖 **مفيش كود اتكتب ومفيش حاجة اتنشرت** — ده رد قراءة زي ما طلبتوا. كل رقم من الداتا `SELECT` بس، وكل استشهاد كود بمسار وسطر على الفرع `feat/complaints-refunds` (نسخة staging). لما الفرق عن الإنتاج بيهم، بنسمّيه.

**ملاحظة على الفحص الحي:** عشان نجيب رد السؤال ٩ من staging فعلاً، صنّفنا الوحدة التجريبية `u_24` مؤقتاً ورجّعناها. الرحلة دي سابت **٣ قيود في feed الأدمن التجريبي** و`submitted_at` جديد على `u_24`. مفيش حاجة تانية اتلمست.

---

## الجزء ٢ — الإجابات

### الـ schema

**١. `SHOW CREATE TABLE`** — من **الإنتاج** (MariaDB 11.8.9). الجداول اللي لها علاقة بالتصاريح أو المجموعات أو المراجعة أو الـ audit هي التلاتة دول؛ **مفيش جدول تصاريح ولا جدول مجموعات ولا جدول قرارات مراجعة** — الثلاثة كلهم أعمدة على `units`.

```sql
CREATE TABLE `units` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `mamsa_owned` tinyint(1) NOT NULL DEFAULT 0,
  `unit_name` varchar(255) DEFAULT NULL,
  `unit_type` varchar(50) DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `unit_group_id` char(26) DEFAULT NULL,
  `apartment_no` varchar(20) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `capacity` tinyint(3) unsigned DEFAULT NULL,
  `bedrooms` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `beds` tinyint(3) unsigned DEFAULT NULL,
  `bathrooms` tinyint(3) unsigned DEFAULT NULL,
  `area` int(10) unsigned DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `district` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `tourism_permit_no` varchar(255) DEFAULT NULL,
  `license_type` enum('tourist_facility','private_hospitality') DEFAULT NULL,
  `licensed_units_count` smallint(5) unsigned DEFAULT NULL,
  `tourism_permit_file` varchar(255) DEFAULT NULL,
  `ownership_doc_file` varchar(255) DEFAULT NULL,
  `company_license_no` varchar(255) DEFAULT NULL,
  `approval_status` enum('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `status` enum('available','unavailable') NOT NULL DEFAULT 'available',
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `cancellation_policy` enum('no_cancel','48_hours') NOT NULL DEFAULT 'no_cancel',
  `cancellation_policy_id` bigint(20) unsigned DEFAULT NULL,
  `checkin_time` time DEFAULT NULL,
  `checkout_time` time DEFAULT NULL,
  `calendar_token` varchar(60) DEFAULT NULL,
  `ical_import_url` varchar(2048) DEFAULT NULL,
  `ical_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `units_code_unique` (`code`),
  UNIQUE KEY `units_calendar_token_unique` (`calendar_token`),
  KEY `units_user_id_foreign` (`user_id`),
  KEY `units_cancellation_policy_id_foreign` (`cancellation_policy_id`),
  KEY `units_unit_group_id_index` (`unit_group_id`),
  CONSTRAINT `units_cancellation_policy_id_foreign` FOREIGN KEY (`cancellation_policy_id`) REFERENCES `cancellation_policies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `units_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_units_facility_license_has_count` CHECK (`license_type` <> 'tourist_facility' or `licensed_units_count` is not null)
);

CREATE TABLE `dashboard_uploads` (

  `id` varchar(40) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(30) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `size` bigint(20) unsigned DEFAULT NULL,
  `width` int(10) unsigned DEFAULT NULL,
  `height` int(10) unsigned DEFAULT NULL,
  `variants` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`variants`)),
  `path` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dashboard_uploads_user_id_foreign` (`user_id`),
  CONSTRAINT `dashboard_uploads_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

CREATE TABLE `audit_logs` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `auditable_type` varchar(255) NOT NULL,
  `auditable_id` bigint(20) unsigned NOT NULL,
  `action` varchar(60) NOT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `before` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before`)),
  `after` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after`)),
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  KEY `audit_logs_actor_id_foreign` (`actor_id`),
  CONSTRAINT `audit_logs_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
```

ملاحظات على اللي فوق:
- `unit_group_id` **label** (`char(26)` ULID) عليه index، **مش FK** لأي جدول — المجموعة مفيش لها صف.
- `audit_logs` موجود (NFR-014) وبيتكتب فيه للشكاوى والحجوزات والاستردادات (`app/Http/Controllers/AdminPanel/ComplaintsController.php:231,277,368,425`، `app/Actions/Bookings/CancelBookingAction.php:159,179`). **ما بيتكتبش فيه أي حاجة عن اعتماد/رفض الوحدات** — `grep "AuditLog::record" app/` ما فيه ولا سطر يخص `Unit`.
- staging على MySQL 8.4.10 بنفس الأعمدة (parity md5 على `database/migrations` متطابقة).

**٢. أعمدة عنوان الإعلان:** `city varchar(100)` · `district varchar(150)` · `address varchar(255)` (نص حر) · `lat decimal(10,7)` · `lng decimal(10,7)`. **مفيش رقم مبنى ولا رقم وحدة** — `apartment_no varchar(20)` هو رقم الباب جوّه المجموعة (401، 402…) مش رقم الوحدة اللي على التصريح.

**٣. المدينة والحي:** **نص حر، مش ID.**
- المدينة: العمود بيخزّن الاسم العربي (`مكة المكرمة`)، بس **كل الكتابة بتعدّي على خريطة واحدة** `App\Support\City::toArabic()` (`app/Support/City.php:8-13`) بتحوّل أي شكل بيبعته العميل (slug، إنجليزي، aliases) للاسم المخزّن الواحد. يعني المدينة **قابلة للمقارنة الآلية بأمان** رغم إنها نص، لأن مفيش غير كتابة واحدة معتمدة لكل مدينة.
- الحي: نص حر بالكامل، `max:150` (`app/Support/Units/UnitWriter.php:90`). **مش قابل للمقارنة الآلية.**

**٤. رقم التصريح وملفه:** **على صف الشقة**، الاتنين (`units.tourism_permit_no`، `units.tourism_permit_file`). لما المجموعة بتتوسّع بتصريح مرفق، الـ cloner **بينسخ القيمتين على كل صف** (`app/Support/Units/UnitCloner.php:76-81` قائمة `DOCUMENTS`، وبتتنسخ لما `copyDocuments = true`). يعني في وضع ب اللي منشور، نفس الرقم ونفس الملف موجودين N مرة — مفيش صف واحد «للتصريح».

**٥. تفرّد أو تحقق على رقم التصريح:** **مفيش.** الـ `UNIQUE` الوحيدان على `units` هما `code` و`calendar_token` (شوف الـ schema). التحقق الوحيد: `'tourismLicenseNumber' => ['sometimes','nullable','string','max:50']` (`UnitWriter.php:100`) — نص حر لحد ٥٠ حرف، والعمود نفسه `varchar(255)`. **مفيش تطبيع** (مسافات، أرقام عربية/هندية).

**٦. (A2) اسم الحقل:** الاسمان الاتنين شغالين، **بس على اتجاهين مختلفين**:

| السطح | الكتابة | القراءة |
|---|---|---|
| لوحة الشريك (`/units`) | `tourismLicenseNumber` (`UnitWriter.php:100,188`) | `tourismLicenseNumber` (`app/Support/Dashboard/UnitPresenter.php:56`) |
| لوحة الأدمن (`/admin/units`) | `tourismLicenseNumber` (نفس `UnitWriter::rules`) | **`tourismPermitNo`** (`app/Support/AdminPanel/UnitPresenter.php:168`) + `tourismLicenseFileId` (`:185`) |
| `/api/v1` العام | — | `tourism_permit_no` (`app/Http/Resources/UnitResource.php:123`، للمالك بس) |
| `/api/v1/partner` | `tourism_permit_no` **أو** `tourismLicenseNumber` (`Api/V1/Partner/UnitController.php:54,138,209`) | `tourism_permit_no` |

**الرسمي للكتابة على اللوحتين: `tourismLicenseNumber`.** الأدمن بيقرا `tourismPermitNo` — ده الشرخ الوحيد، وموجود من أول نسخة الـ presenter. نقترح **إضافة `tourismLicenseNumber` كمفتاح تاني في قراءة الأدمن** (إضافي، `tourismPermitNo` يفضل) عشان الاسم يبقى واحد في الاتجاهين، والواجهة تحوّل على مهلها.

### الوحدات المتعددة

**٧. `POST /units/{id}/apartments` بينسخ إيه:** **كل أعمدة الأصل** ما عدا قائمتين (`UnitCloner.php:44-62`):
- `NOT_COPIED` (هوية وتاريخ): `id, code, calendar_token, unit_group_id, apartment_no, created_at, updated_at, approval_status, submitted_at, rejection_reason, is_featured, ical_import_url, ical_synced_at`
- `DOCUMENTS` (بتتنسخ **بس** لما `copyDocuments = true`): `tourism_permit_no, tourism_permit_file, ownership_doc_file, company_license_no`

الصور بتتشارك كصفوف (نفس الملف على القرص، reference-counted)، والمرافق بتتنسخ. **مسار الشريك على اللوحة ومسار الأدمن الاتنين بيمرروا `copyDocuments: true` دايماً** (`Dashboard/UnitController.php:240`، `AdminPanel/UnitsController.php` في `apartments()`) لأن التوسيع مرفوض من الأصل إلا بتصريح مرفق، وتصريح المرفق صادر للعقار. مسار `/api/v1/partner` القديم فيه بارامتر `copy_documents` اختياري (`Api/V1/Partner/UnitController.php:290-293`).

**تصريح مختلف لكل شقة؟ مش موجود.** الـ payload `{ count }` بس. مفيش أي مدخل لتصريح لكل شقة على أي سطح من التلاتة.

**٨. (P2) الشريك عدّل تصريح شقة من `PATCH /units/{apartmentId}`:**
- **الشقة بتفضل في المجموعة** — `unit_group_id` و`apartment_no` مش من الأعمدة اللي `toColumns()` بيكتبها.
- **الرقم والملف بيتكتبوا على الصف ده بس** (`Dashboard/UnitController.php:113-121`) — باقي الشقق **ما بتتأثر.** يعني ينفع دلوقتي، بالكود الموجود، إن مجموعة وضع ب تنتهي بأرقام تصاريح مختلفة جوّاها — وده حاصل فعلاً على staging (`#39` رقمه `TL-50000` والمجموعة `TL-DEMO-8UNITS`، شوف الجزء ٣.٨).
- **الاستثناء:** `licenseType` و`licensedUnitsCount` **بيتكتبوا على المجموعة كلها** عبر `UnitLicense::applyToGroup()` (`:97-107`)، وحارس على الموديل بيمنع كتابتهم من أي مكان تاني (`app/Models/Unit.php::booted()`).
- ولو الشقة كانت `approved`، التعديل بيرجّعها `pending` **هي بس** (`:116-118`). شفناها حية النهاردة: تصنيف `u_24` بالتصريح رجّعها `pending` رغم إن التعديل كان الترخيص بس.

**٩. (P4) رد فشل التوسيع لما الأصل ناقص — من staging النهاردة**، على `u_24` (معتمدة، ناقصها `beds` وملف التصريح):

```
POST /units/u_24/apartments  {"count":3}          ← بدون تصنيف
422
{"error":{"code":"MULTI_UNIT_REQUIRES_FACILITY_LICENSE",
          "message":"أكثر من وحدة يتطلب تصريح مرفق ضيافة سياحي",
          "meta":{"license_type":null,"max_units":1}}}

PATCH /units/u_24  {"licenseType":"tourist_facility","licensedUnitsCount":8}   → 200
POST /units/u_24/apartments  {"count":3}
422
{"error":{"code":"SOURCE_UNIT_INCOMPLETE",
          "message":"أكمل بيانات الوحدة الأصلية قبل إضافة وحدات إليها",
          "fields":{"beds":"عدد السراير مطلوب","tourismLicenseFileId":"ملف الرخصة مطلوب"},
          "meta":{"unit_id":"u_24"}}}
```

يعني اللي قلناه صح: **`422` بكود `SOURCE_UNIT_INCOMPLETE`**، و`fields` فيها مفاتيح الويزارد، و`meta.unit_id`. الترتيب: التصريح أولاً، بعده الاكتمال. لو الواجهة بتشوفه `400 VALIDATION` فهي بتقرأ الـ `fields` قبل الـ `code` — الكود هو اللي يتفرّع عليه.

**١٠. (G1 / G2) `GET /units` العام والحجز:**
- **صف واحد للمجموعة.** الليستة بتختار ممثّل واحد لكل مجموعة (`MIN(units.id)` مجمّعة على `unit_group_id`) وبترجّعه بـ `available_count` (`app/Http/Controllers/Api/V1/UnitController.php:150-164,174`). **مش N صف.** G1 كما هي مكتوبة مش حاصلة على الـ API — الحاصل إن الواجهة **ما بتقراش `available_count`**، فالكارت بيقول «استوديو» من غير ما يقول إن فيه ٤.
- **الحجز: السيرفر بيحوّل لشقة فاضية في نفس المجموعة، مش بيرفض.** `POST /bookings` بيقفل كل شقق المجموعة المعتمدة المتاحة بترتيب الـ id (`lockForUpdate`) وبيحجز **أول واحدة فاضية** في التواريخ، وبيسعّر من الشقة اللي اتخصصت فعلاً (`Api/V1/BookingController.php:205-244`). لو مفيش ولا واحدة فاضية → `UNIT_UNAVAILABLE` / `UNIT_BLOCKED`. **يعني `unit_id` في رد الحجز ممكن يختلف عن اللي الضيف بعته** — تطبيق الضيف لازم يعرض `booking.unit` من الرد، مش من الكارت.

### وحدات مَمسَى والمراجعة

**١١. تمييز وحدة مَمسَى في الـ DB:** عمودان: `units.mamsa_owned tinyint(1)` (المصدر، من migration `2026_07_28_000002`) و`units.user_id = User::platform()->id` (حساب المنصة، `app/Models/User.php:44`، منذ 19/09 — على الإنتاج `#23`). الأول هو اللي بيتفرّع عليه الكود؛ التاني بيمنع إن اسم بشري يظهر كمالك.

**١٢. (A1) `POST /admin/units` و`PATCH /admin/units/{id}` بيقبلوا `licenseType` و`licensedUnitsCount`؟** **أيوه** — نفس `UnitWriter::rules()` بتاعة الشريك، والاتنين بيمرروهم لـ `UnitLicense::applyToGroup()` (`AdminPanel/UnitsController.php:139` للإنشاء، `:180` للتعديل). **على staging من 16/09 وعلى الإنتاج من 19/09.** A1 اللي لاحظتوه هو إن **الويزارد ما بيبعتهمش** — الـ API بيقبلهم.

**١٣. (A5) تسجيل مين أنشأ ومين اعتمد:** **مش موجود.** مفيش `created_by` ولا `approved_by` ولا `rejected_by` على `units` (الـ schema فوق)، ومفيش جدول قرارات، و`audit_logs` ما بيتكتبش فيه للوحدات (السؤال ١). الأثر الوحيد: `submitted_at` (وقت التقديم) و`updated_at` كبديل لوقت القرار. الكود نفسه بيعترف: `app/Http/Resources/AdminPanel/AdminProfileResource.php:42` — «Not yet tracked per-admin (no reviewer audit trail) — honest zeros».
**استثناء يستاهل يتقال:** مين أنشأ الأربع وحدات مَمسَى على الإنتاج **معروف بالدليل** — migration الترحيل 19/09 سجّلت `from_user_id: 14` لكل وحدة في اللوج. لو عملنا `created_by`، الأربعة دول يتملّوا من اللوج، مش من تخمين.

**١٤. حسابات `superadmin` على الإنتاج: اتنين — وواحد بس نشط.**
```
#14  is_active = 0   (مشرف تجريبي — اللي أنشأ الأربع وحدات)
#19  is_active = 1
```
مفيش دور `Admin` على الإنتاج (0)، ومفيش دور `finance` أصلاً. **ده بيأثر مباشرة على ٣.٤ — شوف الرأي.**

**١٥. (A7) الصلاحيات على السيرفر:** **موجودة، على كل mutation على سطح الأدمن**، بالضبط بالتقسيم اللي طلبتوه في ٣.٥:

```php
// routes/admin-panel.php
:79   Route::patch('units/{id}', …update)            ->middleware('admin.can:units.manage')
:80   Route::delete('units/{id}', …destroy)          ->middleware('admin.can:units.manage')
:83   Route::post('units/{id}/unpublish', …)         ->middleware('admin.can:units.manage')
:104  Route::post('approvals/{id}/approve', …)       ->middleware('admin.can:approvals.manage')
:105  Route::post('approvals/{id}/reject', …)        ->middleware('admin.can:approvals.manage')
```
والـ middleware (`app/Http/Middleware/EnsureAdminPermission.php:57-58`) بيرد `403 { code: "INSUFFICIENT_PERMISSION" }` — **نفس الكود اللي الواجهة بتعرضه.** يعني ٣.٥ **موجود أصلاً** كما هو مكتوب.
للدقة: سطح `/api/v1/admin` القديم (testvue) بيستخدم `role:Admin|SuperAdmin` (`routes/api.php:242`) — دور مش صلاحية. لو testvue هيتقاعد فده مش مهم؛ لو لأ، ده الفرق.

**١٦. (A8) الـ `notes` في الرفض:** **بتتحقق وبتتحذف.** `ApprovalsController::reject()` بيتحقق منها (`'notes' => ['sometimes','nullable','string','max:1000']`، `:191`) وبعدين بيكتب `rejection_reason` بس (`:196`). مفيش عمود ليها. **لا الشريك بيشوفها ولا الأدمن** — بتضيع.

**١٧. (A3) `GET /admin/approvals/{id}` بيرجّع العنوان التفصيلي وlat/lng؟** **أيوه.** الرد = `approvalRow(...)` + `unit: detail(...)` (`ApprovalsController.php:155`)، و`detail()` فيه `address` (`AdminPanel/UnitPresenter.php:157`) و`lat`/`lng` (`:165-166`) و`city`/`district` (`:68-69`). **A3 فجوة عرض، مش فجوة API** — البيانات في `unit.address` و`unit.lat` و`unit.lng`.

### الظهور للضيف

**١٨. (G3) `GET /units/{id}` لوحدة غير معتمدة — من staging النهاردة:**
```
GET /api/v1/units/20  (pending)   → 404 {"message":"الوحدة غير متاحة"}
GET /api/v1/units/13  (rejected)  → 404 {"message":"الوحدة غير متاحة"}
GET /api/v1/units/14  (draft)     → 404 {"message":"الوحدة غير متاحة"}
POST /api/v1/bookings {"unit_id":20,…}  (كضيف مسجّل) → 404 {"message":"المورد غير موجود","code":"NOT_FOUND"}
```
الكود: `Api/V1/UnitController.php:312-316` (الـ show) و`Api/V1/BookingController.php:175-178` (`firstOrFail` على `approved` + `available`). **الضيف ما يقدرش يفتح ولا يحجز غير المعتمد**، حتى لو الواجهة ما بتفحصش. الاعتماد على الـ API هنا صح.

**١٩. (G4) `status` ولا `approval_status`:** **الاتنين مع بعض، في كل مكان.** كل استعلام عام بيشترط `approval_status = 'approved' AND status = 'available'` (`Api/V1/UnitController.php:54-55, 218-219, 238-239, 265-266, 286-287, 313-314`). القيم:
- `approval_status`: `draft | pending | approved | rejected` — **ملحوظة: `pending_review` مش موجودة**، الاسم `pending`.
- `status`: `available | unavailable` — ده مفتاح الشريك/الأدمن لإخفاء وحدة معتمدة بدون ما تفقد اعتمادها (مثال: `#39` على الإنتاج معتمدة و`unavailable`).

### التصاريح

**٢٠. Job بيتعامل مع التصاريح:** واحد بس، وعن **اتساق** مش **انتهاء**: `units:check-licenses --alert` يومياً 03:00 (`routes/console.php:59`، `app/Console/Commands/CheckUnitLicenses.php:27-29`) — بيبلّغ لو مجموعة اختلفت شققها في `license_type`/`licensed_units_count`. **مفيش أي حاجة عن تاريخ الانتهاء** لأن التاريخ مش متخزّن أصلاً.

**٢١. مقارنة عنوان التصريح بعنوان الإعلان:** **مش موجود.** عنوان التصريح نفسه مش متخزّن.

### أرقام من الداتا (`SELECT` بس)

**٢٢. الوحدات لكل `license_type`:**

| | الإنتاج | staging |
|---|---|---|
| `NULL` | **4** (كل الوحدات) | 27 |
| `tourist_facility` | 0 | 12 |
| `private_hospitality` | 0 | 0 |

**٢٣. أرقام التصاريح المكررة بين أكتر من وحدة:**

| | العدد | التفاصيل |
|---|---|---|
| **الإنتاج** | **1** | `50047139` → وحدتان `#35` و`#37`، **مستقلتان** (مش في مجموعة) |
| staging | 4 | `TL-DEMO-8UNITS` ×4 و`TL-STG-APT-001` ×4 (كل واحد جوّه مجموعة واحدة — ده وضع ب الطبيعي) · `TL-TEST-0001` ×2 و`TL-TEST-0002` ×2 (وحدات تجريبية مستقلة) |

**يعني قاعدة التفرّد في ٣.١ ما تنفعش تتفعّل كـ `UNIQUE` على الإنتاج** قبل ما `#35/#37` تتحسم بالمراجعة. **بس تنفع تتفعّل فوراً على الكتابة الجديدة** (الصفوف الموجودة تتعامل كاستثناء). ولاحظوا إن التفرّد لازم يستثني المجموعة الواحدة في وضع ب — نفس الرقم على ٨ شقق تصريح مرفق **مقصود**.

**٢٤. المجموعات:**

| | العدد | التفاصيل |
|---|---|---|
| **الإنتاج** | **0** | — |
| staging | 2 | `01M19EZ…` حجم 8 `[30,39,40,41,42,53,54,55]` تصريح مرفق (٥ معتمدة، ٣ قيد المراجعة) · `01M2X9K…` حجم 4 `[57,61,62,63]` تصريح مرفق (١ معتمدة + ٣ قيد المراجعة — دي بتاعتنا من 19/09) |

**٢٥. وحدات مَمسَى، ومنها `approved` من غير `submitted_at`:**

| | وحدات مَمسَى | `approved` بدون `submitted_at` |
|---|---|---|
| **الإنتاج** | 4 | **1 — `#39`** (وحدة اختبار الدفع؛ بدون رقم تصريح ولا ملف، `status = unavailable`) |
| staging | 4 (بتاعتنا `#57,61,62,63`) | 0 |

(على staging كلياً: 19 وحدة معتمدة بدون `submitted_at` — كلها بذور اختبار من قبل عمود `submitted_at` في 15/08.)

---

## الجزء ٣ — رأينا، بند بند، قبل أي كود

### ٣.١ وضعا التصريح

**اللي موجود أصلاً ومش محتاج تغيير:**
- القاعدة ٥ (المجموعة ليها وضع واحد): **مفروضة النهاردة** — `license_type` جماعي على المجموعة بحارس على الموديل (`Unit::booted()`) وبكاتب واحد (`applyToGroup`) وبفحص يومي. الوضع = `license_type` المجموعة: `tourist_facility` = ب، `private_hospitality` = أ. مفيش داعي لعمود «وضع» جديد.
- القاعدة ٤ (المراجعة شقة شقة): **هي كده النهاردة** — كل صف له `approval_status` بتاعه، والاعتماد/الرفض بيشتغل على صف (`ApprovalsController::approve/reject`). رفض شقة ما بيلمس التانية. صح في الوضعين.
- القاعدة ٩ (النوع مش مربوط بالحساب): **مفيش أي ربط في الكود** — `UnitWriter::rules()` ما بتقراش `partnerDetail.type`. مفيش حاجة تتشال، وبنلتزم إن مفيش حاجة تتضاف.
- القاعدة ١ (وضع ب زي ما هو): موافقين.

**اللي بيتغيّر فعلاً — وده أكبر من «سطرين»:**

1. **حارس الحجم `guardGroupSize`/`guardLicenceCovers` بيرفض أي مجموعة > 1 لو النوع مش `tourist_facility`** (`UnitLicense.php:161-167`). القاعدة ٨ بتقول ده يبقى في وضع ب بس — يعني في وضع أ المجموعة تكبر **بشرط** إن كل صف جديد جايب تصريحه. الحارس بيتغيّر من «النوع بيسمح بالحجم؟» لـ «النوع بيسمح بالحجم، أو كل صف جديد له تصريح مستقل؟». والـ CHECK على الـ DB (`chk_units_facility_license_has_count`) ما بيتأثرش — هو بيربط المرفق بالعدد بس.
2. **الـ cloner بينسخ أو ما بينسخش — مش بيستقبل.** وضع أ محتاج الـ cloner يستقبل تصريح لكل صف جديد ويكتبه بدل النسخ. تغيير واضح المكان (`UnitCloner::assign/cloneOne`).
3. **التفرّد (القاعدة ٣) لازم يتعرّف بدقة:** «رقم التصريح مش مستخدم في أي وحدة تانية» **ما ينفعش حرفياً** لأن وضع ب بيكرّره N مرة عن قصد. التعريف الصح: **الرقم الواحد يبقى إما على صف واحد (وضع أ) أو على مجموعة واحدة (وضع ب) — ومفيش تالت.** ومش ممكن يتعمل كـ index جزئي على MariaDB/MySQL؛ بيتفرض في التطبيق تحت `lockForUpdate` + فحص يومي (نفس نمط الترخيص)، **بعد تطبيع** (السؤال ٥: مفيش تطبيع النهاردة — `50047139` و`٥٠٠٤٧١٣٩` و` 50047139` تلاتة أرقام مختلفة للـ DB).
4. **الحجز في وضع أ (السؤال ١٠):** الـ allocator بيختار أي شقة معتمدة متاحة في المجموعة. في وضع أ كل شقة **تصريحها وتاريخ انتهائها بتوعها** — فالـ allocator لازم يستثني الشقة اللي تصريحها بينتهي قبل خروج الضيف (٣.٢ ب). **البندان لازم يتنفّذوا مع بعض**، وإلا ضيف يتخصص له شقة تصريحها منتهي وقت إقامته.

**شكل الـ payload اللي بنقترحه (القاعدة ٦ و٧):**

```json
POST /units/{id}/apartments            ← نفس المسار، على اللوحتين
{
  "count": 4,                          ← إجمالي المجموعة، زي دلوقتي
  "permits": [                         ← وضع أ بس. غيابه = وضع ب
    { "apartmentNo": "402",            ← اختياري؛ لو غاب السيرفر بيرقّم
      "number": "50048801",
      "fileId": "file_…",
      "expiresAt": "2027-06-30",
      "address": { "city": "riyadh", "district": "النرجس", "building": "12", "unitNo": "3" } },
    …
  ]
}
```
- `permits.length` **لازم = عدد الشقق الجديدة** (`count − الحجم الحالي`)، وإلا `PERMITS_COUNT_MISMATCH { requested: <الجديدة>, permits_provided }`. `requested` هنا عدد الجديدة مش الـ count — عشان الرسالة تطابق اللي الشريك لازم يرفعه.
- وضع ب + `permits` موجودة → `PERMIT_MODE_MIXED { group_mode: "single_permit" }`. وضع أ + `permits` غايبة → `PERMIT_MODE_MIXED { group_mode: "per_unit" }`. (بنقترح القيمتين دول لـ `group_mode`.)
- atomic زي دلوقتي: `DB::transaction` حوالين الإنشاء + التحقق من كل تصريح **قبل** أول كتابة.

**على «الإنشاء بعدد خطوة واحدة»:** ممكن تقنياً (`presign` مستقل عن الوحدة، فالملفات N تتحضّر قبل `POST /units`)، بس بنرجّح **الإبقاء على إنشاء واحد ثم توسيع بتصاريح** لسببين: (١) الويزارد بيكمّل الوحدة على مراحل (صور، وصف) والنسخ بتاخد نسخة اللحظة — إنشاء N من الأول يعني N مسودة ناقصة تتكمّل واحدة واحدة، وده أسوأ من الوضع الحالي؛ (٢) المسار واحد على اللوحتين ومختبَر. لو الرأي غير كده، `POST /units` يستقبل `count` + `permits` — نفس الـ transaction.

### ٣.٢ تاريخ انتهاء التصريح

**أ. التخزين — والسؤال ٤ بيقول العمود على الصف، وبنقترح نغيّر ده:**

النهاردة التصريح **٤ أعمدة منسوخة على كل صف** (الرقم، الملف، النوع، العدد). الانتهاء + عنوان التصريح + التجديد بسجل تاريخي + التفرّد = **٨+ أعمدة كمان منسوخة**، والتجديد «ما يكتبش فوق الحالي» **مستحيل تصميمياً على صف الوحدة** — مفيش مكان لتصريحين.

**فبنقترح جدول `permits`:**

```
permits
  id
  scope_type   ENUM('unit','group')     ← وضع أ: unit · وضع ب: group
  scope_id     (units.id أو unit_group_id)
  number       varchar(64)  (مطبّع)
  file_id      → dashboard_uploads.id
  license_type ENUM(tourist_facility, private_hospitality)
  licensed_units_count  smallint NULL
  expires_at   DATE NULL                ← NULL للقديم، ممنوع التخمين
  addr_city / addr_district / addr_building / addr_unit_no   NULL
  status       ENUM('current','pending','rejected','superseded')
  created_by, reviewed_by, reviewed_at, rejection_reason, review_notes
  created_at, updated_at
```
- **`units.tourism_permit_no/_file/license_type/licensed_units_count` تفضل موجودة كقراءة منسوخة من التصريح الـ `current`** — يعني ولا قارئ واحد بيتكسر (الثلاث واجهات، `/api/v1`، الـ cloner، التقارير)، والكتابة بتبقى من مكان واحد. الترحيل: صف `permits` لكل رقم موجود (وضع ب: صف واحد للمجموعة)، `expires_at = NULL`.
- ده بيحل في ضربة واحدة: التفرّد (رقم على صف واحد في `permits`)، التجديد (صف `pending` جنب `current`)، السجل التاريخي (`superseded`)، `approved_by` للتجديد، الـ `notes` (A8)، وعنوان التصريح (٣.٣).
- **لو الرأي إن الجدول كبير على المرحلة دي:** البديل عمود `permit_expires_at` على `units` + جدول `permit_renewals` منفصل. بيشتغل، بس التفرّد والسجل هيتعملوا مرتين بعدين. **بنرجّح الجدول.**

**إلزامي عند الإرسال — تبعة لازم تتقال:** «إعادة الإرسال» بتحصل **تلقائياً** كل ما وحدة معتمدة تتعدّل (السؤال ٨). يعني بعد التفعيل، **أي تعديل على أي وحدة قديمة تاريخها `NULL` هيترفض** لحد ما التاريخ يُدخل — الشريك اللي بيغيّر سعر هيتطلب منه تاريخ التصريح. ده صح كقرار، بس الواجهة لازم تشرحه، وإلا هيتقرا كباج. **الأدمن يملّي التواريخ من الملفات قبل التفعيل** بيخفف ده.

**ب. سقف التقويم — ممكن بالكامل، وأماكنه معروفة:**
- `POST /units/{id}/availability` و`POST /bookings`: شرط `end_date ≤ expires_at` قبل فحص التعارض → `BOOKING_EXCEEDS_PERMIT_VALIDITY { permit_expires_at }`.
- البحث بتواريخ: `Availability::onlyFree()` بيستثني اللي `expires_at < end_date`.
- `GET /units/{id}/blocked-dates`: بيرجّع `blocked: [ranges]` (`Api/V1/UnitController.php:450-453`) — نضيف range من `expires_at + 1` لآخر النافذة. **إضافي**، والتقويم بيقفل من غير تعديل في التطبيق. بنقترح الـ range يشيل `reason: "permit_expiry"` (إضافي) عشان الواجهة لو حبت تلوّنه مختلف.
- الـ allocator (السؤال ١٠): يستثني شقة تصريحها ينتهي قبل الخروج — **ده مش اختياري في وضع أ.**
- `NULL` = مفيش سقف، زي ما قلتوا.

**ج. التذكيرات:**
- الـ job وسجلّه: `permit_reminders (permit_id, threshold, sent_at)` بـ `UNIQUE(permit_id, threshold)` → مستحيل يتبعت مرتين مهما الـ job اشتغل. `threshold ∈ {60,30,14,7,1,0}`. بيقف لما يبقى فيه تصريح `pending` على نفس الـ scope.
- **⚠️ الإيميل لوحده مش هيوصل لكل شريك:** على الإنتاج **1 من 3 شركاء `email = NULL`** (الحسابات بالهاتف)، و**نطاق `mamsaa.com` على Resend لسه غير موثّق** (الإرسال شغّال من نطاق الاختبار). فبنقترح كل تذكير = إيميل (لو فيه) **+ إشعار داخلي** (وده اللي البانر يقرا منه أصلاً) **+ SMS في الـ 7 و1 و0** لأن دول اللي ليهم أثر مالي. لو الرأي إيميل بس، قولوا، بس اعرفوا إن ثلث الشركاء مش هيتعلّم.
- قوائم الأدمن: `GET /admin/permits?status=expiring|expired` — endpoint جديد إضافي.

**د. يوم الانتهاء:** موافقين إنها **حالة محسوبة مش مكتوبة.** الظهور العام = الشرط الحالي + `(expires_at IS NULL OR expires_at > CURDATE())`؛ الـ show بيرد `404 الوحدة غير متاحة` زي غير المعتمد. في وضع ب التصريح على المجموعة فبيوقف الكل تلقائياً؛ في وضع أ لكل صف. الحجوزات المؤكدة اللي بتعدّي الانتهاء: الـ job اليومي بيطلع قائمة → تنبيه أدمن (`AdminAlert` تصنيف `permit`)، **مفيش إلغاء.** على الإنتاج دلوقتي آخر خروج مؤكد `2026-10-02`، فمفيش حاجة هتتعلّم يوم التفعيل.

**هـ. التجديد — بالجدول بيبقى طبيعي:**
- `POST /units/{id}/permit-renewals` (الشريك) و`POST /admin/units/{id}/permit-renewals` (مَمسَى) → صف `permits` بحالة `pending` على نفس الـ scope. **الوحدة ما تتغيرش** — لا `approval_status` ولا الأعمدة المنسوخة.
- قائمة المراجعة: بنقترح **endpoint مستقل** `GET /admin/permit-renewals` + `approve`/`reject` بدل ما نحشر نوع تاني في `/admin/approvals` — الواجهة بتعامل عناصر القائمة دي كوحدات، وإضافة نوع فيها هتكسر افتراض. لو تحبوا `type` إضافي في `/admin/approvals` بدل endpoint، سطر.
- قبول: `current → superseded`، `pending → current`، **نسخ الرقم/الملف/التاريخ على صفوف الوحدة** (كل المجموعة في وضع ب) — السقف بيتمد في نفس الـ transaction. رفض: `rejected` + سبب + إشعار، القديم شغّال لحد تاريخه. القديم انتهى والتجديد `pending`: الوحدة مختفية بالحساب (د) وبترجع لحظة القبول من غير أي كتابة إضافية.
- منع الاعتماد الذاتي بينطبق بنفس الفحص (`created_by` على صف التصريح).

### ٣.٣ عنوان التصريح
- الحقول على صف `permits` (فوق). بترجع في `GET /admin/approvals/{id}` كـ `permit.address` جنب `unit.address/lat/lng` اللي موجودين فعلاً (السؤال ١٧).
- **المقارنة الآلية: المدينة أيوه، الحي لأ.** المدينة نص بس بخريطة واحدة (السؤال ٣) — `City::toArabic(permit.city) === unit.city` موثوق. الحي نص حر في الاتنين — flag يدوي بس. الرد: `addressMatch: { city: true|false|null, district: null }` — `null` = ما اتقارنش.

### ٣.٤ وحدات مَمسَى — المراجعة وفصل الأدوار
- **المراجعة باقية:** موافقين، ومنفّذ على staging من 19/09 مساءً (النسخ `pending`).
- **A1:** الـ API بيقبل الحقلين (السؤال ١٢) — البند عند الويزارد. الحقول الجديدة (`expiresAt`، `address`) هتتضاف لنفس `UnitWriter::rules()` فبتوصل اللوحتين معاً.
- **`created_by` / `approved_by` / `rejected_by`:** بنقترح **جدول `unit_reviews`** (`unit_id, decision, reason, notes, reviewed_by, created_at`) بدل أعمدة على `units` — بيدي السجل (وحدة اترفضت مرتين واتقبلت)، وبيحل A8 (الـ `notes` تتخزن وتبقى **داخلية**، الشريك بيشوف `reason` بس — لو الرأي غير كده قولوا)، و`approved_by` = آخر صف `approved`. `created_by` عمود على `units` (nullable؛ الأربعة على الإنتاج بتتملّى من اللوج، الباقي `NULL`). **وفي نفس اليوم**: `AuditLog::record($unit, 'unit.approved' | 'unit.rejected')` — الجدول موجود ومفيش سبب يفضل فاضي من الوحدات.
- **⚠️ `SELF_APPROVAL_FORBIDDEN` مع السؤال ١٤ = قفل على الإنتاج.** فيه **معتمد نشط واحد** (`#19`). لو `#19` أنشأ وحدة مَمسَى، **مفيش حد يعتمدها.** القاعدة صح، بس **شرطها التشغيلي مش متوفر النهاردة**: يا حساب اعتماد ثاني نشط قبل التفعيل، يا القاعدة تتفعّل بعلم مقفول لحد ما الحساب يوصل. **قرار إدارة قبل قرار كود.** (ولاحظوا إن الأربع وحدات الموجودة منشئها `#14` وهو غير نشط — التجديدات عليها هيعتمدها `#19` عادي.)

### ٣.٥ إنفاذ الصلاحيات
**موجود بالكامل كما هو مكتوب** (السؤال ١٥): نفس الصلاحيتين على نفس الـ endpoints، ونفس الكود `INSUFFICIENT_PERMISSION` بـ `403`. مفيش شغل هنا على سطح الأدمن. الفرق الوحيد سطح testvue القديم (دور مش صلاحية) — لو هيفضل شغّال نوحّده.

### ٣.٦ سياق المجموعة في الردود
- **P3 `groupId` على صفوف `GET /units` (الشريك):** إضافي، سطرين في `Dashboard/UnitPresenter::make()` — وبنقترح معاه `apartmentNo` و`groupSize` عشان الكارت يتطبّق من غير حساب.
- **A4 في `GET /admin/approvals/{id}`:** `group: { id, size, apartments: [{ id, apartmentNo, status, permitNumber, permitExpiresAt }] }` إضافي.
- **G1:** بعد السؤال ١٠، **الشكل العام موجود أصلاً**: ممثّل واحد + `available_count`. اللي ناقص إن تطبيق الضيف يقراه، ويعرض `booking.unit` من رد الحجز. لو حبيتوا `group_size` كمان جنب `available_count` (المتاح من إجمالي كام) — إضافي.

### ٣.٧ أكواد الرفض
- الأكواد مقبولة كما هي. **ملحوظة على الغلاف:** `{success, message, code, meta}` هو غلاف `/api/v1`. نفس الأكواد هتطلع بغلاف الشريك `{ error: { code, message, fields?, meta? } }` وغلاف الأدمن `{ message, code, fields?, meta? }` (الـ `meta` على الأدمن موجودة من 19/09 على staging). **الكود واحد، الغلاف بتاع السطح.**
- `BOOKING_EXCEEDS_PERMIT_VALIDITY` بـ `422`: تمام. للعلم رفض التوفر الحالي (`UNIT_UNAVAILABLE`/`UNIT_BLOCKED`) بـ `409` — كودان مختلفان بستاتسين، لو تحبوا نوحّدهم قولوا.
- `PERMIT_MODE_MIXED.meta.group_mode` — بنقترح `"per_unit" | "single_permit"`.

### ٣.٨ تنظيف
- **P6 — staging `#39–42`:** أوسع من «من غير ملف»: `#40 #41 #42` **من غير رقم ولا ملف**، و`#39` رقمه **`TL-50000`** مختلف عن مجموعته (`TL-DEMO-8UNITS`) — يعني المجموعة دي (وضع ب) بها شقة برقم غريب و٣ بلا تصريح، وكلها `approved`. الأربعة اتعملت بالـ cloner القديم (قبل نسخ الوثائق والفحص الاستباقي). **مقترحنا:** ننسخ عليهم تصريح المجموعة (رقم + ملف) بالـ tinker على staging — ده الشكل الصح لوضع ب — **لما تقولوا**، لأنها بيانات اختباركم.
- **P7 — Postman:** المجموعة (`backend/postman/Mamsa-API.postman_collection.json`) آخر تعديل 29/08 و**فيها 0 ذكر** لـ `licenseType` أو `apartments` أو `rejectionReason`. هتتحدّث بعد التنفيذ، ومعاها بيئة staging.

---

## حاجات لاحظناها ومحدش سأل عنها

1. **`APP_ENV=local` على staging.** ظهرت وإحنا بنجمع الأرقام (`app()->environment()` رجّعت `local`). وظيفياً مفيش أثر النهاردة — كل البوابات بتستخدم `isProduction()` — بس أي فحص `environment('staging')` هيفشل بصمت، واللوج والتنبيهات بتتعلّم `local`. سطر في `.env` + `config:cache`، على staging بس، لما تقولوا.
2. **تعديل التصنيف بس بيرجّع الوحدة المعتمدة للمراجعة** (شفناه على `u_24`). صح بالقاعدة العامة «معتمدة اتعدّلت → pending»، بس الشريك اللي بيصنّف وحدته استجابة لطلبكم هيلاقيها اختفت من المتجر. الويزارد لازم يقول ده قبل الحفظ.
3. **ملفات تصاريح على الإنتاج مرفوعة وغير مربوطة:** رفعتان بنوع `license_pdf` من الأدمن `#19` — «شهادة ترخيص مرفق سياحي خاص.pdf» (16/09) و**«The Two-Play Advantage.pdf» (19/09)**. الثانية مش تصريح. يعني `presign` بيقبل أي PDF تحت نوع `license_pdf`، **والفحص الوحيد على محتوى الملف هو عين المراجع.** ده مش اقتراح OCR — ده تذكير إن `tourismLicenseFileId` بيثبت وجود ملف، مش وجود تصريح.
4. **`#39` على الإنتاج** («وحدة اختبار الدفع — لا تحجز»): معتمدة، بلا تصريح، `submitted_at NULL`، مخفية بـ `unavailable`. مع قاعدة الإلزامية هتفضل زي ما هي (تاريخها `NULL` ومفيش حد بيعدّلها). بنقترح **ترفض أو تتحذف صراحة** بدل ما تفضل «معتمدة بلا تصريح» في داتا الإنتاج.
5. **ثلاث أسطح كتابة، مش اتنين.** أي قاعدة جديدة (تفرّد، انتهاء، وضع) لازم تغطي `/api/v1/partner` كمان (`Api/V1/Partner/UnitController.php`) — مساره للنسخ فيه `copy_documents=false` اللي بيعمل شقق بلا تصريح كمسودات. لو testvue هيتقاعد، نقفل المسار ده بدل ما نحرسه.
6. **رقم التصريح بلا تطبيع** (السؤال ٥). أي قاعدة تفرّد قبل التطبيع (trim + أرقام هندية → ASCII + حذف الفواصل) هتتخطّى بمسافة. لازم يبقى أول سطر في الكاتب.
7. **تكرار `50047139` على الإنتاج** (`#35`، `#37`): وحدتان مستقلتان، بملفّين مختلفين، نفس العنوان النصي، نفس السعة. يا نفس الوحدة الفعلية مرتين، يا خطأ إدخال. **بتتحسم من الورق** — بس لازم تتحسم **قبل** تفعيل التفرّد، وإلا أول تجديد على أي منهما هيترفض بـ `DUPLICATE_PERMIT_NUMBER`.

---

## الخلاصة والترتيب اللي بنقترحه

| # | البند | حالتنا | ملاحظة |
|---|---|---|---|
| ٣.٥ | الصلاحيات على السيرفر | ✅ **موجود** — الإنتاج وstaging | مفيش شغل |
| ٣.٤ المراجعة | وحدات مَمسَى بتعدّي المراجعة | ✅ staging 19/09 · الإنتاج موقوف | — |
| ٣.٦ | `groupId` على الشريك + `group` في المراجعة | 📐 إضافي، صغير | يتعمل مع أول نشرة |
| ٣.١ | وضع أ | 📐 يتبنى **بعد** تثبيت الـ payload فوق | + الـ allocator |
| ٣.٢ أ + ٣.٣ | جدول `permits` + الانتهاء + العنوان | 📐 **القرار التصميمي الأساسي** — الجدول ولا الأعمدة | كل الباقي بيبني عليه |
| ٣.٢ ب | سقف التقويم | 📐 واضح ومكانه معروف | مع ٣.٢ أ |
| ٣.٢ ج | التذكيرات | 📐 | ⚠️ إيميل لوحده ما بيوصلش لثلث الشركاء |
| ٣.٢ هـ | التجديد | 📐 بالجدول | endpoint مستقل ولا `type` في approvals — قولوا |
| ٣.٤ الأدوار | `created_by` + `unit_reviews` + منع الاعتماد الذاتي | 📐 | ⚠️ **معتمد نشط واحد على الإنتاج** — قرار إدارة أولاً |
| ٣.٧ | الأكواد | ✅ مقبولة | ثلاث أغلفة، كود واحد |
| ٣.٨ | تنظيف staging + Postman | ⏳ لما تقولوا | — |

**التقدير الإجمالي بعد تثبيت الـ contract:** الجدول + الترحيل + الانتهاء + السقف + التذكيرات + التجديد ≈ **٤ أيام**؛ وضع أ + الـ allocator ≈ **يوم ونص**؛ الأدوار والمراجعة ≈ **يوم**؛ سياق المجموعة ≈ **نص يوم**. **سبعة أيام** كاملة، بترتيب: `permits` أولاً (كل حاجة بتبني عليه)، ثم الانتهاء والسقف، ثم وضع أ، ثم الأدوار.

**اللي محتاجينه منكم قبل أول سطر كود:**
1. الجدول `permits` ولا الأعمدة على `units`؟
2. التجديد: endpoint مستقل ولا `type` في `/admin/approvals`؟
3. التذكيرات: إيميل بس، ولا إيميل + داخلي + SMS في الأيام الحرجة؟
4. حساب اعتماد ثاني على الإنتاج — موجود قبل تفعيل منع الاعتماد الذاتي؟
5. P6: ننسخ تصريح المجموعة على `#39–42` في staging؟
