# تقرير النشر — إصلاح refunds · والأربع شروط · **وخلل تسبّبنا فيه ولقيناه أثناء التحقق**

**التاريخ:** 22/09/2026
**التاج:** `prod-2026-09-22-refunds`

اقروا **القسم ٥ الأول** — فيه خلل حي تسببنا فيه في نشرة التصاريح، ظهر وإحنا بننفّذ الشرط رقم ٤، واتصلح.

---

## ١. الشرط ١ — عمود `status` ✅ عدّى من غير تعديل

```sql
`status` enum('pending','succeeded','failed') NOT NULL DEFAULT 'pending'
```

**بيقبل القيم التلاتة اللي الـsettler بيكتبها بالظبط** (`STATUS_PENDING` عند نجاح الإرسال للبوابة، `STATUS_FAILED` عند الفشل، `STATUS_SUCCEEDED` لما مفيش بوابة تتنده). **فالـmigration ما اتغيّرش.**

**وحاجة لاحظناها وإحنا بنقرا الجدول:** `payment_id` عمود `NOT NULL`، والـsettler بيكتب `$payment?->id` — يعني `null` لو الحجز مالوش صف دفع. عملياً مستحيل (المسار ده بيتنده من callback دفعة موجودة)، ولو حصل بيتلقط في الـ`try` والتنبيه يكون خرج قبله. **سجّلناها ولا غيّرناها** — تغيير عمود لحالة نظرية مش مبرّر.

## ٢. الشرط ٢ — الـdiff ✅ إضافات بس

الأسطر الوحيدة اللي «اتشالت» في الـdiff هي تلات أسطر casts **رجعت بنفس القيم** بمحاذاة مسافات مختلفة:

```diff
-        'amount'           => 'float',
-        'refund_percent'   => 'float',
-        'moyasar_response' => 'array',
+        'amount'            => 'float',
+        'refund_percent'    => 'float',
+        'moyasar_response'  => 'array',
```

**ولا cast لعمود قائم اتغيّر سلوكه.** الإضافات: ٦ ثوابت، ١١ مدخل في `$fillable`، دالتان (`splitIsBalanced`، `gatewayPaymentId`)، وعلاقتان (`complaint`، `initiator`).

**عن الأعمدة اللي لسه مش موجودة** (`amount_vat`, `complaint_id`, …): وجودها في `$fillable` و`$casts` **ما بيعملش حاجة** — Laravel بيحوّل الموجود بس. ومسار الإلغاء العادي بيكتب الحقول القديمة نفسها.

## ٣. الشرط ٣ — ترتيب التنبيه ✅ اتنفّذ

`refundUnrecoverable()` اتعادت كتابتها:

```
1. loadMissing + حساب المبلغ
2. 🔔 alertPaymentAfterCancellation(..., 'attempting')   ← أول حاجة، وبرّه أي try للاسترداد
3. try {
     فحص الـ idempotency          ← جوّه الـtry دلوقتي
     نداء البوابة
     كتابة صف الاسترداد
     increment + قيد المحفظة
   } catch { report + Log::critical + outcome = failed }
4. لو فشل → 🔔 تنبيه تاني بـ 'failed'
```

- **التنبيه نفسه مغلّف بـtry خاص بيه** — مزوّد بريد بيتعثّر ما يقدرش يوقف استرداد لسه ما اتعملش.
- **`PaymentAfterCancellation` قبلت قيمة جديدة `attempting`** وبتقول للمستلم صراحة: «لو فشل هيوصلك تنبيه تاني — ولو ما وصلش، فهو تم».
- **٣ اختبارات جديدة**، واتأكدنا إنها بتفشل بالترتيب القديم: رجّعنا التنبيه لآخر الدالة والفحص لبرّه الـtry → وقعوا التلاتة.

## ٤. الشرط ٤ — التجربة على نسخة الإنتاج ✅ عدّى بالكامل

**البيئة:** حاوية **MariaDB 11.8.9** — نفس إصدار الإنتاج بالظبط (مش SQLite) · استرجعنا `backup-prod-prepermits-20260922-140201.sql` (٤٣ جدول) · وشغّلنا عليها **شجرة الإصدار الفعلية** (تاج الإنتاج + الأربع ملفات).

```
migrate --force على النسخة المسترجعة:
  2026_09_10_000001_add_hold_and_idempotency_to_bookings  DONE
  2026_09_22_000001_create_permits_table                  DONE
  2026_09_22_000002_backfill_permits_from_units           DONE
  2026_09_22_000003_add_late_payment_refund_columns       DONE
  2026_09_22_000004_create_permit_reminders_table         DONE
```

**السيناريو، من خلال الـHTTP callback الحقيقي** (`POST /api/v1/payments/callback`): حجز اتلغى لانتهاء المهلة، حد تاني خد التواريخ، وبعدين وصلت الدفعة.

```
callback HTTP status:     200
callback body:            {"success":true,"data":{"ok":true,"status":"paid"}}

booking stayed cancelled: YES
refund row written:       YES #5 · 1150 · status=pending · reason=other · key=late-payment:114
payment refunded_amount:  1150
wallet refund row:        1
alert email:              ✅ اتولّد فعلاً واتسلّم للـmailer
                             To: <السوبر أدمن النشط>
                             body: "الاسترداد التلقائي جارٍ الآن…"   ← نص 'attempting'
replay callback:          200 · refunds = 1 (ما اتكررش)
```

**التنبيه اتثبت بالبريد المتولّد نفسه** (مايلر `log`) مش بـmock — ووجود نص `attempting` بيثبت إنه خرج **قبل** ما نتيجة الاسترداد تبقى معروفة، وهو بالظبط اللي الشرط ٣ طلبه.

---

## ٥. 🔴 خلل تسبّبنا فيه في نشرة التصاريح — ظهر أثناء الشرط ٤، واتصلح

### إيه اللي حصل

نشرة التصاريح (`prod-2026-09-22-permits`) شحنت `app/Http/Controllers/Api/V1/BookingController.php` **من الفرع**. والنسخة دي بتكتب `bookings.hold_expires_at` و`bookings.idempotency_key` — **عمودان من migration `2026_09_10_000001` اللي ما اتنشرش أبداً**.

**النتيجة: أي محاولة حجز من الضيف كانت هتفشل عند الـINSERT.**

**ما حصلش ولا حجز**: الإنتاج فيه **٠ حجز** والمنصة ما اتطلقتش. بس ده وضع، مش عذر.

### إزاي عرفنا

الشرط ٤ نفسه. أول تشغيل للسيناريو على نسخة الإنتاج طلع:
```
SQLSTATE[42S22]: Unknown column 'hold_expires_at' in 'WHERE'
```
**التجربة اللي طلبتوها هي اللي كشفته** — ما كانش هيظهر لا في الحزمة (SQLite بيبني الجدول من الـmigrations كلها) ولا في أي فحص كود.

### غلطنا فين بالظبط

وإحنا بنجهّز نشرة التصاريح، فحصنا الـ٣٥ ملف على **كلاسات** الإنتاج ما عندهوش، وعلى **مزايا** محجوزة — وما فحصناهمش على **أعمدة** الإنتاج ما عندهوش. **رغم إن تقرير المطابقة اللي كتبناه نفس اليوم كان مسمّي الـmigration دي بالاسم كواحدة مش متشغّلة.**

### الإصلاح

اتصلح **للأمام** مش برجوع، والسبب تقني: نفس النشرة استبدلت `app/Support/Booking/UnitUnavailable.php` بنسخة بـ**بارامترين**، ونسخة `BookingController` القديمة بتناديه بواحد — فالرجوع مش ملف واحد، هو ملفين + إعادة كتابة بوابة التصريح بالأسلوب القديم (`422` بدل `409`)، وده كمان بيلغي عقد الأكواد اللي بعتناهولكم النهاردة.

فنشرنا الـmigration الناقصة — `prod-2026-09-22-bookings-hotfix`:

- **إضافية بالكامل**: تلات أعمدة nullable/بقيمة افتراضية + index + CHECK بيثبّت `units_count = 1`.
- **وما بتغيّرش سلوك**: على الإنتاج `hold_expires_at` **بيتكتب وما بيتقراش** — نسخة `Availability.php` المنشورة ما فيهاش ولا إشارة ليه (٠). يعني مهلة الدفع اللي العمود موجود عشانها **نايمة**، والعمود بس بيخلّي الـINSERT ينجح.
- backup لقاعدة البيانات قبلها، والتحقق بعدها: insert بشكل الـcontroller بالظبط **نجح** (وترجّع).

**الإنتاج دلوقتي سليم.** `/up` و`/api/v1/units` و`#34` كلهم `200`.

### اللي اتغيّر عندنا عشان ما يتكررش

قاعدة النشر في `docs/ops/RELEASE-production-branch.md` بقى فيها بند صريح: **قبل أي نشرة، كل ملف داخل فيها يتفحص على الأعمدة والجداول اللي الإنتاج ما عندهوش**، مش على الكلاسات بس. والقائمة بتتبني من `migrate:status` بتاع الإنتاج نفسه.

---

## ٦. النشر — snapshot قبل وبعد

```
قبل:  routes: 233   public shapes: 10
بعد:  routes: 233   public shapes: 10
✓ No contract change: routes and public response shapes are identical.
```
**صفر تغيير في العقد** — زي ما توقّعنا في التقرير: التغيير في الـschema والسلوك الداخلي بس.

الملفان: `~/snap-before-refunds.json` · `~/snap-after-refunds.json`

### التحقق بعد النشر

```
refunds.reason:          EXISTS
refunds.idempotency_key: EXISTS
refunds.failure_reason:  EXISTS
status enum:             enum('pending','succeeded','failed')

Refund constants: TYPE_REFUND, TYPE_VOID, STATUS_PENDING, STATUS_SUCCEEDED,
                  STATUS_FAILED, REASON_COMPLAINT, REASON_HOST_CANCELLATION, REASON_OTHER

refunds rows: 0     bookings rows: 0
migrations ran: 78
```

**وتطابق الملفات:** ٣٤٤ ملف PHP، **٠ فرق** بين الإنتاج و`prod-2026-09-22-refunds`.

### Backups
```
~/backup-prod-prehotfix-20260922-162242.sql    (قبل الـhotfix)
~/backup-prod-prerefunds-20260922-163233.sql   (قبل نشرة refunds)
~/backup-refunds-prod-20260922-163233/         (الملفات)
```

---

## ٧. التاجات دلوقتي

| التاج | إيه فيه |
|---|---|
| `prod-2026-09-22` | لقطة حالة الإنتاج الأصلية |
| `prod-2026-09-22-permits` | التصاريح ١–٣ |
| `prod-2026-09-22-bookings-hotfix` | أعمدة الحجز اللي الـcontroller المنشور بيحتاجها |
| **`prod-2026-09-22-refunds`** | **الحالي** — إصلاح الدفعة المتأخرة |

`git checkout prod-2026-09-22-refunds` = اللي شغّال على الإنتاج دلوقتي، بالظبط.

---

## ٨. الجاي

**المرحلة ٤** على staging (وضع أ)، ومعاها **دليل التنفيذ للواجهات**: `docs/frontend/MAMSA-NEXTJS-BUILD-permits.md`.
