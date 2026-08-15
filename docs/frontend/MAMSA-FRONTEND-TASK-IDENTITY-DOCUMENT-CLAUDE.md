# Task: identity document at partner registration (Claude Code — Next.js)

**For:** a Claude Code agent working in the **partner app** (registration form) and the
**admin panel** (KYC review).
**Backend status:** ✅ **live on staging AND production**, verified 2026-08-15.
**Action required:** yes — individual partner signup is currently **broken in your form** until you ship
the change in §2.

---

## ⚠️ Read this first

Individual partner registration now **requires an identity document image**, and the request must be
sent as **`multipart/form-data`** instead of JSON.

Until your form sends it, individual signup fails with **422** on both environments. Companies are
unaffected.

This is enforced **right now on production**. There are no real partner signups on production yet, so
nothing is currently failing for a real user — but the window is open, so this is the top-priority item.

**If it starts hurting before you can ship:** tell the backend and the requirement is switched off in a
single environment variable (`PARTNER_REQUIRE_IDENTITY_FILE=false`) — no redeploy, effective in
seconds, and it can be switched back on the day you land. You do not need to work around it in the
client.

---

## 1. Why this exists

The identity **number** was always captured and required. The admin already saw a "الهوية الوطنية" row in
the KYC document list — but with **`fileUrl: null`**, because there was nowhere to store a scan.

So an admin was approving a partner's identity **on a typed number alone**, with nothing to look at.
The number now comes with an image the admin can open and verify.

---

## 2. Registration — `POST /api/v1/auth/partner/register`

### 2.1 The request is now multipart

```ts
const form = new FormData();
form.append('type', 'individual');
form.append('name', name);
form.append('phone', phone);            // 9 digits, starts with 5
form.append('code', otpCode);
form.append('email', email);
form.append('national_id', nationalId); // the number — unchanged
form.append('national_id_file', file);  // ← NEW: the scan

await fetch(`${API}/api/v1/auth/partner/register`, { method: 'POST', body: form });
// Do NOT set Content-Type yourself — the browser must set the multipart boundary.
```

### 2.2 Field rules

| Field | Rule |
|---|---|
| `national_id_file` | **required when `type === 'individual'`** · `jpg`, `jpeg`, `png`, `pdf` · **max 5 MB** |
| `national_id` | unchanged — required for individuals |
| `cr_number` | unchanged — required for companies |
| `national_id_file` on a **company** | not required, ignored if sent |

A supplied file is **always** validated for type and size, on every environment — that never depends on
the rollout flag.

### 2.3 Validation errors

Standard Laravel envelope, **422**. Real response from production:

```jsonc
{
  "message": "The national id file field is required when type is individual.",
  "errors": {
    "national_id_file": ["The national id file field is required when type is individual."]
  }
}
```

- [ ] Surface the error on the file input, not as a generic toast.
- [ ] Validate client-side too (type + 5 MB) so a large file is not uploaded just to be rejected.

Note this rejection happens **before** the OTP is checked, so a 422 here does **not** consume the code —
the user can fix the file and resubmit with the same OTP.

### 2.4 A re-submitting applicant keeps their existing file

A rejected applicant who registers again **keeps the document already on file** if the field is omitted.
Sending a new file replaces it.

- [ ] On a resubmission form, show the existing document as present and let the user replace it
      optionally, rather than forcing a re-upload.

---

## 3. Adding or replacing the scan later — `/me/company-docs`

Once the partner has a session, the scan is managed through the existing KYC docs endpoint (root base,
cookie session):

```
GET  /me/company-docs
PUT  /me/company-docs
```

`GET` now returns `nationalIdFileId`:

```jsonc
{
  "cr": "1010101010",
  "iban": "SA03...",
  "authorizationLetterFileId": "file_01J8...",
  "vatCertificateFileId": "file_01J8...",
  "operatorLicenseFileId": "file_01J8...",
  "complete": true,
  "nationalIdFileId": "file_01M02Q..."   // ← NEW
}
```

### 3.1 `complete` deliberately ignores `nationalIdFileId`

**Do not treat `complete` as "the partner's KYC is done."** It means *company payout documents are
complete*, and it is what gates **company** unit submission (`COMPANY_DOCS_INCOMPLETE`, 409).

The identity scan is an individual-only document, so it is **excluded** from that flag — including it
would block every company from submitting a unit over a file companies are never asked for. So
`complete: true` alongside `nationalIdFileId: null` is correct and expected.

### 3.2 Uploading it here uses the normal presign flow

The partner is authenticated at this point, so use the standard upload route:

```
POST /uploads/presign     → { uploadUrl, fileId }
PUT  <uploadUrl>          (raw bytes)
PUT  /me/company-docs     { "nationalIdFileId": fileId }
```

The file must belong to the authenticated partner; a foreign id is rejected with **400 `VALIDATION`**
and `fields.nationalIdFileId`.

**Why registration is different:** at registration there is no session yet, so presign is not available —
hence the direct multipart upload there. Both paths end at the same stored document.

---

## 4. Admin panel — the scan is now reviewable

`GET /admin/partners/{id}` → `documents[]` keeps its shape, but the identity row now carries a real URL.
Live production response for the test partner:

```jsonc
{
  "id": "national_id",
  "kind": "national_id",
  "label": "الهوية الوطنية",
  "value": "1900000015",
  "fileUrl": "https://api.mamsaa.com/storage/dashboard/national_id/file_01M02QG7....png",  // ← was always null
  "status": "verified"
}
```

- [ ] Render `fileUrl` as an openable / zoomable document. It may be a **PDF as well as an image** —
      handle both, or open in a new tab.
- [ ] Keep your null fallback: companies have no identity row, and individuals who registered before
      this change may still have `fileUrl: null`.

Verification is unchanged:

```
POST /admin/partners/{partnerId}/documents/national_id/verify
```

**Image host:** files are served from `api.mamsaa.com` / `staging.mamsaa.com` — already on your Next.js
image allowlist, nothing to add.

### 4.1 `documentsComplete` is stricter for individuals

| Type | Required for `documentsComplete: true` |
|---|---|
| individual | `national_id` **+ `national_id_file`** + `iban` |
| company | `cr_number` + `iban` |

- [ ] Expect existing individual partners to read `documentsComplete: false` until they upload a scan.
      That is the intended tightening, not a regression.

---

## 5. Test accounts — a fake identity is already seeded

So you can exercise the admin review flow immediately, both test partners now carry a placeholder
identity card. It is a plain generated PNG with a **"NOT A REAL ID — TEST ONLY"** watermark, a synthetic
number, and no resemblance to a real Saudi ID.

| Env | Partner | ID number | Document |
|---|---|---|---|
| production | `+966555000002` (شريك تجريبي) | `1900000015` | seeded ✅ |
| staging | `+966500000002` (محمد الشريك الفردي) | `1023456789` | seeded ✅ |

The production test partner shows `documentsComplete: false` — correct, because that account has **no
IBAN**, not because of the identity. Per-document verify/reject works regardless.

---

## 6. Endpoint summary

| Method | Endpoint | Change |
|---|---|---|
| POST | `/api/v1/auth/partner/register` | **now multipart**; `national_id_file` required for individuals |
| GET | `/me/company-docs` | returns `nationalIdFileId` (excluded from `complete`) |
| PUT | `/me/company-docs` | accepts `nationalIdFileId` |
| POST | `/uploads/presign` | unchanged — used for the authenticated path |
| GET | `/admin/partners/{id}` | identity row now has a real `fileUrl`; `documentsComplete` stricter |
| POST | `/admin/partners/{id}/documents/national_id/verify` | unchanged |

---

## 7. Checklist

**Partner app (urgent — production is enforcing):**
- [ ] Registration switched to `multipart/form-data` (no manual `Content-Type`)
- [ ] File input for individuals, hidden for companies
- [ ] Client-side validation: jpg/jpeg/png/pdf, ≤ 5 MB
- [ ] 422 field error surfaced on the input
- [ ] Resubmission does not force a re-upload
- [ ] Profile screen can add/replace via presign → `nationalIdFileId`
- [ ] `complete` is **not** used as a KYC-done signal (see §3.1)

**Admin panel:**
- [ ] Identity `fileUrl` opens (handle PDF as well as image)
- [ ] Tolerates `fileUrl: null` for companies and legacy partners
- [ ] `documentsComplete: false` on legacy individuals renders sensibly

---

## 8. Also shipped, no action needed

**`submitted_at` on units** — `avgReviewHours` on the approvals dashboard now measures **submission →
decision** instead of creation → decision, so draft time no longer counts as review time.

**You can now colour it against the 24h/48h SLA** — the caveat from the previous hand-off is lifted. The
approvals queue also sorts on real submission time, so "oldest waiting first" means what it says. Live on
both environments.
