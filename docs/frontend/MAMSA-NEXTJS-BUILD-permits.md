# دليل التنفيذ — التصاريح على واجهات Next.js التلاتة

**الأساس:** `docs/backend/permits-frontend-contract-phases-1-3.md` (العقد الكامل بالأمثلة)
**الكود المنشور:** `prod-2026-09-22-refunds` · **التاريخ:** 22/09/2026

الملف ده **إيه اللي تبنيه**، مش إيه اللي الـAPI بيرجّعه. العقد في الملف التاني.

---

## ٠. حاجات مشتركة بين التلات تطبيقات

### ٠.١ أنواع TypeScript — حطّوها في package مشترك

```ts
// packages/types/permits.ts
export type PermitStatus = 'valid' | 'expiring' | 'expired' | 'unknown';

export type PermitFields = {
  permitExpiresAt: string | null;   // YYYY-MM-DD ميلادي
  permitStatus: PermitStatus;
};

export type PermitAddress = {
  city: string | null;
  district: string | null;
  building: string | null;
  unitNo: string | null;
};

export type PermitRenewal = {
  id: string;
  status: 'pending' | 'current' | 'rejected' | 'superseded';
  permitExpiresAt: string | null;
  tourismLicenseNumber: string | null;
  tourismLicenseFileId: string | null;
  submittedAt: string;
  reviewedAt: string | null;
  rejectionReason: string | null;
};
```

### ٠.٢ الأغلفة التلاتة — استخرجوا الخطأ مرة واحدة

كل تطبيق بيتكلم مع سطح واحد بس، وكل سطح ليه غلاف مختلف:

```ts
// lib/api-error.ts — نسخة لكل تطبيق حسب سطحه
export type ApiError = { code: string; message: string; fields?: Record<string,string>; meta?: Record<string,unknown> };

// admin  → { message, code, fields?, meta? }
export const parseAdminError = (b: any): ApiError =>
  ({ code: b?.code ?? 'UNKNOWN', message: b?.message ?? '', fields: b?.fields, meta: b?.meta });

// partner → { error: { code, message, fields?, meta? } }
export const parsePartnerError = (b: any): ApiError =>
  ({ code: b?.error?.code ?? 'UNKNOWN', message: b?.error?.message ?? '', fields: b?.error?.fields, meta: b?.error?.meta });

// guest  → { success:false, message, code, meta? }
export const parseGuestError = (b: any): ApiError =>
  ({ code: b?.code ?? 'UNKNOWN', message: b?.message ?? '', meta: b?.meta });
```

**اعرضوا `message` زي ما هو — كله عربي جاهز للعرض.** الـ`code` للتفرّع والـ`fields` لتوجيه الفورم.

### ٠.٣ الهجري ↔ الميلادي — شغلكم إنتوا

الـAPI بيقبل ويرجّع **ميلادي `YYYY-MM-DD` فقط**. التصاريح مطبوعة هجري.

```bash
npm i dayjs @umalqura/core   # أو hijri-date
```

```tsx
// components/HijriGregorianDateField.tsx
// حقل واحد بتبويبين: هجري | ميلادي. بيخزّن ميلادي دايماً.
// ويعرض تحت الحقل المقابل الهجري للتأكيد: "١٤٤٩/٠٤/٢٢ هـ"
```
لازم يكون **في التلات أماكن**: ويزارد الشريك، ويزارد الأدمن، وفورم التجديد.

### ٠.٤ مكوّن الحالة — واحد، مشترك

```tsx
// components/PermitStatusBadge.tsx
const MAP = {
  valid:    { label: 'سارٍ',            tone: 'success' },
  expiring: { label: 'ينتهي قريباً',     tone: 'warning' },
  expired:  { label: 'منتهي',           tone: 'danger'  },
  unknown:  { label: 'غير مسجّل',        tone: 'neutral' },  // مش خطأ
};
```

**🔴 `unknown` مش تحذير.** دي حالة كل الإعلانات القديمة — على الإنتاج **كل التصاريح التلاتة بدون تاريخ**. لو عرضتوها أحمر، أول يوم تشوفوا لوحة كلها حمرا.

---

## ١. تطبيق الضيف (mamsa-app)

**أقل تطبيق شغل — وأهم حاجتين فيه إنكم ما تكسروش حاجة.**

### ١.١ مفيش أي حقل تصريح

ولا رقم ولا تاريخ ولا حالة بيوصلكم. **ما تضيفوش أي UI للتصاريح.** التأثير الوحيد إن أيام معيّنة مقفولة.

### ١.٢ التقويم — **اشتغل لوحده، بس تأكدوا**

`GET /api/v1/units/{id}/blocked-dates` بيرجّع مدى زيادة:

```json
{ "start": "2026-10-02", "end": "2026-11-01", "reason": "permit_expiry" }
```

**لو الـdate picker بتاعكم بيقرا `blocked[]` كمصفوفة مديات، مفيش شغل — بيقفل لوحده.**

**اللي لازم تعملوه:**
- ✅ تأكدوا إن `reason` **optional** في الـtype — المديات التانية (حجوزات، إغلاق يدوي) بترجع **من غيره**.
- 🟡 اختياري ومستحسن: لما المستخدم يضغط على يوم مقفول بـ`reason === 'permit_expiry'`، اعرضوا «هذه الوحدة غير متاحة للحجز بعد هذا التاريخ» بدل «محجوز».

```ts
type BlockedRange = { start: string; end: string; reason?: 'permit_expiry' };
```

### ١.٣ كود `409` جديد على التوفر والحجز

```ts
if (res.status === 409 && body.code === 'BOOKING_EXCEEDS_PERMIT_VALIDITY') {
  // meta.permit_expires_at موجود
  toast('هذه الوحدة غير متاحة للتواريخ المختارة. جرّب تواريخ أقرب.');
  // اقترحوا تواريخ تنتهي قبل meta.permit_expires_at
}
```
**تعاملوا معاه زي `UNIT_UNAVAILABLE`** — نفس الـ`409`، نفس المعنى: «التواريخ دي مش متاحة».

### ١.٤ 🔴 رد الحجز — الشقة اللي اتخصصت

```ts
// غلط: تكمّل على الوحدة اللي في الكارت
// صح:
const allocated = booking.unit;          // الشقة اللي السيرفر خصصها فعلاً
const unitId = booking.unit.id;          // ممكن تختلف عن اللي بعتّوه
// booking.unit_id في جذر الرد = null — ما تستخدموهوش
```
في مبنى، الكارت بيعرض ممثّل واحد والسيرفر بيختار شقة فاضية ومرخّصة. **صفحة تأكيد الحجز لازم تقرا `booking.unit`.**

⚠️ `booking.unit` **ما فيهوش `apartment_no`** حالياً — لو محتاجين تعرضوا رقم الباب للضيف، اطلبوه (سطر واحد عندنا).

### ١.٥ `available_count` على الكارت

```tsx
{unit.available_count > 1 && <Badge>{unit.available_count} وحدات متاحة</Badge>}
```
⚠️ **مفيش `group_size`** — تقدروا تقولوا «٤ متاحة» بس مش «٤ من ٦».

---

## ٢. لوحة الشريك (mamsa-partner-dashboard)

**أكبر نصيب من الشغل.**

### ٢.١ ويزارد الوحدة — خطوة الترخيص

أضيفوا `permitExpiresAt` جنب رقم التصريح وملفه:

```tsx
<HijriGregorianDateField
  name="permitExpiresAt"
  label="تاريخ انتهاء التصريح"
  required={PERMIT_EXPIRY_REQUIRED}   // من env، حالياً false
  help="مكتوب على التصريح. أدخله هجري أو ميلادي."
/>
```

**بيتبعت في `PATCH /units/{id}` و`POST /units` مع باقي الحقول.**

### ٢.٢ 🔴 تحذير قبل الحفظ — ده أهم بند في الملف ده

**أي تعديل على وحدة `approved` بيرجّعها `pending` وبيشيلها من المتجر — حتى لو عدّلتوا حقل تصريح واحد.**

```tsx
// قبل أي submit على وحدة status === 'approved'
<ConfirmDialog
  title="سيعود الإعلان للمراجعة"
  body="تعديل وحدة منشورة يُرجعها لقائمة المراجعة، ولن تظهر للضيوف حتى تتم الموافقة مرة أخرى."
  confirmLabel="تعديل على أي حال"
/>
```

**الاستثناء الوحيد: التجديد.** `POST /units/{id}/permit-renewals` **ما بيغيّرش حالة الوحدة** — وده بالظبط سبب وجوده. **قولوا ده صراحة في واجهة التجديد**: «الإعلان يظل ظاهراً أثناء مراجعة التجديد».

### ٢.٣ بانر انتهاء التصريح

**المصدر: `GET /notifications` وفلترة `type === 'permit_expiring'`.** مفيش endpoint خاص.

```tsx
// hooks/usePermitBanner.ts
const { data } = useSWR('/notifications?limit=20', fetcher, { refreshInterval: 60_000 });
const alert = data?.data?.find(n => n.type === 'permit_expiring' && !n.read);
```

```tsx
{alert && (
  <Banner tone={unit.permitStatus === 'expired' ? 'danger' : 'warning'}>
    {alert.title}
    <Button href={`/units/${unitId}/permit/renew`}>جدّد التصريح</Button>
  </Banner>
)}
```

⚠️ **قيود حالية في الإشعار** (بلّغناكم بيها):
- `body` فاضي و`href` بـ`null` — **الزرار لازم تبنوه إنتوا**.
- الإشعار **ما فيهوش `unit_id`** — اسم الوحدة جوّه نص `title` بس.

**فالأفضل عملياً:** خدوا الحالة من **الوحدة نفسها** (`permitStatus` + `permitExpiresAt` في `GET /units`) للبانر اللي جوّه صفحة الوحدة، واستخدموا الإشعار للجرس العام بس. كده مش محتاجين تستنوا إصلاح الإشعار.

```tsx
// بانر صفحة الوحدة — من بيانات الوحدة، مش من الإشعار
{unit.permitStatus === 'expiring' && <Banner tone="warning">
  تصريح هذه الوحدة ينتهي في {formatBoth(unit.permitExpiresAt)}. لا يمكن استقبال حجوزات تنتهي بعد هذا التاريخ.
  <Button>جدّد التصريح</Button>
</Banner>}

{unit.permitStatus === 'expired' && <Banner tone="danger">
  انتهى تصريح هذه الوحدة — الإعلان لا يظهر للضيوف ولا يستقبل حجوزات.
  <Button>جدّد التصريح</Button>
</Banner>}
```

### ٢.٤ صفحة التجديد — شاشة جديدة

`/units/[id]/permit/renew`

```tsx
// GET /units/{id}/permit-renewals أولاً
const renewals = await api.get(`/units/u_${id}/permit-renewals`);
const pending = renewals.find(r => r.status === 'pending');

if (pending) {
  // مفيش فورم — اعرضوا الحالة
  return <PendingCard
    submittedAt={pending.submittedAt}
    newExpiry={pending.permitExpiresAt}
    note="الإعلان يعمل بالتصريح الحالي حتى تتم المراجعة." />;
}
```

الفورم:

| الحقل | إلزامي | ملاحظة |
|---|---|---|
| `permitExpiresAt` | ✅ | لازم في المستقبل |
| `tourismLicenseNumber` | ❌ | **اعرضوا الحالي كـplaceholder**: «يُستخدم الحالي إذا تُرك فارغاً» |
| `tourismLicenseFileId` | ❌ | نفس presign الحالي، `kind: license_pdf` |
| `permitAddress.{city,district,building,unitNo}` | ❌ | «العنوان المكتوب على التصريح» |

**معالجة الأخطاء (كلها `422` بغلاف الشريك):**

```ts
const HANDLERS: Record<string, string> = {
  RENEWAL_ALREADY_PENDING: 'لديك طلب تجديد قيد المراجعة بالفعل.',
  PERMIT_EXPIRED:          'التاريخ المدخل في الماضي. أدخل تاريخ انتهاء التصريح الجديد.',
  NO_PERMIT_TO_RENEW:      'أضف تصريح الوحدة أولاً من صفحة التعديل.',
  PERMIT_EXPIRY_REQUIRED:  'تاريخ انتهاء التصريح الجديد مطلوب.',
};
```

**وسجل التجديدات** تحت الفورم: `status` + `submittedAt` + `rejectionReason` للمرفوضة.

### ٢.٥ بوابة الإرسال للمراجعة

`POST /units/{id}/submit` بيرجّع **`400`** (مش 422) بكود `VALIDATION` و`fields`:

```ts
// وجّهوا المستخدم لخطوة الويزارد حسب المفتاح
const STEP: Record<string,string> = {
  beds: 'basics', address: 'location', location: 'location',
  tourismLicenseFileId: 'license', permitExpiresAt: 'license',
  photos: 'photos', description: 'description',
};
```
`permitExpiresAt` ليه رسالتان مختلفتان (منتهي / مطلوب) — **اعرضوا الرسالة الجاية من السيرفر**.

### ٢.٦ التوسيع — `SOURCE_UNIT_INCOMPLETE`

```ts
if (code === 'SOURCE_UNIT_INCOMPLETE') {
  // meta.unit_id = "u_24" → الوحدة الأصل، مش الشقق
  router.push(`/units/${meta.unit_id}/edit?highlight=${Object.keys(fields).join(',')}`);
}
```
⚠️ **وعلى الإنتاج `MULTI_UNIT_ENABLED=false`** — زرار «أضف وحدات» **يتخفي**، وإلا كل ضغطة بترجع `MULTI_UNIT_DISABLED`.

```ts
const multiUnit = process.env.NEXT_PUBLIC_MULTI_UNIT_ENABLED === 'true';  // false على الإنتاج
```

---

## ٣. لوحة الأدمن (mamsa-admin-dashboard)

### ٣.١ شاشة الوحدة — حقول جديدة

في قسم الترخيص: `permitExpiresAt` + `<PermitStatusBadge status={permitStatus} />`.

**و`pendingRenewalId`:**
```tsx
{unit.pendingRenewalId && (
  <Alert tone="info">
    يوجد طلب تجديد قيد المراجعة.
    <Link href={`/permit-renewals/${unit.pendingRenewalId}`}>افتح الطلب</Link>
  </Alert>
)}
```
**ده مهم:** وحدة `expired` **ومعاها** `pendingRenewalId` = الإعلان هادي **بس حد اتصرّف بالفعل**، والعلاج في طابور المراجع نفسه. من غير التنبيه ده المراجع هيفتكرها مشكلة مهملة.

### ٣.٢ الكتابة — نفس الحقول الخمسة

`PATCH /admin/units/{id}` بياخد `permitExpiresAt` مع الأربعة القدام. **نفس تحذير ٢.٢ ينطبق هنا** — تعديل وحدة معتمدة بيرجّعها للمراجعة.

### ٣.٣ شاشة جديدة: `/permits` — مراقبة التصاريح

```tsx
// تبويبات
<Tabs>
  <Tab id="expiring" label="تنتهي خلال ٣٠ يوم" default />
  <Tab id="expired"  label="منتهية" />
  <Tab id="valid"    label="سارية" />
</Tabs>
// GET /admin/permits?status={tab}&page&pageSize&sortBy=expiresAt&sortDir
```

**أعمدة الجدول:** الوحدة · الشريك · رقم التصريح · تاريخ الانتهاء (+ badge) · `unitsCovered` · `scope`.

⚠️ **التصاريح بدون تاريخ مش في أي تبويب** — اعرضوا سطر تحت الجدول: «التصاريح غير المسجّل لها تاريخ لا تظهر هنا».
⚠️ **`unitId`/`unitName` في مبنى = وحدة واحدة من كذا** — استخدموا `unitsCovered` عشان تقولوا «يغطي ٨ وحدات».

### ٣.٤ شاشة جديدة: `/permit-renewals` — طابور التجديدات

**طابور مستقل، مش جوّه `/approvals`.** `GET /admin/permit-renewals?status=pending`

**شاشة التفاصيل — الحاجة الوحيدة اللي المراجع محتاجها: المقارنة.**

```tsx
<div className="grid grid-cols-2 gap-6">
  <Panel title="العنوان على التصريح">
    {permitAddress.city} · {permitAddress.district}
    {permitAddress.building && `مبنى ${permitAddress.building}`}
    {permitAddress.unitNo && `وحدة ${permitAddress.unitNo}`}
  </Panel>
  <Panel title="عنوان الإعلان">
    {listingAddress.city} · {listingAddress.district}
    {listingAddress.address}
    <MapPin lat={listingAddress.lat} lng={listingAddress.lng} />
  </Panel>
</div>

<Compare>
  <div>التصريح الحالي: {currentPermit.tourismPermitNo} — ينتهي {currentPermit.permitExpiresAt}</div>
  <div>التصريح الجديد: {tourismPermitNo} — ينتهي {permitExpiresAt}</div>
</Compare>

<a href={permitFileUrl} target="_blank">فتح ملف التصريح</a>   {/* رابط موقّع، صالح ساعتين */}
```

⚠️ **مفيش `addressMatch`** — **المقارنة بعين المراجع.** ما تبنوش عليها منطق آلي.
⚠️ **`permitAddress` كل حقوله ممكن تكون `null`** — اعرضوا «غير مُدخل» مش فراغ.
⚠️ **`permitFileUrl` صالح ساعتين** — اطلبوا الصف من جديد لو الصفحة مفتوحة من زمان.

**القرار:**
```ts
await api.post(`/admin/permit-renewals/${id}/approve`);                    // body فاضي
await api.post(`/admin/permit-renewals/${id}/reject`, { reason, notes });  // reason إلزامي
```
- **`reason` بيوصل الشريك** · **`notes` داخلية** — سمّوهم كده في الفورم بالظبط.
- `409 RENEWAL_NOT_PENDING` = حد تاني قرر قبلك → اعملوا refetch واعرضوا الحالة.
- الصلاحيات: العرض `approvals.view` · القرار `approvals.manage` · شاشة `/permits` بتحتاج `units.view`. **أخفوا الأزرار حسب صلاحيات `/admin/me`** — السيرفر بيرفض بـ`403 INSUFFICIENT_PERMISSION` بس الشاشة المفروض ما تعرضش زرار مش مسموح.

### ٣.٥ شاشة المراجعة `/approvals/{id}` — ما اتغيّرتش

الحقول الجديدة بتوصل جوّه `unit` (`unit.permitExpiresAt`, `unit.permitStatus`, `unit.pendingRenewalId`). **اعرضوهم في كارت الوحدة.**

⚠️ **مفيش `permit.address` ولا `addressMatch` ولا كائن `group` هنا** — دول المرحلة ٦ وما اتبنتش.

---

## ٤. ترتيب التنفيذ المقترح

| # | الشغل | التطبيق | الحجم |
|---|---|---|---|
| 1 | `reason` optional في `BlockedRange` + `409 BOOKING_EXCEEDS_PERMIT_VALIDITY` | الضيف | ساعة |
| 2 | `booking.unit` في صفحة التأكيد بدل الكارت | الضيف | ساعة |
| 3 | `PermitStatusBadge` + حقل التاريخ الهجري/الميلادي | مشترك | نص يوم |
| 4 | بانر الوحدة من `permitStatus` + تحذير «سيعود للمراجعة» | الشريك | نص يوم |
| 5 | صفحة التجديد + السجل | الشريك | يوم |
| 6 | `/permits` (٣ تبويبات) | الأدمن | نص يوم |
| 7 | `/permit-renewals` + شاشة المقارنة والقرار | الأدمن | يوم |
| 8 | `pendingRenewalId` في شاشة الوحدة والمراجعة | الأدمن | ساعة |

**١ و٢ الأهم** — دول اللي ممكن يكسروا حاجة شغالة. الباقي إضافات.

---

## ٥. متغيرات البيئة

```env
NEXT_PUBLIC_MULTI_UNIT_ENABLED=false     # production · staging=true
NEXT_PUBLIC_PERMIT_EXPIRY_REQUIRED=false # الاتنين حالياً
NEXT_PUBLIC_PERMIT_WARNING_DAYS=30
```

**خلّوهم من env مش ثوابت** — `PERMIT_EXPIRY_REQUIRED` هيتقلب على الإنتاج لما الأدمن يملّي التواريخ، ووقتها الحقل يبقى إلزامي في الويزارد **من غير نشرة منكم**.

---

## ٦. اختبارات مقترحة عندكم

1. وحدة `permitStatus: 'unknown'` → **مفيش أي تحذير** (دي كل الإنتاج النهاردة).
2. تعديل وحدة `approved` → ظهور حوار «سيعود للمراجعة».
3. تجديد مقدَّم → الفورم مقفول، والكارت بيقول «الإعلان يعمل بالتصريح الحالي».
4. `blocked-dates` بمدى `permit_expiry` → التقويم مقفول بعد التاريخ.
5. حجز في مبنى → صفحة التأكيد بتعرض `booking.unit.id` مش الكارت.
6. `409 RENEWAL_NOT_PENDING` → refetch بدل رسالة خطأ عامة.

---

## ٧. اللي مستني منّا (مش بيوقفكم)

| البند | الحجم |
|---|---|
| `body` + `href` + `unit_id` في إشعار `permit_expiring` | سطر — **قولوا وننفّذه** |
| `apartment_no` في `booking.unit` | سطر — **قولوا** |
| المرحلة ٦: `addressMatch` · كائن `group` · `group_size` · مفتاح `tourismLicenseNumber` للأدمن · `groupId`/`apartmentNo` لقائمة الشريك | نص يوم |
