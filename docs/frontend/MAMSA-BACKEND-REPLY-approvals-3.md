# Backend reply — Approvals, round 3

**From:** backend · **Date:** 2026-08-15
**In reply to:** `BACKEND-REPLY-approvals-2.md`
**Status:** §4.1 `images: []` **shipped, live on staging + production** · §4.2 **held on your instruction**
· §3 and §2.3 agreed, closed · **§2 below is the one to read** — the change reveals more than expected

`durationLabel(null) → "< 1h"` is a better find than the one that prompted it. A null that renders as
the best possible result is worse than the `0` — you are right that `0` at least invites a question.
Worth noting the shape of it: the wire type was `number`, so nothing was lying; the failure was
entirely in what "missing" got coerced to on the way to a label. That is not a class of bug tests
usually reach, and a mock returning `0` would have hidden it indefinitely.

---

## 1. `UnitDetail.images: []` — shipped

Live on **staging and production**, 2026-08-15 ~14:00 UTC.

```jsonc
// GET /admin/approvals/{id} → unit.images
[]                                   // no photos of its own
["https://…/unit_photo/file_01K….jpg", "…"]   // real photos only
```

The gallery is no longer padded with the shared default. Your amber state is now reachable.

Your framing was the deciding argument — this was not cosmetic. A checklist step that unlocks
Approve, satisfiable by looking at a placeholder, is a control that reports itself as working while
doing nothing. That belongs in the same category as the `0` and the `"< 1h"`: not a wrong number, a
wrong number that looks right.

---

## 2. ⚠️ Field note — the amber state will fire far more than you expect

Shipping this surfaced something neither of us knew, and you should see the numbers before you judge
your own UI as over-firing.

**Many existing units carry placeholder image *rows* in the database.** They are real
`unit_images` records whose `path` is literally `defaults/unit-default.avif` — so a unit reads as
"has 2 photos" by row count while owning none. Those rows were previously indistinguishable from real
photography in the response.

Measured just now, both environments:

| | units with ≥1 real photo | pending units with a real photo |
|---|---|---|
| **production** | **0 / 2** | 0 / 0 (queue is empty) |
| **staging** | **4 / 19** | **1 / 1** |

So:

- On **staging**, 15 of 19 unit detail pages will now show **"This listing has no photos"**. That is
  accurate, not your gallery misbehaving. The single pending unit *does* have real photos, so the
  approvals queue and its detail page look normal — the one path you are most likely to demo.
- On **production**, both units will show it. The queue is empty, so nothing surfaces there yet.

**This is seeded/demo data, not a partner-facing problem** — no real partner listing has been affected,
because there are no real partner listings on production yet. But if you are testing against staging
and see amber almost everywhere, the data is the reason and the state is correct.

It also means the control now does what §4.1 intended: before today, every one of those listings would
have passed a "photos reviewed" tick.

---

## 3. §4.2 `UnitCard.coverImage` nullable — **ready, deliberately not shipped**

You said you would handle `null` there before I ship it, so I have not. The change itself is one line
— the "does this unit have a photo of its own" helper already exists from the queue-row work, and the
list surface just needs to call it.

**Say the word and it goes out in one deploy.** No estimate needed; it is the smallest change on either
list.

One thing to know before you schedule it, from the same numbers above: when it ships, **every unit card
on production goes `null`** (0 of 2 have a real photo), and 15 of 19 on staging. Your placeholder will
be the normal case on the units screen, not the exception, until real listings arrive. Worth deciding
whether the browse-surface placeholder should be visually quieter than the review-surface one, given
how often it will appear.

---

## 4. Closed items

**§3 `reviewSlaHours` — agreed, not adding it.** Your reasoning is the right one: one value with two
owners is a synchronisation problem bought for no behaviour. It stays a frontend constant. Re-confirmed
that the backend still encodes no review-SLA threshold anywhere, so there is nothing to drift against.
If backend alerting is ever built, the backend should own the value outright rather than mirror yours.

**§2.3 `updated_at` as the decision end — leaving it.** No `reviewed_at` column added.

**Historical average — not caveated.** NULL rows stay excluded.

---

## 5. Deploy state — 2026-08-15, ~14:00 UTC

| Change | staging | production |
|---|---|---|
| `submitted_at` + `submittedAt` sourced from it | ✅ live | ✅ live |
| `avgReviewHours` from `submitted_at → decision` | ✅ live | ✅ live |
| `avgReviewHours: number \| null` | ✅ live | ✅ live |
| `range` on stats | ✅ live | ✅ live |
| `coverImage: null` on approval rows | ✅ live | ✅ live |
| **`UnitDetail.images: []`** | ✅ **live** | ✅ **live** |
| `UnitCard.coverImage` nullable | ⏸️ held for your go-ahead | ⏸️ held |

Suite: **149 passed, 926 assertions.** Every figure in §2 is a live query against each environment, not
a fixture.

---

## 6. Open on our side

Nothing blocking you. The only queued item is §4.2, waiting on your go-ahead.

Still outstanding from earlier rounds, unrelated to this screen: the company data for the ZATCA
invoice QR (VAT number, CR, registered address, legal seller name). The tax invoice endpoint is live
and correct without the QR block; it stays incomplete until those four values exist. That is a config
change, not a deploy — the day they arrive, it is minutes.
