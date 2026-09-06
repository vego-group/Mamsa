# رد الباك اند — مواصفات شكاوى الضيوف والاسترداد v1.0

**التاريخ:** 06/09/2026
**المرجع:** `BACKEND_SPEC_COMPLAINTS_REFUNDS_v1.0.md` (06/09/2026)
**الحالة:** مراجعة فنية مقابل الكود الفعلي — **مش جاهز للتنفيذ من غير حسم 4 نقاط**

المواصفة سليمة في المنطق المحاسبي، والأرقام في القسم 3 صحيحة رياضياً وبتطابق قواعد التسعير الحالية. المشاكل كلها في **الاشتباك مع الموجود فعلاً** — الملف اتكتب كأن الاسترداد feature جديدة بالكامل، وهي مش كده: فيه جدول استرداد شغّال في الإنتاج، وفيه نوع قيد اتعمل مخصوص للحالة دي من شهر أغسطس، وفيه webhook تسوية بيشتغل.

كل نقطة تحت معاها المرجع في الكود.

---

## 1. اعتراضات لازم تتحسم قبل بداية الشغل

### ❌ B1 — `splitCommission()` مش موجودة، والبديل بيقرأ النسبة من المكان الغلط

**R2** بيقول: المبلغ يمر على `splitCommission()` بنفس منطق الحجز الأصلي، ومفيش دالة حساب جديدة. المبدأ صح — بس الدالة دي **مش موجودة في الكود**. المصدر الوحيد للحقيقة اسمه:

```
app/Support/Pricing.php  →  Pricing::breakdown(float $nightlyGross, int $nights, bool $mamsaOwned)
```

والمشكلة الحقيقية أعمق من الاسم. السطر 81:

```php
$commissionRate = $mamsaOwned ? 1.0 : (float) config('booking.commission_rate');
```

الدالة بتقرأ النسبة **الحالية** من الـ config (10% دلوقتي)، بينما كل حجز مجمّد نسبته في عموده `bookings.commission_rate`. يعني لو جت شكوى على حجز قديم متجمّد على **2%** (`Booking::LEGACY_COMMISSION_RATE`, `app/Models/Booking.php:44`) واستخدمنا `Pricing::breakdown()` زي ما هي:

- الشريك هيتخصم منه **أقل** من اللي يستاهل
- وممسى هترجّع عمولة **ما أخدتهاش أصلاً**

وده بالظبط اللي R2 بيمنعه. **المطلوب:** تقسيم الاسترداد يقرأ `bookings.commission_rate` من الصف المجمّد، مش من `config()`. ده تعديل سطر واحد بس لازم يتكتب في المواصفة صراحة، لأنه بيغيّر اختبار T2 (شوف القسم 3).

---

### ❌ B2 — `booking_refunds` بيكرّر جدول `refunds` الشغّال في الإنتاج

جدول الاسترداد موجود من 24 يونيو:

```
database/migrations/2026_06_24_000006_create_refunds_table.php
  booking_id, payment_id, type(refund|void), amount decimal(10,2),
  refund_percent, tier_label, status(pending|succeeded|failed),
  moyasar_refund_id, moyasar_response(json)
```

ومش نايم — بيستخدمه:
- شاشة الإلغاءات في لوحة الأدمن (`AdminPanel/CancellationsController.php`)
- webhook تسوية ميسر (`Dashboard/WebhookController.php`)
- `payments.refunded_amount` اللي بيتراكم مع كل استرداد جزئي

لو عملنا `booking_refunds` جنبه، يبقى عندنا **مصدرين للحقيقة على نفس الحجز**: المواصفة بتحسب `already_refunded_halalas` من الجدول الجديد، بينما شاشة الإلغاءات و`payments.refunded_amount` بيحسبوا من القديم. النتيجة المباشرة: **إمكانية استرداد أكتر من قيمة الحجز** لو الحجز اتلغى جزئياً قبل الشكوى.

**البديل المقترح:** نوسّع `refunds` بدل ما نبني جدول تاني — نضيف عليه:
`complaint_id` nullable, `reason` enum(complaint|host_cancellation|other),
`amount_vat`, `amount_commission`, `amount_partner`,
`idempotency_key` unique, `credit_note_number`, `credit_note_qr`, `manual_transfer_reference`.

الهدف اللي المواصفة نفسها ذكرته في §4.3 — «خليه عام من دلوقتي عشان إلغاء الشريك يمر عليه كمان» — **متحقق أصلاً** في `refunds`، لأنه مربوط بالإلغاءات من يوم واحد.

---

### ❌ B3 — القيد المحاسبي لازم يتعلّق على الـ webhook، مش على رد الـ HTTP

**R10** بيقول: مفيش قيد قبل ما ميسر يأكد نجاح الاسترداد. المبدأ صح 100% — بس تسلسل §6 بيخالفه: الخطوة 9 بتكتب القيد أول ما `POST /refund` يرجّع نجاح.

في ميسر (وفي الكود عندنا) رد الـ 200 معناه **قبول الطلب**، مش **تسوية**. الكود الحالي عارف ده كويس:

```php
// AdminPanel/CancellationsController.php:165
// Gateway-accepted refunds settle via webhook → 'pending' until then
'status' => $gateway ? 'pending' : 'succeeded',
```

والتسوية الفعلية بتيجي على:

```
POST /webhooks/moyasar   →  Dashboard/WebhookController.php   (fail-closed, secret_token)
```

لو خصمنا من الشريك على رد الـ HTTP وبعدين الاسترداد فشل في التسوية، يبقى خصمنا فلوس من شريك مقابل مبلغ ما رجعش للضيف — وتصحيحه هيحتاج قيد عكسي يدوي في جدول append-only.

**المطلوب:** فرع النجاح في §6 (قيد الليدجر + `refund_status` + إغلاق الشكوى + الإشعارات) ينتقل بالكامل لمعالج الـ webhook. الـ endpoint بيقف عند `refunds.status = pending`.

**نتيجة جانبية مهمة:** T5 («فشل ميسر → صفر قيود») بيبقى متحقق تلقائياً بدل ما يبقى شرط لازم نحرسه.

---

### ❌ B4 — `bookings.refund_status` مش عمود، وما ينفعش يبقى عمود

R6 و§6 بيتكلموا عن `bookings.refund_status = 'refunded' | 'partial'`. العمود ده **مش موجود**، وحالة الاسترداد دلوقتي **مشتقّة** بالحساب:

```php
// AdminPanel/CancellationsController.php:196  applyRefundStatus()
'refunded' => SUM(refunds.amount) >= bookings.total_amount
'partial'  => SUM(refunds.amount) > 0 AND < bookings.total_amount
```

لو خزّناها في عمود، يبقى عندنا قيمتين لنفس المعنى، وأول استرداد يفشل بينهم في الترتيب هيخلّيهم يختلفوا — والشاشة الشغّالة دلوقتي هتقول حاجة والعمود يقول حاجة تانية.

**المطلوب:** تفضل مشتقّة. R6 («اللي بيتغير هو `refund_status` فقط») بيتحقق من غير أي migration — وده أنضف لأنه بيمنع اختراع حالة حجز جديدة أصلاً، وهو هدف R6.

---

## 2. نقاط لازم تتقرر (مش مانعة للبدء)

### D1 — نوع القيد: استخدموا `refund_reversal` الموجود

الـ enum الحالي:

```php
// 2026_08_13_000002_create_partner_ledger_entries_table.php:32
enum('type', ['earning', 'payout', 'refund_reversal', 'adjustment'])
```

و`refund_reversal` **اتعمل مخصوص للحالة دي** — النص الحرفي في الـ migration:

> «its firing action — refunding a completed booking — is deferred pending a product decision, but the enum value ships today at zero cost»

وللنهاردة **مفيش سطر واحد في الكود بيكتبه** (تأكدنا بالبحث). المواصفة بتضيف `complaint_refund` + `complaint_penalty`، يعني `ALTER TABLE` على enum في جدول مالي حي — وفي MySQL ده إعادة بناء للجدول كله.

**المقترح:** `refund_reversal` لقيد الاسترداد (ده غرضه)، و`complaint_penalty` هو الوحيد اللي يتضاف فعلاً.

### D2 — الليدجر مافيهوش `booking_id`

§4.4 طالب إن كل قيد يحمل `booking_id` و`reference_id`. الأعمدة الفعلية هي `ref_type` / `ref_id` / `ref_code`. الشكل الصحيح:

```
ref_type = 'refund'
ref_id   = refunds.id
ref_code = كود الحجز   ← ده اللي بيظهر للشريك في كشف الحساب
```

### D3 — الوحدة الحسابية: هللات في الـ API، ريال في الداتابيز

R9 (هللات integers) سليم للـ API. بس الجداول كلها `decimal(x,2)` بالريال:
`refunds.amount`، `partner_ledger_entries.amount`، `partner_wallets.available_balance`.

يعني فيه حدّ تحويل واضح لازم يتكتب في المواصفة، وإلا اختبار الـ invariant هيتكتب على الوحدة الغلط.

### D4 — الوحدات المملوكة لممسى مش متغطية

`Pricing` بيحط `commissionRate = 1.0` و`partnerShare = 0` لما `units.mamsa_owned = true`. المواصفة مافيهاش فرع للحالة دي. والخطر مش نظري: `units.user_id` على الوحدات دي هو **الأدمن اللي أنشأ الإعلان** — فتطبيق ساذج هيخصم من محفظة أدمن.

**المطلوب:** استرداد على وحدة مملوكة لممسى = **صفر قيود ليدجر**. (اختبار جديد، شوف T15).

### D5 — R8 بيدّي `finance` صلاحية المصفوفة بتمنعها عنه

```php
// app/Support/AdminPermissions.php:33
FINANCE = [... 'wallets.view', 'payouts.view', 'payouts.execute' ...]   // مفيش wallets.adjust
```

`finance` مالوش `wallets.adjust` بشكل مقصود. تنفيذ الاسترداد بيخصم من محفظة شريك — يعني بيديله نفس القدرة بطريق غير مباشر. كمان مفيش أي `complaints.*` في `ALL` ولا في `FINANCE`، فلازم تتضاف للاتنين.

**قرار مطلوب:** يا إما نضيف `complaints.refund` لـ finance ونقبل الأثر صراحةً، يا إما التنفيذ يبقى superadmin.

---

## 3. الاختبارات — رد على T1–T14

| # | الحالة | الملاحظة |
|---|---|---|
| T1 | ✅ قابل للتنفيذ | عبر `Pricing::breakdown(500.00, 1)` — الأرقام تطابق |
| T2 | ⚠️ **بيثبت العكس** | شوف تحت |
| T3 | ✅ | مع مراعاة B2 — المتاح يتحسب من `refunds` |
| T4 | ✅ | `idempotency_key` unique جديد |
| T5 | ✅ يبقى تلقائي | لو اتطبق B3 |
| T6–T8 | ✅ | مباشر |
| T9 | ✅ **متحقق أصلاً** | `available_balance` signed — «MAY be negative» (`PartnerWallet.php:13`) |
| T10 | ✅ **متحقق أصلاً** | `PayoutEligibility.php:44` → `below_minimum` عند `wallet.min_payout_amount` = 2000 |
| T11 | ✅ | بالحالة المشتقّة (B4) |
| T12–T14 | ✅ | مباشر |

### T2 غلط في صياغته

النص: «fixture بعمولة مختلفة (7%) بيغير الناتج — لازم يفشل قبل التطبيق إثباتاً إن النسبة بتتقرأ من مصدر واحد».

لو النسبة بتتقرا من `config()`، الاختبار ده **هينجح** — وهو مش المطلوب. اللي بيثبت R2 فعلاً هو العكس:

> **T2 (معدّل):** حجز مجمّد على 2% بينما `config('booking.commission_rate')` = 10% → الاسترداد يتقسم بـ **2%**.

الاختبار ده **بيفشل على الكود الحالي**، وده بالظبط اللي المواصفة عايزة تثبته.

### اختبارات ناقصة

- **T15** — استرداد على وحدة `mamsa_owned` → صفر قيود ليدجر (D4)
- **T16** — إعادة إرسال نفس webhook التسوية → خصم واحد بس، مش اتنين

---

## 4. رد على سؤالي ميسر (§6)

**س1 — حد زمني للاسترداد على دفعة قديمة؟**
مش موجود في كودنا ولا في توثيق التكامل الحالي. **محتاج تأكيد من ميسر مباشرة** — مش قابل للاشتقاق من عندنا. المسار البديل اللي طلبتوه نصف مبني: `refunds.type` فيه `void`، وفيه مسار بدون بوابة (`$simulated` في `CancellationsController:153`)، فإضافة `manual_transfer_reference` عمود صغير.

**س2 — أكتر من استرداد جزئي على نفس الدفعة؟**
من ناحيتنا **مبني ومتحمّل**:
```php
MoyasarService::refund(string $moyasarId, ?int $amountHalalas = null)   // جزئي مدعوم
Payment::refundableAmount()                                            // بيراكم ويحد السقف
```
لكن إن ميسر نفسه يسمح بـ N استرداد جزئي على نفس الـ payment — **سؤال للبوابة**، مش لينا.

---

## 5. ملاحظات تشغيلية

**✅ staging على مفاتيح اختبار.** `MOYASAR_SECRET_KEY=sk_test_…` على staging و`sk_live_…` على الإنتاج. يعني T1–T14 تتنفذ على staging من غير أي حركة فلوس حقيقية.

**⚠️ فيه webhook مسجّل على حساب ميسر الحي وبيأشّر على staging.** التسجيل `bb6257ff-184a-40f4-87e3-a009937a436b` → `https://staging.mamsaa.com/api/v1/payments/callback`، مشترك في أحداث الحساب الحي. دلوقتي بيرد `401` لأن الـ shared secret مش مطابق، فمفيش أثر — **بس** `MOYASAR_WEBHOOK_SECRET` واحد بالحرف على staging والإنتاج، يعني أي حد "يصلّح" التطابق ده هيخلي أحداث دفع حقيقية تتعالج على staging.

النهاردة أثر ده محدود. **بعد B3 بيبقى الـ webhook هو اللي بيحرّك خصومات محافظ الشركاء** — يعني حدث حقيقي يقدر يكتب قيود على بيانات staging. الترتيب المطلوب قبل Phase A:

1. `DELETE /v1/webhooks/bb6257ff-…` من الحساب الحي، وتسجيل staging على حساب الاختبار
2. فصل `MOYASAR_WEBHOOK_SECRET` بين البيئتين

**✅ §8 (ربط الاسترداد بالفاتورة الأصلية) — مش محتاج شغل.** رقم الفاتورة **مشتق** مش مخزّن:
```php
// Api/V1/InvoiceController.php:100
sprintf('%s-%s-%s-%06d', $prefix, $at->format('Y'), $at->format('m'), $booking->id)
```
فأي صف استرداد عنده `booking_id` بيوصل لرقم الفاتورة بنفس الاشتقاق. مافيش علاقة ناقصة.

**ℹ️ الإنتاج مافيهوش queue worker.** الإشعارات في §7 (SMS + إيميل لأربع أطراف) لو اتبعتت داخل الـ transaction هتطوّل زمن الاستجابة. تتبعت بعد الـ commit.

---

## 6. الخلاصة

| البند | الرد |
|---|---|
| R1, R3, R4, R5, R7, R9, R10 | ✅ مقبولة — R5 وR10 متحققين في البنية الحالية |
| R2 | ⚠️ مقبول المبدأ، الدالة المذكورة غير موجودة والبديل بيقرأ النسبة الغلط (B1) |
| R6 | ⚠️ مقبول الهدف، التنفيذ المقترح بعمود مخزّن مرفوض (B4) |
| R8 | ⚠️ محتاج قرار على مصفوفة الصلاحيات (D5) |
| القسم 4 (المخطط) | ❌ `booking_refunds` يتلغي لصالح توسعة `refunds` (B2) |
| القسم 6 (التسلسل) | ❌ فرع النجاح ينتقل للـ webhook (B3) |
| القسم 3 (الحساب) | ✅ صحيح رياضياً وبيطابق `Pricing` |
| القسم 8 (ZATCA) | ✅ مافيش شغل مطلوب |
| القسم 9 (الاختبارات) | ⚠️ T2 يتعاد صياغته + T15/T16 يتضافوا |

**الوقت المتوقع بعد حسم B1–B4:** الشغل نفسه مباشر — أغلب البنية التحتية (الليدجر، المحفظة، مسار ميسر، الـ webhook، الصلاحيات) موجودة وشغّالة. اللي جديد فعلاً هو جدول الشكاوى ومرفقاته وربطه بمسار الاسترداد القائم.

**النشر:** staging فقط، متفقين. snapshot قبل وبعد جزء إجباري.
