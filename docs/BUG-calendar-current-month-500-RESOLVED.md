# RESOLVED — `GET /units/:id/calendar` 500

**Re:** `BUG-calendar-current-month-500.md`
**Status:** fixed, deployed to staging + production (`c84240e`)
**Contract change:** none. `NEXTJS-DASHBOARD-CALENDAR-ICAL.md` was already correct — no frontend
change is required.

Thank you for this report — the repro table (same unit, same session, only `month` differing) is
what made it fast to find, and the server stack trace matched your DevTools timestamps exactly.

---

## Root cause

`CalendarController` eager-loads `blockedDates.icalFeed` and reads `$block->icalFeed?->source` to
fill the `source` field on `external` days. That `icalFeed` relationship **was never defined on the
`UnitBlockedDate` model** — only the inverse (`UnitIcalFeed::blockedDates`) existed. So the ORM threw:

```
Illuminate\Database\Eloquent\RelationNotFoundException:
Call to undefined relationship [icalFeed] on model [App\Models\UnitBlockedDate].
```

That's a backend defect, 100% ours. The fix is the four lines that declare the relation.

## Your "current month" theory — close, but the real trigger is different

This matters for how you test, so it's worth stating precisely: **the bug has nothing to do with the
current month.** The trigger is whether the requested month **contains any blocked dates**.

Eloquent skips eager-loading entirely when a query returns zero rows — so for a month with no blocked
dates, the broken relation was never resolved and the endpoint returned a perfectly good `200`. On
staging, June had **0** blocked rows and July had **5**. That's the whole difference.

Consequences worth knowing:

- A **past** month with blocked dates would have `500`d identically.
- A **future** month with none would have returned `200`.

So it wasn't "past works, current/forward breaks" — it was "empty months work, months with blocks
break". Your instinct that it was an unhandled exception on a real-data code path was right.

## Verified on staging

`GET /units/u_12/calendar` (a unit with an iCal feed + manual block), all `200`:

```jsonc
// 2026-07 — the month that used to 500
{ "date": "2026-07-05", "status": "external", "source": "Airbnb" }      // feed name via the fixed relation
{ "date": "2026-07-09", "status": "external", "source": "Booking.com" } // legacy row, no feed id → falls back to note
{ "date": "2026-07-22", "status": "blocked",  "reason": "صيانة" }
{ "date": "2026-07-27", "status": "booked",   "bookingCode": "BK-45", "bookingId": "b_45" }
```

Covered by two regression tests: a month with real iCal + manual rows, and the null-feed fallback.
(An "empty month" test would have passed vacuously and caught nothing — which is exactly why this
shipped.)

---

## ⚠️ Staging data was reset — action needed on your side

While debugging I made a mistake and wiped the staging database; I rebuilt it from the seeders.
**Production was never touched.** What this means for you:

- **Unit ids changed.** The `u_11` in your report no longer exists. The dashboard test partner now
  owns **`u_12` (approved), `u_13` (pending), `u_14` (draft), `u_15` (rejected)`**. Any hardcoded
  ids or saved links need updating — fetch ids from `GET /units` rather than pinning them.
- **Any test data you created by hand is gone** — including the `test` and `Airbnb` iCal feeds
  visible in your screenshots, and any properties you added through the wizard. Sorry — you'll need
  to re-create them.
- **Login is unchanged:** phone `0512345678`, OTP `111222`.
- `u_12` deliberately has a feed + manual block + `external` days, so the calendar has something real
  to render.

If a wiped staging blocks you again, ping us — restoring is a few minutes of work.

## Also fixed while in here

- `POST /uploads/presign` etc. are unaffected; no other endpoint changed.
- Two backend-only issues found via this trace: a stale-count risk in `/me`'s
  `hostCancellationsLast12m`, and three migrations that made the test suite unrunnable. Neither
  affects your integration.
