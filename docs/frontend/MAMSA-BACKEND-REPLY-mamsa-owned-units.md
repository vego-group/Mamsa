# Mamsa-owned units — all eight items, shipped

**From:** backend · **Date:** 2026-08-24 · **Re:** `BACKEND-REQUEST-mamsa-owned-units.md`
**Status:** ✅ live on **staging AND production** (production deployed 2026-08-24 at the owner's
instruction). Verified end to end on staging with a real presign → PUT → create → submit →
approvals-queue run.

⚠️ **Production went out ahead of your sign-off**, at the owner's direct request rather than on the
usual "name the day" rule — so read the breaking-change section below **before** your next admin
release, not after. Two changes to `POST /admin/units` are not backward compatible: `chalet` /
`hotel_room` now `422`, and the response is the created unit instead of `{ ok: true }`. If your
production console strictly asserts `ok === true` on create, that assertion now fails.

All three flags can be flipped. §1, §2, §3, §5, §6 and §7 are built; §4 is answered and the real
bug behind it is fixed; §8 is answered in full, and **two of your five assumptions were wrong** —
§8.2 and §8.5 need changes on your side before you flip anything.

```ts
ADMIN_UPLOADS_ENABLED                 = true   // §1
ADMIN_UNIT_CREATE_ACCEPTS_FULL_DRAFT  = true   // §2
ADMIN_UNIT_SUBMIT_ENABLED             = true   // §5
```

---

## Read this first — the two things that will break your calls

### `chalet` and `hotel_room` are now rejected (§8.2)

The console offers five types. **The platform supports three.** Migration
`2026_07_01_000004_remove_unsupported_unit_types` didn't just stop accepting the others — it
**deleted every existing unit** of any other type, and the partner wizard has only ever offered
apartment / studio / villa.

The old endpoint accepted `chalet` and stored it, and mapped `hotel_room` → `hotel`. That was
worse than a rejection: it created a unit that could never pass review and that a re-run of that
cleanup would delete. **Now it's a `422` with a named field.** Please drop those two options.

### The admin panel does NOT use `{ error: { … } }` (§8.5)

Your §8.5 assumed the partner dashboard's envelope. This console has never used it:

| | partner dashboard | **admin panel** |
|---|---|---|
| shape | `{ error: { code, message, fields? } }` | **`{ message, code, fields? }`** — flat |
| validation code | `VALIDATION` | **`VALIDATION_ERROR`** |
| validation status | `400` | **`422`** |

```jsonc
// POST /admin/units  { "type": "chalet" }   → 422
{
  "message": "نوع الوحدة غير صالح — المدعوم: شقة، استوديو، فيلا",
  "code": "VALIDATION_ERROR",
  "fields": { "type": "نوع الوحدة غير صالح — المدعوم: شقة، استوديو، فيلا" }
}
```

`fields` is **new** — it did not exist on this surface before today, and you were right to want it.
Keys are the literal request-body paths, dots included: `type`, `city`, `amenities.0`,
**`photoFileIds.2`**. Read them as flat string keys, not as a nested object — `photoFileIds.2` is
one key, so `fields["photoFileIds.2"]`, never `fields.photoFileIds[2]`.

`message` is unchanged and still the first error, so nothing you have today breaks.

---

## §4 — `mamsaOwned`: you were right that it was money, wrong about where

You asked us to confirm the flag is set on create. **It already was** — `mamsa_owned => true` has
been written by `POST /admin/units` since the endpoint existed, and `BACKEND_SPEC.md` §194 was the
accurate line. Our earlier §4.2 reply was wrong to say otherwise, and you were right to push.

**But confirming it would have closed the item on a bug that was still there.** The flag was
*written* and read by nothing. `Pricing::breakdown()` — the one place a booking's split is frozen —
took no ownership argument at all and applied 2% / 98% to every booking on the platform.

On a Mamsa-owned listing `units.user_id` is **the admin who created it**. So:

```
booking on a Mamsa unit, 1150 SAR gross
  netBase       1000.00
  commission      20.00   → Mamsa
  partnerShare   980.00   → credited to the ADMIN's partner wallet
                          → queued into the payout run
                          → a real bank transfer, owed to nobody
```

Fixed. `mamsaOwned: true` now means commission = the entire net base, `partnerShare = 0`, no ledger
entry, no wallet, nothing in the payout run — exactly what your `splitPriceForUnit` already assumes.
The invariant `commission + partnerShare + vat === gross` still holds exactly in both branches.

**Exposure: zero.** We checked both servers before fixing: `mamsa_owned = true` matches **0 units on
staging and 0 on production**. No booking has ever been split wrong, because no Mamsa-owned unit has
ever existed. Your "money that has possibly already been booked wrong" was the right instinct about
the wrong layer — the mechanism was real, the damage was not.

Your §4 footnote about `'mamsaOwned' => false` written as a literal: that was a read-side presenter
default, since fixed, and it never touched a stored row.

**One more, found while verifying:** the approvals queue showed a Mamsa-owned unit under the
**admin's personal name** (`partnerName: "محمد أشرف"`), because `approvalRow` fell back to
`owner.name` while the units list already read `"ممسى"` for the same row. A reviewer saw one unit
attributed two different ways, and a staff member sitting in the queue as though they were an
applicant. The queue row now carries:

```jsonc
{ "partnerName": "ممسى", "partnerType": "mamsa", "mamsaOwned": true }
```

`mamsaOwned` on the approval row is new — worth a badge, so a reviewer knows they're reviewing
Mamsa's own listing.

---

## §1 — `POST /admin/uploads/presign`

**`/admin/uploads/presign`**, namespaced, exactly as you declared it. No constant to change.

```http
POST /admin/uploads/presign
{ "kind": "unit_photo" | "license_pdf",
  "fileName": "photo.jpg", "mimeType": "image/jpeg", "size": 204800 }

→ 201 { "uploadUrl": "https://staging.mamsaa.com/uploads/file_01m0sq…?signature=…",
        "fileId": "file_01m0sq9e18fbkxmr7nd1y1bdre" }

PUT {uploadUrl}    ← raw bytes, no auth header, no cookies
→ 200 { "fileId": "…", "url": "https://…/storage/dashboard/unit_photo/….png" }
```

Four things to note:

- **`fileId` is `file_<ulid>`**, not `f_abc123`. If you're pattern-matching or mocking, use the real
  shape.
- **The `uploadUrl` is a signed URL to our own host**, not S3 — there is no S3 on this hosting. The
  flow is identical from your side. It expires in **30 minutes** and is **single-use**
  (`409 UPLOAD_USED` on a second PUT).
- **Send the raw bytes with no `Authorization` header and no cookies.** The signature is the
  authorisation. Don't attach the admin session — it isn't needed and CORS is simpler without it.
- **`mimeType` is not trusted**, exactly as you assumed. The received bytes are sniffed for magic
  numbers: `unit_photo` must really be PNG/JPEG, `license_pdf` must really be `%PDF`. A `.jpg`
  extension on a PDF is a `400 INVALID_FILE_TYPE`.

`company_doc` is not exposed to admins, as you asked — `422` if sent.

Cap is **10 MB**, enforced twice: on the declared `size` at presign, and on the actual byte count at
PUT.

**This is the same receiving endpoint the partner dashboard uses.** Deliberately: that's where the
real security lives, and a second copy of those checks is a second place for them to rot.

### Ownership

An upload belongs to the admin who presigned it, and the unit write path only accepts fileIds owned
by **the same user**. One admin cannot attach another's pending upload — `422` with
`fields["photoFileIds.0"]`. Nothing is created when that happens; the write fails before any
mutation, so you never get a half-attached unit.

---

## §2 — the full body

Everything you listed is accepted, plus `beds`:

```jsonc
POST /admin/units
{
  // required
  "name": "استوديو ممسى العليا", "type": "studio",
  "city": "Riyadh", "district": "العليا",
  "pricePerNight": 450, "bedrooms": 1, "bathrooms": 1, "capacity": 2, "sizeSqm": 90,

  // all optional
  "description": "وصف الوحدة…",
  "amenities": ["wifi", "ac", "kitchen"],
  "cancellationPolicy": "moderate",
  "checkIn": "15:00", "checkOut": "12:00",
  "lat": 24.7136, "lng": 46.6753,
  "address": "حي العليا، الرياض",
  "tourismLicenseNumber": "TL-2025-00042",
  "tourismLicenseFileId": "file_…",
  "photoFileIds": ["file_…", "file_…"],   // ordered, authoritative
  "coverFileId": "file_…",                // must be one of photoFileIds
  "beds": 2                               // optional; defaults to bedrooms
}
```

- **The nine you already send stay required** — no change to your working call.
- **`photoFileIds` replaces the whole set**, as you asked. Absent → gallery untouched. Present, even
  empty → authoritative replace, so removing a photo actually removes it.
- **An absent key means "unchanged / not supplied"**, never "blank it" — confirmed, on both `POST`
  and `PATCH`.
- **`beds`**: the submit gate requires `beds >= 1` and your wizard has no input for it, so create
  seeds it from `bedrooms`. Add an input if you want it separate; otherwise ignore it.

---

## §3 — create returns the unit

`201` with the **full `UnitDetail`**, the same shape `GET /admin/units/{id}` returns — not just the
id. Real response from the staging run:

```jsonc
{ "id": "22", "code": "MRNCTGOZ", "name": "استوديو ممسى العليا",
  "city": "الرياض", "type": "studio", "status": "draft",
  "mamsaOwned": true, "partnerName": "ممسى", "pricePerNight": 450,
  "images": [ … ], "amenities": ["واي فاي","مطبخ","تكييف"],
  "tourismPermitNo": "TL-2025-00042", … }
```

Render the success screen from this; no refetch needed.

---

## §5 — submit, and the answer to your question

**Yes, admin-created units go through review.** You asked whether Mamsa reviewing its own listing is
theatre. The review step is, a bit — but the **completeness gate** isn't, and it lives in the same
place. Photos, a permit, a description, a location inside Saudi Arabia: that's what makes a listing
publishable at all, and this is the single point that enforces it for both consoles. Auto-approving
would have meant writing a second gate or letting Mamsa units go live with no photos.

Keep your "Create and send for review" label. One queue, one code path.

```http
POST /admin/units/{id}/submit      ← no body
→ 200  the full unit, status "pending_review"
→ 409  CONFLICT           not a draft/rejected unit
→ 422  VALIDATION_ERROR   incomplete — see fields
```

The `422` names **every** gap at once, so you can mark all the offending steps rather than making
the admin discover them one at a time:

```jsonc
{ "message": "بيانات غير مكتملة", "code": "VALIDATION_ERROR",
  "fields": {
    "description": "الوصف يجب أن يكون بين 10 و 500 حرف",
    "address": "العنوان مطلوب",
    "location": "الموقع يجب أن يكون داخل حدود المملكة",
    "tourismLicenseNumber": "رقم رخصة السياحة مطلوب",
    "tourismLicenseFileId": "ملف الرخصة مطلوب",
    "photos": "أضف صورة واحدة على الأقل"
  } }
```

Two field keys have no matching body key — map them yourself:
**`location`** → the map step (lat/lng), **`photos`** → the photo step.

`submitted_at` is stamped automatically and the unit appears in `GET /admin/approvals` immediately.

---

## §6 — `PATCH /admin/units/{id}`

Same body as `POST`, everything optional.

```
200  the full updated unit
409  CONFLICT   the unit is pending_review — locked while a reviewer has it
404  NOT_FOUND
```

**Editing an approved unit sends it back to `pending_review`** and off the public site — the same
rule partner units follow, for the same reason: what was approved is no longer what's published.
Render the same warning banner the partner dashboard shows.

Accepts `u_22` or `22`.

---

## §7 — `DELETE /admin/units/{id}`

```
200  { "ok": true }        draft deleted
409  CONFLICT              anything past draft — it has history worth keeping
404  NOT_FOUND
```

---

## §8 — the rest of the confirmations

### 8.1 City — send whatever you already send

**This is now impossible to get wrong, which is what you asked for.** Every spelling normalises to
the stored Arabic:

```
"Riyadh"  →  الرياض       (admin console — capitalised label)
"riyadh"  →  الرياض       (partner dashboard — slug)
"الرياض"  →  الرياض       (already stored form)
"Mecca"   →  مكة المكرمة   (exonym alias)
```

Verified on staging: `city: "Riyadh"` stored as `الرياض`.

**Two things changed, both in your favour:**

- Before, `POST /admin/units` stored `city` **verbatim**. `"Riyadh"` went into a column holding
  `"الرياض"`, so the unit was invisible to every city filter and browse surface — silently, as an
  empty list. That was the failure mode you predicted in §8.1; it just landed on the read side
  instead of as a `400`.
- A city we don't serve is now a **`422` with `fields.city`**, not a silent store.

**There are 20 cities, not 8** — the admin console is showing a subset. They are a strict superset
of nothing; both consoles draw from one canonical list. `GET /admin/cities` returns it as
`{ key, en, ar }`, which is the safest thing to populate your dropdown from:

```
riyadh · jeddah · makkah · madinah · dammam · khobar · dhahran · taif · abha ·
khamis_mushait · tabuk · buraydah · hail · jubail · yanbu · najran · jazan ·
alula · baha · hofuf
```

(Incidentally: `hofuf` was resolving to null through a bad alias, so `?city=Hofuf` filtered on the
literal string and matched nothing. Fixed in the same change.)

### 8.2 Unit types — three

`apartment` · `studio` · `villa`. See the top of this document. `chalet` and `hotel_room` → `422`.

### 8.3 Amenities — your eight are correct, and there are seven more

Your eight all validate: `wifi`, `ac`, `kitchen`, `parking`, `pool`, `security`, `self_checkin`,
`family_friendly`.

The full vocabulary is **fifteen** — the storefront added these so it could pick a stable icon per
amenity rather than matching Arabic text:

```
smart_tv · garden · bbq · elevator · washer · private_beach · event_hall
```

Worth offering all fifteen; a partner unit can already have them, so an admin unit that can't is an
odd gap. An unknown key is `422` with `fields["amenities.0"]`.

### 8.4 Validation rules — the real list

| field | create | at submit |
|---|---|---|
| `name` | required, 2–150 | ≥ 2 |
| `type` | required, the 3 | must be one of the 3 |
| `pricePerNight` | required, **> 0** | > 0 |
| `city` | required, must resolve | must resolve |
| `district` | required, ≤ 150 | — |
| `bedrooms` | required, ≥ 0 | — |
| `bathrooms` | required, **1–10** | ≥ 1 |
| `beds` | optional, **1–20** | **≥ 1** |
| `capacity` | required, ≥ 1 | ≥ 1 |
| `sizeSqm` | required, ≥ 0 | — |
| `description` | ≤ 500 | **10–500** |
| `address` | ≤ 255 | **required** |
| `lat` / `lng` | numeric | **required, inside Saudi Arabia** |
| `checkIn` / `checkOut` | `HH:mm`, 24h | — |
| `amenities[]` | the 15 keys | — |
| `cancellationPolicy` | `flexible\|moderate\|strict` | — |
| `tourismLicenseNumber` | ≤ 50 | **required** |
| `tourismLicenseFileId` | owned `license_pdf` | **required** |
| `photoFileIds` | ≤ **10**, owned `unit_photo` | **≥ 1 real photo** |
| `coverFileId` | must be in `photoFileIds` | — |

Note the split: `description` is only length-capped at create but **10–500 at submit**, and
`address` / `lat` / `lng` / the permit / photos are **optional at create, required at submit**. That
is what makes a partial draft saveable. Your client-side mirror of 10–500 / 1–10 / at-least-one-photo
was right; apply it at the submit step, not the create step, or you'll block a legitimate draft.

### 8.5 Error envelope

See the top. Flat, `VALIDATION_ERROR`, `422`, and `fields` now exists.

---

## What we verified, and what we didn't

**Run end to end on staging, against the live API — not simulated:**

```
presign unit_photo   → 201, file_01m0sq9e18fbkxmr7nd1y1bdre
PUT real PNG bytes   → 200
presign license_pdf  → 201
PUT real PDF bytes   → 200
POST /admin/units    → 201  city "Riyadh" stored as "الرياض", 1 image,
                            3 amenities in Arabic, permit stored,
                            mamsaOwned true, partnerName "ممسى"
POST …/submit        → 200  status pending_review, submitted_at stamped
GET  /admin/approvals→ 200  present, partnerName "ممسى", partnerType "mamsa"
PATCH while pending  → 409  CONFLICT
POST type=chalet     → 422  fields.type
POST city=Atlantis   → 422  fields.city
```

The staging test unit was deleted afterwards.

**Backend suite: 286 passed, 1457 assertions**, including 19 new tests for this wizard and 5 for the
ownership split.

**Production (deployed 2026-08-24)** — same eleven files, `config:cache` + `route:cache`, with a
rollback tarball kept on the server. Verified there:

```
routes alive + auth-gated : presign / PATCH / DELETE / submit → 401 unauthenticated
City::toArabic            : Riyadh, riyadh, الرياض → الرياض · Hofuf → الهفوف · Atlantis → null
Pricing partner unit      : commission 20.00   share 980.00  vat 150.00  gross 1150.00
Pricing Mamsa-owned unit  : commission 1000.00 share   0.00  vat 150.00  gross 1150.00
supported types           : apartment, studio, villa
mamsa_owned units on prod : 0
/up 200 · /api/v1/units 200 · ?city=Hofuf 200 · www.mamsaa.com 200 · partner login 200
```

**What was NOT verified on production:** the authenticated HTTP chain (presign → PUT → create →
submit). Production has no fixed-OTP path any more and the demo admin accounts are suspended, so
the only way in is a real SMS to the owner's phone — we won't trigger that to run a test. The
logic above was verified in-process on the production box, and the full HTTP chain was verified on
staging against identical code.

---

## Checklist

- [ ] Drop `chalet` and `hotel_room` from the type selector (**§8.2** — they now `422`)
- [ ] Read errors as **flat** `{ message, code, fields? }` at **422**, code `VALIDATION_ERROR` (§8.5)
- [ ] Read `fields` as flat string keys — `fields["photoFileIds.2"]`, not nested (§8.5)
- [ ] Flip all three capability flags
- [ ] `fileId` is `file_<ulid>`; PUT the bytes with **no auth header and no cookies** (§1)
- [ ] Map `fields.location` → map step and `fields.photos` → photo step (§5)
- [ ] Apply the 10–500 description rule at **submit**, not create (§8.4)
- [ ] Populate the city dropdown from `GET /admin/cities` — there are 20 (§8.1)
- [ ] Consider offering all 15 amenities (§8.3)
- [ ] Badge `mamsaOwned` in the approvals queue (§4)
- [ ] Warn on editing an approved unit — it returns to `pending_review` (§6)
- [ ] Remove the amber "unpublishable" banner from the wizard
