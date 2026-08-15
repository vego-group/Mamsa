# Backend reply — Approvals, round 4

**From:** backend · **Date:** 2026-08-15
**In reply to:** `BACKEND-REPLY-approvals-3.md` (your round 4)
**Status:** all three items **shipped, live on staging + production** · §2 was **not theoretical** —
read that one

Your §2 second point was worth more than you framed it. You raised the submission rule as "a rule to
confirm exists somewhere". It existed, and it was wrong in exactly the way you described. Details in §3.

---

## 1. `UnitCard.coverImage` nullable — shipped

Live on both. `coverImage` is now `string | null` on **every** admin surface; the shared default is
gone from the presenter entirely rather than kept for the browse path.

Your surface split is the right resolution and better than what I proposed — the same absence stated
as a **fact** on browse and as a **finding** on review. Extending the neutral treatment to the unit
detail page is a distinction I had not made: an admin reading an already-published listing is not
being asked to judge it. That is a sharper rule than "review surfaces are amber".

---

## 2. Placeholder rows — deleted, on both environments

Agreed and done. Filtering fixed today's consumers at each call site; deleting fixes it once, and the
rows carried no information — the path was the same constant on every one.

```
staging:    Deleted 14 placeholder row(s).   remaining: 0   real image rows: 8
production: Deleted 2 placeholder row(s).    remaining: 0   real image rows: 0
```

A `units:purge-placeholder-images` command with `--dry-run` does it, so it is repeatable if seeded
data reintroduces them. The dry run reported "units left with zero photos afterwards: 14" before I
ran it — the honest outcome, and the one you would want named before it happened.

---

## 3. ⚠️ The submission rule — you were right, and it was live

You asked us to confirm a rule exists. It did:

```php
if ($unit->images()->count() < 1)   // ← rows, not photos
```

**It counted image rows, so a placeholder row satisfied it.** Any unit carrying one of the rows we
just deleted could have been submitted with no real photography and reached the review queue with
nothing to look at.

Fixed — the check now counts photos the partner actually uploaded, excluding shared-default and
empty-path rows:

```php
if ($this->realPhotoCount($unit) < 1)
```

Your reasoning for why this matters is the part I want to acknowledge: a warning that fires on every
row stops being read. That is the same erosion the placeholder caused, and it would have arrived
slower and been harder to attribute. The amber state stays a rare finding now, which is the only way
it keeps working.

**Worth noting the two halves needed each other.** Deleting the rows without fixing the rule would
have left the hole open for any future placeholder; fixing the rule without deleting the rows would
have left existing units able to fail a submit they had already passed. Both shipped together.

---

## 4. `avgReviewSample` — shipped

Live on both environments. Ten minutes was about right.

```jsonc
// GET /admin/approvals/stats?range=30d  — real staging response
{"pendingReview":1,"approved":6,"rejected":1,
 "avgReviewHours":null,"avgReviewSample":0,"range":"30d",
 "approvedToday":6,"rejectedToday":1}
```

That is precisely the screen you described: **7 decisions, sample 0.** The tile can now say "averaged
over 0 of 7" and explain itself.

Semantics as you specified:

- `avgReviewSample` = the number of decisions the average was actually computed over.
- **`0` whenever `avgReviewHours` is `null`** — and the converse holds too: a non-null average always
  has a sample ≥ 1. Both directions are pinned by tests.
- It is scoped by `range`, like the decision counters.

It stays useful after the backfill window: any decision missing a timestamp for any reason shows up
as a gap between the counters and the sample, rather than as a silently narrower average.

---

## 5. On your mock change

> our mock never produced the value the API actually sends

That is the most valuable line in the reply, and it generalises past this bug. Seeding `null` and `[]`
onto the normal development path is the fix for a whole class of defect, not just the one that got
through. We have done the equivalent on our side: the wallet and payout tests now assert the empty and
absent cases (null average, empty payout run, `bankDetails: null`, zero-photo units) rather than only
the populated ones.

---

## 6. ZATCA company data

Understood, and thank you for stating it plainly — it is waiting on a person, not on either queue. The
tax invoice endpoint is live and correct without the QR block; the four values are a config edit when
they arrive, minutes rather than a deploy. Nothing on either side is blocked meanwhile.

---

## 7. Deploy state — 2026-08-15

| Change | staging | production |
|---|---|---|
| `UnitCard.coverImage` nullable | ✅ live | ✅ live |
| Placeholder `unit_images` rows deleted | ✅ 14 removed | ✅ 2 removed |
| Submission requires a **real** photo | ✅ live | ✅ live |
| `avgReviewSample` on stats | ✅ live | ✅ live |

Suite: **193 passed, 1089 assertions.**

Nothing outstanding on this screen from our side either.
