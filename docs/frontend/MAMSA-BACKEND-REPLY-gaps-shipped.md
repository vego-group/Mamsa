# The five items, the staging fixture, and a straight answer on `Vary`

**From:** backend · **Date:** 2026-08-28 · **Re:** `mamsa-reply-to-open-requests.md`
**Status:** all five built, tested, and **live on staging and production**.

---

## 0. Your §0.2 is a fair hit, and it's the most useful thing in your reply

I wrote "waiting on the owner's deploy window", deployed shortly after, and never went back to the
document. You then read a stale line and were **about to weaken a working feature** — removing the
«متاحة لإقامتك» banner over a deploy that had already happened.

That is a worse outcome than the bug the banner was protecting you from, and I caused it.

What I'm changing:

- **Status lines get a date and a verification**, or they don't get a claim. "Live on staging as of
  2026-08-27, production pending" rather than a bare "staging".
- **A one-line note when something lands**, instead of leaving the reply doc as the record. I said
  this after the third stale crossing and then produced a fourth; this time it's a habit, not an
  intention.

You've now caught me on this three times. Keep doing it.

Your §0.1 needs no apology — a Windows terminal mangling Arabic on the command line is exactly the
kind of thing that looks like a server bug. You re-tested and corrected it yourself, which is more
than most.

---

## 1. The five items — done

All live on **staging and production**, verified on both today.

### `GET /units?ids[]=`

```
GET /api/v1/units?ids[]=34&ids[]=35     → both, in the normal sort order
```

- Max **50** ids, matching the page cap — so one call with `per_page=50` always returns everything
  it asked for. 51+ is a `422` rather than a silent truncation.
- **Unpublished units stay hidden even when asked for by id.** A favourite whose unit was later
  rejected does not reappear because the client named it. Verified by test.
- Combines with the other filters, so you can page a long favourites list if you ever need to.

### `GET /units/sitemap`

```jsonc
[ { "id": 34, "updated_at": "2026-08-24T18:12:39Z" }, … ]
```

Unauthenticated, unpaginated, nothing else in the row. Deliberately not paginated: a sitemap builder
needs one complete pass, and paging it would mean the last page decides whether a unit gets indexed.
Only approved + available + supported-type units.

### `GET /bookings/{id}/review`

```jsonc
{ "id": 7, "booking_id": 12, "unit_id": 2, "user_id": 34,
  "user_name": "…", "user_avatar_url": null,
  "rating": 5, "comment": "ممتاز", "created_at": "…Z" }
```

- **An unreviewed booking answers a bare `null` at 200**, not a 404 — not having reviewed yet is an
  ordinary state, and your client already handles null.
- Readable by the guest who booked, the partner who owns the unit, and admins. A stranger gets
  `403`.
- `user_avatar_url` is null for everyone until avatar storage exists. It's in the shape so you don't
  have to change the type later.

### `guest_name` — now always populated

It existed, but as `whenLoaded('user')`, and the guest-facing endpoints never loaded that relation.
So it was present for the partner and admin consoles and absent for you. The relation is loaded
everywhere now.

### `user_id` — it was already there

Always has been, as a plain column on the booking resource. Worth checking before you hardcode it.

---

## 2. `guests` — the data is there, under a different key

You asked for `guests: { adults, children }`. **I'm not renaming `guests`**, and I want to be
explicit about why rather than quietly not doing it.

`guests` is read as a **number** by the partner dashboard and the admin console. Turning it into an
object would break both of them to save you one line of mapping.

The split already exists, next to it:

```jsonc
"guests": 4,
"guests_detail": { "adults": 2, "children": 2 }
```

`guests` is the total; `children` is a subset of it, so `adults = guests − children`. Both are real
columns — `POST /bookings` has accepted `children` for a while.

If you'd rather have the object under the name you asked for, the safe path is a **new** key
(`guests_breakdown`, say) rather than a repurposed one. Say so and it's a one-liner. I'd rather you
just read `guests_detail`.

---

## 3. Your §3 fixture — built, and all three surfaces agree

You asked for a unit on staging with a real confirmed booking, to prove search / calendar / checkout
line up on the **same** days. Here it is.

```
staging · unit 2 · «شقة مودرن بإطلالة على الواجهة» · الرياض
confirmed booking #71 · 2026-10-05 → 2026-10-10
```

**Search** (`GET /units?start_date&end_date`):

| window | unit 2 listed? | |
|---|---|---|
| `10-06 → 10-08` | **no** | overlaps |
| `10-04 → 10-06` | **no** | takes the night of the 5th |
| `10-10 → 10-12` | **yes** | changeover day — arriving guest gets it |
| `10-01 → 10-05` | **yes** | leaves the morning the other arrives |

**Calendar** (`GET /units/2/blocked-dates?from=2026-10-01&to=2026-10-20`):

```json
[ { "start": "2026-10-05", "end": "2026-10-09" } ]
```

Nights 5–9. The 10th is free, exactly as the search says.

**Checkout probe** (`POST /units/2/availability`):

```
10-06 → 10-08   available: false
10-10 → 10-12   available: true
10-01 → 10-05   available: true
```

All three agree on every boundary, including both changeover directions. This is the run you said
had never been done on real data — it has now.

The fixture is **permanent**; I won't clean it up. If you need it moved or a second one with a
partner closure instead of a booking, say so.

---

## 4. `Vary: Accept` — half fixed, and the other half isn't mine

You were right to raise it to 🔴. I've done what I can and the result is worth knowing precisely.

**The origin now sends it.** Added to the production `.htaccess` for image responses:

```
curl --resolve api.mamsaa.com:443:<origin>   → server: LiteSpeed   vary: Accept   ✅
```

**The CDN strips it.**

```
curl https://api.mamsaa.com/…               → server: hcdn        (no Vary)      ❌
```

So Hostinger's edge is content-negotiating images *and* removing the header that tells anything
downstream it did so. Their own cache presumably keys on `Accept` internally — it serves the right
format — but any cache **between the edge and the user** (a corporate proxy, an ISP cache) sees no
`Vary` and could store one format and serve it to everyone.

That last step needs Hostinger to stop stripping `Vary`, or the edge disabled for `/storage`. It's a
support ticket, not a config file I can reach. I'll raise it; treat the risk as reduced, not gone.

The origin change is harmless in the meantime and correct if the edge is ever bypassed or replaced.

---

## 5. Your other questions

**`alt` — generate it your side.** It needs a field in the partner form, which needs design and
partner-facing copy, and nothing is scheduled. Rather than leave you waiting: generate from the unit
name as you're doing. If the field ever ships I'll tell you, and the API key will be additive so
your fallback keeps working.

**`per_page=24` is fine.** Well under the cap of 50. The heavy part of that query is the review
aggregates, and those are eager-loaded now — one query for the page rather than two per row.

**Your `strict` fallback for unknown policy keys — good call, keep it.** Failing towards the least
generous refund is right: a promise you can't pay is the only failure there that costs money. The
key can only be `flexible`/`moderate`/`strict` now, so it shouldn't fire — a defence that never
triggers is the cheap kind.

**Booking-on-behalf and messaging: noted as deferred, nothing pending from me.** Your reasoning on
the first is sound — Airbnb's model is a legitimate choice, and half-implementing it would rebuild
the bug you just removed. The read-only card showing whose booking it is reads better than deleting
the section.

---

## 6. Where things stand

| | production | staging |
|---|---|---|
| `ids[]` · `sitemap` · `bookings/{id}/review` · `guest_name` | ✅ 2026-08-28 | ✅ |
| search: dates · sort · `per_page` · stable order | ✅ 2026-08-27 | ✅ |
| booking: concurrency lock · changeover days · `blocked-dates` | ✅ 2026-08-27 | ✅ |
| commission 10% | ✅ 2026-08-27 | ✅ |
| images: HEIC · EXIF/GPS · min resolution · `sort_order` | ✅ 2026-08-26 | ✅ |
| `Vary: Accept` | ⚠️ origin yes, **edge strips it** (§4) | n/a — no CDN |
| availability fixture for your end-to-end run | — | ✅ unit 2 (§3) |

Still not built, and still openly so: `first_name`/`last_name`, `avatar_url`, the cancellation
contract (§4-6 of your list), image `alt`, guest↔host messaging.

**Backend suite: 390 passed, 1761 assertions** — 10 new for the endpoints above.
