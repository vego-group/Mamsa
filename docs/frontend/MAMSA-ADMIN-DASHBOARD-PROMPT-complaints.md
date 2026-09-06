# مهمة الفرونت — شاشات الشكاوى في لوحة الأدمن

**التاريخ:** 06/09/2026
**المستودع:** `vego-group/mamsa-admin-dashboard` (فرع `staging`)
**الباك اند:** جاهز ومنشور على `feat/complaints-refunds-backend` — 513 اختبار، صفر فشل
**البيئة:** `https://staging.mamsaa.com` (جذر، بدون `/api/v1`) — كوكي جلسة، حارس `admin-panel`
**التحديث:** v5 — أضفنا ترتيب الفحصين (§4.3). v4 — **رجّعنا الخمس بنود اللي فقدتها v3** (§0 و§5)، وأضفنا **مصفوفة الانتقالات** (§1.1). قبلها: v3 = `canReject` والرفض من `approved`؛ v2 = `REFUND_IN_FLIGHT` و`pendingRefundHalalas` والقسم ٤.٢.

---

## 0. قواعد المنصة — سارية على الشاشة دي زي أي شاشة تانية

الخمسة دول مقفولين على مستوى المنصة، ومش خاصين بالشكاوى. رجّعناهم هنا بعد ما سقطوا سهواً من نسخة سابقة:

| القاعدة | التفصيل |
|---|---|
| **التاريخ** | `DD/MM/YYYY` في كل مكان. الـ API بيرجّع ISO-8601 Zulu — التحويل والعرض بتوقيت الرياض |
| **العملة** | **ريال سعودي فقط**. مفيش عملة تانية في أي شاشة |
| **إعادة الجلب** | بعد **كل** فعل ناجح، أعيدوا جلب التفاصيل. الأفعال بترجّع `{ ok: true }` مش الكيان المحدّث، والحالة بتتغير في الباك اند بطرق الرد ما بيوصفهاش |
| **`idempotencyKey`** | مفتاح جديد **فقط بعد فشل مؤكد** — رد فشل صريح وصل. مش عند timeout ولا انقطاع شبكة: دول حالات «مش عارفين»، وإعادة الإرسال فيها لازم تكون **بنفس المفتاح** |
| **⚠️ `PLATFORM_COMMISSION_RATE`** | **ممنوع استخدامه في شاشات الشكاوى** — التفصيل تحت |

### ⚠️ عن `PLATFORM_COMMISSION_RATE` تحديداً

في مستودع لوحة الأدمن:

```ts
// src/lib/constants/business.ts
export const PLATFORM_COMMISSION_RATE = 0.10;
export const PARTNER_SHARE_RATE = 1 - PLATFORM_COMMISSION_RATE;
```

وبيتستخدم في `CancellationDetailDrawer` و`BookingDetailDrawer` لحساب التقسيم **في الواجهة**.

**ده صحيح هناك وغلط هنا.** نسبة العمولة **مجمّدة على كل حجز** في `bookings.commission_rate` وقت إنشائه. حجز اتاخد أيام الـ 2% لسه عليه 2%، والثابت ده بيقول 10%. فحساب حصة الشريك في الواجهة لشكوى على حجز قديم بيطلع **رقم غلط بخمس أضعاف**، والشاشة هتعرض للأدمن مبلغ خصم مختلف عن اللي اتخصم فعلاً.

> **القاعدة:** كل أرقام التقسيم في شاشات الشكاوى بتيجي من الـ API — `partnerHalalas` و`vatHalalas` و`commissionHalalas`. **مفيش حساب في الواجهة، ومفيش استخدام للثابت ده.**

---

## 1. الفكرة في سطرين

ضيف بيشتكي بعد إقامته. الأدمن بيراجع، **بيعتمد مبلغ**، وبعدين **حد تاني بينفذه**. الفصل بين الاعتماد والتنفيذ هو جوهر الميزة — مش تفصيلة UI.

المبالغ كلها **بالهللات (integer)** في الـ API. القسمة على ١٠٠ للعرض بس.

---

## 1.1 دورة الحياة — أربع حالات، فعلين، دورين

```
submitted ──review──▶ under_review ──approve──▶ approved ──refund──▶ resolved_refunded
                            │                       │
                            └───────reject──────────┴──▶ resolved_rejected
```

| الفعل | الصلاحية المطلوبة | الأثر |
|---|---|---|
| مراجعة | `complaints.review` | `submitted` → `under_review` |
| **اعتماد مبلغ** | `complaints.approve` | `under_review` → `approved` + تثبيت المبلغ |
| **تنفيذ** | `complaints.execute_refund` | ينفّذ **المبلغ المعتمد بالظبط** |
| رفض | `complaints.approve` | → `resolved_rejected` |

**الصلاحيات بتتقرا من `permissions[]` في `/admin/me`** — زي باقي الشاشات، مش من اسم الدور.

`finance` عندها `complaints.view` و`complaints.execute_refund` **بس**. `superadmin` عندها الكل. يعني نفس الشاشة بتتصرف مختلف حسب المستخدم، وده مقصود.

⚠️ **لو التنفيذ فشل** الحالة بترجع `approved` (مش `under_review`) — يعني الزرار يشتغل تاني من غير دورة اعتماد جديدة.

---

## 1.2 مصفوفة الانتقالات — الجدول الكامل

اقتراحكم، ومطبّق بأثر رجعي على الفيتشر دي. **العمود الأخير هو اللي كانت فيه العيوب التلاتة كلها**، ومكانش موصوف في أي مستند قبل كده.

| الفعل | من `submitted` | من `under_review` | من `approved` | من `approved` + استرداد ماشي |
|---|---|---|---|---|
| review | ✅ | — | — | — |
| approve | — | ✅ | — | — |
| amend | — | — | ✅ | ⛔ `canAmendApproval=false` · 409 |
| execute | — | — | ✅ | ⛔ **409 `REFUND_IN_FLIGHT`** |
| reject | ✅ | ✅ | ✅ | ⛔ **409 `REFUND_IN_FLIGHT`** |
| settle | — | — | ✅ → `resolved_refunded` | — |

**«استرداد ماشي»** = أي صف في `refunds[]` حالته `pending` أو `succeeded`. `failed` **مش** منها — ده ما رجّعش حاجة، والشكوى بترجع `approved` لإعادة المحاولة.

وفيه خانة سابعة مش في الجدول لأنها مش فعل بتاع الواجهة، بس تهمكم:

> **تسوية على شكوى مش في `approved`** → القيد المحاسبي **بيتكتب** (الفلوس اتحركت)، وحالة الشكوى **ما بتتغيرش** (القرار المسجّل ما يتكتبش فوقه)، وتنبيه بيتولّد. يعني لو شفتوا شكوى `resolved_rejected` وعليها استرداد `succeeded`، ده مش خطأ عرض — ده حالة شاذة اتسجلت عن قصد وفيه حد اتبلّغ بيها.

---

## 2. الـ Endpoints

أضيفوهم في `src/lib/api/endpoints.ts` بنفس النمط:

```ts
complaints: {
  list:     '/admin/complaints',
  detail:   (id: string) => `/admin/complaints/${id}`,
  status:   (id: string) => `/admin/complaints/${id}/status`,     // PATCH
  approve:  (id: string) => `/admin/complaints/${id}/approve`,    // POST
  approval: (id: string) => `/admin/complaints/${id}/approval`,   // PATCH — تعديل المبلغ
  refund:   (id: string) => `/admin/complaints/${id}/refund`,     // POST
  reject:   (id: string) => `/admin/complaints/${id}/reject`,     // POST
},
```

### `GET /admin/complaints`

باراميترات: `status`, `search`, `page`, `pageSize` — نفس مغلف القوائم المعتاد `{ items, total, page, pageSize, sortBy, sortDir }`.

البحث بيغطي كود الحجز، اسم الوحدة، اسم الضيف، ورقم جواله.

```ts
type ComplaintRow = {
  id: number
  status: 'submitted' | 'under_review' | 'approved' | 'resolved_refunded' | 'resolved_rejected'
  bookingCode: string | null
  unitName: string | null
  guestName: string | null
  partnerName: string | null
  hasAttachments: boolean
  createdAt: string | null   // ISO-8601 Zulu
}
```

### `GET /admin/complaints/{id}`

كل اللي الشاشة محتاجاه في نداء واحد:

```ts
type ComplaintDetail = {
  complaint: {
    id: number
    status: ComplaintRow['status']
    description: string
    contactedPartner: boolean
    internalNote: string | null          // أدمن فقط — لا يظهر لضيف ولا شريك
    guestMessage: string | null          // النص الذي يراه الضيف
    reviewedAt: string | null
    approvedRefundHalalas: number | null
    approvedAt: string | null
    canAmendApproval: boolean            // ← يتحكم في إظهار زر التعديل
    canReject: boolean                   // ← يتحكم في إظهار زر الرفض
    createdAt: string | null
  }
  attachments: Array<{ url: string; mime: string }>   // روابط موقّعة تنتهي بعد 15 دقيقة
  booking: {
    code: string | null
    checkIn: string | null
    checkOut: string | null
    grossHalalas: number
    vatHalalas: number
    commissionHalalas: number
    partnerShareHalalas: number
    alreadyRefundedHalalas: number       // مُسوّى فعلاً
    pendingRefundHalalas: number         // عند البوابة، لم يُسوَّ بعد
    maxRefundableHalalas: number         // = gross − already − pending  ← سقف حقل المبلغ
    mamsaOwned: boolean
  }
  guest:   { name: string | null; phone: string | null }
  partner: { name: string | null; phone: string | null; availableBalanceHalalas: number }
  unit:    { id: number | null; name: string | null }
  refunds: Array<{
    id: number
    status: 'pending' | 'succeeded' | 'failed'
    amountHalalas: number
    partnerHalalas: number
    failureReason: string | null
    moyasarRefundId: string | null
    createdAt: string | null
  }>
}
```

### الأفعال

```
PATCH  /admin/complaints/{id}/status     {}                                    → { ok: true }
POST   /admin/complaints/{id}/approve    { amountHalalas, guestMessage?, internalNote? }
PATCH  /admin/complaints/{id}/approval   { amountHalalas }
POST   /admin/complaints/{id}/refund     { amountHalalas, idempotencyKey }
       → { ok: true, refundId, status: 'pending'|'succeeded', replayed?: true }
POST   /admin/complaints/{id}/reject     { guestMessage, internalNote? }
```

المغلف عند الخطأ هو المعتاد: `{ code, message, fields? }` والرسائل **عربية جاهزة للعرض** — اعرضوا `message` زي ما هو.

الأكواد اللي تستاهل معالجة خاصة:

| الكود | المعنى | التصرف |
|---|---|---|
| `AMOUNT_NOT_APPROVED` | 422 — المبلغ لا يطابق المعتمد | اعرض `fields.amountHalalas` (فيها المبلغ المعتمد) |
| `AMOUNT_EXCEEDS_REFUNDABLE` | 422 — أكبر من المتاح | اعرض `fields.amountHalalas` |
| **`REFUND_IN_FLIGHT`** | **409 — يوجد استرداد ماشٍ بالفعل** | **مش خطأ. عطّل زر التنفيذ (والرفض) واعرض حالة الاسترداد في `fields.refundId`** |
| `CONFLICT` | 409 — انتقال حالة غير مسموح | أعد تحميل التفاصيل، الحالة اتغيرت من تحتك |
| `INSUFFICIENT_PERMISSION` | 403 | الزر ما كانش المفروض يظهر أصلاً |

---

## 3. الشاشتان

### 3.1 القائمة — `/(admin)/complaints`

جدول بنفس شكل `cancellations`. أعمدة: الحالة · كود الحجز · الوحدة · الضيف · الشريك · التاريخ · مؤشر مرفقات.

فلتر بالحالة + بحث. الصف كله يفتح التفاصيل.

### 3.2 التفاصيل — `/(admin)/complaints/[id]`

أربع مناطق:

**أ. الشكوى** — الوصف، «تواصل مع الشريك؟»، المرفقات كشبكة صور.
> ⚠️ روابط المرفقات **موقّعة وتنتهي بعد ١٥ دقيقة**. لا تخزّنوها في state طويل العمر ولا تعيدوا استخدامها بعد فتح الصفحة بمدة — أعيدوا جلب التفاصيل بدل ذلك.

**ب. الأطراف** — الضيف (اسم + **جوال**) والشريك (اسم + **جوال** + الرصيد المتاح). الجوالان معروضان عمداً: الأدمن بيتصل بالطرفين قبل القرار.

**ج. المال** — تفكيك الحجز والمتاح للاسترداد:
```
إجمالي الحجز        1,000.00
منها ضريبة            130.43
عمولة ممسى             86.96
حصة الشريك            782.61
سبق استرداده            0.00   ← alreadyRefundedHalalas (مُسوّى)
قيد التنفيذ            500.00   ← pendingRefundHalalas (عند البوابة)
المتاح للاسترداد       500.00   ← سقف الإدخال = gross − already − pending
```

> السطران منفصلان عن قصد. **«سبق استرداده»** فلوس رجعت فعلاً؛ **«قيد التنفيذ»** فلوس البوابة قبلتها ولسه ما سوّتش. لو دمجتوهم في رقم واحد، الشاشة مش هتقدر تفسّر ليه السقف أقل مما يوحي به المسترد.
> لو `booking.mamsaOwned = true` اعرضوا شارة **«وحدة مملوكة لممسى — لا يوجد خصم على شريك»**. الاسترداد بيتنفذ عادي، بس مفيش محفظة بتتخصم.

**د. المسار** — سجل الاستردادات، والأزرار حسب الحالة والصلاحية.

---

## 4. الحالات اللي بتتنسي — وهي المهمة

### 4.1 `pending` ليست نجاحاً

رد التنفيذ بيرجّع `status: 'pending'` في الحالة العادية. معناه **البوابة قبلت الطلب، ولسه ما سوّتش**. التسوية بتوصل بعدين عبر webhook.

> **ممنوع** عرض «تم الاسترداد» على `pending`. النص الصحيح: **«قيد التنفيذ لدى بوابة الدفع»** بلون محايد — مش أخضر.
> أخضر يبقى بس لما `refunds[].status === 'succeeded'`.

الضيف نفسه مش بيتبلّغ بمبلغ إلا بعد التسوية — فلو الشاشة قالت «تم» والضيف ما وصلهوش شيء، ده تناقض هيوصل للدعم.

### 4.2 الشكوى تفضل `approved` بعد التنفيذ — والزر لازم يتعطّل

أهم فخ في الشاشة دي، واكتشفه فريقكم قبل ما يتكتب أي كود.

التنفيذ الناجح **مابيقفلش الشكوى**. الحالة بتتحول لـ `resolved_refunded` عند **التسوية**، مش عند التنفيذ. يعني بعد ضغطة ناجحة:

```
refunds[0].status = 'pending'
complaint.status  = 'approved'     ← لسه approved
```

والتسوية ممكن تتأخر ساعة لو الـ webhook ما وصلش (مهمة المطابقة بتشتغل كل ساعة). فالأدمن بيبص على شاشة فيها زرار تنفيذ شغال، وبيفتكر إن المحاولة الأولى فشلت، وبيضغط تاني.

**القاعدة في الواجهة:**

> زر «تنفيذ» يتعطّل لما يكون فيه أي صف في `refunds[]` حالته `pending` أو `succeeded` — مش بس لما الحالة تبقى `resolved_*`.
> ومكانه تظهر حالة الاسترداد الجاري.

**والباك اند بيحرسها كمان** — التنفيذ التاني بيرجّع `REFUND_IN_FLIGHT` / 409 حتى بمفتاح idempotency جديد. الواجهة بتمنع الضغطة، والباك اند بيمنع النتيجة. الاتنين مطلوبين: الواجهة عشان التجربة، والباك اند لأن **الواجهة مش مكان الحماية المالية**.

### 4.3 التنفيذ يطلب `idempotencyKey`

ولّدوا **UUID v4 واحد لكل محاولة تنفيذ**، واحتفظوا بيه طول المحاولة. لو الطلب اتقطع وأعيد بنفس المفتاح، الباك اند بيرجّع النتيجة الأصلية بـ `200` ومعاها `replayed: true` — **مش خطأ**، اعرضوها كنجاح عادي.

مفتاح جديد لكل ضغطة **جديدة** على الزر (بعد فشل **مؤكد**)، مش لكل إعادة إرسال لنفس المحاولة.

#### الترتيب مضمون: المفتاح قبل الحارس

سؤالكم عن أنهي فحص بيتنفذ الأول — **فحص المفتاح بيسبق حارس `REFUND_IN_FLIGHT`**، في الكونترولر وفي الخدمة الاتنين. فمعنى ده عملياً:

| الحالة | الرد |
|---|---|
| نفس المفتاح، والصف الأول لسه `pending` | **`200` + `replayed: true` + نفس `refundId`** |
| مفتاح جديد، والصف الأول لسه `pending` | `409 REFUND_IN_FLIGHT` |

الطلب المعاد بنفس المفتاح **هو نفس الطلب**، والحارس غرضه يمسك الطلبات التانية — بالظبط زي ما حللتوا.

**والترتيب ده متثبّت باختبار** بيفشل لو حد عكسه، مش متروك للصدفة: اتأكدنا إنه يرجّع `409` بدل `200` لما الفحصين يتبدلوا. فالـ mock عندكم المبني على «المفتاح أولاً» بيطابق الباك اند.

### 4.4 التنفيذ لا يقبل غير المبلغ المعتمد

حقل المبلغ في شاشة التنفيذ **للقراءة فقط** — يعرض `approvedRefundHalalas`. مافيش تعديل.

الباك اند بيقارن **بالتساوي التام**؛ أي رقم آخر بيرجع `AMOUNT_NOT_APPROVED`. لو عايزين رقم مختلف، ده تعديل اعتماد (٤.٥) وبصلاحية مختلفة.

### 4.5 تعديل المبلغ المعتمد

زر «تعديل المبلغ» يظهر **فقط** لما `complaint.canAmendApproval === true`.

بيبقى `false` أول ما يوجد استرداد `pending` أو `succeeded` — يعني الفلوس اتحركت أو في الطريق. الباك اند بيرفض بـ `409` كمان، فالزر إخفاؤه راحة للمستخدم مش الحارس الوحيد.

كل تعديل بيتسجل في سجل التدقيق بالقيمة القديمة والجديدة.

### 4.6 الأزرار حسب الصلاحية

| الحالة | `superadmin` | `finance` |
|---|---|---|
| `submitted` | «بدء المراجعة» | لا شيء (قراءة) |
| `under_review` | «اعتماد مبلغ» · «رفض» | لا شيء |
| `approved` | «تعديل المبلغ»* · **«رفض»**† · «تنفيذ»** | **«تنفيذ»**\*\* |
| `resolved_*` | قراءة | قراءة |

\* حسب `canAmendApproval`
\*\* **معطّل** لو فيه صف `pending` أو `succeeded` في `refunds[]` — شوف ٤.٢
† الرفض مسموح من `approved` كمان، مش من `under_review` بس — حسب `canReject`. الحالة: اتعمد مبلغ، وبعدين وصل دليل من الشريك إن الشكوى غير صحيحة. من غير المسار ده الشكوى بتفضل عالقة: مش هتتنفذ ومش هتتقفل. بيتقفل أول ما يبقى فيه استرداد ماشي (`REFUND_IN_FLIGHT` / 409).

`superadmin` يقدر ينفّذ (مخرج طوارئ)، بس المسار المفضّل شخصان. لو نفس الشخص اعتمد ونفّذ، السجل بيتوسم `single_actor` — مش لازم يظهر في الواجهة، بس مايتمنعش.

---

## 5. صياغة المبالغ

- التخزين والـ API **هللات**. اقسموا على ١٠٠ للعرض بمنزلتين.
- **ممنوع** حساب حصة الشريك في الواجهة. الباك اند بيرجّعها (`partnerHalalas`) وقسمتها تعتمد على نسبة عمولة **مجمّدة على الحجز** قد تختلف عن النسبة الحالية.
- المبلغ المعروض للشريك كخصم هو `partnerHalalas` **لا** `amountHalalas` — الضريبة ترجع لهيئة الزكاة والعمولة لممسى، ولا يتحملهما الشريك.

---

## 6. الترتيب المقترح

1. `endpoints.ts` + الأنواع + خطاف القائمة
2. شاشة القائمة بالفلتر والبحث
3. شاشة التفاصيل — عرض فقط، بالمناطق الأربع
4. الأفعال: مراجعة → اعتماد → رفض
5. التنفيذ بالـ idempotency، وحالة `pending`، وتعطيل الزر أثناء التنفيذ (٤.٢)
6. تعديل الاعتماد خلف `canAmendApproval`

كل خطوة قابلة للاختبار على staging وحدها.

---

## 7. ملاحظات تشغيلية

- **الشكاوى منشورة على staging الآن** — الجداول والمسارات الـ13 حيّة، والمهمة المجدولة تعمل. ابدأوا الربط مباشرة.
- **حالة جاهزة للبناء عليها:** الشكوى **#1 على الحجز 83** على staging، مرّت بالدورة كاملة إلى `resolved_refunded` ومعها استرداد مُسوّى وقيد ليدجر حقيقي (−391.30). تفتح على `GET /admin/complaints/1`.
- مسار التسوية اختُبر حيّاً على staging عبر `POST /webhooks/moyasar` مباشرة — لا يوجد تسجيل webhook لـ staging عند ميسر (سجل ميسر على مستوى الحساب، فتسجيل staging يعني استقبالها لأحداث الإنتاج). لا يؤثر على عملكم.
- كل الرسائل من الباك اند عربية وجاهزة للعرض. لا تترجموها في الواجهة.
- الوقت كله ISO-8601 Zulu — اعرضوه بتوقيت الرياض.
