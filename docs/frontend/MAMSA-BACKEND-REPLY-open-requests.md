# Consolidated open requests — answers, and what changed

**From:** backend · **Date:** 2026-08-27 · **Re:** `mamsa-open-requests-consolidated.md`

Thank you for consolidating — it made the priorities obvious and it surfaced two problems I'd have
missed.

**Read §0 first.** Several items are already done and some of your evidence has gone stale, so the
priority order in your file isn't the priority order any more. In particular **§1.1 does not
reproduce**, and **§2.1 was worse than you framed it**.

---

## 0. Corrections before the answers

### 0.1 `?city=الرياض` works on production right now — measured today

Your headline item, "أخطر بند في الملف كله". I couldn't reproduce it:

```
GET /units                 → total 2   both "الرياض"
GET /units?city=الرياض      → total 2   ← not 0
GET /units?city=الري        → total 0   (partial — was never supported)
GET /units?city=riyadh     → total 0   (slug — was never supported)
```

Codepoints on the wire are identical to yours. The exact-value filter matches.

I can't tell you what you hit on 2026-08-25 — a unit was edited since, which would have
re-normalised its stored city, and that's the most likely explanation. What I *can* tell you is that
the filter was **exact-match-only**, so any spelling other than the stored Arabic returned an empty
list — and an empty list is indistinguishable from "this city has no units". That's a bad failure
mode regardless of what you saw.

**Fixed anyway:** the filter now resolves slug / English / Arabic through the same helper every other
surface uses. `riyadh`, `Riyadh` and `الرياض` all work. An unrecognised city still returns nothing —
correctly, and only then.

### 0.2 The coordinates in §1.2 are already corrected

`GET /units/34` today: `lat 24.854463, lng 46.658672` — Al-Narjis, Riyadh. Someone fixed it through
the admin console.

For the record, the cause was diagnosed on 2026-08-24: the backend never transformed `lat`/`lng`,
and unit 34's own reverse-geocoded address agreed with the wrong pin, so the bad value arrived from
the console before the save. The likely mechanism was a number input being stepped — arrow keys or
a mouse wheel — which changes the integer part by exactly 1 and leaves the decimals alone, which is
precisely what the two rows showed. Details in `MAMSA-FRONTEND-ADMIN-LOCATION-INPUT.md`.

### 0.3 Most of §6 (images) shipped to production on 2026-08-26

Your list has these as open. They're live:

| your item | status |
|---|---|
| HEIC conversion on upload | ✅ live — verified with a real 3000×2000 HEIC |
| EXIF orientation applied + **GPS stripped** | ✅ live — 0 EXIF properties on the stored file |
| minimum resolution + clear 422 | ✅ live — at **1024/576**, not 1280×720 (§0.4) |
| 10MB cap | ✅ already existed |
| `sort_order` | ✅ live |
| aspect-ratio constraint | ❌ **deliberately not enforced** (§0.4) |
| `alt` | ❌ open — needs a partner form field first |
| `Vary: Accept` | ❌ open, and see §7 — it matters more than you think |

The EXIF/GPS one was a live data leak: unit photos were stored as raw bytes, so a partner's phone
photo published the property's coordinates to anyone with a metadata viewer. Closed.

### 0.4 Two of your image specs were deliberately not implemented as written

Both were owner decisions, both explained in `MAMSA-BACKEND-REPLY-unit-images.md`:

- **Minimum 1024/576, not 1280×720**, measured on long/short edge rather than width/height. As
  written it rejected every photo in the library *and* every portrait — including the 9:16 phone
  shots whose lightbox rendering started that thread.
- **Aspect ratio not enforced.** Rejecting 9:16 while building a full-screen viewer for it was
  self-contradictory, and you agreed when I raised it.

### 0.5 §2.2 and §3.6 were answered and built yesterday

`MAMSA-BACKEND-REPLY-booking-availability.md`. Summary in §2 below — including the fact that **one of
your three points was not covered**, and is now.

---

## 1. Live issues

### 1.1 `?city=` — see §0.1. Fixed, though not for the reason given.

### 1.2 `lat`/`lng` — see §0.2. Already corrected in the data.

### 1.3 `cancellation_policy: "no_cancel"` — you were right, and it was worse than a naming problem

`no_cancel` was never a policy. It's the value of a **dead enum column** whose only possible values
were `no_cancel` and `48_hours`, from before the tiered-policy system existed. The refund engine has
never read it.

So a client using it as a pre-payment fallback wasn't just showing an unfamiliar key — it was
showing a refund schedule the platform would never honour, exactly as you said.

**Fixed.** `cancellation_policy` now carries the **effective preset key** — the policy the refund
engine would actually apply — always equal to `cancellation_policy_details.template`.

**The complete list of possible values, as requested:**

```
flexible · moderate · strict
```

That's the whole vocabulary; they're rows in the presets table, and there are three. Not
`24_hours`, `7_days` or `non_refundable` — those don't exist here. A unit that never chose one
inherits the platform default (`moderate`), and the field reports what would be enforced, so it is
**never null and never outside those three**.

### 1.4 404 leaking the model class — fixed

```
before:  {"message":"No query results for model [App\\Models\\Unit] 999999"}
after:   {"message":"المورد غير موجود","code":"NOT_FOUND"}
```

`/api/v1` was the only surface without an error renderer of its own, so it fell through to Laravel's
default. The partner and admin surfaces already had one.

Unhandled 500s now return `{ message, code: "SERVER_ERROR" }` with the detail going to the log.
**Validation and auth responses are untouched** — you already parse those shapes and I'm not going
to move them underneath you.

### 1.5 `/units/popular` and `is_featured` — intended, and here's the difference

Confirmed, they're unrelated:

- **`popular`** = ordered by **count of confirmed bookings**, newest first as a tiebreaker. Purely
  behavioural.
- **`is_featured`** = a manual editorial flag an admin sets.

A unit can be either, both, or neither. If your "مميز" badge and default "موصى به" order should
follow the editorial flag, use `?featured=1` — the default sort now puts featured first anyway
(§2.3).

---

## 2. Confirmations

### 2.1 `GET /units?start_date&end_date` — **the answer was NO. It is now YES.**

This was the most serious item in your file and it deserved the alarm you gave it.

The parameters were **accepted and silently ignored**. Your banner — «متاحة لإقامتك من ٥ إلى ٨
سبتمبر» — was over an unfiltered list. You were right that it amounted to telling the guest
something untrue.

Worse than accepted-and-ignored: sending only one of the two also passed, so a half-built window
looked like it worked.

**Now:**

- Both dates → the list excludes any unit with a conflicting booking (`confirmed` **or**
  `pending_payment`) or a partner closure or an imported iCal block.
- Only one date → **422**. A half window is a client bug, and answering it with an unfiltered list
  is how the banner went wrong in the first place.
- Same predicate the availability probe and the booking create enforce — one implementation, so the
  search cannot promise what the create refuses.

**Keep the banner.**

### 2.2 Double-booking — two of three held, one did not

Answered in full yesterday; the short version:

| | |
|---|---|
| `/availability` compares real bookings? | ✅ yes — `confirmed` + `pending_payment` + closures + iCal |
| `POST /bookings` re-checks at creation? | ✅ yes — it never trusted the client |
| Race protection at the database level? | ❌ **no. Now fixed.** |

The check ran as a plain query then an insert — no transaction, no lock, no constraint. Two
requests could both read "free" and both succeed. Now the check and insert run in one transaction
behind a row lock on the unit. Proven with 8 genuinely parallel requests: **1 created, 7 refused, 1
row**.

Production had **0 bookings**, so it never fired on real data.

**Also fixed while there:** changeover days were inconsistent — a stay *starting* on another's end
date was refused while one *ending* on another's start date was allowed. Same situation, two
answers. Now a stay occupies nights `[start, end)` and the changeover day belongs to the arriving
guest, so back-to-back bookings work in both directions.

### 2.3 `sort` — **was NOT supported. Now is.**

It was accepted and ignored, like the dates. All four keys now work:

```
price_asc · price_desc · rating · newest
```

`newest` needs `created_at`, which is also now on the resource (§4).

Unknown or absent → featured first, then newest. That is the "موصى به" shape, so you can keep
sending `undefined` and get something sensible rather than arbitrary.

### 2.4 `avg_rating` / `reviews_count` — confirmed, both as you assumed

- `reviews_count` is the real count of review rows.
- `avg_rating` is **`0`, never null**, when there are none.

So "unrated" is `reviews_count === 0`, not `avg_rating === 0`. Your «جديد» badge is reading the
right field.

**One thing I fixed while confirming it:** both were computed with a separate query *per unit* — two
extra queries per row. At the old fixed page size of 12 that was 24 extra queries; with `per_page`
now settable to 50 it would have been 100. Both are eager-loaded now.

---

## 3. Pagination — all three confirmed, and one of your worries was worse than you thought

1. **`?page=2`** — correct, standard Laravel.
2. **`?per_page=`** — **did not exist. Added.** Range 1–50, default still 12. Capped because an
   uncapped page size is a way to ask for the whole table in one query.
3. **Stable ordering** — this is the one. You asked whether a tiebreaker on `id` was needed.
   **There was no `ORDER BY` at all.** Not an unstable sort — no sort. The database was free to
   return rows in any order and to pick a different one per query, so paging really could show a
   unit twice and never show another.

   Every sort branch now ends with `units.id`, including the default. There's a test that pages
   through 7 identical-price units and asserts none repeats and none is lost.

`meta` keeps its shape: `{ current_page, last_page, per_page, total }` — pinned by a test now.

---

## 4. Fields

**Done now**, both on the list resource:

- **`created_at`** — ISO 8601. Without it `newest` was impossible and you were inventing
  `new Date()`.
- **`owner`** on `GET /units` — it existed on the detail but the list never loaded the relation, so
  every card showed a blank host and an unlit verification badge. Full object, same shape as the
  detail (`name`, `type`, `is_verified`, `avatar_url`).

**Not done — these are real work, not oversights:**

| item | why it isn't a quick fix |
|---|---|
| `first_name` / `last_name` | two new columns, a migration to split existing names, and every write path updated. Your «عبد الله» example is the right argument for doing it — it needs a slot, not a patch |
| `avatar_url` on the user | there is no avatar storage yet; the field would be null forever until upload exists |
| `guests: {adults, children}` | the column split already exists — bookings store `guests` **and** `children`. The resource just doesn't expose them as an object. Small; say the word and it's next |
| `guest_name`, `user_id` on the booking | small, same batch |
| `review` shape on the booking | needs §3.4's endpoint decided first, so they agree |
| `cancellation-preview` totals, `tier_label` as data, `cancelled_by` values | one coherent piece of work on the cancellation contract |
| `country` | you said not urgent; agreed |

---

## 5. Missing endpoints

| | status |
|---|---|
| `GET /units/{id}/blocked-dates` (§3.6) | ✅ **built** — see below |
| `GET /units?ids[]=` (§3.2) | not built. Cheap, and the favourites bug you describe is real |
| `POST`/`GET /bookings/{id}/messages` (§3.3) | not built. This is a feature, not a field — needs a table, auth rules on who may read a thread, and a notification decision |
| `GET /bookings/{id}/review` (§3.4) | not built. Small |
| `GET /units/sitemap` (§3.5) | not built. Trivial |

### `blocked-dates` is live on staging

```
GET /api/v1/units/{id}/blocked-dates?from=2026-09-01&to=2026-12-31

{ "from": "...", "to": "...", "blocked": [ { "start": "2026-09-10", "end": "2026-09-11" } ] }
```

Unauthenticated. `from` defaults to today, `to` to +6 months, window capped at 400 days. Ranges are
merged.

⚠️ **`end` is the last unavailable NIGHT, not the booking's end date.** The example above is a real
booking of **10 → 12**: it returns `{10, 11}` because the 12th is the changeover day and is
bookable. Disable `start … end` inclusive and the calendar agrees with the booking endpoint exactly.
If you disabled through the raw end date you'd refuse a night the API accepts.

---

## 6. Guest details at checkout (§5.1)

**`POST /bookings` does not accept separate guest details.** It records the authenticated user as the
guest. So the form is collecting `firstName` / `lastName` / `email` / `phone` and discarding them —
your reading is correct.

Booking on someone else's behalf is a real scenario and worth supporting, but it is a product
decision with consequences: who receives the confirmation, whose email gates the booking, whose
phone the partner is given. **Until that's decided, remove the fields** rather than collect data
that goes nowhere.

The adults/children split (§5.2) is the smaller half and can move independently — the columns
already exist.

---

## 7. `Vary: Accept` — you're right, and it's more urgent than "⚪"

You flagged this as a nicety. It isn't, because of something you may not know: **production sits
behind a CDN that content-negotiates images**.

`api.mamsaa.com` is fronted by Hostinger's edge (`server: hcdn`). Measured:

```
same .jpg URL, no Accept header      →  74,636 B  image/jpeg
same .jpg URL, browser Accept        →  59,114 B  image/webp
cache-control: public, max-age=604800
```

So the edge is already serving different bytes for the same URL based on `Accept`, with a 7-day
cache. That is exactly the situation `Vary: Accept` exists for. I'm treating this as the highest
priority item in §6.

It's an edge configuration rather than an application header, so it needs the hosting panel — I'll
confirm once it's set.

---

## 8. CORS for localhost (§7.1)

Already allowed, and has been. Staging's list includes `http://localhost:3000`, `:3001`, `:3002`,
`:5173`, `:5174`, and any `https://mamsa-*.vercel.app`.

If you're hitting this against **production**, that's a different list — tell me which ports and
I'll add them. Note the console you're proxying around is probably pointed at production; staging
needs no proxy.

---

## 9. Status

Everything above marked fixed is **on staging**, verified. Production has the images and description
work; the booking, search and error-shape changes are waiting on the owner's deploy window.

**Backend suite: all green** — 14 new tests on search alone (availability window, city spellings,
sort, page size, paging stability, `created_at`/`owner` presence), 18 on booking availability.

### Your three blockers

1. **`?city=`** — works today; also made spelling-agnostic. ✅
2. **`start_date`/`end_date`** — the answer was **no**; it is now **yes**. Keep the banner. ✅
3. **Pagination** — `?page=` confirmed, `?per_page=` added, and the ordering problem was worse than
   you suspected: there was no ordering at all. ✅
