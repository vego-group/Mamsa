# Company registration — upload the commercial registration (السجل التجاري)

**From:** backend · **Date:** 2026-08-17 · **For:** the company **registration** flow and the partner
dashboard
**Status:** ✅ **shipped, live on staging + production** · additive — nothing existing changed shape

A company partner types a 10-digit CR number and nothing else. An admin reviewing them approves on
the strength of that string: no document, no issuing authority, nothing to cross-check. Meanwhile an
individual has supplied a national ID number **and** a scan for a while.

`cr_file` closes that. Three ways in — pick the one that matches your surface.

---

## 1. Every CR endpoint, in one place

| # | Endpoint | Method | Auth | Carries the CR as |
|---|---|---|---|---|
| **1** | `/api/v1/auth/partner/register` | `POST` | none (OTP in body) | **`cr_file`** — multipart file |
| **2** | `/uploads/presign` | `POST` | partner session | `{ kind: "company_doc" }` → `{ uploadUrl, fileId }` |
| **3** | `<uploadUrl>` | `PUT` | signed URL | raw bytes |
| **4** | `/me/company-docs` | `PUT` | partner session | **`crFileId`** — the id from step 2 |
| **5** | `/me/company-docs` | `GET` | partner session | reads back **`crFileId`**, **`crUrl`** |
| **6** | `/admin/partners/{id}` | `GET` | admin session | `documents[]` row `commercial_registration` → **`fileUrl`** + **`value`** |
| **7** | `/admin/partners/{partnerId}/documents/commercial_registration/verify` | `POST` | admin, `partners.manage` | marks it reviewed |

**Registration uses #1. The dashboard uses #2 → #3 → #4.** They write the same column; use whichever
the user is in front of.

---

## 2. At registration — one multipart field

`POST /api/v1/auth/partner/register` already accepts `national_id_file` for individuals. `cr_file` is
its company counterpart, on the same request.

```
POST /api/v1/auth/partner/register
Content-Type: multipart/form-data

type=company
name=شركة الأفق
phone=512345678
code=123456
email=ops@alofuq.sa
cr_number=1010101010
cr_file=<File>          ← new
```

- **`multipart/form-data`, not JSON, not a presign id.** Registration is OTP-verified but the user has
  no session yet, so they cannot call the authenticated presign endpoint. Send the bytes directly.
- **Accepts `jpg`, `jpeg`, `png`, `pdf`, max 5 MB.** A CR is usually photographed rather than scanned,
  so images are first-class — do not force a PDF conversion.
- **Currently OPTIONAL** — see §5. Registration succeeds without it today.
- Re-submitting without the field **does not wipe** a CR already on file. Only a new file overwrites.

### 2.1 Validation errors (`422`)

```jsonc
{ "message": "…", "errors": {
    "cr_file": ["صيغة الملف غير مدعومة (jpg, png, pdf)."]      // wrong type
    // or      ["حجم الملف يتجاوز 5 ميجابايت."]                 // too large
    // or      ["صورة السجل التجاري مطلوبة للشركات."]           // once required — §5
}}
```

Standard Laravel validation envelope, same as the rest of that endpoint.

---

## 3. From the partner dashboard — the presign chain

For a company that is already registered and signed in. Same three steps as every other document:

```jsonc
// 1 — reserve
POST /uploads/presign
{ "kind": "company_doc", "fileName": "cr.pdf", "mimeType": "application/pdf", "size": 84213 }
→ { "uploadUrl": "https://…/uploads/…?signature=…", "fileId": "file_01je…" }

// 2 — send the bytes to uploadUrl, NOT to /uploads
PUT <uploadUrl>
<raw bytes>
→ { "fileId": "file_01je…", "url": "https://…/storage/dashboard/company_doc/file_01je….pdf" }

// 3 — attach
PUT /me/company-docs
{ "crFileId": "file_01je…" }
```

- **`kind: "company_doc"`** — the existing kind, which accepts pdf **and** images. There is no
  separate `cr` kind.
- The `uploadUrl` is **signed and expires in 30 minutes**. PUT to it verbatim; do not rebuild the URL.
- The stored extension follows the **bytes**, not the filename — a photographed CR lands as `.png` and
  opens correctly.

### 3.1 Reading it back

`GET /me/company-docs` returns the docs object **bare** (not wrapped in `data`):

```jsonc
{
  "cr": "1010101010",
  "crFileId": "file_01je…",       // ← new
  "crUrl": "https://…/storage/dashboard/company_doc/file_01je….pdf",   // ← new, resolved
  "iban": "SA03…",
  "authorizationLetterFileId": null,
  "vatCertificateFileId": null,
  "operatorLicenseFileId": null,
  "nationalIdFileId": null,
  "complete": false
}
```

`crUrl` is ready to put in an `<a href>` or an `<iframe>` — no second call, no signing. Show it back so
the partner can confirm they uploaded the right page.

### 3.2 Errors on attach (`400`)

```jsonc
{ "error": { "code": "VALIDATION", "message": "بيانات غير صالحة",
             "fields": { "crFileId": "ملف غير موجود" } } }
```

Means the id is not an upload owned by this partner, or the bytes were never PUT. Note the partner
dashboard's envelope is `{ error: { … } }` — different from registration's, because they are different
API surfaces.

### 3.3 ⚠️ `crFileId` cannot be cleared

`PUT /me/company-docs` does a partial merge and ignores nulls, so `{"crFileId": null}` is a no-op.
**Replace it by uploading another file.** Say so if you need a remove action and it is a one-line
change.

---

## 4. What the admin sees

`GET /admin/partners/{id}` → the `documents[]` row that used to be permanently fileless:

```jsonc
{ "id": "commercial_registration",
  "kind": "commercial_registration",
  "label": "السجل التجاري",
  "value": "1010101010",                                    // the typed number
  "fileUrl": "https://…/file_01je….pdf",                    // ← was ALWAYS null
  "status": "pending_review" }
```

**Render `value` and `fileUrl` together.** The number is what gets checked against the registry; the
scan is what proves the number belongs to this applicant. `POST /admin/partners/{id}/documents/commercial_registration/verify`
already worked — it just had nothing behind it.

---

## 5. ⚠️ Sequencing — read this before you make it required anywhere

Two switches, both currently **off**, both flipped only after you ship the field:

| switch | effect when on | who flips it |
|---|---|---|
| `DASHBOARD_REQUIRE_CR_FILE` | `cr_file` becomes **required** at registration → a company signup without it `422`s | backend, on your word |
| the CR row's `expects` | `documentsComplete` starts requiring the scan → **every existing company flips to incomplete** | backend, deliberately |

**Nothing is required today, on purpose.** Turning either on before the client can send a `cr_file`
breaks company registration outright, and the second one puts an unclearable flag on every company
already on the platform — an alarm nobody can resolve is one reviewers learn to scroll past, which
costs you the flags that are real.

**Tell us when the registration field is live** and we flip the first one. The second waits until
existing companies have had a path to upload, which is the dashboard flow in §3.

---

## 6. Checklist

**Company registration**
- [ ] A file input on the company branch of the form, beside `cr_number`
- [ ] Accepts `jpg`, `jpeg`, `png`, `pdf`; client-side max 5 MB with a clear message
- [ ] Submitted as `multipart/form-data` on the existing register call — **not** JSON, **not** presign
- [ ] Copy naming it as صورة/صورة السجل التجاري, and that a photo is fine
- [ ] Optional today — do not block submit on it until we flip the switch (§5)
- [ ] `422` `errors.cr_file` rendered under the field

**Partner dashboard**
- [ ] A card for companies whose `crFileId` is null, prompting the upload
- [ ] presign (`kind: "company_doc"`) → signed PUT → `PUT /me/company-docs { crFileId }`
- [ ] `crUrl` shown back so the partner can check the right page uploaded
- [ ] Re-upload replaces; no remove action (§3.3)

**Admin panel**
- [ ] `commercial_registration` opens `fileUrl` — it is no longer always null
- [ ] Keep it in `VALUE_ONLY_DOCUMENT_KINDS` until the dashboard upload ships (§5)

---

## 7. Deploy state

| | staging | production |
|---|---|---|
| `partner_details.cr_file` migration | ✅ run | ✅ run |
| `cr_file` on `POST /auth/partner/register` | ✅ live | ✅ live |
| `crFileId` on `PUT /me/company-docs` | ✅ live | ✅ live |
| `crFileId` / `crUrl` on `GET /me/company-docs` | ✅ live | ✅ live |
| `fileUrl` on the admin `commercial_registration` row | ✅ live | ✅ live |
| `DASHBOARD_REQUIRE_CR_FILE` | ❌ off | ❌ off |

**Suite: 253 passed, 1320 assertions** — 7 on this feature, covering registration with an image and a
PDF, the wrong file type, the dashboard chain, another partner's file being refused, the admin row,
and that shipping the column does not flip any existing company to incomplete.
