# Booked dates — two of your three were fine, one was not

**From:** backend · **Date:** 2026-08-27 · **Re:** `mamsa-booking-availability-question.md`
**Status:** fixed and on **staging**, proven with real concurrent requests. **Not yet on production.**

You asked for written confirmation on three points. Two hold. **The third did not** — the race you
described was real and could have double-booked a unit. It's closed.

Chasing it turned up a second bug you hadn't asked about, in the overlap logic itself. And your §3
endpoint is built.

```text
1. /units/{id}/availability compares against real bookings?   نعم — confirmed + pending_payment
                                                              + partner closures + iCal imports
2. POST /bookings re-checks at creation?                      نعم — always did, never trusted the client
3. Race protection at the database level?                     ❌ لا — لم تكن موجودة. أُصلحت اليوم.
```

---

## 1. The probe — confirmed, and it checks more than you assumed

`POST /units/{id}/availability` queries real rows. It refuses when **any** of these overlaps:

| | |
|---|---|
| a `confirmed` booking | paid and live |
| a `pending_payment` booking | someone mid-checkout — they hold the nights, or two people pay for the same room |
| a partner's manual closure | the owner blocked the dates |
| an imported iCal event | the unit is let on another platform |

A `cancelled` or `completed` booking releases the dates. Never a hardcoded `true`.

## 2. The create — confirmed, it never trusted you

`POST /bookings` has always run the same check itself. Skipping the probe entirely was never a way
past it. Your worry here was unfounded, but it was the right thing to verify rather than assume.

---

## 3. ⚠️ The race — you were right, and it was real

> ضيفين يفتحوا checkout لنفس الوحدة في نفس اللحظة … الاتنين ينجحوا في إنشاء حجز بنفس التواريخ

Exactly what could happen. The re-check at creation ran as a plain query followed by an insert —
**no transaction, no row lock, and no database constraint.** Two requests could both read "free"
before either had written, and both then succeeded. Re-checking without a lock narrows the window;
it does not close it.

MySQL cannot express "no overlapping date ranges" as a constraint the way Postgres can, so the fix
is a lock: the check and the insert now run inside one transaction behind `lockForUpdate` on the
**unit** row. Two requests for the same unit queue; the second sees the first's booking and is
refused. Bookings for different units are completely unaffected.

### Proven, not asserted

Eight genuinely parallel `POST /bookings`, same unit, same nights, against staging:

```
req 1: 422   req 2: 422   req 3: 201   req 4: 422
req 5: 422   req 6: 422   req 7: 422   req 8: 422

201 created : 1
422 refused : 7
rows in the database for that window: 1
```

**Nothing was double-booked on production.** It has 0 bookings, so the window never opened on real
data. Staging's test rows were cleaned up afterwards.

---

## 4. The bug you didn't ask about: changeover days were inconsistent

While writing the tests, the overlap predicate answered the **same physical question two different
ways**:

```
existing stay 10 → 15

new stay 15 → 18   (arrives the day the other leaves)   →  REFUSED
new stay 07 → 10   (leaves the day the other arrives)   →  ALLOWED
```

The cause was invisible from the outside. `start_date` is cast to `date` but stored as
`2026-09-10 00:00:00`, and the query compared it against bare dates — so a `BETWEEN` whose upper
bound was `2026-09-10` **excluded** the row starting that day. One direction matched by accident,
the other didn't.

### What it is now

A stay occupies **nights**, from `start` up to but not including `end`. The guest checks out on
`end` and the room is free that evening, so the changeover day belongs to the arriving guest. That
is the standard model, and it matches what your units already carry — separate check-out (12:00)
and check-in (16:00) times.

```
existing stay 10 → 15

new stay 15 → 18   →  ALLOWED     the changeover day
new stay 07 → 10   →  ALLOWED     the changeover day
new stay 14 → 18   →  REFUSED     shares the night of the 14th
new stay 07 → 11   →  REFUSED     shares the night of the 10th
```

**This earns a night on every turnover** that the old behaviour refused to sell. The owner made the
call; the alternative was blocking both directions for a guaranteed cleaning day.

Production has **0 bookings**, so nothing existing is reinterpreted by the change.

### One definition, not three

The predicate was written out in the probe and again in the create, and your §3 endpoint would have
been a third copy. A probe that disagrees with the create is exactly how a guest loses a booking on
the final screen — so all three now read one implementation. Partner closures already used the
correct half-open comparison; the bookings query was the odd one out.

---

## 5. §3 — the calendar feed is built

```
GET /api/v1/units/{id}/blocked-dates?from=2026-09-01&to=2026-12-31
```

```jsonc
{
  "from": "2026-08-27",
  "to":   "2027-02-27",
  "blocked": [
    { "start": "2026-09-10", "end": "2026-09-11" }
  ]
}
```

- **No authentication** — a guest browsing a unit page has no token yet.
- `from` defaults to today, `to` to six months out. Both optional; the window is capped at 400 days
  so one call cannot scan years.
- Flat response, like the sibling `/availability` — two envelopes on adjacent routes would be a
  pointless branch for you.

### Read `end` carefully — these are NIGHTS, inclusive

The example above comes from a **real booking of 10 → 12** on staging. It returns `{10, 11}`,
**not** `{10, 12}`, because the 12th is the changeover day and is bookable.

So `start` and `end` are both inclusive and describe the nights to grey out. Disable
`start … end` and the calendar matches the booking rule exactly — verified live:

```
booking 10 → 12 on staging
  blocked-dates  →  { "start": "10", "end": "11" }
  probe 12 → 14  →  available: true      the day the calendar leaves open IS bookable
  probe 11 → 14  →  available: false     the night the calendar greys out IS refused
```

If you disabled through `end` as the booking's raw end date, the picker would refuse a date the API
accepts — the same disagreement as before, just in the other direction.

### Merging and privacy

Ranges are **merged**: two stays that meet come back as one span, so you never draw a selectable
gap that isn't real. Bookings and partner closures are deliberately **not** distinguished — a guest
only needs to know a date can't be chosen, and labelling spans "booked" would publish how busy a
partner's unit is to anyone who asks.

---

## 6. What's left

**Keep the checkout check.** It is still the last line of defence: someone else can book while your
guest fills in their details, and the calendar they loaded is a snapshot. The difference is that it
should now be rare rather than routine.

The `422` from `POST /bookings` distinguishes the two cases if you want different copy:

```
"الوحدة محجوزة في هذه الفترة"      another booking
"الوحدة غير متاحة في هذه الفترة"    the partner closed the dates
```

**Backend suite: 364 passed, 1674 assertions** — 18 new here, covering each blocking status, the
create refusing without a prior probe, both changeover directions, one-night overlaps, and the feed's
merging and clipping.

**Production deploy is pending the owner's go-ahead.** Say the word and I'll confirm the date.
