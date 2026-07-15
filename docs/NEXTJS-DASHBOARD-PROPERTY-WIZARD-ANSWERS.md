# Add-Property Wizard — Backend Answers

Responses to `NEXTJS-DASHBOARD-PROPERTY-WIZARD-QUESTIONS.md`. All four are resolved; the backend
now matches the shapes you're already sending. Deployed to staging + production.

---

## 1. Attaching photos & license to a unit — ✅ your shape is confirmed

`POST /units` and `PATCH /units/:id` now accept exactly what you're sending:

```jsonc
{
  // ...standard §4 fields (name, type, pricePerNight, …)
  "tourismLicenseFileId": "file_abc",       // fileId from presign+PUT (kind: license_pdf)
  "photoFileIds": ["file_1", "file_2"],     // fileIds (kind: unit_photo), in display order
  "coverFileId": "file_1"                   // which fileId is the cover
}
```

Semantics — please implement to these:

- **`photoFileIds` is an authoritative replace, keyed on presence.** If the key is **present**, the
  unit's gallery is replaced to exactly that ordered list. If the key is **absent**, the gallery is
  left untouched (so a PATCH that only edits, say, the price won't wipe photos). An empty array
  `[]` clears all photos.
- **Order is preserved** — the array order is the display order.
- **`coverFileId`** must be one of the `photoFileIds` (else `400 VALIDATION`,
  `fields.coverFileId`). If omitted, the **first** photo becomes the cover.
- **Ownership is enforced.** Every `fileId` must be a `stored` upload owned by you, of the right
  kind (`unit_photo` for photos, `license_pdf` for the license). A foreign/unknown/wrong-kind id →
  `400 VALIDATION` with per-field keys: `fields["photoFileIds.0"]`, `fields.tourismLicenseFileId`.
  Validation happens **before** any change — a bad id never leaves a half-attached unit.
- Max **10** photos per unit.

### Round-tripping on edit (important)

The Unit **response** `photos[]` now echoes the **fileId** as each photo's `id`:

```jsonc
"photos": [
  { "id": "file_1", "url": "https://…/storage/dashboard/unit_photo/file_1.jpg", "isCover": true },
  { "id": "file_2", "url": "https://…", "isCover": false }
]
```

So on an edit where the partner keeps some photos and adds others, just send
`photoFileIds: [<existing photo ids you're keeping>, <new fileIds>]` — the existing photos' `id`
values ARE their fileIds, re-sendable directly. No separate "keep these" call.

> `tourismLicenseFileId` round-trips the same way — the response field returns the fileId you sent.

---

## 2. `PUT /me/company-docs` request body — ✅ confirmed, partial updates supported

Exactly the shape you're sending (five fields, no `complete` — it's server-computed):

```jsonc
{
  "cr": "1234567890",
  "iban": "SA0380000000608010167519",
  "authorizationLetterFileId": "file_1",
  "vatCertificateFileId": "file_2",
  "operatorLicenseFileId": "file_3"
}
```

- **Partial updates are allowed.** Every field is optional; send any subset. Saving CR+IBAN first,
  then the three file ids across later visits, all works — each PUT merges into the stored record.
- `cr` must be 10 digits, `iban` must match `^SA\d{22}$` (else `400 VALIDATION`).
- Each file id must be a `stored` upload of kind `company_doc` owned by you (else `400 VALIDATION`).
- `complete` (in the GET/PUT response) flips to `true` only once all five are present — that's the
  flag the §4 submit gate (`COMPANY_DOCS_INCOMPLETE`) checks for company partners.

No backend change was needed here — it already behaved this way.

---

## 3. `POST /uploads/presign` `kind` for company docs — ✅ single `company_doc` is correct

Use the single shared `kind: "company_doc"` for all three (authorization letter, VAT certificate,
operator license) — do **not** split into per-document kinds. The `kind` only drives server-side
type/size validation (`company_doc` = PDF, magic-byte checked, ≤10MB); **which** document a file is
is determined purely by which field you put its `fileId` in on `PUT /me/company-docs`
(`authorizationLetterFileId` vs `vatCertificateFileId` vs `operatorLicenseFileId`). So one kind is
sufficient and correct.

The three valid kinds remain: `unit_photo`, `license_pdf`, `company_doc`.

---

## 4. Location / geocoding — ✅ acknowledged, no backend change

Understood — client-side Leaflet + Nominatim, no backend endpoint. We're keeping lat/lng as the
source of truth (validated server-side to be inside Saudi bounds on submit). Noted the Nominatim
public-instance rate limit for production; when onboarding volume grows we'll decide between
self-hosting Nominatim or a paid geocoder — that's a backend/infra call we'll own, no frontend
change required either way. Nothing needed from you now.

---

## What changed on the backend (for reference)

- `POST /units` / `PATCH /units/:id`: added `photoFileIds` / `coverFileId` handling + upload
  ownership validation for photos and the license file.
- `unit_images` gained a `file_id` column so photo responses echo the stable fileId.
- No change to company-docs or presign — they already matched (#2, #3).

All covered by tests (photo attach, ownership rejection, cover validation). Live on
`staging.mamsaa.com` and `api.mamsaa.com`.
