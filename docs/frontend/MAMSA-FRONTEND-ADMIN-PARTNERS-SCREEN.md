# Task: the Partners screen (Claude Code — Next.js admin panel)

**For:** a Claude Code agent building `/admin/partners` — the partner directory, KYC review and
partner lifecycle actions.
**Backend status:** ✅ **live on staging AND production**. Verified 2026-08-15.
**Read §7 before you design the actions** — there is one missing endpoint that shapes the UI.

Every payload and error below is a **real staging response**.

---

## 1. The endpoints

```
GET  /admin/partners?page=&pageSize=&search=&type=&status=&sortBy=&sortDir=
GET  /admin/partners/stats
GET  /admin/partners/{id}
POST /admin/partners/invite                        { phone, type, name? }
POST /admin/partners/{id}/approve
POST /admin/partners/{id}/reject                   { reason }
POST /admin/partners/{id}/suspend                  { reason }
POST /admin/partners/{id}/verify
POST /admin/partners/{id}/revoke-verification
POST /admin/partners/{partnerId}/documents/{documentId}/verify
```

Root-mounted, **no `/api/v1`**. Cookie session, `credentials: "include"`.

| | Permission | Finance role |
|---|---|---|
| the three `GET`s | `partners.view` | ✅ **has it** |
| every `POST` | `partners.manage` | ❌ no |

Finance can **read** the partner directory but perform no action on it. Gate every button on
`partners.manage` from `/admin/me`.

---

## 2. List — `GET /admin/partners`

```jsonc
{ "items": [ … ], "total": 7, "page": 1, "pageSize": 10 }
```

A real row:

```jsonc
{
  "id": "24",
  "code": "PTR-024",
  "name": "مناهل",
  "type": "company",                       // company | individual
  "city": "",
  "email": "mnahil@vego.sa",
  "phone": "+9665XXXXXXXX",
  "joinedAt": "2026-07-17T07:24:52+03:00",
  "unitsCount": 0,
  "bookingsCount": 0,
  "revenue": 0,
  "rating": 0,
  "verified": true,                        // the badge — NOT the KYC status
  "status": "active",                      // pending | rejected | suspended | active
  "isActive": true,
  "cancellations12m": 0,
  "cancellationRate": 0,
  "flagged": false                         // host-cancellation threshold breached
}
```

- [ ] **`verified` and `status` are different things** — see §5. A partner can be `active` and
      unverified, or verified and suspended.
- [ ] `flagged` marks a partner over the host-cancellation threshold. Worth a row-level marker; it is
      the signal that a partner is cancelling on guests.
- [ ] `city` is often `""` — render an em dash, not an empty cell.

**`status` is derived, in this order:**

```
partnerDetail.status = pending   → "pending"
partnerDetail.status = rejected  → "rejected"
user.is_active = false           → "suspended"
otherwise                        → "active"
```

So a KYC decision outranks suspension in the label. A rejected-then-suspended partner reads
`rejected`.

---

## 3. Stats — `GET /admin/partners/stats`

Real staging response:

```jsonc
{"total":7,"individuals":5,"companies":2,"active":7,"pending":0,
 "verified":1,"highRisk":1,"totalRevenue":240237.45}
```

- `verified` counts the **badge**, not KYC approval.
- `highRisk` counts partners over the cancellation threshold (same rule as `flagged`).
- `totalRevenue` is platform-wide gross across all partners.

No range parameter — these are always all-time/current.

---

## 4. Detail — `GET /admin/partners/{id}`

Everything from the list row, **plus**:

Real staging response for partner `4` (trimmed to the added fields):

```jsonc
{
  …row fields…,
  "nationalId": "1023456789",       // individual
  "crNumber": null,                 // company
  "tourismPermitNo": null,          // always null — the permit is per-unit, not per-partner
  "iban": null,
  "documents": [ … ],               // §6
  "documentsComplete": false,       // no IBAN on file → not complete
  "commissionPaid": 1771.65,        // Mamsa's cut from this partner
  "partnerEarning": 108454.35,      // 25 bookings, 110,226 gross
  "avgPerBooking": 4409.04,
  "rejectionReason": null           // set when status = rejected
}
```

Note `commissionPaid + partnerEarning` does **not** equal `revenue`: revenue is VAT-inclusive gross,
and VAT belongs to ZATCA, not to either party. Don't render the two as a split of the total.

- [ ] `tourismPermitNo` is **always `null`** here. Tourism permits belong to units — show them on the
      unit/approvals screen, not the partner profile. Don't build a field for it.
- [ ] `rejectionReason` is what the partner was told. Show it on a rejected profile so a reviewer
      handling a resubmission knows what was demanded.

---

## 5. `verified` vs `status` — two independent axes

This trips people up, so state it explicitly in the UI.

| | What it means | How it changes |
|---|---|---|
| **`status`** | KYC / account lifecycle | `approve`, `reject`, `suspend` |
| **`verified`** | a **trust badge** shown to guests | `verify`, `revoke-verification` |

They are stored separately and neither implies the other. On staging, 7 partners are `active` but only
**1** is `verified`.

- [ ] Render them as two distinct controls, not one status dropdown.
- [ ] Don't label the badge "verified partner" next to a `status: "pending"` chip without explanation —
      pick wording that separates "we approved their paperwork" from "we vouch for them publicly".

---

## 6. Documents — the KYC review block

```jsonc
[
  {"id":"national_id","kind":"national_id","label":"الهوية الوطنية",
   "fileUrl":"https://staging.mamsaa.com/storage/dashboard/national_id/file_01M0….png",
   "value":"1023456789","status":"verified"},
  {"id":"authorization_letter","kind":"authorization_letter","label":"خطاب تفويض",
   "fileUrl":null,"value":null,"status":"verified"},
  {"id":"iban","kind":"iban","label":"رقم الآيبان","fileUrl":null,"value":null,"status":"verified"}
]
```

The set differs by partner type:

| individual | company |
|---|---|
| `national_id` (number + **scan**) | `commercial_registration` (number) |
| | `vat_certificate` (file) |
| | `operator_license` (file) |
| `authorization_letter` (file) | `authorization_letter` (file) |
| `iban` (value) | `iban` (value) |

- [ ] Render `fileUrl` as an openable document — **it may be a PDF as well as an image**.
- [ ] `fileUrl: null` with a `value` means "a number was typed, there is nothing to look at".
      `value: null` **and** `fileUrl: null` means the partner never supplied it. Those should not look
      the same.

### 6.1 ⚠️ A "verified" document may never have been checked

`status` per document is **derived from the partner's KYC status** unless that specific document was
explicitly verified:

```
partner approved  → every document defaults to "verified"
partner rejected  → every document defaults to "rejected"
otherwise         → "pending_review"
```

`POST …/documents/{documentId}/verify` overrides one document to `verified` permanently.

**So an approved partner shows all documents as "verified" even if a reviewer never opened one** — the
example above says `verified` for an `iban` whose value is `null`.

- [ ] Do not present the badge as proof an individual document was inspected. Wording like "متوافق مع
      حالة الشريك" for the derived case, versus an explicit check mark for individually-verified ones,
      is more honest — the backend cannot currently distinguish the two in the response.
- [ ] **There is no per-document *reject*.** Only verify. To reject a document, reject the partner with
      a reason naming it.

### 6.2 `documentsComplete`

`true` only when the required set is present **and** the partner's KYC status is approved:

| type | required |
|---|---|
| individual | `national_id` + **`national_id_file`** + `iban` |
| company | `cr_number` + `iban` |

An individual is not complete on a typed ID number alone — the scan is what an admin actually reviews.
Expect older individual partners to read `false` until they upload one.

---

## 7. ⚠️ There is no un-suspend on this screen

`POST /{id}/suspend` sets a partner inactive. **Nothing on the partners surface reverses it.**

Reactivation today is `PATCH /admin/users/{id}/status` with `{ "status": "active" }` — a different
screen, a different permission (`users.manage`), and it does **not** clear the stored suspension
reason.

- [ ] **Do not build a suspend toggle.** A switch that only moves one way is worse than a one-way
      button; make suspension an explicit, confirmed action.
- [ ] On a suspended partner, either link to the user record or explain that reactivation happens on
      the users screen — don't leave a dead end.
- [ ] Suspend requires a `reason`, and it 409s unless the partner is currently **active and
      KYC-approved**.

**Tell us if you want `POST /{id}/reactivate`** — it is a small, clean addition (clearing the
suspension reason at the same time, which the users endpoint does not do). It was not in the original
contract, so it has not been built.

---

## 8. Actions and their errors

All actions return `{ ok: true }` and nothing else — **refetch the detail and the list afterwards.**

Live error bodies:

```jsonc
409 {"message":"طلب الشريك ليس قيد المراجعة","code":"CONFLICT"}           // approve/reject a non-pending partner
409 {"message":"لا يمكن إيقاف هذا الشريك في حالته الحالية","code":"CONFLICT"}  // suspend a non-active partner
409 {"message":"هذا الرقم مسجّل بالفعل","code":"CONFLICT"}                 // invite an existing phone
422 {"message":"يجب إدخال سبب الرفض","code":"VALIDATION_ERROR"}
404 {"message":"الشريك غير موجود","code":"NOT_FOUND"}                      // unknown id, or a user who is not a partner
```

| Action | Guard | Required body |
|---|---|---|
| `approve` | partner must be **pending** → else 409 | — |
| `reject` | partner must be **pending** → else 409 | `reason` (≤500) |
| `suspend` | must be **active + approved** → else 409 | `reason` (≤500) |
| `verify` / `revoke-verification` | none | — |
| `documents/{id}/verify` | none | — |
| `invite` | phone must not exist → else 409 | `phone`, `type`, `name?` |

- [ ] `approve` / `reject` only apply to **pending** partners. Hide those buttons entirely on an
      already-decided profile rather than showing a 409 on click.
- [ ] `409 CONFLICT` on approve/reject usually means someone else just decided it — refresh, don't
      retry.
- [ ] `invite` accepts `+9665XXXXXXXX`, `05XXXXXXXX` or `5XXXXXXXX` and sends an SMS.

---

## 9. Checklist

**List & stats:**
- [ ] `verified` and `status` shown as separate concepts (§5)
- [ ] `flagged` surfaced as a row marker
- [ ] Empty `city` renders as an em dash
- [ ] Stats have no range — don't add a range control

**Detail:**
- [ ] No field for `tourismPermitNo` (always null — it is per-unit)
- [ ] `rejectionReason` shown on rejected profiles
- [ ] `documentsComplete` explained by type (§6.2)

**Documents:**
- [ ] `fileUrl` opens (handle PDF **and** image)
- [ ] "number typed, no file" distinguished from "nothing supplied"
- [ ] Document `verified` badge not presented as proof of individual inspection (§6.1)
- [ ] No per-document reject control — it does not exist

**Actions:**
- [ ] Every button gated on `partners.manage`; finance sees read-only
- [ ] `approve` / `reject` hidden unless the partner is pending
- [ ] `reject` and `suspend` dialogs require a reason
- [ ] **No suspend toggle** — one-way action, with reactivation explained (§7)
- [ ] Refetch after every action (they return only `{ ok: true }`)

---

## 10. Testing it

**Staging** has 7 partners, all currently `approved`/`active`, 1 verified, 1 flagged as high-risk.

Reproduce the errors safely — **none of these write anything**:

- `409` approve non-pending → `approve` on partner `4`
- `409` suspend ineligible → `suspend` on a non-partner user id
- `409` duplicate invite → `invite` with `+966500000002`
- `422` → `reject` with no reason
- `404` → any unknown id

Partner `4` is the best detail fixture: individual, 25 bookings, with a real identity scan on the
`national_id` document (`fileUrl` populated) so the document viewer can be exercised end to end — and
`documentsComplete: false` because it has no IBAN, which is the more interesting of the two states.

Partner `24` (مناهل) is the only one with `verified: true`, for the badge-vs-status distinction in §5.

**Production** has 2 partners, both individuals with no documents uploaded — useful for the
"nothing supplied" empty states.
