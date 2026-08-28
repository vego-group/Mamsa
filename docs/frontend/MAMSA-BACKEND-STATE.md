# Mamsa backend — everything that changed, and where it is now

**From:** backend · **Date:** 2026-08-28
**For:** all three Next.js apps — `mamsa-app` (www) · `mamsa-partner-dashboard` · `mamsa-admin-dashboard`

One document replacing eleven. Everything below is **verified against production today**, not
copied from an earlier reply — three of you have nearly reverted working features because a status
line in one of my docs had gone stale, so every claim here was re-checked before it was written.

**Everything in this document is live on production and on staging.** Two items are explicitly
marked open at the end; nothing else is pending.

---

## 0. If you read one section

| you were doing this | stop, because |
|---|---|
| deriving commission by multiplying a total | the rate changed 2% → **10%**, is frozen per booking, and is now returned. §5 |
| ignoring `meta` on `GET /units` | `?page=`/`?per_page=` work and ordering is stable. Anyone with >12 units was invisible past page 1. §2 |
| sending `start_date`/`end_date` and assuming they filtered | they were **ignored until 2026-08-27**. They work now. §2 |
| reading `cancellation_policy` as a refund promise | it used to return a dead value. Now the effective preset. §4 |
| showing `message` from a 404 to a user | it leaked our model names. Now a generic message + `code`. §4 |
| using one image URL at five sizes | `variants` + `width`/`height` shipped. §3 |
| disabling calendar days up to a booking's `end_date` | `blocked-dates` returns **nights** — `end` is one day earlier. §2 |

---

## 1. Base URLs

```
production   https://api.mamsaa.com
staging      https://staging.mamsaa.com
```

Three surfaces, three envelopes — unchanged:

| surface | mount | auth | error shape |
|---|---|---|---|
| guest app | `/api/v1/*` | Bearer | `{ message, code }` |
| partner dashboard | root (`/me`, `/units`, …) | cookie session | `{ error: { code, message, fields? } }`, validation = **400** |
| admin console | `/admin/*` | cookie session | `{ message, code, fields? }`, validation = **422** |

---

## 2. Search, availability and the calendar

### `GET /units` — three parameters used to be accepted and ignored

That is worse than rejecting them, because you built promises on top. All three work now.

```
GET /units?city=riyadh&start_date=2026-09-05&end_date=2026-09-08&sort=price_asc&per_page=24&page=2
```

| parameter | notes |
|---|---|
| `start_date` + `end_date` | excludes units with a conflicting booking (`confirmed` **or** `pending_payment`), a partner closure, or an imported iCal block. **Send both or neither — one alone is a `422`** |
| `sort` | `price_asc` · `price_desc` · `rating` · `newest`. Unknown/absent → featured first, then newest ("موصى به") |
| `per_page` | 1–50, default 12 |
| `page` | standard |
| `city` | slug (`riyadh`), English (`Riyadh`) or Arabic (`الرياض`) all work |
| `ids[]` | max 50. Unpublished units stay hidden even when named by id |

**Ordering is now deterministic.** There was previously no `ORDER BY` at all — not an unstable
sort, none — so paging could show one unit twice and never show another. Every sort ends with `id`.

`meta` is stable: `{ current_page, last_page, per_page, total }`.

### `GET /units/{id}/blocked-dates?from=&to=`

Unauthenticated. `from` defaults to today, `to` to +6 months, window capped at 400 days. Ranges are
merged.

```jsonc
{ "from": "…", "to": "…", "blocked": [ { "start": "2026-10-05", "end": "2026-10-09" } ] }
```

⚠️ **`end` is the last unavailable NIGHT, not the booking's end date.** That example is a real
booking of **10-05 → 10-10**: it returns `end: 10-09` because the 10th is the changeover day and is
bookable. Disable `start … end` inclusive and the calendar agrees with the booking endpoint exactly.

### Changeover days

A stay occupies nights `[start, end)`. A booking of 10→15 uses the nights of the 10th–14th; the
15th is free for the next guest. This used to be **inconsistent** — an arrival on the 15th was
refused while a departure on the 10th was allowed, the same situation answered two ways.

### Double-booking is now prevented at the database level

`POST /bookings` always re-checked availability, but outside any transaction or lock — two requests
could both read "free" and both succeed. The check and the insert now run in one transaction behind
a row lock. Proven with 8 genuinely parallel requests: **1 created, 7 refused, 1 row**.

**Keep your checkout check.** Someone can still book while your guest fills in the form. The two
refusals are distinguishable:

```
"الوحدة محجوزة في هذه الفترة"      another booking
"الوحدة غير متاحة في هذه الفترة"    the partner closed the dates
```

### A staging fixture for testing this end to end

```
staging · unit 2 · confirmed booking 2026-10-05 → 2026-10-10
```

Permanent — it will not be cleaned up. All three surfaces agree on every boundary: search hides it
for `10-06→10-08`, offers it for `10-10→10-12`, the calendar returns nights `10-05…10-09`, and the
probe matches.

---

## 3. Images

`GET /units` and `/units/{id}` — each image now carries:

```jsonc
{ "id": 91, "url": "…", "is_main": true,
  "width": 1600, "height": 1200,
  "variants": { "thumb": "…_thumb.webp", "card": "…_card.webp", "full": "…_full.webp" } }
```

| key | box | fit |
|---|---|---|
| `thumb` | 400×300 | cover (4:3 crop) |
| `card` | 800×600 | cover (4:3 crop) |
| `full` | 2048 long edge | contain — never cropped |
| `url` | original | untouched, not deprecated |

- **`variants` is `null`, never a fake.** A photo without derivatives returns null rather than three
  copies of the original — that is your signal to fall back to `url`.
- **Never upscaled.** A 432×768 portrait asked for `card` comes back 432×324, not blown up.
- **`width`/`height` describe the ORIGINAL**, which shares its aspect with `full` and nothing else.
  Put them on the `full` `<img>` only. `thumb` and `card` are always 4:3 — size those in CSS.

**Upload rules** (partner + admin consoles):

| | |
|---|---|
| formats | jpeg · png · webp · **heic** (converted to JPEG on receipt) |
| min resolution | long edge ≥1024, short edge ≥576 — orientation-agnostic, so portraits are not auto-rejected |
| max size | 10 MB |
| aspect ratio | **not enforced** |
| EXIF | orientation baked into the pixels, then **all metadata stripped** |

That last one closed a live data leak: photos were stored as raw bytes, so a partner's phone photo
published the property's GPS coordinates to anyone with a metadata viewer.

---

## 4. Fields and error shapes

**New on the unit resource:**

- `created_at` — ISO 8601. `newest` sorting is impossible without it.
- `owner` on the **list**, not just the detail. It existed on `/units/{id}` but the list never
  loaded the relation, so every card showed a blank host and an unlit verification badge.

**`cancellation_policy` now means something.** It used to echo a dead enum column (`no_cancel` /
`48_hours`) that the refund engine never read — so using it as a pre-payment fallback showed a
refund schedule that would never be honoured. It now carries the **effective preset key**, always
equal to `cancellation_policy_details.template`.

**The complete vocabulary is three values:**

```
flexible · moderate · strict
```

Not `24_hours`, `7_days` or `non_refundable` — those do not exist here. Never null: a unit that
never chose one inherits the platform default and the field reports what would be enforced.

**404s no longer leak internals.**

```
before   {"message":"No query results for model [App\\Models\\Unit] 999999"}
after    {"message":"المورد غير موجود","code":"NOT_FOUND"}
```

Unhandled 500s return `{ message, code: "SERVER_ERROR" }`. **Validation and auth shapes are
unchanged** — you already parse those.

**On the booking resource:** `user_id`, `guest_name` (now always populated) and
`guests_detail: { adults, children }` alongside the scalar `guests` total.

`guests` deliberately stays a number: the partner and admin consoles read it as one, and renaming it
to an object would break both to save the storefront a line.

---

## 5. Commission — 2% → 10%

**The guest price does not change.** Commission comes out of the partner's share of the net base:

```
base 1000  →  guest pays 1150 · VAT 150 · commission 100 · partner 900
```

- **Frozen per booking.** A rate change never re-prices an existing booking.
- **Returned, so stop deriving it:** `commission_rate` on `/api/v1/bookings/{id}` (admin/owner only
  — a guest never sees the platform's margin), `commissionRate` on the partner dashboard and
  `/admin/bookings/{id}`.
- **Reports aggregate per row**, never one rate applied to a total. Three admin pages used to get
  this wrong; on a single 10% booking the old expression under-reported by 800 SAR.

Not on `/partner/ledger` or `/payouts/summary`: a ledger entry is a money movement, not a booking —
a payout spans many and an adjustment has no rate at all.

---

## 6. Text fields

- **`description` limit is 2000 characters** (was a guessed 500 that nobody ever confirmed).
  Counted in **characters**, not bytes.
- **`strip_tags` removed** from `description`, `name`, `district` and `address`. It was not a
  sanitiser: a `<` followed by anything but a space deleted everything through the next `>`. Since
  `>` opens a note line in your markup, `"شروط <= ثلاثة\n> ملاحظة"` was stored as `"شروط  ملاحظة"` —
  corrupted in the column, not the render. `"<200م من المسجد"` became `""`.
- Newlines and every marker (`#` `##` `*` `**` `-` `>` `»` `•` `–` `—`) round-trip byte for byte.
- **The 10-character minimum gates submit, not save** — a draft may hold nothing.

**Clearing an optional field**, one rule everywhere:

| body | effect |
|---|---|
| key absent | unchanged |
| `null` or `""` (text) | cleared |
| `[]` or `null` (arrays) | cleared |
| `["wifi"]` | replaces the whole set |

⚠️ **`photoFileIds` is the exception worth knowing.** Photos with no `fileId` (predating the upload
flow) are **preserved** across any `photoFileIds` write — they cannot appear in a list you send, so
deleting them would answer a limit of the request format rather than anyone's intent. There are
currently zero such rows anywhere.

---

## 7. Two things still open

**1. `Vary: Accept` — half fixed, and the other half is not ours.**

Production sits behind Hostinger's CDN, which content-negotiates images: the same `.jpg` URL is
served as WebP to a browser that accepts it and as JPEG otherwise, with a 7-day cache. The origin
now sends `Vary: Accept` — verified — but **the edge strips it**. Any cache between the edge and the
user could therefore store one format and serve it to everyone. Closing it needs a Hostinger support
ticket, not a config change.

Measure against production with a browser `Accept` header, or you are measuring the CDN's JPEG path
rather than the one your users take.

**2. The staging partner ledger still holds the old 98% shares.** 50 earning entries at the old rate
sit under a recorded payout of 87,800 SAR; regenerating at 90% could drive a balance negative.
Three options are on the table and it needs a decision — production is unaffected (zero bookings).

---

## 8. Not built, and openly so

| | why |
|---|---|
| `first_name` / `last_name` | two columns, a migration to split existing names, every write path. Needs a slot, not a patch |
| `avatar_url` on the user | no avatar storage exists; the field would be null forever |
| image `alt` | needs a partner form field. **Generate it your side** — if it ships, the API key will be additive |
| guest ↔ host messaging | a feature, not a field: table, read permissions, notification decision |
| cancellation contract (`total_amount`, `forfeited_amount`, `tier_label` as data, `cancelled_by` values) | agreed to do as one coherent piece |
| booking on someone else's behalf | deferred by the www team; the account is the guest |
| commission proration on refunds | nothing to prorate — commission is only realised at `completed`, and a cancelled booking never gets there |

---

## 9. Where the detail lives

| topic | document |
|---|---|
| images contract + the CDN measurement | `MAMSA-BACKEND-REPLY-unit-images.md`, `-2.md` |
| description limits and `strip_tags` | `MAMSA-BACKEND-REPLY-description-formatting.md`, `-followup.md` |
| double-booking, changeover days, `blocked-dates` | `MAMSA-BACKEND-REPLY-booking-availability.md` |
| search, pagination, error shapes | `MAMSA-BACKEND-REPLY-open-requests.md` |
| `ids[]`, sitemap, booking review, staging fixture | `MAMSA-BACKEND-REPLY-gaps-shipped.md` |
| commission reconciliation | `MAMSA-BACKEND-REPLY-commission-v2.md` |
| admin unit form + the map-pin input bug | `MAMSA-FRONTEND-ADMIN-UNIT-EDIT.md`, `MAMSA-FRONTEND-ADMIN-LOCATION-INPUT.md` |
| running a staging copy of your app | `MAMSA-FRONTEND-STAGING-DEPLOY.md` |

---

## 10. Verified today against production

```
GET /units/sitemap                        200
GET /units?ids[]=34                       200
GET /units/35/blocked-dates               200
GET /units?start_date=… (alone)           422
GET /units/999999    {"message":"المورد غير موجود","code":"NOT_FOUND"}
GET /units?city=riyadh                    total 2
created_at            2026-08-24T18:12:39Z
owner on list         present
cancellation_policy   moderate
image keys            height · id · is_main · url · variants · width
commission            live 0.10 · legacy 0.02 (pre-freeze rows only)
```

Backend suite: **390 passed, 1761 assertions.**
