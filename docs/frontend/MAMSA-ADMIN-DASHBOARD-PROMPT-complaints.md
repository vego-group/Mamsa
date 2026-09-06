# مهمة الفرونت — شاشات الشكاوى في لوحة الأدمن

**التاريخ:** 06/09/2026
**المستودع:** `vego-group/mamsa-admin-dashboard` (فرع `staging`)
**الباك اند:** جاهز ومنشور على `feat/complaints-refunds-backend` — 513 اختبار، صفر فشل
**البيئة:** `https://staging.mamsaa.com` (جذر، بدون `/api/v1`) — كوكي جلسة، حارس `admin-panel`

---

## 0. الفكرة في سطرين

ضيف بيشتكي بعد إقامته. الأدمن بيراجع، **بيعتمد مبلغ**، وبعدين **حد تاني بينفذه**. الفصل بين الاعتماد والتنفيذ هو جوهر الميزة — مش تفصيلة UI.

المبالغ كلها **بالهللات (integer)** في الـ API. القسمة على ١٠٠ للعرض بس.

---

## 1. دورة الحياة — أربع حالات، فعلين، دورين

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
    alreadyRefundedHalalas: number
    maxRefundableHalalas: number         // ← سقف حقل المبلغ
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
سبق استرداده            0.00
المتاح للاسترداد     1,000.00   ← سقف الإدخال
```
> لو `booking.mamsaOwned = true` اعرضوا شارة **«وحدة مملوكة لممسى — لا يوجد خصم على شريك»**. الاسترداد بيتنفذ عادي، بس مفيش محفظة بتتخصم.

**د. المسار** — سجل الاستردادات، والأزرار حسب الحالة والصلاحية.

---

## 4. الحالات اللي بتتنسي — وهي المهمة

### 4.1 `pending` ليست نجاحاً

رد التنفيذ بيرجّع `status: 'pending'` في الحالة العادية. معناه **البوابة قبلت الطلب، ولسه ما سوّتش**. التسوية بتوصل بعدين عبر webhook.

> **ممنوع** عرض «تم الاسترداد» على `pending`. النص الصحيح: **«قيد التنفيذ لدى بوابة الدفع»** بلون محايد — مش أخضر.
> أخضر يبقى بس لما `refunds[].status === 'succeeded'`.

الضيف نفسه مش بيتبلّغ بمبلغ إلا بعد التسوية — فلو الشاشة قالت «تم» والضيف ما وصلهوش شيء، ده تناقض هيوصل للدعم.

### 4.2 التنفيذ يطلب `idempotencyKey`

ولّدوا **UUID v4 واحد لكل محاولة تنفيذ**، واحتفظوا بيه طول المحاولة. لو الطلب اتقطع وأعيد بنفس المفتاح، الباك اند بيرجّع النتيجة الأصلية بـ `200` ومعاها `replayed: true` — **مش خطأ**، اعرضوها كنجاح عادي.

مفتاح جديد لكل ضغطة **جديدة** على الزر (بعد فشل مثلاً)، مش لكل إعادة إرسال لنفس المحاولة.

### 4.3 التنفيذ لا يقبل غير المبلغ المعتمد

حقل المبلغ في شاشة التنفيذ **للقراءة فقط** — يعرض `approvedRefundHalalas`. مافيش تعديل.

الباك اند بيقارن **بالتساوي التام**؛ أي رقم آخر بيرجع `AMOUNT_NOT_APPROVED`. لو عايزين رقم مختلف، ده تعديل اعتماد (٤.٤) وبصلاحية مختلفة.

### 4.4 تعديل المبلغ المعتمد

زر «تعديل المبلغ» يظهر **فقط** لما `complaint.canAmendApproval === true`.

بيبقى `false` أول ما يوجد استرداد `pending` أو `succeeded` — يعني الفلوس اتحركت أو في الطريق. الباك اند بيرفض بـ `409` كمان، فالزر إخفاؤه راحة للمستخدم مش الحارس الوحيد.

كل تعديل بيتسجل في سجل التدقيق بالقيمة القديمة والجديدة.

### 4.5 الأزرار حسب الصلاحية

| الحالة | `superadmin` | `finance` |
|---|---|---|
| `submitted` | «بدء المراجعة» | لا شيء (قراءة) |
| `under_review` | «اعتماد مبلغ» · «رفض» | لا شيء |
| `approved` | «تعديل المبلغ»* · «تنفيذ» | **«تنفيذ»** |
| `resolved_*` | قراءة | قراءة |

\* حسب `canAmendApproval`

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
5. التنفيذ بالـ idempotency وحالة `pending`
6. تعديل الاعتماد خلف `canAmendApproval`

كل خطوة قابلة للاختبار على staging وحدها.

---

## 7. ملاحظات تشغيلية

- الشكاوى **لم تُنشر على staging بعد** — الجداول غير موجودة هناك حتى الآن. نسّقوا معنا قبل بدء الربط الفعلي؛ الواجهة تُبنى على العقد أعلاه وهو ثابت.
- كل الرسائل من الباك اند عربية وجاهزة للعرض. لا تترجموها في الواجهة.
- الوقت كله ISO-8601 Zulu — اعرضوه بتوقيت الرياض.
