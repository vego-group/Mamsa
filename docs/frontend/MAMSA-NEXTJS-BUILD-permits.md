# دليل التنفيذ — التصاريح على واجهات Next.js التلاتة

**الأساس:** `docs/backend/permits-frontend-contract-phases-1-3.md` (عقد الإنتاج)
**والملحق:** `docs/backend/permits-frontend-contract-phases-4-6.md` (وضع أ + المرحلة ٦ — staging)
**الكود المنشور:** `prod-2026-09-22-refunds` على الإنتاج · `5d29d18` على staging
**التاريخ:** 22/09/2026 · **محدَّث 23/09/2026** بقسم وضع أ (§٨) وقسم الأعلام (§٩)

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

> ⚠️ **القسم ده اتبدّل.** اقروا §٩ — الأعلام بقت تتجاب من `GET /config` وقت التشغيل.
> خلّوا الـ`NEXT_PUBLIC_*` كقيمة احتياطية للإقلاع الأول بس، لو النداء وقع.

```env
NEXT_PUBLIC_MULTI_UNIT_ENABLED=false     # قيمة احتياطية فقط
NEXT_PUBLIC_PERMIT_EXPIRY_REQUIRED=false # قيمة احتياطية فقط
NEXT_PUBLIC_PERMIT_WARNING_DAYS=30       # قيمة احتياطية فقط
```

السبب: `NEXT_PUBLIC_*` بيتحرق **وقت البناء**، فقلب العَلَم على السيرفر ما كانش بيعمل حاجة لحد ما تلات تطبيقات تتبني وتتنشر — وده بالظبط العكس من الغرض.

---

## ٦. اختبارات مقترحة عندكم

1. وحدة `permitStatus: 'unknown'` → **مفيش أي تحذير** (دي كل الإنتاج النهاردة).
2. تعديل وحدة `approved` → ظهور حوار «سيعود للمراجعة».
3. تجديد مقدَّم → الفورم مقفول، والكارت بيقول «الإعلان يعمل بالتصريح الحالي».
4. `blocked-dates` بمدى `permit_expiry` → التقويم مقفول بعد التاريخ.
5. حجز في مبنى → صفحة التأكيد بتعرض `booking.unit.id` مش الكارت.
6. `409 RENEWAL_NOT_PENDING` → refetch بدل رسالة خطأ عامة.

---

## ٧. اللي كان مستني منّا — **اتنفّذ كله على staging** (23/09)

| البند | الحالة |
|---|---|
| `body` + `href` + `unit_id` في إشعار `permit_expiring` | ✅ staging · §٦ في الملحق |
| `apartment_no` في `booking.unit` | ✅ staging · §٤.٢ في الملحق |
| `addressMatch` · كائن `group` | ✅ staging · §٢ في الملحق |
| `group_size` في القائمة العامة | ✅ staging · §٤.١ في الملحق — **اقروا التعريف، مش زي `group.size`** |
| مفتاح `tourismLicenseNumber` لقراءة الأدمن | ✅ staging · §٧ في الملحق |
| عنوان التصريح وتاريخه قابلين للكتابة على الوحدة | ✅ staging · §٣ في الملحق |
| `groupId`/`apartmentNo` في رد `/submit` | ✅ staging · §١.١ في الملحق |

❌ **لسه على الإنتاج:** ولا واحد منهم. الإنتاج على المراحل ١–٣ لحد النشرة الموحّدة.

---

## ٨. 🔴 وضع أ — تصريح لكل شقة (لوحة الشريك + لوحة الأدمن)

**staging فقط.** على الإنتاج `MULTI_UNIT_ENABLED=false` وهيفضل كده لحد ما الواجهة تجهز — شوف §٩ لإزاي تقرا العَلَم من غير ما تعيد البناء.

### ٨.٠ الفكرة في سطر

نوع الترخيص **هو** الوضع. مافيش مفتاح تاني يتبعت ولا يتخزّن:

| `licenseType` | الوضع | معناه |
|---|---|---|
| `tourist_facility` | **ب** — `single_permit` | تصريح مرفق واحد بيغطي كل الأبواب · `licensedUnitsCount` هو السقف |
| `private_hospitality` | **أ** — `per_unit` | **كل باب بتصريحه** · مافيش سقف، فيه ورقة لكل شقة |
| `null` | غير مصنّف | **ماينفعش يتوسّع خالص** |

**وضع أ مش «تصريح واحد بيغطي ٥ أبواب» — هو ٥ تصاريح بتشترك في عنوان.** كل قاعدة تحت نتيجة للجملة دي.

### ٨.١ الشاشة — العدد على نفس الخطوة، والفورم بيتمدّد

خطوة الترخيص في الويزارد بقت كده:

```
┌─ الترخيص ──────────────────────────────────────────────┐
│ نوع الترخيص:   ( ) مرفق سياحي   (•) ضيافة خاصة          │
│ عدد الشقق:     [ 3 ]        ← الإجمالي، مش الإضافة       │
│                                                         │
│ ▸ ظهر لأن النوع = ضيافة خاصة والعدد > الحالي:           │
│                                                         │
│  ┌ الشقة رقم [ 2 ] ──────────────────────────────────┐  │
│  │ رقم التصريح *      [ MA-2              ]          │  │
│  │ ملف التصريح *      [ ارفع ملف ]  file_01m…  ✓     │  │
│  │ تاريخ الانتهاء     [ 2027-09-23 ]  ميلادي          │  │
│  │ عنوان التصريح      مدينة/حي/مبنى/رقم الوحدة        │  │
│  └───────────────────────────────────────────────────┘  │
│  ┌ الشقة رقم [ 3 ] ──────────────────────────────────┐  │
│  │ … نفس الحقول                                      │  │
│  └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

**عدد الكروت = `count` − عدد الأبواب الحالية.** مبنى فيه بابين وبيروح لخمسة بيعرض **٣** كروت، مش ٥. اللي موجود عنده ورقته خلاص.

**نوع = مرفق سياحي → مافيش كروت خالص.** العدد بس، والتصريح الموجود بيتنسخ على الأبواب الجديدة.

**نوع = فاضي والعدد > 1 → اقفلوا الزرار** ووروا رسالة «اختار نوع الترخيص الأول». السيرفر هيرفض بـ`MULTI_UNIT_REQUIRES_FACILITY_LICENSE`، بس ما ينفعش الشريك يملّي فورم كامل عشان يتقاله كده بعدين.

### ٨.٢ الحقول لكل شقة — الإلزامي والاختياري

| الحقل | المفتاح | إلزامي؟ | الشكل |
|---|---|---|---|
| رقم التصريح | `number` | ✅ | نص ≤ 50 |
| ملف التصريح | `fileId` | ✅ | `file_…` من مسار الرفع المعتاد |
| رقم الشقة | `apartmentNo` | اختياري | نص ≤ 20 — **لو بعتّوه بيحدد الباب** |
| تاريخ الانتهاء | `expiresAt` | اختياري\* | `YYYY-MM-DD` **ميلادي** |
| عنوان التصريح | `address.{city,district,building,unitNo}` | اختياري | نصوص |

\* اختياري في التحقق، **لكن**: لو `permitExpiryRequired` شغّال (§٩) بقى إلزامي، ولو التاريخ **فات** الطلب بيترفض `400 VALIDATION` + `fields.permitExpiresAt` **مهما كان العَلَم**. يعني اقفلوا التاريخ في الـdate picker على `>= اليوم` — ده أرخص من دورة كاملة على السيرفر.

**`fileId` إلزامي بجد.** في وضع أ مافيش أي ملف بيتنسخ من الأصل: باب من غير ورقته باب ما ينفعش ينشر. لو الرفع لسه شغال، اقفلوا الحفظ لحد ما يخلص.

### ٨.٣ 🔴 نداء واحد، مش اتنين

```ts
// ✅ كده
await api.post(`/units/${id}/submit`, { count, permits })

// ❌ مش كده
await api.post(`/units/${id}/apartments`, { count, permits })
await api.post(`/units/${id}/submit`)          // ← لو ده وقع، الشريك عنده شقق pending ووحدة draft
```

`/submit` بياخد نفس الـbody بالظبط، وبيعمل **التوسيع والتقديم في transaction واحدة**. أي رفض = الوحدة لسه `draft` **وصفر شقق اتخلقت**. مافيش حالة نص مبنى، فمافيش شاشة «كمّل اللي فات».

`/apartments` **لسه موجود وما اتغيّرش** — استعملوه للمبنى اللي **معتمد بالفعل** وعايز يزوّد أبواب. الفرق: `/apartments` للمبنى القائم، `/submit` للإنشاء الأول.

الـbody كله اختياري: من غير `count`، أو بـ`count` أقل من أو يساوي العدد الحالي، الرد **نفس رد التقديم القديم بالظبط**.

### ٨.٤ الرد — اقروا `units[]`

```jsonc
{
  "unit": { "…": "الوحدة الأصل" },
  "groupId": "01M36C95Y28DDDCCMD0RPYJ8EF",   // null للوحدة المستقلة
  "groupSize": 3,                             // 1 للوحدة المستقلة
  "units": [                                  // دايماً موجودة، حتى لو باب واحد
    { "id": "u_68", "apartmentNo": "1", "status": "pending" },
    { "id": "u_69", "apartmentNo": "2", "status": "pending" },
    { "id": "u_70", "apartmentNo": "3", "status": "pending" }
  ],
  "message": "سيصلك إشعار خلال 24–48 ساعة"
}
```

اعرضوا شاشة نجاح بتقول «٣ شقق دخلت المراجعة» واسردوا `units[]`. الشريك كتب «٣» ولازم يشوف تلاتة.

### ٨.٥ الأخطاء — كل واحد وشكله على الشاشة

| الكود | HTTP | يعني | ارسموه إزاي |
|---|---|---|---|
| `PERMIT_MODE_MIXED` | 422 | ضيافة خاصة من غير `permits`، أو مرفق سياحي ومعاه `permits` | `meta.group_mode` بيقول الوضع الصح — صلّحوا الفورم من غير ما تسألوا |
| `MULTI_UNIT_REQUIRES_FACILITY_LICENSE` | 422 | نوع الترخيص فاضي | ودّوه لخطوة الترخيص |
| `PERMITS_COUNT_MISMATCH` | 422 | عدد الكروت ≠ الجديد | `meta.requested` = العدد المتوقع |
| `DUPLICATE_PERMIT_NUMBER` | 422 | الرقم على إعلان تاني، أو متكرر جوّه نفس الطلب | `meta.permit_number` — **علّموا الكارت ده هو** |
| `VALIDATION` | **400** | حقل ناقص/غلط · `fields["permits.1.fileId"]` | المفتاح فيه **رقم الكارت** — وزّعوه على الكارت الصح |
| `UNIT_NOT_SUBMITTABLE` | 409 | الوحدة مش draft/rejected | refetch |
| `QUANTITY_EXCEEDS_LICENSED_UNITS` | 422 | مرفق سياحي والعدد فوق `licensedUnitsCount` | `meta` فيه السقف والمطلوب |
| `MULTI_UNIT_DISABLED` | 422 | العَلَم مقفول على البيئة دي | ما تعرضوش خطوة العدد أصلاً — اقروا §٩ |

⚠️ **`VALIDATION` رقمه 400 على سطح الشريك، مش 422.** التوقّع الغلط ده بيخلّي الأخطاء تظهر كـ«خطأ غير متوقع».

### ٨.٦ لوحة الأدمن — نفس الشكل، غلاف مختلف

```
POST /admin/units/{id}/apartments      { count, permits }
```

نفس الـbody والقواعد. الاختلافات:

1. **الغلاف:** `{ message, code, fields?, meta? }` و`VALIDATION_ERROR` بـ**422** (مش `VALIDATION` بـ400).
2. **لوحدات ممسى بس** (`mamsaOwned: true`) — الصلاحية `units.manage`.
3. **مش وراء `MULTI_UNIT_ENABLED`** — يعني شغّال على الإنتاج دلوقتي كمان.
4. ومافيش `/submit` هنا: الأدمن بيوافق بنفسه.

### ٨.٧ شاشة المراجعة — اللي بيتغيّر فيها

`GET /admin/approvals/{id}` بقى فيه `permit` و`addressMatch` و`group` (§٢ في الملحق). التلات قواعد اللي بتوقع الشاشة لو اتكسرت:

1. **`addressMatch.city: null` رمادي مش أخضر** — معناها ما اتقارنش.
2. **`addressMatch.district` دايماً `null`** — ما ترسموش ليه أيقونة أصلاً.
3. **`group: null` = اخفي القسم** — مش «مبنى فيه باب واحد».

وفي `group.apartments[]` وروا `permitNumber` لكل باب: ده اللي بيخلّي المراجع يشوف إن وضع أ فعلاً تصاريح مختلفة، مش رقم واحد متكرر.

### ٨.٨ اختبارات تكفي القسم ده

1. ضيافة خاصة · عدد 3 من باب واحد → كرتين، `/submit` واحد، ٣ أبواب `pending`.
2. نفس السيناريو والنداء بيرجّع `DUPLICATE_PERMIT_NUMBER` → **الوحدة لسه draft ومافيش شقق** في القائمة بعد refetch.
3. مرفق سياحي · عدد 4 → **مافيش كروت**، والـbody `{ count: 4 }` وبس.
4. نوع فاضي · عدد 2 → الزرار مقفول قبل أي نداء.
5. كارت بتاريخ انتهاء **فات** → الـdate picker رافضه؛ ولو عدّى، `fields.permitExpiresAt` بيوصل الكارت الصح.
6. `/submit` من غير body على وحدة عادية → نفس السلوك القديم بالظبط.

---

## ٩. الأعلام وقت التشغيل — `GET /config`

```ts
// مرة واحدة عند إقلاع التطبيق. من غير توكن.
const { flags, permitWarningDays } = await fetch(`${API}/config`).then(r => r.json())
// { flags: { multiUnitEnabled, permitExpiryRequired, legacyUnitWritesEnabled }, permitWarningDays }
```

| التطبيق | المسار |
|---|---|
| الضيف | `GET /api/v1/config` |
| لوحة الشريك | `GET /config` |
| لوحة الأدمن | `GET /admin/config` |

التلاتة **نفس الرد بالظبط** ومن غير مصادقة.

**ليه:** `NEXT_PUBLIC_*` بيتحرق وقت البناء. `MULTI_UNIT_ENABLED` و`PERMIT_EXPIRY_REQUIRED` الاتنين مفروض يتقلبوا من السيرفر لما البيانات تجهز — وعَلَم وقت بناء ما بيعملش ده.

**إزاي تستعملوه:**

- اقروه مرة عند الإقلاع، حطّوه في context/store، **ما تنادوش عليه في كل شاشة**.
- لو النداء وقع، ارجعوا لقيمة `NEXT_PUBLIC_*` الاحتياطية. عَلَم مقفول أأمن من شاشة فاضية.
- `permitWarningDays` استعملوه بدل ما تكتبوا 30 في تلات تطبيقات — ده نفس الرقم اللي الجوب اليومي بيشتغل بيه، وتثبيته في الواجهة هو إزاي الاتنين بيختلفوا.
- `legacyUnitWritesEnabled: false` معناها مسارات الكتابة القديمة بـBearer بترجّع **410 `ENDPOINT_RETIRED`**. لو شفتوا 410 من مسار قديم، ده مش عطل — ده ده.

**القيم دلوقتي:** staging `multiUnitEnabled: true` · الإنتاج `false` (والمسار نفسه لسه مش منشور هناك).
