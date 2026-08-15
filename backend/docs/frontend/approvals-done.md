# Mamsa — Approvals request: all three items done

**From:** backend · **Date:** 2026-08-15
**In reply to:** `BACKEND-REQUEST-approvals.md`
**Status:** ✅ live on **staging and production** · suite green (134 tests, 885 assertions)

## Summary

| # | Item | Result |
|---|---|---|
| 1 | `range` on `GET /admin/approvals/stats` | ✅ **done** — switch it on |
| 2 | `coverImage` on the queue rows | ✅ **done** — no new hostname needed |
| 3 | `409 CONFLICT` on approve/reject | ✅ **confirmed already correct** |
| 4 | Batch endpoint | noted, not built — sequential calls are fine |

Your document was unusually easy to build from: the required/nice-to-have/confirm split meant nothing
had to be guessed, and the "why" on the range switch is what made the echo requirement obvious rather
than arbitrary. Two real bugs fell out of implementing it (§1.2, §1.3).

---

## 1. `range` on `GET /admin/approvals/stats` — done

All three keys ship, so your switch turns on.

```http
GET /admin/approvals/stats?range=today|7d|30d
```

**Live from staging just now:**

```jsonc
// range=today
{"pendingReview":1,"approved":2,"rejected":1,"avgReviewHours":684.3,"range":"today",
 "approvedToday":2,"rejectedToday":1}

// range=30d   ← the numbers actually move
{"pendingReview":1,"approved":6,"rejected":1,"avgReviewHours":371.3,"range":"30d",
 "approvedToday":6,"rejectedToday":1}

// no parameter, or range=all-time  → range:"today"
```

Semantics implemented exactly as specified:

- `pendingReview` is **live queue depth**, unscoped by range — identical across all three, verified.
- `approved` / `rejected` count **decisions inside the window**.
- `avgReviewHours` is the mean over the same decisions, **fractional**, in hours.
- Unknown or missing `range` → `today`.
- `range` is echoed back.

Legacy `approvedToday` / `rejectedToday` are retained so nothing breaks before you ship; drop them from
your parsing whenever convenient.

### 1.1 `today` was previously the wrong day — fixed

The app runs in **UTC**, and the old implementation used `whereDate(updated_at, today())`. That is the
UTC calendar day, so "today" was wrong by up to three hours either side of the Riyadh boundary — a
decision made at 01:00 Riyadh counted as yesterday.

`today` is now computed from **Asia/Riyadh** day boundaries converted to the UTC instants the
timestamps are stored in, consistent with `PAYOUT_TIMEZONE` as you asked.

### 1.2 🐛 `avgReviewHours` was truncated on production but fractional in tests

The helper used `TIMESTAMPDIFF(HOUR, …)` on MySQL — which **truncates** — while the SQLite branch used
`julianday × 24`, which is fractional. So the same data reported `14.2` in tests and `14` in
production, and nobody would have noticed until an SLA colour looked off.

Now `MINUTE / 60` on both. Since you colour this against a 24h / 48h threshold, the fraction matters.

### 1.3 ⚠️ What `avgReviewHours` actually measures — please read

Units carry **no `submitted_at` column**. The average is therefore measured from
**`created_at` → decision**, i.e. *unit creation* to decision — **not submission to decision**.

For a unit that sat in draft for a week before being submitted, that draft time is counted as review
time. It is why staging currently reports **684 hours**: the number is real, but it is not reviewer
latency.

**So do not colour it against a 24h/48h SLA yet** — it will read red on units the reviewer handled
promptly.

To make it mean what your UI implies, the backend needs a `submitted_at` timestamp stamped when a unit
enters `pending`. That is roughly **half a day** including a backfill. Say the word and it ships; until
then, consider showing the figure without the SLA colouring, or labelling it "time since listing
created".

---

## 2. `coverImage` — done, and no hostname to whitelist

Each item in `GET /admin/approvals` now carries:

```jsonc
"coverImage": "https://staging.mamsaa.com/storage/dashboard/..."
```

- Absolute URL, same source as `UnitDetail.coverImage`.
- **Never null** — falls back to the shared default image, so your placeholder branch becomes a
  belt-and-braces path rather than the common one.
- **Hosts are `staging.mamsaa.com` and `api.mamsaa.com`**, both already on your Next.js allowlist.
  Nothing to add. No CDN is involved.

Implementation note: the row now reads the unit's images relation, so the list query eager-loads it —
otherwise the queue would have fired one extra query per row.

---

## 3. `409 CONFLICT` — confirmed, no change needed

`POST /admin/approvals/{id}/approve` and `.../reject` already behave exactly as your bulk flow assumes:

| Situation | Response |
|---|---|
| Unit no longer `pending_review` | **409** `{"code":"CONFLICT","message":"الوحدة ليست في انتظار المراجعة"}` |
| Unit does not exist | **404** `{"code":"NOT_FOUND","message":"الطلب غير موجود"}` |
| Success | 2xx `{"ok":true}` |

The `message` is human-readable Arabic and stays that way, so surfacing it verbatim in your failure
list is safe. Your three-outcome mapping needs no adjustment.

---

## 4. Batch endpoint — not built, and not needed

Understood as informational. Sequential per-request calls are fine:

- The admin endpoints are rate-limited at **240 requests/minute**, so ~10 calls in a few seconds is
  nowhere near it.
- Each call is independent, so a 409 on one genuinely does not affect the rest.

If the queue ever grows to the point where admins bulk-decide 50+ at a time, a batch endpoint becomes
worth it. At ~10 it would be premature.

---

## 5. Checklist for your side

- [ ] Turn the Today / 7d / 30d switch on — all three keys ship
- [ ] Drop `approvedToday` / `rejectedToday` from parsing whenever convenient
- [ ] Keep the `coverImage` placeholder branch, but expect it rarely to fire
- [ ] **Hold the SLA colouring on `avgReviewHours`** until `submitted_at` exists (§1.3)
- [ ] No change needed to the 409 handling
