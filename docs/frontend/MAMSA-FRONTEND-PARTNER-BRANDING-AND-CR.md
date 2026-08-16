# Partner branding & the commercial-registration document

**From:** backend · **Date:** 2026-08-16 · **For:** the Next.js **partner dashboard** (build) and the
**admin panel** (read)
**Status:** ✅ live on staging · additive only — nothing existing changed shape

Two additions to partner onboarding:

1. **A company's brand logo** — optional, companies only, rendered on every listing they own.
2. **The commercial-registration document** — the CR has always been a typed number with no scan
   behind it, so an admin approving it was approving ten digits.

Neither gates anything. Read §4 before you wire the logo into a required step.

---

## 1. Upload flow — unchanged, one new `kind`

Same two-step presign you already use for `unit_photo` and `company_doc`:

```
POST /uploads/presign  { kind, fileName, mimeType, size }  →  { uploadUrl, fileId }
PUT  <uploadUrl>       <raw bytes>                          →  { fileId, url }
```

| kind | accepts | use for |
|---|---|---|
| `logo` | **png / jpg only** | the brand logo |
| `company_doc` | pdf / png / jpg | the CR document |

**`logo` refuses a PDF** (`400 INVALID_FILE_TYPE`) where `company_doc` accepts one. That is
deliberate: the logo is rendered in an `<img>` on the storefront, and a PDF there is a broken tile on
every listing the company owns. Filter the file picker to images for this kind, or your partner will
find out at the PUT.

The CR takes `company_doc` precisely *because* it allows photos — a commercial registration is
usually photographed, not scanned. The stored extension follows the **bytes**, not the kind, so a
photographed CR lands as `.png` and opens.

---

## 2. `PUT /me/company-docs` — two new fields

```jsonc
PUT /me/company-docs
{
  "logoFileId": "file_01jd…",   // optional, companies only
  "crFileId":   "file_01je…"    // optional
}
```

Response is the full docs object (bare, **not** wrapped in `data`):

```jsonc
{
  "cr": "1010123456", "iban": null,
  "authorizationLetterFileId": null, "vatCertificateFileId": null,
  "operatorLicenseFileId": null,
  "complete": false,
  "nationalIdFileId": null,
  "logoFileId": null, "logoUrl": null,
  "crFileId": null,   "crUrl": null
}
```

`logoUrl` and `crUrl` are resolved public URLs — render them directly, no second call.

### 2.1 Errors

| status | code / field | when |
|---|---|---|
| `422` | `LOGO_COMPANY_ONLY` | `logoFileId` sent by an individual partner |
| `400` | `fields.logoFileId: "يجب رفع الشعار كصورة"` | the file id is not a `logo`-kind upload |
| `400` | `fields.crFileId: "ملف غير موجود"` | the file belongs to another partner, or was never PUT |

The second one exists because passing a `company_doc` PDF id into `logoFileId` would otherwise be
accepted and break the storefront. Only hide the logo control for individuals — do not rely on the
error as the gate.

### 2.2 Clearing the logo

**`{"logoFileId": null}` removes it.** This is the one field on this endpoint that can be cleared;
every other key is ignored when null, because the endpoint does a partial merge. Optional means
removable — a partner who uploaded the wrong image needs a way back to having none.

`crFileId` follows the old partial-merge rule and **cannot** be nulled. Replace it by uploading
another file. Say so if you need clearing and it is a one-line change.

---

## 3. Where the logo appears

### 3.1 On every listing — `GET /api/v1/units/{id}` → `data.owner`

```jsonc
"owner": {
  "id": 4, "name": "شركة الأفق", "type": "company",
  "is_verified": true,
  "avatar_url": null,      // personal avatar — still not stored
  "logo_url": null         // ← new
}
```

**`logo_url` is null for individuals and for companies that never uploaded one — which is most of
them.** The key is always present; a missing key and a null are different claims, so you can branch on
it safely. Render the initials fallback for null.

Do **not** read the logo through `avatar_url`. They are different things: `avatar_url` is a person,
`logo_url` is a brand. Prefer `logo_url`, fall back to initials, never the reverse.

### 3.2 On the admin partner record — `GET /admin/partners/{id}`

`logoUrl` sits at the top level, **deliberately outside `documents[]`**. Branding is not evidence and
must never enter the review queue or influence an approval decision. A test asserts it never appears
there.

---

## 4. ⚠️ Neither field gates completeness — keep it that way

`complete` in the docs object is what blocks a company from submitting a unit for review. **Both new
fields are computed after it and excluded from it**, for a specific reason:

- Every company already registered has a **CR number and no scan**. Folding `crFileId` into `complete`
  would freeze all of them out of unit submission on the day it deployed.
- The logo is branding. Blocking a business over a missing image nobody reviews is not a control, it
  is an outage.

This array has already caused that exact regression once, when the identity scan was added to it. If
you want either field required at onboarding, **make it a client-side step gate** — and tell us first,
because on production it will strand existing partners mid-flow.

---

## 5. The CR document on the admin side

`GET /admin/partners/{id}` → `documents[]`, the entry with `kind: "commercial_registration"`:

```jsonc
{ "id": "commercial_registration", "kind": "commercial_registration",
  "label": "السجل التجاري",
  "value": "1010123456",                       // the typed number, still shown
  "fileUrl": "https://…/dashboard/company_doc/file_01je….png",   // ← was always null
  "status": "pending_review" }
```

`fileUrl` was hardcoded `null` since the endpoint was written, while the VAT certificate and operator
licence both carried files. So the reviewer had a ten-digit number and nothing to open, on the one
document that proves the company exists and states what it is licensed to do.

Verification is unchanged: `POST /admin/partners/{partnerId}/documents/commercial_registration/verify`
already worked — it just had nothing behind it.

**Render `value` and `fileUrl` together.** The number is what gets checked against the registry; the
scan is what proves the number belongs to this applicant.

---

## 6. Checklist

**Partner dashboard**
- [ ] Logo control shown only when `accountType === "company"`
- [ ] File picker restricted to png/jpg for `kind: "logo"`
- [ ] `logoUrl` rendered from the response; initials fallback on null
- [ ] A remove action sending `{"logoFileId": null}`
- [ ] CR upload via `kind: "company_doc"`, accepting pdf **and** photos
- [ ] `crUrl` shown back so the partner can confirm the right page uploaded
- [ ] Neither field blocks the submit step (§4)

**Admin panel**
- [ ] `documents[].fileUrl` opened for `commercial_registration` — it is no longer always null
- [ ] `logoUrl` rendered on the partner header, outside the documents list
- [ ] Nothing added to the approvals queue for either

**Storefront**
- [ ] `owner.logo_url` on the unit page, initials fallback on null
- [ ] `avatar_url` not repurposed for it

---

## 7. Deploy state

| | staging | production |
|---|---|---|
| `logo` upload kind | ✅ live | ⏳ |
| `logoFileId` / `logoUrl` on `/me/company-docs` | ✅ live | ⏳ |
| `owner.logo_url` on the public unit | ✅ live | ⏳ |
| `logoUrl` on the admin partner detail | ✅ live | ⏳ |
| `crFileId` / `crUrl` + admin `fileUrl` | ✅ live | ⏳ |
| Migrations (`logo_file`, `cr_file`) | ✅ run | ⏳ |

Suite: **229 passed, 1239 assertions** — 13 of them on this feature.

Production needs two migrations, so it is a deploy rather than a file copy. Say when.
