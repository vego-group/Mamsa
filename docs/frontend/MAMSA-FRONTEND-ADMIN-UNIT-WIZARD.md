# Admin listing wizard — implementation guide (Next.js)

**From:** backend · **Date:** 2026-08-24 · **For:** the Next.js **admin console**
**Companion to:** `MAMSA-BACKEND-REPLY-mamsa-owned-units.md` (the contract). This document is the
work on your side.
**Backend status:** live on **staging and production**.

Everything the wizard needed exists now. This is what to change to turn it on, in the order that
avoids breaking what already works.

> **Do §1 before you flip any flag.** Two changes to `POST /admin/units` are already live on
> production and are not backward compatible. If your console asserts `ok === true` on create, it
> is failing right now.

---

## 1. Fix what's already broken (do this first)

### 1.1 `POST /admin/units` no longer returns `{ ok: true }`

It returns the created unit — a full `UnitDetail`, `201`. Anywhere you do this:

```ts
// ✗ this now throws on a successful create
const res = await api.post('/admin/units', body)
if (!res.data.ok) throw new Error('create failed')
```

replace with:

```ts
// ✓ the response IS the unit
const unit = await createUnit(body)   // see §4
router.push(`/units/${unit.id}`)
```

### 1.2 `chalet` and `hotel_room` are rejected

Remove them from the type selector. The platform supports three types, and sending either now
returns `422` with `fields.type`.

```ts
export const UNIT_TYPES = ['apartment', 'studio', 'villa'] as const
export type UnitType = (typeof UNIT_TYPES)[number]

export const UNIT_TYPE_LABELS: Record<UnitType, string> = {
  apartment: 'شقة',
  studio:    'استوديو',
  villa:     'فيلا',
}
```

### 1.3 The error envelope is flat — this console never used `{ error: … }`

This is the single most likely source of "the error message disappeared" bugs, because the wrong
shape reads as `undefined` rather than throwing.

| | partner dashboard | **admin console (you)** |
|---|---|---|
| shape | `{ error: { code, message, fields? } }` | **`{ message, code, fields? }`** |
| validation code | `VALIDATION` | **`VALIDATION_ERROR`** |
| validation status | `400` | **`422`** |

```ts
// src/lib/api/errors.ts
export type AdminApiError = {
  message: string
  code: string
  /** Present on VALIDATION_ERROR. Keys are literal body paths — dots included. */
  fields?: Record<string, string>
}

export function toAdminError(e: unknown): AdminApiError {
  const d = (e as any)?.response?.data
  return {
    message: d?.message ?? 'حدث خطأ غير متوقع، حاول مرة أخرى',
    code:    d?.code ?? 'UNKNOWN',
    fields:  d?.fields,
  }
}

export const isValidation = (e: AdminApiError) => e.code === 'VALIDATION_ERROR'
```

**Render `message` verbatim.** It is written in Arabic for the admin and is more specific than
anything you would substitute. A generic "try again" in place of the API's own message cost a full
debugging round on the Vue app once already.

### 1.4 `fields` keys are flat strings, dots included

`photoFileIds.2` is **one key**, not a path into a nested object.

```ts
// ✗ undefined — there is no nested photoFileIds array
const err = error.fields?.photoFileIds?.[2]

// ✓
const err = error.fields?.['photoFileIds.2']
```

---

## 2. Types

```ts
// src/lib/types/units.ts
export type UploadKind = 'unit_photo' | 'license_pdf'
export type FileId = string            // "file_01m0sq9e18fbkxmr7nd1y1bdre"

export type PresignResponse = { uploadUrl: string; fileId: FileId }

export type CancellationPolicyKey = 'flexible' | 'moderate' | 'strict'

export type UnitWriteBody = {
  // required on create
  name: string
  type: UnitType
  city: string                  // any spelling — see §5
  district: string
  pricePerNight: number
  bedrooms: number
  bathrooms: number
  capacity: number
  sizeSqm: number

  // optional everywhere
  beds?: number
  description?: string
  amenities?: AmenityKey[]
  cancellationPolicy?: CancellationPolicyKey
  checkIn?: string              // "15:00"
  checkOut?: string             // "12:00"
  lat?: number
  lng?: number
  address?: string
  tourismLicenseNumber?: string
  tourismLicenseFileId?: FileId
  photoFileIds?: FileId[]       // ordered, authoritative
  coverFileId?: FileId          // must be one of photoFileIds
}

/** PATCH takes the same body, all optional. */
export type UnitPatchBody = Partial<UnitWriteBody>

export type UnitStatus = 'draft' | 'pending_review' | 'approved' | 'rejected'
```

```ts
// src/lib/constants/endpoints.ts
export const ENDPOINTS = {
  // …
  uploadsPresign: '/admin/uploads/presign',
  units:          '/admin/units',
  unit:      (id: string) => `/admin/units/${id}`,
  unitSubmit:(id: string) => `/admin/units/${id}/submit`,
} as const
```

---

## 3. The upload flow

Two steps, and the second one is easy to get wrong.

```ts
// src/lib/api/uploads.ts
import { api } from './client'
import { ENDPOINTS } from '@/lib/constants/endpoints'

const MAX_BYTES = 10 * 1024 * 1024

export async function uploadFile(file: File, kind: UploadKind): Promise<FileId> {
  if (file.size > MAX_BYTES) throw new Error('حجم الملف يتجاوز الحد المسموح (10MB)')

  // 1. presign — on the admin session
  const { data } = await api.post<PresignResponse>(ENDPOINTS.uploadsPresign, {
    kind,
    fileName: file.name,
    mimeType: file.type,
    size:     file.size,
  })

  // 2. PUT the raw bytes.
  //
  //    The signature in the URL IS the authorisation. Do NOT send the admin
  //    session: no Authorization header, no cookies. Use a bare fetch rather
  //    than your configured axios instance, which attaches credentials.
  const put = await fetch(data.uploadUrl, {
    method: 'PUT',
    body: file,                        // raw bytes, not FormData
    credentials: 'omit',
  })
  if (!put.ok) throw new Error('تعذّر رفع الملف')

  return data.fileId
}
```

Five things that bite:

| | |
|---|---|
| **No `FormData`** | send the `File` directly as the body; a multipart wrapper fails the magic-byte check |
| **No credentials** | `credentials: 'omit'`, no auth header — the signature is the auth |
| **Single use** | a second `PUT` to the same URL is `409 UPLOAD_USED`; re-presign to retry |
| **30-minute expiry** | presign when the user picks the file, not when the wizard opens |
| **MIME is not trusted** | the bytes are sniffed. A `.jpg` that is really a PDF is `400 INVALID_FILE_TYPE` |

`kind: 'unit_photo'` must be a real PNG or JPEG. `kind: 'license_pdf'` must be a real PDF.

### Upload as the user picks, not at submit

Uploading ten photos when the admin presses the final button means a long wait at the worst
possible moment, and a failure there loses the whole form. Upload each file on selection, keep the
returned `fileId` in wizard state, and show per-file progress.

```ts
const [photos, setPhotos] = useState<{ id: FileId; preview: string }[]>([])

async function onPick(files: FileList) {
  for (const file of Array.from(files).slice(0, 10 - photos.length)) {
    const id = await uploadFile(file, 'unit_photo')
    setPhotos(p => [...p, { id, preview: URL.createObjectURL(file) }])
  }
}
```

Max **10** photos.

---

## 4. The API client

```ts
// src/lib/api/units.ts
export async function createUnit(body: UnitWriteBody): Promise<UnitDetail> {
  const { data } = await api.post<UnitDetail>(ENDPOINTS.units, body)
  return data                                   // 201, the full unit
}

export async function patchUnit(id: string, body: UnitPatchBody): Promise<UnitDetail> {
  const { data } = await api.patch<UnitDetail>(ENDPOINTS.unit(id), body)
  return data
}

export async function submitUnit(id: string): Promise<UnitDetail> {
  const { data } = await api.post<UnitDetail>(ENDPOINTS.unitSubmit(id), {})  // no body
  return data                                   // status → "pending_review"
}

export async function deleteUnit(id: string): Promise<void> {
  await api.delete(ENDPOINTS.unit(id))          // drafts only; 409 otherwise
}
```

### Create then submit — two calls, deliberately

```ts
async function finish(form: UnitWriteBody) {
  const unit = await createUnit(form)      // 201 → draft
  await submitUnit(unit.id)                // 200 → pending_review
  router.push(`/units/${unit.id}`)
}
```

Keep `unit.id` in state the moment create succeeds. If `submit` then fails validation, the admin
fixes the gap and you call `submitUnit(id)` again — **do not create a second unit.** Getting this
wrong is how you end up with duplicate drafts every time someone forgets a photo.

---

## 5. City — send whatever you already send

`"Riyadh"` is normalised to `الرياض` server-side. So is `"riyadh"` and so is `"الرياض"`. You do not
need to change what the console sends.

Two things to change anyway:

**There are 20 cities, not 8.** Populate the dropdown from the API instead of a hardcoded list:

```ts
const { data: cities } = useSWR<{ key: string; en: string; ar: string }[]>(
  '/admin/cities', fetcher,
)
// value={c.key}  label={locale === 'ar' ? c.ar : c.en}
```

**An unserved city is now `422`,** not a silent store. That is the fix to a real bug: the old
endpoint stored `"Riyadh"` verbatim into a column holding `"الرياض"`, so the unit was invisible to
every city filter — silently, as an empty list. Sending `c.key` from `/admin/cities` avoids the
question entirely.

---

## 6. Amenities — fifteen keys

Your eight are correct and unchanged. Seven more exist and partner units already use them, so an
admin unit that can't offer them is an odd gap:

```ts
export const AMENITIES = [
  'wifi', 'ac', 'kitchen', 'parking', 'pool', 'security', 'self_checkin', 'family_friendly',
  'smart_tv', 'garden', 'bbq', 'elevator', 'washer', 'private_beach', 'event_hall',
] as const
export type AmenityKey = (typeof AMENITIES)[number]
```

An unknown key is `422` with `fields['amenities.0']`.

---

## 7. Validation — which rule belongs to which step

This is the part worth getting exactly right, because a rule applied at the wrong step either
blocks a legitimate draft or lets the admin reach the end of a six-minute form before failing.

| field | enforce at **create** | enforce at **submit** |
|---|---|---|
| `name` | required, 2–150 | ≥ 2 |
| `type` | required, one of 3 | one of 3 |
| `pricePerNight` | required, **> 0** | > 0 |
| `city` | required, from the list | from the list |
| `district` | required, ≤ 150 | — |
| `bedrooms` | required, ≥ 0 | — |
| `bathrooms` | required, **1–10** | ≥ 1 |
| `capacity` | required, ≥ 1 | ≥ 1 |
| `sizeSqm` | required, ≥ 0 | — |
| `beds` | optional, 1–20 | **≥ 1** (defaults from `bedrooms`) |
| `description` | ≤ 500 | **10–500 — required** |
| `address` | ≤ 255 | **required** |
| `lat` / `lng` | — | **required, inside Saudi Arabia** |
| `tourismLicenseNumber` | ≤ 50 | **required** |
| `tourismLicenseFileId` | — | **required** |
| `photoFileIds` | ≤ 10 | **≥ 1** |
| `coverFileId` | must be in `photoFileIds` | — |
| `checkIn` / `checkOut` | `HH:mm` 24-hour | — |

**The right-hand column is why a draft can be saved half-finished.** Do not enforce those at
create — an admin who has photos but no permit yet must still be able to save.

Mirror them client-side so the admin sees a field error rather than a server rejection, but treat
the server as the authority: it is the one that decides whether the unit reaches the queue.

---

## 8. Submit errors → wizard steps

`submit` returns **every** gap at once, so mark all the offending steps rather than sending the
admin around one at a time.

```jsonc
// 422
{ "message": "بيانات غير مكتملة", "code": "VALIDATION_ERROR",
  "fields": {
    "description": "الوصف يجب أن يكون بين 10 و 500 حرف",
    "address": "العنوان مطلوب",
    "location": "الموقع يجب أن يكون داخل حدود المملكة",
    "tourismLicenseNumber": "رقم رخصة السياحة مطلوب",
    "photos": "أضف صورة واحدة على الأقل"
  } }
```

Two keys have no matching body key — map them:

```ts
const FIELD_TO_STEP: Record<string, WizardStep> = {
  tourismLicenseNumber: 'license',
  tourismLicenseFileId: 'license',
  name: 'details', type: 'details', pricePerNight: 'details',
  bedrooms: 'details', bathrooms: 'details', beds: 'details',
  capacity: 'details', sizeSqm: 'details', description: 'details',
  city: 'location', district: 'location', address: 'location',
  location: 'location',          // ← lat/lng, no body key of its own
  photos: 'photos',              // ← the gallery as a whole
}

function stepsWithErrors(fields: Record<string, string>): WizardStep[] {
  return [...new Set(
    Object.keys(fields).map(k => FIELD_TO_STEP[k.split('.')[0]] ?? 'review'),
  )]
}
```

`k.split('.')[0]` handles the indexed keys (`photoFileIds.2` → `photoFileIds`). Anything
unrecognised falls back to the review step, where the raw `message` is shown — so a field we add
later degrades to "visible" rather than "swallowed".

---

## 9. Edit and delete

```ts
PATCH  /admin/units/{id}    200 the updated unit · 409 CONFLICT · 404
DELETE /admin/units/{id}    200 { ok: true }     · 409 CONFLICT · 404
```

Three rules to render:

- **A partial patch changes only what it names.** An absent key means "unchanged", never "blank
  it". Send only the dirty fields — do not round-trip the whole form.
- **Editing an approved unit sends it back to `pending_review`** and removes it from the public
  site. Warn before saving, the same way the partner dashboard does:
  > تعديل وحدة منشورة سيعيدها إلى المراجعة وسيخفيها من الموقع حتى الموافقة عليها مجدداً.
- **`409 CONFLICT` on a `pending_review` unit** — it's locked while a reviewer has it. Show
  `message` and disable the form; it will not succeed until the review finishes.

Delete is drafts only. Past draft, it's `409` — the unit has history worth keeping.

`{id}` accepts `u_22` or `22`.

---

## 10. The approvals queue — badge Mamsa's own listings

Approval rows now carry three new/changed keys:

```jsonc
{ "mamsaOwned": true, "partnerName": "ممسى", "partnerType": "mamsa" }
```

Previously a Mamsa-owned unit showed the **creating admin's personal name** in the queue, so a
staff member appeared as though they were an applicant — and the units list said `"ممسى"` for the
same row. Both now agree.

```tsx
{row.mamsaOwned
  ? <Badge variant="platform">وحدة ممسى</Badge>
  : <span>{row.partnerName}</span>}
```

`partnerType` is now `'individual' | 'company' | 'mamsa'` — widen the type or the badge switch
falls through.

---

## 11. Flip the flags

Once §1 is done:

```ts
// src/lib/constants/api-capabilities.ts
export const ADMIN_UPLOADS_ENABLED                = true
export const ADMIN_UNIT_CREATE_ACCEPTS_FULL_DRAFT = true
export const ADMIN_UNIT_SUBMIT_ENABLED            = true
```

Then remove the amber "this unit cannot be published" banner from the wizard, and change the final
button to **"إنشاء وإرسال للمراجعة"**.

---

## 12. One thing you don't need to build

`mamsaOwned` is set by the server on every unit created through `POST /admin/units`. **Do not send
it**, and do not compute a revenue split client-side for these units.

The backend now keeps the entire net base on a Mamsa-owned booking and credits no partner wallet —
which is what your `splitPriceForUnit` already assumed. Until today the engine ignored the flag and
split 2%/98% regardless, which on a Mamsa unit would have paid 98% to the admin who created it. No
booking was ever affected (no Mamsa-owned unit existed yet), and it is fixed and tested. Your
helper needs no change; it was right and the server was wrong.

---

## Checklist

**Before flipping anything**

- [ ] `POST /admin/units` success path reads the unit, not `ok === true` (§1.1)
- [ ] `chalet` and `hotel_room` removed from the type selector (§1.2)
- [ ] Errors read as flat `{ message, code, fields? }`, `VALIDATION_ERROR`, `422` (§1.3)
- [ ] `fields` accessed as flat keys — `fields['photoFileIds.2']` (§1.4)

**The wizard**

- [ ] Upload on file-pick, not at submit; `credentials: 'omit'`, raw `File`, no `FormData` (§3)
- [ ] `unit.id` kept after create so a failed submit retries instead of creating a duplicate (§4)
- [ ] City dropdown from `GET /admin/cities` — 20 of them (§5)
- [ ] All 15 amenity keys offered (§6)
- [ ] Submit-only rules not enforced at create (§7)
- [ ] `fields.location` → map step, `fields.photos` → photo step (§8)
- [ ] Unknown field keys fall back to the review step, message shown (§8)

**Edit, delete, queue**

- [ ] PATCH sends only dirty fields (§9)
- [ ] Warning before editing an approved unit (§9)
- [ ] `409` on a `pending_review` unit disables the form (§9)
- [ ] Delete offered on drafts only (§9)
- [ ] `mamsaOwned` badge in the approvals queue; `partnerType` widened to include `'mamsa'` (§10)

**Then**

- [ ] Three flags flipped, amber banner removed, button relabelled (§11)
