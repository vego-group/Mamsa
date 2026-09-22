# رد — الصفّان اتحذفوا · الفرق بين الإنتاج والفرع · وطريقة النشر الجديدة

**التاريخ:** 22/09/2026

---

## ١. ✅ الصفّان في `wallet_transactions`

**backup قبلهم:** `~/backup-prod-wallettx-20260922-130139.sql` (122,621 بايت · md5 `c0932112c9a140281a9231a1ebd83df3`)

السكربت بيرفض يشتغل لو الصفوف مش بالظبط اتنين، ولو `user_id` مش 20، ولو `booking_id` مش `NULL`:

```
  #14 user=20 PAY-2026-000111 payment -5.00 booking_id=NULL
  #15 user=20 REF-2026-000004 refund   5.00 booking_id=NULL
rows deleted: 2
wallet_transactions remaining: 0
```

---

## ٢. الفرق بين الإنتاج والفرع `feat/complaints-refunds`

مسح md5 كامل على `app` و`config` و`routes` و`database/migrations` و`database/seeders` و`bootstrap`:

| | |
|---|---|
| ملفات PHP على الفرع | **364** |
| ملفات PHP على الإنتاج | **327** (+ ملفين cache مولّدين) |
| **على الفرع ومش على الإنتاج** | **35** |
| موجودين في الاتنين ومختلفين | **39** |
| على الإنتاج ومش على الفرع | **0** (غير `bootstrap/cache`) |

### ٢.١ 🔴 نظام الشكاوى والاستردادات — **مش منشور على الإنتاج. ولا ملف واحد.**

**٢٤ ملف من الـ35** بتاعته، ومنهم **٤ migrations ما اتشغلوش**:

```
Models:        BookingComplaint · BookingComplaintAttachment
Controllers:   Api\V1\ComplaintController · Dashboard\ComplaintController
               AdminPanel\ComplaintsController · ComplaintAttachmentController
Service:       ComplaintRefundService
Command:       ReconcileStuckRefunds        Exception: RefundInFlightException
Config:        config/complaints.php
Notifications: 10 (ComplaintSubmitted، ComplaintApproved، ComplaintRejected،
               ComplaintUnderReview، ComplaintReceivedPartner،
               ComplaintPartnerDebited، ComplaintRefundSettled،
               ComplaintRefundFailed، ComplaintRefundStuck،
               RefundSettlementAmbiguous)
Migrations:    create_booking_complaints_table · …_attachments_table
               extend_refunds_for_complaints · comment_refunds_moyasar_id_column
```

وجداول `booking_complaints` و`booking_complaint_attachments` **مش موجودة** على الإنتاج، ومسارات الشكاوى مش في `routes/api.php` ولا `routes/dashboard.php` ولا `routes/admin-panel.php` بتوع الإنتاج. الإجابة: **لأ، مش منشور، ولا جزء منه.**

### ٢.٢ 🔴 وخلل حقيقي طلع من المسح — كان مستني يحصل

`BookingPaymentSettler` **منشور على الإنتاج**، وفيه `refundUnrecoverable()` — المسار اللي بيرجّع فلوس ضيف وصلت دفعته **بعد** ما حجزه اتلغى. الدالة دي:

- بتقرا `Refund::where('idempotency_key', …)`
- وبتكتب `reason` و`idempotency_key` و`failure_reason`
- وبتستعمل `Refund::REASON_OTHER` و`Refund::STATUS_PENDING/FAILED/SUCCEEDED`

**ولا واحد فيهم موجود على الإنتاج:**

```
refunds columns: id, booking_id, payment_id, type, amount, refund_percent,
                 tier_label, status, moyasar_refund_id, moyasar_response,
                 created_at, updated_at
  reason: MISSING   idempotency_key: MISSING   failure_reason: MISSING

Refund model (production): TYPE_REFUND, TYPE_VOID فقط — مفيش REASON_* ولا STATUS_*
```

كلهم جم مع migration الشكاوى `2026_09_06_000003`، واللي ما اتنشرش.

**النتيجة لو حصلت الحالة:** أول سطر في الدالة (`where('idempotency_key')`) بيرمي خطأ SQL، **وهو برّه الـ try/catch** — يعني الضيف **ما بيترجعلوش فلوسه**، والتنبيه للعمليات **ما بيطلعش**، والـ callback بيرد `500`.

**ليه ما ظهرش لحد دلوقتي:** الشرط هو دفعة توصل بعد إلغاء — الحالة اللي الميزة دي اتعملت عشانها بالظبط، وما حصلتش على الإنتاج. **المسح هو اللي لقاه، مش فشل.**

**الإصلاح جاهز على الفرع** (`2026_09_22_000003_add_late_payment_refund_columns.php`): بيضيف التلاتة بس، بنفس تعريفات migration الشكاوى، وكل خطوة محروسة بـ`hasColumn` — **no-op على staging** (الأعمدة هناك)، **وهو الإصلاح كله على الإنتاج**. وخلّينا migration الشكاوى نفسه محروس كمان، فالترتيب بين الاتنين ما بقاش مهم. ومعاه `app/Models/Refund.php` من الفرع — فحصناه، بيحمّل على الإنتاج من غير كلاسات الشكاوى (`BookingComplaint::class` نص مش تحميل).

**مش هننشره من غير موافقتك.** حجمه: migration واحد + ملف موديل واحد.

### ٢.٣ باقي اللي مش على الإنتاج (11 ملف)

| المجموعة | |
|---|---|
| **مرحلة ١ للتصاريح** (7) | `Permit` · `PermitWriter` · `PermitNumber` · `PermitBackfill` · `BackfillPermits` + migrationان — **مقصود، staging بس** |
| **migrationان مش مشغّلين** | `require_checkout_time_on_units` (الإنتاج: `checkout_time` لسه `NULL` مسموح — بس **مفيش ولا وحدة فيها NULL**) · `add_hold_and_idempotency_to_bookings` (`bookings.hold_expires_at` و`idempotency_key` **مش موجودين**، **والكود المنشور مش بيستعملهم** — اتأكدنا) |
| **إشعاران** | `ProductionMarkerMissing` · `SettlementOnUnexpectedState` — **مفيش كود على الإنتاج بيشاور عليهم**، فمش مشكلة |

### ٢.٤ الـ39 ملف المختلفين
كلهم نسخ أقدم من الفرع (الفرع فيه شغل الشكاوى ومرحلة ١ ومسار التوسيع). **مفيش ولا ملف على الإنتاج أحدث من الفرع** — يعني مفيش تعديل يدوي ضايع زي اللي حصل في `PaymentController` يوم 26/08.

---

## ٣. ✅ حالة الإنتاج بقت في git

**فرع `release/production` + تاج `prod-2026-09-22`** — `backend/{app,config,routes,database,bootstrap}` فيه **مطابق بايت-ببايت للسيرفر**، متحقق بمسح md5: **327 ملف، 0 فرق**.

فيه التعديلات الخمسة اللي اتعملت النهارده بالإيد، **مكتوبة في commit واحد** برسالة بتقول ليه اتعملت كده وإيه اللي مش authoritative فيه (الاختبارات والتوثيق جايين من فرع الميزة؛ الإنتاج ما فيهوش حزمة اختبارات). و`bootstrap/cache` مستثنى لأنه مولّد.

يعني **حالة الإنتاج دلوقتي تتبني من git**: `git checkout prod-2026-09-22`.

---

## ٤. ✅ النشر من غير تعديل بالإيد — الطريقة

`docs/ops/RELEASE-production-branch.md`. باختصار:

1. **`release/production` هو الإنتاج.** أي تغيير بيتعمل عليه الأول (cherry-pick من فرع الميزة، أو تعديل بالإيد **في git** لو الملف مختلف على الإنتاج) — فالتعديل اليدوي بيحصل في مكان متتبَّع بدل السيرفر.
2. **الملفات اللي تتبعت = ناتج `git diff --name-only prod-<آخر تاج> HEAD`** — مش اختيار بالذاكرة.
3. **الميزة اللي مش متفق على نشرها ما تدخلش الفرع** — فـ`routes/api.php` بتاعه أصلاً من غير مسارات الشكاوى، ومحدش محتاج يشيل حاجة بالإيد وقت النشر. ده اللي كان بيفرض التعديل اليدوي.
4. **migration بتتنشر مع الكود اللي بيحتاجها، في نفس النشرة.** خلل `refunds` فوق حصل بالظبط لأن الكود سبق الأعمدة.
5. **فحص تطابق md5 قبل كل نشرة** (الأمر في الملف) — أي فرق معناه حد عدّل على السيرفر أو نشرة ما اتسجلتش.
6. **تاج جديد بعد كل نشرة ناجحة** — كل حالة إنتاج ليها اسم.

---

## ٥. المرحلة ٢
ماشية على staging زي ما قلتوا، مش مستنية أي حاجة من فوق.

**محتاجين موافقة على:** نشر إصلاح `refunds` (البند ٢.٢) — migration + موديل، وهو أول حاجة على `release/production`.
