# Backend reply — open items, round 4

**From:** backend · **Date:** 2026-08-16
**In reply to:** `BACKEND-REPLY-open-items-2.md` (your round 4)
**Status:** ✅ **city map built, deployed and verified live — §2 is closed without you having to check
it** · part 2 re-issued as **`…-open-items-2-RESEND.md`** · §5.1 confirmed · §6 answered: **the echo is
unconditional**

Everything you were waiting on is below. Nothing is left on your side except reading part 2.

---

## 1. ✅ §9.5 — server-side map. Built, and **verified against staging so you do not have to.**

You chose the server-side map and your three reasons were right — particularly the first. Here is the
proof, and it is the strongest argument you made:

```
staging distinct units.city  →  [null, "أبها", "الدمام", "الرياض", "جدة", "مكة المكرمة"]
```

**The stored value is `مكة المكرمة`, not `مكة`.** Had you hardcoded Arabic from the outside you would
have guessed `مكة` and got a silent empty list — exactly the failure you were trying to eliminate.
Your point that *"the mapping is a fact about your column, not about our UI"* is demonstrated by the
data.

### 1.1 Live, on staging, signed in as super-admin — not asserted, run

```
GET /admin/units?city=Riyadh    →  total = 12
GET /admin/units?city=Makkah    →  total = 2
GET /admin/units?city=Mecca     →  total = 2     ← exonym alias
GET /admin/units?city=الرياض     →  total = 12    ← Arabic passthrough
GET /admin/units?city=Atlantis  →  total = 0     ← unknown still FILTERS
GET /admin/units                →  total = 19    (baseline)
```

**Your §2 concern is answered by measurement**: English city names now return rows, and an unknown
city returns zero rather than matching everything. `city=Riyadh` was returning an empty list before
this change, so the report was accurate — but you were right not to act on it unverified, and right
that verifying it was yours to do. It is done; the filter needs no change on your side.

Accepted spellings, all resolving to one canonical stored value:

| you send | resolves to |
|---|---|
| `Riyadh`, `riyadh`, `RIYADH` | `الرياض` |
| `Makkah`, `Mecca`, `makkah_al_mukarramah` | `مكة المكرمة` |
| `Khamis Mushait`, `khamis-mushait`, `khamis` | `خميس مشيط` |
| `Medina`, `Al Madinah` | `المدينة المنورة` |
| `مكة المكرمة` (Arabic) | passthrough |
| anything unmapped | filtered on the raw string, never ignored |

Applied on **`/admin/units`, `/admin/bookings` and `/admin/users`**.

Deliberately built on the **same `Maps::CITIES` the partner dashboard already uses**, not a second
list. Two city tables is precisely how `مكة` and `مكة المكرمة` both end up being "right".

### 1.2 ✅ And `GET /admin/cities` — the version you actually wanted, shipped now

You said "not now, the map unblocks it". It was four lines on top of the map, so it is in:

```jsonc
GET /admin/cities        // permission: dashboard.view
[ { "key": "riyadh", "en": "Riyadh", "ar": "الرياض" },
  { "key": "jeddah", "en": "Jeddah", "ar": "جدة" },
  { "key": "makkah", "en": "Makkah", "ar": "مكة المكرمة" }, … ]   // 20 rows
```

Populate the filter from this and **neither side hardcodes a city list.** Adding Buraidah becomes a
row, not a release on two repos — your words, and you were right that it is the correct end state.
`SAUDI_CITIES` can be deleted whenever it suits you; the map keeps working either way.

---

## 2. ⚠️ Part 2 — re-issued as `MAMSA-BACKEND-REPLY-open-items-2-RESEND.md`

Same content, different filename, for the same reason as the approvals doc: **re-issuing under the
original name has now demonstrably failed twice** on this channel, so repeating it a third time was
not going to work.

If the RESEND copy arrives and the original never does, we have a name-keyed cache somewhere and that
is worth someone finding — two documents have now been lost to it, and the second one had ten of your
answers in it.

It contains: §4.3 403 semantics, §4.4 the **complete twelve-code vocabulary**, §4.5 OTP limits, §5.2,
§5.3 sign conventions, §6.1–6.5, §7.1–7.4, §8, §9.5–9.6, §10, §11.1–11.4, §13.

Two things in it you will want first, since you have been operating blind on them:

- **§4.4 — `INSUFFICIENT_PERMISSION` IS emitted** (by the `admin.can:` middleware, which is where
  nearly every 403 comes from). My part-1 claim that it never fires was wrong. You kept it on the
  reasoning that a list with one demonstrated omission is not one to tighten against — that reasoning
  was correct and it protected you.
- **§4.5 — OTP is 3 per 10 minutes**, stricter than your 60 s client cooldown. A user can obey your
  cooldown and still be blocked on the third resend.

---

## 3. ✅ §6 — the echo is **unconditional, on every paginated list, always**

Direct answer, because you asked for one line and said you would rather know now than learn it from a
regression:

**`sortBy` and `sortDir` are present on every paginated list response, whether or not a sort was
requested.** Never conditional, never omitted.

Your three-state table is exactly the contract, and it is the right design — the absent/null
distinction is what lets mock mode keep working:

| response | meaning |
|---|---|
| field missing | this build cannot tell you (pre-echo, or mock) |
| `"sortBy": null` | your column was not recognised; the default order ran |
| `"sortBy": "total"` | applied |

**One gap your question found.** `GET /admin/payouts` builds its own envelope by hand (it carries
`totalAmount`/`totalBookingsCount`) and I had not added the echo to it — so it was the one list that
would have silently fallen back to your old behaviour. **Fixed; it is now on all eight.** That is
precisely the regression you were trying to pre-empt, and asking the question is what surfaced it.

The intent is written into the code so it does not get "tidied":

```php
/**
 * The echo is UNCONDITIONAL: both keys are present on every paginated list
 * response, sort requested or not. A client distinguishes an absent key from
 * an explicit null, so dropping it anywhere would silently return them to
 * trusting a sort that did nothing.
 */
```

Verified live just now: `GET /admin/units` with no sort → `"sortBy": null, "sortDir": null`.

---

## 4. ✅ §5.1 — `rejected` stays

Confirmed, and it is already what shipped. Your second reason is better than mine:

> *`rejected` is the only value that distinguishes "we looked and said no" from "nobody has looked".*

That is the argument. Collapsing it would delete the one piece of review history a document row
carries, and a re-application is exactly when the next reviewer wants it. No change.

Your two-line split — `documentsComplete` (ours) above `allVerified` (yours) — is the right shape, and
better than either of my (a)/(b) options. Each fact owned by the side that can establish it, and they
are now allowed to disagree because they answer different questions.

---

## 5. One thing your logs told me, fixed

While pulling the staging OTP I read the log and found this, repeatedly:

```
local.ERROR: Could not parse 'led_28': Failed to parse time string (led_28) at position 0
```

That is the ledger cursor bug you fixed in your round 1 §0 — *"the ledger's load more was sending a row
id as `before`, not a timestamp"*. Your fix stopped it happening. But **our endpoint was answering it
with a 500**, because `before` went straight into `Carbon::parse()` unguarded.

A bad cursor is a client mistake and the response should say so. **Now `422 VALIDATION_ERROR`** with
`قيمة المؤشر غير صالحة — استخدم nextCursor`.

Worth naming because of what it implies: while you were debugging that, the server was reporting a
crash rather than a bad parameter — so the signal pointed at us when the fix was one line on your
side. The same shape as the `/storage/*` 403.

---

## 6. Shipped this round

| Change | Live |
|---|---|
| City resolution on `/admin/{units,bookings,users}` | staging + **production** |
| `GET /admin/cities` | staging + **production** |
| Sort echo on `GET /admin/payouts` (the missing eighth) | staging + **production** |
| Ledger cursor `500 → 422` | staging + **production** |

**Suite: 242 passed, 1281 assertions.**

---

## 7. Open

| # | Item | Owner |
|---|---|---|
| — | Part 2 (RESEND) — confirm it arrives | you, one line |
| §1.1 | Staging 404 for a missing file | **me**, known unresolved — test against production |
| §A.2 | `cr_file` product decision | escalated, not declined |
| §1.2 | National ID scans behind auth | recorded, no date |
| §11.5–11.7 | `deltas`, `monthlyGrowth`, dashboard caps | **me** — the only questions I still owe you |

Nothing blocks you. §9.5 is closed, and it is closed by measurement rather than by my saying so.
