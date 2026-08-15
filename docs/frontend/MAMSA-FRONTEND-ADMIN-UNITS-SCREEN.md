# Task: the Units screen (Claude Code — Next.js admin panel)

**For:** a Claude Code agent building `/admin/units` — the property directory, unit detail, and
Mamsa-owned listing creation.
**Backend status:** ✅ **live on staging AND production**. Verified 2026-08-15.
**Shares components with:** the approvals screen — `unit` in an approval detail **is** this screen's
detail payload. Build the detail view once and reuse it.

Every payload and error below is a **real staging response**.

---

## 1. The endpoints

```
GET  /admin/units?page=&pageSize=&search=&status=&type=&city=&partnerId=&sortBy=&sortDir=
GET  /admin/units/stats
GET  /admin/units/{id}
POST /admin/units                      { name, type, city, district, pricePerNight,
                                         bedrooms, bathrooms, capacity, sizeSqm }
POST /admin/units/{id}/unpublish       { reason }
```

Root-mounted, **no `/api/v1`**. Cookie session, `credentials: "include"`.

| | Permission | Finance role |
|---|---|---|
| the three `GET`s | `units.view` | ❌ no reach |
| `POST` create / unpublish | `units.manage` | ❌ no |

Superadmin-only screen. Gate the nav entry on `units.view`.

**There is no update and no delete.** A unit's content is edited by its partner, not by an admin — the
only admin mutations are creating a Mamsa-owned listing and taking a published one down.

---

## 2. List — `GET /admin/units`

```jsonc
{ "items": [ … ], "total": 19, "page": 1, "pageSize": 10 }
```

A real row:

```jsonc
{
  "id": "21",
  "code": "MRNT3A15",
  "name": "استديو مناهل تست",
  "partnerId": "9",
  "partnerName": "شريك تجريبي للوحة",
  "city": "الرياض",
  "district": "القيروان",
  "type": "studio",                    // apartment | villa | chalet | studio | hotel_room
  "status": "approved",                // draft | pending_review | approved | rejected
  "pricePerNight": 600,
  "bedrooms": 1, "bathrooms": 0, "capacity": 2, "sizeSqm": 0,
  "rating": 0, "reviewsCount": 0,
  "occupancyRate": 0,                  // % of the trailing 90 days booked
  "revenue": 0, "bookingsCount": 0,
  "coverImage": "https://staging.mamsaa.com/storage/dashboard/unit_photo/file_01ky….png",
  "mamsaOwned": false,
  "rejectionReason": null,
  "approvedAt": "2026-08-15T04:57:17+03:00"    // null unless approved
}
```

### Filters — all verified live against staging

| Param | Example | Result |
|---|---|---|
| `status` | `approved` / `pending_review` / `draft` | 14 / 1 / 2 |
| `type` | `apartment` | 7 |
| `city` | `الرياض` | 12 |
| `partnerId` | `4` | 5 |
| `search` | `شقة` | 6 — matches unit **name**, **code**, or **city** |
| `sortBy` | `pricePerNight` · `rating` · `occupancyRate` · `revenue` · `bookingsCount` · `name` · `createdAt` | |
| `sortDir` | `asc` \| `desc` | default `desc` |

Default order: `createdAt` descending.

- [ ] `status` uses the **spec literals** (`pending_review`), not the internal DB value (`pending`).
      Send what you receive.
- [ ] `hotel_room` is the spec value; it maps to an internal `hotel`. Always use `hotel_room`.
- [ ] This list includes **drafts** — units the partner has not submitted. Consider defaulting the
      filter to exclude them, or an admin browsing "our properties" sees half-built listings.
- [ ] `coverImage` is **nullable** — see §6.

---

## 3. Stats — `GET /admin/units/stats`

```jsonc
{"total":19,"approved":14,"pendingReview":1,"avgOccupancy":11,"totalRevenue":240237.45}
```

- `avgOccupancy` is a **percentage across approved units over the trailing 90 days**, capped at 100.
- `totalRevenue` is platform-wide VAT-inclusive gross from revenue-bearing bookings — the same figure
  the partners and bookings screens show. It is **not** scoped to any filter you have applied.

No range parameter.

- [ ] Don't let the stats row appear to respond to the table's filters — it never does. Either place it
      clearly outside the filtered area or label it "إجمالي المنصة".

---

## 4. Detail — `GET /admin/units/{id}`

The list row **plus** nine fields:

```
description, images, amenities, lat, lng, publicUrl, tourismPermitNo, permitFileUrl, ownerIdNumber
```

```jsonc
{
  …row fields…,
  "description": "…",
  "images": ["https://…/file_01kx….jpg", "https://…/file_01kx….jpg"],
  "amenities": ["واي فاي", "موقف سيارات"],
  "lat": 21.42, "lng": 39.82,
  "publicUrl": null,                   // set only when approved
  "tourismPermitNo": "TL-2015-2568",
  "permitFileUrl": "https://…/license_pdf/file_01kx….pdf",
  "ownerIdNumber": "1205656234"
}
```

**This is the identical payload embedded as `unit` in `GET /admin/approvals/{id}`.** One component,
two screens.

- [ ] `permitFileUrl` is frequently a **PDF** — open in a new tab, don't use `<img>`.
- [ ] `ownerIdNumber` is the partner's national ID. Treat as sensitive: no logging, no analytics.
- [ ] `publicUrl` is `null` unless the unit is approved — don't render a dead link.
- [ ] `images: []` means the unit genuinely has no photos (§6).

---

## 5. The two mutations

### 5.1 `POST /admin/units` — create a Mamsa-owned listing

All nine fields are **required**. The unit is created as a **draft**, owned by the acting admin, and
flagged `mamsaOwned: true`.

```jsonc
{ "name": "…", "type": "apartment", "city": "الرياض", "district": "…",
  "pricePerNight": 450, "bedrooms": 2, "bathrooms": 1, "capacity": 4, "sizeSqm": 90 }
```

Returns `{ ok: true }` with **201** — and nothing else. No id, no body. **Refetch the list** to find
the new row.

- [ ] It starts as a **draft** and goes through the same review pipeline as a partner unit — it is not
      live on creation. Say so in the success message, or an admin will expect it published.
- [ ] There is no photo upload on this endpoint. A newly created Mamsa unit has no images and cannot
      be usefully published until they are added — flag that as a follow-up step in the UI.

### 5.2 `POST /admin/units/{id}/unpublish` — take an approved unit down

Requires `reason`. Moves `approved → rejected`, removing it from the public site. The reason reaches
the partner as the rejection reason.

- [ ] Only valid on an **approved** unit → 409 otherwise. Hide the control on draft/pending/rejected.
- [ ] Confirm before firing: it removes a live listing and notifies the partner.
- [ ] The unit lands in `rejected`, so the partner can fix and resubmit — it reappears in the
      approvals queue. Word the confirmation as "unpublish and return to the partner", not "delete".

---

## 6. `coverImage` and `images` are nullable/empty — deliberately

`coverImage` is `null`, and `images` is `[]`, when the unit has no photography of its own. They were
previously padded with a shared stock image, which made empty listings look photographed.

| Surface | Treatment |
|---|---|
| **Units grid (this screen)** | neutral grey icon, "No photo" — a fact about the record |
| Approvals detail (review) | amber, "grounds for rejection" |
| Unit detail (this screen) | **neutral** — an admin reading a published unit is not being asked to judge it |

- [ ] Never pad an empty gallery client-side either.
- [ ] Partners can no longer submit with zero real photos, so an empty gallery on an **approved** unit
      should be rare. Mamsa-owned drafts created via §5.1 are the common case.

---

## 7. Errors

Flat admin envelope `{ message, code }`, Arabic messages. Captured live:

```jsonc
409 {"message":"الوحدة ليست منشورة","code":"CONFLICT"}              // unpublish a non-approved unit
422 {"message":"يجب إدخال سبب إلغاء النشر","code":"VALIDATION_ERROR"} // unpublish with no reason
422 {"message":"نوع الوحدة مطلوب","code":"VALIDATION_ERROR"}         // create with a missing field
404 {"message":"الوحدة غير موجودة","code":"NOT_FOUND"}               // unknown id
403 INSUFFICIENT_PERMISSION                                          // lacks the permission
```

Validation returns **one message at a time** (the first failure), not a field map. Show it as a form-level
error, or track which field you sent that maps to it.

---

## 8. Checklist

**List & stats:**
- [ ] `status` sent as spec literals (`pending_review`, not `pending`)
- [ ] `hotel_room` used for the type, never `hotel`
- [ ] Drafts either filtered out by default or clearly marked (§2)
- [ ] Stats row not implied to follow the table filters (§3)
- [ ] Sort offered only on the seven supported keys

**Detail:**
- [ ] Detail component shared with the approvals screen (§4)
- [ ] `permitFileUrl` opens as a PDF
- [ ] `ownerIdNumber` treated as sensitive
- [ ] `publicUrl: null` → no link
- [ ] `images: []` → neutral empty state, never padded (§6)

**Mutations:**
- [ ] Create form: all nine fields required; success says **draft, pending review** (§5.1)
- [ ] Refetch the list after create — the response carries no id
- [ ] Unpublish only on approved units; confirmed; worded as "return to partner" (§5.2)
- [ ] Both gated on `units.manage`

---

## 9. Testing it

**Staging** has 19 units: 14 approved, 1 pending review, 2 drafts.

| Fixture | Use it for |
|---|---|
| unit `21` | approved with a real cover photo and `approvedAt` |
| unit `20` | full detail — 2 photos, tourism permit PDF, owner ID |
| `?status=draft` (2) | draft rows, and the 409 on unpublish |
| `?partnerId=4` (5) | partner-filtered view |

Reproduce the errors safely — **none of these write anything**:

- `409` → unpublish a draft
- `422` → unpublish with no reason, or create with a missing field
- `404` → any unknown id

**Production** has 2 units, both without photos — useful for the empty-gallery states.

Backend suite: **194 passed, 1094 assertions.**
