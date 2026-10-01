# Task: the Approvals screen (Claude Code — Next.js admin panel)

**For:** a Claude Code agent building `/admin/approvals` — the unit review queue and its detail view.
**Backend status:** ✅ **live on staging AND production**. Verified 2026-08-15.
**History:** this screen went through four rounds of backend/frontend correction. The decisions those
rounds settled are folded in below — **§7 lists the ones that are easy to get wrong again.**

Every payload and error is a **real staging response**.

---

## 1. The five endpoints

```
GET  /admin/approvals?page=&pageSize=&search=&requestType=&partnerType=&sortBy=&sortDir=
GET  /admin/approvals/stats?range=today|7d|30d
GET  /admin/approvals/{id}
POST /admin/approvals/{id}/approve
POST /admin/approvals/{id}/reject          { reason }
```

Root-mounted, **no `/api/v1`**. Cookie session, `credentials: "include"`.

| | Permission | Finance role |
|---|---|---|
| the three `GET`s | `approvals.view` | ❌ no reach |
| approve / reject | `approvals.manage` | ❌ no reach |

The whole screen is superadmin-only. Gate the nav entry on `approvals.view` from `/admin/me`.

---

## 2. The queue — `GET /admin/approvals`

Only units **awaiting review** appear here (`approval_status = pending`). Standard paginated envelope:

```jsonc
{ "items": [ … ], "total": 1, "page": 1, "pageSize": 10 }
```

A real row:

```jsonc
{
  "id": "20",
  "code": "REQ-020",
  "unitId": "20",
  "unitName": "شقة بمكة شارع أجياد",
  "unitType": "apartment",
  "coverImage": "https://staging.mamsaa.com/storage/dashboard/unit_photo/file_01kx….jpg",
  "city": "مكة المكرمة",
  "partnerId": "22",
  "partnerName": "Reda",
  "partnerType": "individual",
  "submittedAt": "2026-07-17T12:32:36+03:00",
  "requestType": "new",              // new | resubmission
  "previousRejection": null          // { reason, at } when resubmission
}
```

### Query parameters

| Param | Values | Notes |
|---|---|---|
| `page` / `pageSize` | | pageSize capped at 100 |
| `search` | free text | matches unit **name**, unit **code**, **city**, or **partner name** |
| `requestType` | `new` \| `resubmission` | `reapproval_after_edit` is accepted but **always returns empty** — not tracked yet |
| `partnerType` | `individual` \| `company` | |
| `sortBy` | **only `submittedAt`** | anything else is ignored |
| `sortDir` | `asc` \| `desc` | |

**Default order is `submittedAt` ascending — oldest waiting first.** That is the SLA order and the
right default; don't override it with newest-first.

- [ ] `previousRejection` on a resubmission carries `{ reason, at }`. **Show the previous reason
      prominently** — a reviewer looking at a resubmission needs to know what was wrong last time
      before judging whether it was fixed.
- [ ] `coverImage` is **nullable** — see §7.1.
- [ ] Don't offer sort controls on columns other than `submittedAt`; an ignored sort looks broken.

---

## 3. Stats — `GET /admin/approvals/stats?range=`

Real staging responses across the three ranges:

```jsonc
today → {"pendingReview":1,"approved":2,"rejected":1,"avgReviewHours":null,"avgReviewSample":0,
         "range":"today","approvedToday":2,"rejectedToday":1}
30d   → {"pendingReview":1,"approved":6,"rejected":1,"avgReviewHours":null,"avgReviewSample":0,
         "range":"30d","approvedToday":6,"rejectedToday":1}
```

| Field | Meaning |
|---|---|
| `pendingReview` | **never range-scoped** — "what is on my desk right now" |
| `approved` / `rejected` | decisions **inside** the range |
| `avgReviewHours` | `number \| null` — submission → decision, in hours |
| `avgReviewSample` | how many decisions the average was computed over |
| `range` | echoed back; an unknown value **falls back to `today`** |
| `approvedToday` / `rejectedToday` | legacy keys, mirror `approved` / `rejected` for the requested window |

- [ ] `pendingReview` must not change when the range changes. If your UI implies otherwise, relabel it.
- [ ] An unrecognised `range` silently becomes `today` — always read `range` back rather than assuming
      your request was honoured.
- [ ] Prefer `approved` / `rejected`; `approvedToday` / `rejectedToday` exist only for older clients.

### 3.1 `avgReviewHours` + `avgReviewSample` — render them together

**`null` means "no measured decisions", never "0 hours".** Render `—` with a caption, no colour, no
SLA threshold applied. `0.0` is a real measurement (a decision within minutes).

`avgReviewSample` exists because the tiles count different populations. Live right now:

```
approved 6 · rejected 1 · avgReviewHours null · avgReviewSample 0
```

Seven decisions and an average over zero of them — both correct: those seven predate the
`submitted_at` column, so none is measurable. **Caption it as "averaged over N of M decisions"** or the
screen reads as broken.

- [ ] `avgReviewSample === 0` ⟺ `avgReviewHours === null`. Both directions hold.
- [ ] Apply the **48h** target only when `avgReviewHours` is non-null. 48 *continuous* hours from
      submission (amber at 24h) — no working-calendar logic; the backend encodes no threshold at all,
      so this value is entirely yours.

---

## 4. Detail — `GET /admin/approvals/{id}`

Everything from the queue row, **plus**:

```jsonc
{
  …row fields…,
  "unit": { …full UnitDetail… },
  "partnerVerified": false,
  "partnerRating": 0
}
```

`unit` is the complete admin UnitDetail — the same shape the units screen uses, so reuse that
component. Review-relevant fields inside it:

```jsonc
{
  "id": "20", "name": "شقة بمكة شارع أجياد", "status": "pending_review",
  "images": ["https://…/file_01kx….jpg", "https://…/file_01kx….jpg"],
  "coverImage": "https://…/file_01kx….jpg",
  "tourismPermitNo": "TL-2015-2568",
  "permitFileUrl": "https://…/license_pdf/file_01kx….pdf",   // may be a PDF
  "ownerIdNumber": "1205656234",
  "publicUrl": null,                                          // set only once approved
  "description": "…", "amenities": [...], "lat": …, "lng": …,
  "pricePerNight": …, "bedrooms": …, "bathrooms": …, "capacity": …, "sizeSqm": …
}
```

- [ ] `permitFileUrl` is frequently a **PDF** — open in a new tab or use a PDF-capable viewer, not an
      `<img>`.
- [ ] `ownerIdNumber` is the partner's national ID — a reviewer cross-checks it against the permit.
      Treat it as sensitive: no logging, no analytics payloads.
- [ ] `publicUrl` is `null` until the unit is approved. Don't render a dead link on a pending unit.
- [ ] `images` is **`[]` when the unit has no photos** — see §7.2.

---

## 5. The two decisions

```ts
POST /admin/approvals/{id}/approve                      → { ok: true }
POST /admin/approvals/{id}/reject   { reason }          → { ok: true }
```

`reason` is **required** on reject and reaches the partner. Both actions notify the partner.

Live error bodies:

```jsonc
409 {"message":"الوحدة ليست في انتظار المراجعة","code":"CONFLICT"}
422 {"message":"يجب إدخال سبب الرفض","code":"VALIDATION_ERROR"}
404 {"message":"الطلب غير موجود","code":"NOT_FOUND"}
```

| `code` | When | Handle |
|---|---|---|
| `CONFLICT` (409) | the unit is no longer pending — someone else decided it, or the partner edited it | **the important one**: show `message`, refresh the queue. Not a bug |
| `VALIDATION_ERROR` (422) | reject with no reason | show under the reason field |
| `NOT_FOUND` (404) | unknown id / stale link | refresh |
| `INSUFFICIENT_PERMISSION` (403) | lacks `approvals.manage` | hide the buttons for such roles |

- [ ] **`409 CONFLICT` is expected in normal use.** Two reviewers on the same queue, or a partner
      editing while you review, both produce it. Treat it as "already handled", refresh, and move on —
      never as a failed request to retry.
- [ ] Reject must open a dialog with a required reason. The partner sees this text verbatim; it should
      say what to fix.
- [ ] Both endpoints return only `{ ok: true }` — refetch the queue **and** the stats afterwards.

---

## 6. Bulk actions

Supported by looping the single endpoints — there is no bulk API. Rate limit is 240/min, so a page of
10 sequential calls is far under it.

- [ ] Keep bulk selection to one page and issue calls **sequentially**.
- [ ] Collect per-row failures rather than aborting: a `409` on row 3 must not stop rows 4–10. Surface
      the Arabic `message` per failed row.

---

## 7. The four things previous rounds got wrong

These are settled, but each was a real defect. They are the ones to re-check when this screen changes.

### 7.1 `coverImage` is nullable — and the placeholder must be non-photographic

`null` when the unit has no photo of its own. It was previously padded with a shared stock image,
which made empty listings look photographed **and** left the rows indistinguishable anyway.

Treatment differs by surface, deliberately:

| Surface | Treatment |
|---|---|
| Units grid (browse) | neutral grey icon, "No photo" — a fact about the record |
| **Approvals detail (review)** | **amber**: "no photos — a unit cannot be assessed without them, grounds for rejection" |

The unit detail page shares the gallery with approvals but takes the **neutral** treatment: an admin
reading an already-published unit is not being asked to judge it.

### 7.2 `unit.images: []` defeats the checklist if you pad it

An empty array means no photos. It was previously `[defaultImageUrl]`, so a reviewer could tick
"photos reviewed" while looking at a placeholder and approve a photoless listing onto the public site.

- [ ] Never pad an empty gallery client-side either. Absence must stay visible.
- [ ] Partners can no longer submit with zero real photos (the rule now counts uploads, not rows), so
      an empty gallery on a pending unit should be **rare** — which is exactly what makes the amber
      state worth reading.

### 7.3 `submittedAt` is a real submission time now

It used to be `updated_at`, which reset on any write — so a row's waiting time silently shrank whenever
anything touched the unit. It is now stamped when the unit enters review, and **re-stamped on
resubmission** (the clock restarts per submission, it does not run from the first attempt).

Safe to grade against the SLA. Rows that predate the column fall back to `updated_at`.

### 7.4 Timestamps here carry an offset, not `Z`

`"submittedAt": "2026-07-17T12:32:36+03:00"` — Riyadh offset, whereas the wallet/payout endpoints emit
Zulu (`…Z`). Both are valid ISO-8601 and `new Date()` parses both.

- [ ] Don't string-slice timestamps or assume a trailing `Z`. Parse them.

---

## 8. Checklist

**Queue:**
- [ ] Default sort `submittedAt` ascending, oldest first — not overridden
- [ ] Sort control offered only on `submittedAt`
- [ ] `previousRejection.reason` shown prominently on resubmissions
- [ ] `coverImage: null` renders the non-photographic placeholder (§7.1)
- [ ] `requestType=reapproval_after_edit` not offered as a filter (always empty)

**Stats:**
- [ ] `pendingReview` not presented as range-scoped
- [ ] `range` read back from the response, not assumed
- [ ] `avgReviewHours: null` → `—`, no colour, no threshold (§3.1)
- [ ] Caption shows `avgReviewSample` as "N of M decisions"
- [ ] 48h/24h thresholds applied only to non-null values

**Detail:**
- [ ] `unit.images: []` → amber "no photos" state, never padded (§7.2)
- [ ] `permitFileUrl` opens as a PDF
- [ ] `ownerIdNumber` treated as sensitive
- [ ] `publicUrl: null` on pending units → no link

**Decisions:**
- [ ] Reject dialog with a required reason; copy says what to fix
- [ ] `409 CONFLICT` handled as "already decided" + refresh (§5)
- [ ] Queue **and** stats refetched after every decision
- [ ] Bulk: sequential, one page, per-row failures collected
- [ ] Buttons gated on `approvals.manage`

---

## 9. Testing it

**Staging** currently has **1 pending unit** (id `20`, "شقة بمكة شارع أجياد") with two real photos, a
tourism permit PDF, and an owner ID — the full-detail happy path.

Reproduce the errors safely — **none of these write anything**:

- `409 CONFLICT` → approve any already-approved unit
- `422 VALIDATION_ERROR` → reject with no `reason`
- `404 NOT_FOUND` → any unknown id
- `range` fallback → send `?range=bogus` and watch it echo `"range":"today"`

Note staging's `avgReviewSample` is **0** across every range: its seven decided units all predate
`submitted_at`. That is the exact state §3.1 describes, so it is the right environment to build the
caption against.

**Production** has 0 pending units — the queue renders empty, which is correct.

Backend suite covering this screen: **193 passed, 1089 assertions.**
