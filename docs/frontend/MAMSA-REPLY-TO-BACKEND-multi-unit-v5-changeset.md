# v4 → v5 — قائمة تعديلات قابلة للتنفيذ

**التاريخ:** 10/09/2026
**المرجع:** `mamsa-multi-unit-inventory-backend-contract-v4.md`
**الغرض:** المستند ده **مش مراجعة تانية**. ده التعديلات بالظبط اللي تتطبّق على v4 عشان يبقى قابل للتنفيذ على الكود الحقيقي — بند بند، بأسماء حقيقية. طبّقها وأصدر v5.

المراجعة الكاملة والأدلة في `MAMSA-REPLY-TO-BACKEND-multi-unit-inventory-v4.md`. هنا التنفيذ بس.

---

## القاعدة الواحدة اللي كل حاجة تحتها

> **«الكمية» موجودة بالفعل — بس هي عدد صفوف، مش عمود.**

المنصة بتنفّذ الوحدات المتعددة من ٣٠/٠٨ بـ **صف حقيقي لكل شقة**، مربوطين بـ `unit_group_id`. فكل مكان في v4 مكتوب فيه `quantity` أو `approved_quantity`، الترجمة:

| v4 | المكافئ المنفَّذ |
|---|---|
| `units.quantity` | `COUNT(*) FROM units WHERE unit_group_id = ?` |
| `units.approved_quantity` | نفسه + `approval_status='approved' AND status='available'` |
| `available(unit,S,E)` | `Availability::freeCount($unit,$start,$end)` — **موجودة** |
| استعلام الـ sweep (ب.3) | مش محتاجينه — الصفوف الحقيقية بتجاوب السؤال |
| القفل (ج.1) | موجود في `BookingController` — بيقفل كل الإخوة `ORDER BY id` |
| «زيادة الكمية» | `POST /api/v1/partner/units/{unit}/apartments` — **موجود** |

---

## ✅ اللي يتشال من v4 — اتنفّذ بالفعل

احذف الأقسام دي بالكامل. الكود موجود ومختبر ومنشور.

| القسم | السبب |
|---|---|
| **(أ.1)** «ليه عمود كمية ومش جدول inventory» | القرار اتاخد بالعكس: الصفوف الحقيقية. الحيثيات موثّقة في تعليق `2026_08_30_000002_add_unit_group_to_units.php` |
| **(أ.2)** `quantity` + `approved_quantity` | يتشالوا. الباقي في القسم ٢ تحت |
| **(أ.4)** `UPDATE units SET quantity = 1` | 🔴 **خطر** — بيفكّك المبنى الموجود على staging لخمس كروت منفصلة |
| **(ب.2)** و **(ب.3)** معادلة واستعلام الـ peak | `Availability::freeCount()` و`attachCounts()` |
| **(ب.5)** التوفر في البحث | `attachCounts()` — استعلام واحد للصفحة |
| **(ج.1)** و **(ج.2)** القفل وسيناريو التزامن | منفَّذ ومختبر تحت تزامن |
| **(د.4)** الدفع بعد المهلة | ✅ **اتنفّذ 10/09** — تفاصيله في `MAMSA-REPLY-TO-BACKEND-late-payment-fix.md` |
| **(ز.2)** آخر وحدة واتنين بيحجزوا | منفَّذ |
| **(ي)** «`unit_group_id` ما يتضافش دلوقتي» | 🔴 **موجود ومنشور من ٣٠/٠٨** |
| **القرار ٧** «الإعلانات المكررة تتساب» | الخمس وحدات على staging **مجموعة مقصودة**، مش تكرار |

### وبند اتحل لوحده

**(و)** «`approved_quantity` منفصلة عشان الإعلان ما يختفيش أثناء المراجعة» — **المشكلة دي مش موجودة في نموذج المجموعة.**

كل شقة صف بحالة اعتماد خاصة بيها. الشريك اللي عنده ٥ شقق معتمدة وبيضيف ٥ كمان: الخمسة القدام **بيفضلوا `approved` وبيفضلوا بيبيعوا**، والخمسة الجداد بس هما اللي `draft` مستنيين مراجعة. **مفيش عمود إضافي ولا سطر في فلتر البحث** — النموذج بيديها ببلاش.

> احذف كل مبرر «فصل العمودين» من (و) واستبدله بسطر واحد: *«الشقق المعتمدة بتفضل تبيع وإحنا بنراجع الجداد، لأن الاعتماد على مستوى الصف.»*

---

## 📝 اللي يتعدّل — تصحيحات إجبارية

### ١. أسماء الأعمدة — ستة غلط

طبّق البدائل دي في **كل** الاستعلامات والأمثلة والفهارس في الوثيقة:

| في v4 | الصح |
|---|---|
| `permit_number` | **`tourism_permit_no`** |
| `bookings.check_in` / `check_out` | **`start_date`** / **`end_date`** |
| `units.price_per_night` | **`price`** |
| `units.name` | **`unit_name`** |
| `units.is_available` | **مش موجود** → `units.status ENUM('available','unavailable')` |
| `units.status IN ('draft','pending_review',…)` | **`units.approval_status`** — عمود تاني خالص |

### ٢. 🔴 `pending_review` مش قيمة موجودة

```
units.approval_status  ENUM('draft','pending','approved','rejected')
units.status           ENUM('available','unavailable')
```

القيمة **`pending`**، مش `pending_review`. أي كود يكتب `pending_review` **هيترفض من المحرك**.

وفلتر البحث في (و) يتصحّح لـ:

```php
->where('approval_status', 'approved')   // مش whereIn مع pending
->where('status', 'available')
```

الوحدة في `pending` **بتختفي من البحث فعلاً** (`UnitController` بيشترط `approved`)، وده صح ومقصود — والشقق المعتمدة التانية في المجموعة ما بتتأثرش.

### ٣. مغلّف الأخطاء

`{message, error_code, meta}` **مش موجود**. الموجود على `/api/v1`:

```json
{ "message": "…", "code": "INSUFFICIENT_INVENTORY", "meta": { … } }
```

**المفتاح `code` مش `error_code`.** الأكواد نفسها كويسة زي ما هي — `meta` تتضاف كمفتاح تالت.

### ٤. استعلام الفحص (أ.3) — اتشغّل، والنتيجة صفر

بأسماء الأعمدة الصح:

```sql
SELECT b1.unit_id, b1.id AS a, b2.id AS b
FROM bookings b1 JOIN bookings b2
  ON b1.unit_id = b2.unit_id AND b1.id < b2.id
 AND b1.start_date < b2.end_date AND b2.start_date < b1.end_date
WHERE b1.status IN ('confirmed','completed')
  AND b2.status IN ('confirmed','completed');
```

**النتيجة: صفر صفوف على staging والإنتاج** (وكمان صفر لو ضفنا `pending_payment`). البند ده يتقفل في v5 بالنتيجة مكتوبة.

### ٥. ⚠️ الإنتاج MariaDB مش MySQL

```
الإنتاج           MariaDB 11.8.9
staging/التطوير   MySQL 8.4.10
```

**اتأكدنا إن MariaDB بتفرض CHECK فعلاً** (رفض إدخال مخالف بـ `4025 CONSTRAINT ... failed`)، فالقيود شغّالة على المحركين. بس ضيف سطر في v5:

> *«أي قيد أو استعلام يتجرّب على المحركين. النجاح على staging مش دليل على الإنتاج.»*

---

## ➕ اللي يتضاف — البنود الجديدة فعلاً

دي اللي في v4 ومالهاش مقابل في الكود. **دي القيمة الحقيقية للوثيقة.**

### أ. `license_type` + `licensed_units_count` — شرط قانوني

**يتنفّذ زي ما هو تقريباً، بس القيد على حجم المجموعة مش على عمود كمية.**

```sql
ALTER TABLE units
  ADD COLUMN license_type ENUM('tourist_facility','private_hospitality')
             NULL DEFAULT NULL AFTER tourism_permit_no,
  ADD COLUMN licensed_units_count SMALLINT UNSIGNED NULL DEFAULT NULL AFTER license_type;

ALTER TABLE units
  ADD CONSTRAINT chk_units_facility_license_has_count
      CHECK (license_type <> 'tourist_facility' OR licensed_units_count IS NOT NULL);
```

**القيدان التانيان في v4 ما ينفعوش كـ CHECK هنا** — حجم المجموعة مش على الصف، فـ CHECK ما تقدرش تشوفه. مكانهم التطبيق، عند **نقطة واحدة**:

```
POST /api/v1/partner/units/{unit}/apartments      ← الموجود، هو ده مكان التوسيع
```

قواعد التحقق بالترتيب، قبل `UnitCloner`:

| # | الشرط | الرد |
|---|---|---|
| ١ | `license_type = 'tourist_facility'` و`licensed_units_count` فاضي | `422 LICENSED_UNITS_COUNT_REQUIRED` |
| ٢ | الحجم المطلوب > ١ و`license_type != 'tourist_facility'` (أو `NULL`) | `422 MULTI_UNIT_REQUIRES_FACILITY_LICENSE` |
| ٣ | الحجم المطلوب > `licensed_units_count` | `422 QUANTITY_EXCEEDS_LICENSED_UNITS` |
| ٤ | `MULTI_UNIT_ENABLED = false` والحجم > ١ | `422 MULTI_UNIT_DISABLED` |

«الحجم المطلوب» = `UnitCloner::projectedSize()` لمسار الأرقام، أو `count` لمسار العدد — **الاتنين موجودين وبيتحسبوا قبل الكتابة أصلاً**.

**والسقف بيبقى الأصغر بين اتنين:** `UnitCloner::MAX_GROUP = 100` (سقف تقني، بلاست رادياس) و`licensed_units_count` (سقف قانوني). الرد الحالي `GROUP_TOO_LARGE` يفضل للأول، والجديد للتاني.

> **ملاحظة تستاهل تتسجل:** الكود **بيفرّق بالفعل** بين تصريح المبنى وتصريح الشقة — الخيار `copy_documents` مقفول افتراضياً بتعليق بيقول إن «تصريح متصدّر لشقة ما بيغطيش جيرانها». `license_type` هو **التصريح الرسمي لنفس التفرقة**، فالبند ده بيتركّب على نية موجودة مش بيصادمها.

**الوحدات القديمة:** `license_type = NULL` وضع صالح تماماً لأي وحدة مستقلة. مفيش backfill ومفيش تخمين — زي ما v4 قال بالظبط. ✅

### ب. `hold_expires_at` — التوفر يتحرر لحظياً

**البند ده صح ومفيد ويتنفّذ زي ما هو.**

الوضع الحالي: `pending_payment` بيحجز التواريخ لحد ما `bookings:expire-pending` تشتغل — **عمر ٦٠ دقيقة + مهمة كل ١٥ دقيقة = لحد ٧٥ دقيقة تواريخ مقفولة على حجز مهجور**.

```sql
ALTER TABLE bookings
  ADD COLUMN hold_expires_at TIMESTAMP NULL DEFAULT NULL AFTER status;
CREATE INDEX idx_bookings_hold_expiry ON bookings (status, hold_expires_at);
```

والشرط يدخل في **`Availability::conflictingBookings()`** — النقطة الوحيدة اللي كل السطوح بتعدّي عليها:

```php
->where(fn ($q) => $q
    ->where('status', Booking::STATUS_CONFIRMED)
    ->orWhere(fn ($q) => $q
        ->where('status', Booking::STATUS_PENDING)
        ->where(fn ($q) => $q->whereNull('hold_expires_at')
                             ->orWhere('hold_expires_at', '>', now()))))
```

`whereNull` مقصود: الحجوزات القديمة قبل الميزة ما لهاش مهلة، ولازم تفضل حاجزة زي ما هي.

> **🟠 تحفّظ واحد على الرقم:** v4 بيقول ١٥ دقيقة، والحالي ٦٠.
> **١٥ قرار منتج مش قرار تقني.** الضيف اللي بيستنى OTP بنك على شبكة بطيئة ممكن يخسر حجزه، والباج اللي قفلناه النهارده (الدفع بعد الإلغاء) بيحصل **أكتر** كل ما المهلة تقصر.
> **نبدأ بـ `BOOKING_HOLD_MINUTES=60`** (نفس السلوك الحالي، بس بقى لحظي) وننزلها ببيانات حقيقية. الرقم كونفج فالتغيير مش إصدار.

### ج. أكواد الأخطاء — تحسين حقيقي

الحالي بيرجّع **`422` برسالة عربية** لما الوحدة تبقى محجوزة:

```php
throw new UnitUnavailable('الوحدة محجوزة في هذه الفترة');   // → 422 {message}
```

يتحوّل لـ:

| الحالة | الكود | متى |
|---|---|---|
| `409` | `INSUFFICIENT_INVENTORY` | مفيش شقة فاضية في المجموعة للمدى ده — مع `meta.available_count` |
| `409` | `UNIT_BLOCKED` | فيه شقق بس الشريك قافلها | 
| `503` | `INVENTORY_LOCK_TIMEOUT` | انتهت مهلة انتظار القفل — مع `Retry-After: 2` |
| `422` | `MULTI_UNIT_BOOKING_NOT_SUPPORTED` | `units_count` > ١ |

**التفرقة بين `409` و`503` مهمة فعلاً:** «مفيش رصيد» ≠ «حاول تاني». والكود الحالي بيرجّع الرسالتين المختلفتين (محجوزة / مقفولة) كنص عربي — **والواجهة ما تفرّعش على نص عربي**، وده نفس الدرس اللي طلع من الشكاوى.

### د. `Idempotency-Key` على إنشاء الحجز

مالوش مقابل. **نفس نمط الاسترداد ينفع يتعاد استخدامه حرفياً** — والدرس اللي اتعلمناه هناك يتنقل معاه: **الفحص يتعمل جوّه القفل مش قبله**، وإلا طلبين بنفس المفتاح في نفس المللي ثانية بياخدوا `409` بدل `200 replayed`.

### هـ. `units_count` على `bookings`

يتضاف زي ما v4 قال، مقفول على `1` بـ CHECK. رخيص دلوقتي، وبيمنع تغيير عقد الـ API لما نفتح «أكتر من شقة في الطلب الواحد».

---

## 🧪 الاختبارات — تعديل قائمة (ح)

| بند v4 | القرار |
|---|---|
| ١، ٢، ٣ (التوفر والتداخل ونصف مفتوح) | ✅ **موجودين** في `BookingAvailabilityTest` |
| ٤ (١٠ متزامنين على رصيد ٥) | ✅ موجود بشكله — تزامن حقيقي على المجموعة |
| ٥ (`pending` منتهية ما بتستهلكش) | ➕ **جديد** — مع البند (ب) |
| ٦ (المهمة ما بتلغيش دفعة ناجحة) | ✅ موجود (حارس `whereDoesntHave`) |
| ٧ (دفع ناجح بعد الإلغاء) | ✅ **اتنفّذ 10/09 — ٦ اختبارات، ٥ منهم بيفشلوا قبل الإصلاح** |
| ٨ (تقليل تحت الحجوزات القائمة) | ♻️ **يتحوّل** لـ: «شقة عليها حجز قائم ما ينفعش تتشال من المجموعة» |
| ٩ (زيادة → مراجعة والوحدة فضلت تبيع) | ♻️ **يتحوّل** لـ: «توسيع مبنى ما بيأثرش على الشقق المعتمدة» |
| ١٠ (الوحدات القديمة سلوكها مطابق) | ✅ موجود |
| ١١ + ١١أ–و (التصريح) | ➕ **جديدة كلها** — مع البند (أ)، بس على حجم المجموعة |
| ١٢ (التسعير ما اتغيرش) | ✅ موجود — **والسعر لكل شقة شغّال، وده أكتر من اللي البند بيطلبه** |

---

## ☑️ قائمة تنفيذ v5

**تشيل:**
- [ ] (أ.1) و(أ.2 الكمية) و(أ.4) و(ب.2) و(ب.3) و(ب.5) و(ج.1) و(ج.2) و(د.4) و(ز.2) و(ي `unit_group_id`) والقرار ٧
- [ ] كل مبرر «فصل `approved_quantity`» من (و)

**تصحّح:**
- [ ] ٦ أسماء أعمدة
- [ ] `pending_review` → `pending`، و`status` → `approval_status`
- [ ] `error_code` → `code`
- [ ] نتيجة (أ.3): **صفر صفوف، staging والإنتاج**
- [ ] سطر MariaDB/MySQL

**تضيف:**
- [ ] `license_type` + `licensed_units_count` — CHECK واحد + ٤ قواعد على `POST /{unit}/apartments`
- [ ] `hold_expires_at` + الشرط في `Availability::conflictingBookings()` — **بـ ٦٠ دقيقة**
- [ ] أكواد `409`/`503` بدل `422` بنص عربي
- [ ] `Idempotency-Key` — الفحص **جوّه القفل**
- [ ] `bookings.units_count` مقفول على ١

---

## الخلاصة

**٧٠٪ من v4 منفَّذ بالفعل، و٣٠٪ منه جديد ومفيد.** التعديلات فوق بتشيل الأول وتخلّي التاني قابل للبناء.

**اللي مستني قرار واحد:** لو موافقين على القاعدة («الكمية = عدد الصفوف») — طبّق القائمة وأصدر v5 وإحنا نبدأ في البنود الجديدة فوراً. لو مصرّين على عمود الكمية، محتاجين قرار مكتوب على مصير `unit_group_id` و`apartment_no` والسعر لكل شقة و iCal لكل شقة والمجموعة القايمة على staging — وشايفينه تراجع، بس القرار قراركم.
