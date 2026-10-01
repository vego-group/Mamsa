# Mamsa backend — everything that changed, and where it is now

**From:** backend · **Updated:** 2026-08-29 *(supersedes the 2026-08-28 version)*
**For:** all three Next.js apps — `mamsa-app` (www) · `mamsa-partner-dashboard` · `mamsa-admin-dashboard`

One document replacing twenty. Every claim below was **re-checked against production today**, not
carried forward from an earlier reply — stale status lines in these documents have three times
nearly caused a working feature to be reverted, so the discipline is: verify, then write.

**Everything here is live on production and staging.** Nothing is pending. The open items are in §9
and neither is code; §10 covers the one visible number that changed.

*Last verified 2026-08-29 after the cancellation-rate and SQL-portability rounds. The staging ledger
rebuild is **done**, not pending — earlier status tables in three of my replies said otherwise and
have been corrected in place.*

---

## 0. If you read one section

| you may still be doing this | stop — see |
|---|---|
| deriving commission by multiplying a total | rate is **10%**, frozen per booking, and returned. §5 |
| treating `price_per_night` as pre-VAT | it is **VAT-inclusive**. §4 |
| computing VAT as `gross × 0.15` yourself | read `taxes` — ours is derived by subtraction so it can't drift. §4 |
| ignoring `meta` on `GET /units` | paging works and ordering is stable. Anyone with >12 units was invisible past page 1. §2 |
| assuming `start_date`/`end_date` filtered | they were **ignored until 2026-08-27**. They work now. §2 |
| reading `cancellation_policy` as a refund promise | it used to return a dead value. §3 |
| showing a 404 `message` to a user | it leaked our model names. §3 |
| one image URL at five sizes | `variants` + `width`/`height`. §6 |
| disabling calendar days through a booking's `end_date` | `blocked-dates` returns **nights** — `end` is a day earlier. §2 |
| deriving a cancellation's partner impact from `bookingTotal` | that total is VAT-inclusive. The frozen split is on the row now. §5 |

---

## 1. Base URLs and envelopes

```
production   https://api.mamsaa.com
staging      https://staging.mamsaa.com
```

| surface | mount | auth | error shape |
|---|---|---|---|
| guest app | `/api/v1/*` | Bearer | `{ message, code }` |
| partner dashboard | root (`/me`, `/units`, …) | cookie session | `{ error: { code, message, fields? } }`, validation **400** |
| admin console | `/admin/*` | cookie session | `{ message, code, fields? }`, validation **422** |

Field naming follows the surface: `/api/v1` is snake_case, the two dashboards are camelCase.

---

## 2. Search, availability and the calendar

### `GET /units`

```
?city=riyadh&start_date=2026-09-05&end_date=2026-09-08&sort=price_asc&per_page=24&page=2&ids[]=34
```

| parameter | notes |
|---|---|
| `start_date` + `end_date` | excludes units with a conflicting booking (`confirmed` **or** `pending_payment`), a partner closure, or an iCal block. **Send both or neither — one alone is `422`** |
| `sort` | `price_asc` · `price_desc` · `rating` · `newest`. Unknown/absent → featured first, then newest |
| `per_page` | 1–50, default 12 · `page` standard |
| `city` | slug (`riyadh`), English (`Riyadh`) or Arabic (`الرياض`) |
| `ids[]` | max 50. Unpublished units stay hidden even when named by id |

**Ordering is deterministic.** There was previously no `ORDER BY` at all, so paging could show one
unit twice and never show another. Every sort ends with `id`.

`meta` is stable: `{ current_page, last_page, per_page, total }`.

### `GET /units/{id}/blocked-dates?from=&to=`

Unauthenticated. `from` defaults to today, `to` to +6 months, capped at 400 days, ranges merged.

⚠️ **`end` is the last unavailable NIGHT, not the booking's end date.** A booking of 10-05 → 10-10
returns `end: 10-09`, because the 10th is the changeover day and is bookable. Disable
`start … end` inclusive and the calendar matches the booking endpoint exactly.

### Changeover days and double-booking

A stay occupies nights `[start, end)` — the changeover day belongs to the arriving guest. This was
once *inconsistent*: an arrival on the 15th was refused while a departure on the 10th was allowed.

`POST /bookings` now checks and inserts inside a transaction behind a row lock. Proven with 8
parallel requests: **1 created, 7 refused, 1 row**. Keep your checkout check — someone can still
book while your guest fills the form. The two refusals are distinguishable:

```
"الوحدة محجوزة في هذه الفترة"      another booking
"الوحدة غير متاحة في هذه الفترة"    the partner closed the dates
```

### Staging fixture for end-to-end testing

```
staging · unit 2 · confirmed booking 2026-10-05 → 2026-10-10   (permanent)
```

All three surfaces agree on every boundary: search hides it for `10-06→10-08`, offers it for
`10-10→10-12`, the calendar returns nights `10-05…10-09`, the probe matches.

---

## 3. Fields and error shapes

**On the unit resource:** `created_at` (ISO 8601) and `owner` on the **list**, not just the detail —
the list never loaded the relation, so every card showed a blank host and an unlit badge.

**`cancellation_policy` means something now.** It used to echo a dead enum (`no_cancel` /
`48_hours`) the refund engine never read, so using it as a pre-payment fallback showed a refund
schedule that would never be honoured. It now carries the **effective preset key**, always equal to
`cancellation_policy_details.template`.

**The complete vocabulary is three values** — verified on production today:

```
flexible · moderate · strict
```

Never null: a unit that never chose one inherits the platform default and the field reports what
would be enforced.

**404s no longer leak internals:**

```
before   {"message":"No query results for model [App\\Models\\Unit] 999999"}
now      {"message":"المورد غير موجود","code":"NOT_FOUND"}
```

Unhandled 500s return `{ message, code: "SERVER_ERROR" }`. **Validation and auth shapes are
unchanged.**

**On the booking resource:** `user_id`, `guest_name` (always populated now) and
`guests_detail: { adults, children }` beside the scalar `guests` total. `guests` deliberately stays
a number — the partner and admin consoles read it as one.

---

## 4. Price basis — `price_per_night` is VAT-INCLUSIVE

`Pricing::breakdown(1000, nights: 1)`, run on production today:

```
total              1000.00     ← what the guest pays. Nothing is added at checkout
subtotal            869.57     ← = 1000 / 1.15
vat                 130.43
commission           86.96     ← 10% of the NET BASE, never of the gross
partner_share       782.61

subtotal + vat = 1000.00       commission + share = 869.57
```

⚠️ **Don't compute the VAT yourself.** `subtotal` comes from a division and `vat` from a
**subtraction**, so `subtotal + vat === total` holds *exactly*. Computing `gross × 0.15`
independently will occasionally land a halala away from the invoice. Read `pricing.total`,
`pricing.subtotal`, `pricing.taxes`; `tax_percent` is on the unit resource so nobody hardcodes 15.

A display-time estimate before the quote returns is fine. Just don't use your own figure once the
server has given you one.

**Partner-facing:** a partner typing 360 is setting what the *guest* pays. At 10% they receive
`360/1.15 − 10% = 281.74` per night. Worth stating in the partner UI.

---

## 5. Commission — 10%

```
live rate 0.10          legacy 0.02 (reconstructs pre-freeze rows only — never merge them)
```

- **Frozen per booking.** A rate change never re-prices an existing booking.
- **Returned, so stop deriving it:** `commission_rate` on `/api/v1/bookings/{id}` (admin/owner only
  — a guest never sees the platform's margin), `commissionRate` on the partner dashboard and
  `/admin/bookings/{id}`.
- **Reports aggregate per row.** Three admin pages once applied one rate to a total; on a single 10%
  booking that under-reported by 800 SAR.
- **A zero commission is reported as zero.** Nothing imputes any more — a fallback that guessed
  couldn't tell a legitimate zero from an unwritten one.

### Cancellation rows carry the frozen split

`/admin/cancellations` (camelCase) and `/api/v1/admin/cancellations` (snake_case):

```jsonc
{ "bookingTotal": 1150.00,   // VAT-INCLUSIVE — do not derive a split from this
  "netBase": 1000.00, "commission": 100.00, "partnerShare": 900.00,
  "commissionRate": 0.10,    // the rate this row was FROZEN at
  "impact": -100.00 }        // unchanged: commission, negated
```

A booking frozen at the old 2% reports **20**, never 100 — the rate travels with the booking.

**`commissionRate` is on the row too** (`commission_rate` in snake_case). Without it a console shows
frozen money beside a rate badge read from a local constant: 20 SAR under a "(10%)" label. Do **not**
derive it as `commission / netBase` — commission is stored rounded to 2dp, so that division returns
`0.10000345`, never `0.10`, and the drift grows as the booking shrinks.

On a **Mamsa-owned** unit the frozen rate is **`1.0`** — the platform keeps the whole net base because
there is no partner to pay. Not `0` (which reads as "no commission") and not `null` (which breaks
`commission === rate × netBase`).

---

## 6. Images

Each image on `GET /units` and `/units/{id}`:

```jsonc
{ "id": 91, "url": "…", "is_main": true, "width": 1600, "height": 1200,
  "variants": { "thumb": "…_thumb.webp", "card": "…_card.webp", "full": "…_full.webp" } }
```

| key | box | fit |
|---|---|---|
| `thumb` | 400×300 | cover (4:3 crop) |
| `card` | 800×600 | cover (4:3 crop) |
| `full` | 2048 long edge | contain — never cropped |
| `url` | original | untouched, not deprecated |

- **`variants` is `null`, never a fake** — your signal to fall back to `url`.
- **Never upscaled.** A 432×768 portrait asked for `card` returns 432×324.
- **`width`/`height` describe the ORIGINAL**, which shares its aspect with `full` and nothing else.
  Put them on the `full` `<img>` only — `thumb` and `card` are always 4:3, so size those in CSS.

**Upload rules:** jpeg · png · webp · **heic** (converted on receipt) · min long edge 1024 / short
edge 576 · max 10 MB · aspect ratio **not** enforced · EXIF orientation baked in and **all metadata
stripped**.

That last one closed a live data leak: photos were stored as raw bytes, so a partner's phone photo
published the property's GPS coordinates.

---

## 7. Text fields

- **`description` limit is 2000 characters** (the old 500 was a guess nobody confirmed), counted in
  **characters**, not bytes.
- **`strip_tags` removed** from `description`, `name`, `district`, `address`. It was not a
  sanitiser: `<` followed by anything but a space deleted everything through the next `>`. Since `>`
  opens a note line, `"شروط <= ثلاثة\n> ملاحظة"` stored as `"شروط  ملاحظة"`, and
  `"<200م من المسجد"` became `""` — corrupted in the column, not the render.
- Newlines and every marker (`#` `##` `*` `**` `-` `>` `»` `•` `–` `—`) round-trip byte for byte.
- **The 10-character minimum gates submit, not save.**

**Clearing an optional field**, one rule:

| body | effect |
|---|---|
| key absent | unchanged |
| `null` or `""` (text) | cleared |
| `[]` or `null` (arrays) | cleared |
| `["wifi"]` | replaces the whole set |

⚠️ **`photoFileIds` exception:** photos with no `fileId` (predating the upload flow) are
**preserved** across any `photoFileIds` write — they can't appear in a list you send, so deleting
them would answer a limit of the request format rather than intent. Zero such rows exist anywhere.

---

## 8. Endpoints added

| | |
|---|---|
| `GET /units/{id}/blocked-dates` | calendar feed, unauthenticated |
| `GET /units?ids[]=` | fetch a known set, max 50 |
| `GET /units/sitemap` | `{id, updated_at}[]`, unauthenticated, unpaginated |
| `GET /bookings/{id}/review` | the object, or a bare `null` at 200 — unreviewed is not a 404 |

---

## 9. Two things open, neither of them code

**`Vary: Accept`.** Production sits behind Hostinger's CDN, which content-negotiates images — the
same `.jpg` URL is served as WebP to a browser that accepts it, with a 7-day cache. The **origin now
sends `Vary: Accept`**, verified; **the edge strips it**. Any cache between the edge and the user
could store one format and serve it to everyone. Closing it needs a Hostinger ticket.

*Measure against production with a browser `Accept` header, or you're measuring the CDN's JPEG path
rather than the one your users take.*

**A flaky test.** `SuspendedAccountTest` reaches the live FGC SMS gateway and fails auth
non-deterministically; it passes on re-run. Affects no endpoint. Flagged because a red CI run that
means nothing is one people learn to ignore.

---

## 10. One visible number changed — `avg_review_hours`

Not a regression. The old value was computed with `TIMESTAMPDIFF(HOUR, …)`, which **truncates**: a
14.2-hour gap was reported as 14. It now uses `MINUTE/60`.

On staging the admin figure moved **883.3 → 884.1**. On production it is still 0 (no review data),
so the shift will appear there the first time reviews land. If you compare the screen across that
point, the increase is the correction, not a fault.

Two things this did **not** touch, both checked rather than assumed:

- **Your SLA thresholds.** There is no breach calculation in the backend at all. The approval row
  carries only `submittedAt`, a full ISO timestamp to the second with offset
  (`2026-07-17T12:32:36+03:00`), and no hours/SLA/wait/breach key. The truncated expression had
  exactly two consumers, both display averages — and the admin-panel one never used it.
- **Every other number.** 22 lines of real staging output compared before and after the refactor:
  21 identical, and the only line that moved was the one above.

⚠️ Since the SLA is computed on your side, the risk there is the `+03:00` offset being read as UTC —
a three-hour error against a 48-hour threshold, far larger than anything discussed here.

---

## 10. Not built, and openly so

| | why |
|---|---|
| `first_name` / `last_name` | two columns, a migration to split existing names, every write path |
| `avatar_url` on the user | no avatar storage exists; it would be null forever |
| image `alt` | needs a partner form field. **Generate it your side** — if it ships, the key will be additive |
| guest ↔ host messaging | deferred by the www team |
| booking on someone else's behalf | deferred; the account is the guest |
| cancellation contract (`total_amount`, `forfeited_amount`, `tier_label` as data, `cancelled_by` values) | agreed to do as one coherent piece |
| commission proration on refunds | nothing to prorate — commission is realised only at `completed`, and a cancelled booking never gets there |

---

## 11. Verified on production today

```
Pricing::breakdown(1000,1)   total 1000 · subtotal 869.57 · vat 130.43
                             commission 86.96 · partner_share 782.61
commission                   live 0.10 · legacy 0.02
cancellation policies        flexible, moderate, strict
description max              2000
unit resource                created_at ✓ · owner ✓ · cancellation_policy "moderate"
image keys                   height · id · is_main · url · variants · width
404                          {"message":"المورد غير موجود","code":"NOT_FOUND"}
consistency check            checked 0 / 0 · skipped 0
repo vs production           300 files · 0 differing · 0 missing
```

Backend suite: **409 passed, 1807 assertions.**

---

## 12. Where the detail lives

| topic | document |
|---|---|
| images contract, and the CDN measurement | `MAMSA-BACKEND-REPLY-unit-images.md`, `-2.md` |
| description limits and `strip_tags` | `MAMSA-BACKEND-REPLY-description-formatting.md`, `-followup.md` |
| double-booking, changeover days, `blocked-dates` | `MAMSA-BACKEND-REPLY-booking-availability.md` |
| search, pagination, error shapes | `MAMSA-BACKEND-REPLY-open-requests.md` |
| `ids[]`, sitemap, booking review, staging fixture | `MAMSA-BACKEND-REPLY-gaps-shipped.md` |
| commission, rounds 1–5 | `MAMSA-BACKEND-REPLY-commission-v2.md` … `-v6.md` |
| price / VAT basis | `MAMSA-BACKEND-REPLY-price-vat-basis.md` |
| cancellation row split | `MAMSA-BACKEND-REPLY-cancellation-split.md` |
| staging ledger rebuild | `MAMSA-BACKEND-REPORT-ledger-reseed.md` |
| admin unit form, map-pin input bug | `MAMSA-FRONTEND-ADMIN-UNIT-EDIT.md`, `MAMSA-FRONTEND-ADMIN-LOCATION-INPUT.md` |
| running a staging copy of your app | `MAMSA-FRONTEND-STAGING-DEPLOY.md` |
