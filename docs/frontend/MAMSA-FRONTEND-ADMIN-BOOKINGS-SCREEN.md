# Task: the Bookings screen (Claude Code — Next.js admin panel)

**For:** a Claude Code agent building `/admin/bookings` — the booking directory and detail view.
**Backend status:** ✅ **live on staging AND production**. Verified 2026-08-15.
**⚠️ A money field changed today — read §5 before rendering any figure.**

Every payload and error below is a **real staging response**.

---

## 1. The endpoints

```
GET /admin/bookings?page=&pageSize=&search=&status=&city=&partnerId=&unitId=&userId=&from=&to=&sortBy=&sortDir=
GET /admin/bookings/counts
GET /admin/bookings/stats
GET /admin/bookings/{id}
```

Root-mounted, **no `/api/v1`**. Cookie session, `credentials: "include"`.
Permission: **`bookings.view`** — held by superadmin **and finance**.

**This screen is entirely read-only.** There is no admin cancel, no refund, no status change. Refund
retries live on the cancellations screen (`POST /admin/cancellations/{id}/retry-refund`), not here.

---

## 2. List — `GET /admin/bookings`

```jsonc
{ "items": [ … ], "total": 59, "page": 1, "pageSize": 10 }
```

A real row:

```jsonc
{
  "id": "61",
  "code": "BKG-0061",
  "guestId": "6",
  "guestName": "نورة المستخدمة",
  "guestPhone": "+966500000004",
  "unitId": "2",
  "unitName": "شقة مودرن بإطلالة على الواجهة",
  "unitCity": "الرياض",
  "partnerId": "4",
  "partnerName": "محمد الشريك الفردي",
  "checkIn": "2026-09-10T00:00:00+03:00",
  "checkOut": "2026-09-12T00:00:00+03:00",
  "nights": 2,
  "guests": 2,
  "total": 900,                       // VAT-INCLUSIVE gross — see §5
  "commission": 15.65,
  "partnerShare": 766.96,
  "nightlyRate": 450,
  "paymentMethod": "card",
  "paymentStatus": "paid",            // paid | refunded | …
  "moyasarRef": "test_invoice_demo",
  "status": "confirmed",              // pending_payment | confirmed | completed | cancelled
  "createdAt": "2026-08-15T11:11:14+03:00",
  "mamsaOwned": false
}
```

### Filters — all verified live

| Param | Example | Notes |
|---|---|---|
| `status` | `cancelled` → 8 | one of the four literals |
| `city` | `الرياض` → 36 | exact match on the unit's city |
| `partnerId` | `4` → 25 | bookings across all that partner's units |
| `unitId` / `userId` | | exact ids |
| `from` / `to` | `2026-09-01` → 1 | filters on **check-in date**, not creation |
| `search` | `61` → 1 | booking **id** (digits), guest name, guest phone, or unit name |
| `sortBy` | `total` · `checkIn` · `createdAt` | anything else ignored |
| `sortDir` | `asc` \| `desc` | default `desc` |

Default order: `createdAt` descending — newest bookings first.

- [ ] `from`/`to` filter **check-in**, not booking date. Label the date range control accordingly, or
      an admin looking for "bookings made last week" will get the wrong set.
- [ ] `search` matches a bare booking **id**, not the display `code`. Searching `BKG-0061` finds
      nothing; searching `61` finds it. Either strip the prefix client-side before sending, or label
      the box "رقم الحجز أو اسم الضيف".

---

## 3. Counts — `GET /admin/bookings/counts`

```jsonc
{"all":59,"pending_payment":0,"confirmed":1,"completed":50,"cancelled":8}
```

The four statuses sum to `all`. Ideal for tab badges above the table. No parameters — always global,
never filtered by whatever the table is currently showing.

- [ ] Don't recompute tab counts from the current page — use this endpoint.
- [ ] `pending_payment` is a real status (a started-but-unpaid booking). It is `0` on staging, not
      absent.

---

## 4. Stats — `GET /admin/bookings/stats`

```jsonc
{"totalRevenue":240237.45,"commission":3892.35,"avgBookingValue":4710.54}
```

Computed over **revenue-bearing** bookings (confirmed + completed) — cancelled ones are excluded, so
these will not reconcile against `counts.all`. No range parameter.

---

## 5. ⚠️ The money model — `total` is gross, and `partnerShare` is NOT `total − commission`

**This was a live defect, fixed today.** The API previously derived `partnerShare = total − commission`,
which handed the partner the VAT. On the real booking above:

| | Wrong (before) | Correct (now) |
|---|---|---|
| `total` (gross) | 900.00 | 900.00 |
| `commission` | 15.65 | 15.65 |
| **`partnerShare`** | **884.35** ❌ | **766.96** ✅ |

The 117.39 difference is the VAT, which is remitted to ZATCA and was never the partner's money. An
admin quoting 884.35 would have contradicted what the wallet actually transfers.

The real arithmetic:

```
total (gross)  = 900.00
netBase        = 900 / 1.15   = 782.61      ← not exposed on this endpoint
vat            = 900 − 782.61 = 117.39      ← ZATCA's
commission     = 782.61 × 2%  = 15.65       ← Mamsa's
partnerShare   = 782.61 − 15.65 = 766.96    ← the partner's, frozen per booking
```

- [ ] **Never compute `partnerShare` client-side.** It is a frozen per-booking column; derive nothing
      from `total`.
- [ ] **`total ≠ commission + partnerShare`.** The remainder is VAT. If you show all three together,
      say so, or the row looks like it does not add up.
- [ ] The VAT and net-base figures are **not** on this endpoint. For a full VAT breakdown use the tax
      invoice endpoint on the booking.

---

## 6. Detail — `GET /admin/bookings/{id}`

Everything from the row, **plus** `policySnapshot` and `timeline`.

### 6.1 `policySnapshot` — frozen at payment time

```jsonc
{"name":"moderate","capturedAt":"2026-08-15T11:11:14+03:00","tiers":[]}
```

The cancellation policy **as it was when the guest paid** — not the unit's current policy. That is the
point of it: a partner changing policy later cannot retroactively alter what a guest agreed to.

- [ ] `tiers` can be `[]` on older bookings whose snapshot predates tier capture. Render "no tier
      detail recorded" rather than an empty table.
- [ ] Label it explicitly as the policy at time of payment.

### 6.2 `timeline` — the lifecycle

```jsonc
[
  {"id":"1","label":"إنشاء الحجز","at":"2026-08-15T11:11:14+03:00","state":"done"},
  {"id":"2","label":"تأكيد الدفع","at":"2026-08-15T11:11:14+03:00","state":"done"},
  {"id":"3","label":"تسجيل الوصول","at":"2026-09-10T00:00:00+03:00","state":"current"},
  {"id":"4","label":"تسجيل المغادرة","at":"2026-09-12T00:00:00+03:00","state":"current"}
]
```

`state` ∈ `done` | `current` | `cancelled`. Labels are Arabic and server-authored — render as-is.

- [ ] **Future steps carry `state: "current"`, not `"upcoming"`.** Both check-in and check-out above
      are dated in the future and both read `current`. Don't rely on `state` alone to find "where we
      are now" — compare `at` against the clock if you need a single active step.
- [ ] A cancelled booking marks the affected steps `cancelled`.

---

## 7. Errors

Flat admin envelope `{ message, code }`, Arabic messages.

| Status | `code` | When |
|---|---|---|
| `404` | `NOT_FOUND` — `"الحجز غير موجود"` | unknown id |
| `403` | `INSUFFICIENT_PERMISSION` | lacks `bookings.view` |
| `401` | `UNAUTHENTICATED` | session expired |

No 409s — nothing here mutates.

---

## 8. Checklist

**List:**
- [ ] Date range labelled as **check-in**, not booking date (§2)
- [ ] Search accepts a bare id; `BKG-` prefix stripped or the label adjusted (§2)
- [ ] Sort offered only on `total`, `checkIn`, `createdAt`
- [ ] Default newest-first preserved

**Counts & stats:**
- [ ] Tab badges from `/counts`, not from the current page (§3)
- [ ] `pending_payment` tab present even at 0
- [ ] Stats explained as revenue-bearing only — they won't match `counts.all` (§4)

**Money:**
- [ ] `partnerShare` rendered from the API, **never** computed (§5)
- [ ] `total ≠ commission + partnerShare` explained, or the three not shown as a split (§5)

**Detail:**
- [ ] `policySnapshot` labelled as the policy at payment time (§6.1)
- [ ] `tiers: []` renders "no tier detail recorded"
- [ ] Timeline labels rendered as-is; `current` not treated as "the one active step" (§6.2)

**General:**
- [ ] No cancel/refund/status controls — the screen is read-only (§1)
- [ ] Finance sees this screen; nothing to gate beyond `bookings.view`

---

## 9. Testing it

**Staging** has 59 bookings: 1 confirmed, 50 completed, 8 cancelled, 0 pending_payment.

| Fixture | Use it for |
|---|---|
| booking `61` | the money model — gross 900, commission 15.65, partnerShare 766.96 (§5), plus a full 4-step timeline |
| `?status=cancelled` (8) | cancelled rows and their timelines |
| `?partnerId=4` (25) | the partner-filtered view |
| `?city=الرياض` (36) | city filter |

**Production** has 2 bookings and no completed stays.

Backend suite: **194 passed, 1094 assertions** — including a regression test pinning that
`partnerShare` excludes VAT and never equals `total − commission`.
