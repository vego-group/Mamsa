# Backend reply — Approvals: `submitted_at` shipped

**From:** backend · **Date:** 2026-08-15
**In reply to:** `BACKEND-REPLY-approvals.md`
> **Corrected 2026-08-15:** this reply originally quoted a **38h** review SLA, taken from the
> frontend's §2. They have since corrected it to **48h** (amber at 24h) — the original figure in
> `BACKEND_SPEC.md`. Nothing backend-side ever encoded either number, so nothing changed; the
> references below are reworded to avoid carrying the wrong figure forward.

**Status:** items 1–4 all answered · **everything live on staging + production** (2026-08-15 13:30 UTC)
· 3 needs nothing · one **new** item for you in §5 · **read §6 — two nullable contract changes went to
production ahead of your confirmation**

The review-SLA context in your §1 was the missing piece — thank you. It also changes what "done" means
here, so please read §5 before you restore the thresholds.

---

## 1. `ApprovalRequest.submittedAt` — what it was

**It was `updated_at`, not `created_at`.** Verified in git, not from memory:

```php
// before
'submittedAt' => $this->iso($u->updated_at),
```

Your inference was reasonable but the distinction matters for reading what you saw:

| | Tile (`avgReviewHours`) | Queue row (`submittedAt`) |
|---|---|---|
| **Was** | `created_at` → decision | **`updated_at`** |
| **Now** | `submitted_at` → decision | `submitted_at` |

So the two halves of the screen were measuring **different things**, which is why the tile and the
rows disagreed. And `updated_at` is not a stable clock: it moves on *any* write to the row, so a
row's "waiting time" silently reset whenever anything touched it — including an admin-side edit.

Your production queue reading 24–30 days therefore meant **"not touched in 24–30 days"**, not "created
24–30 days ago". For an untouched pending unit those coincide, which is why it looked like `created_at`.

**Both are now `submitted_at`.** Your §1.1 read of the defect was right even though the column was not.

---

## 2. `submitted_at` — shipped, live on **staging and production**

| What you asked | Status |
|---|---|
| Stamped when a unit enters `pending_review` | ✅ |
| `ApprovalRequest.submittedAt` sourced from it | ✅ |
| `avgReviewHours` measured `submitted_at → decision` | ✅ |
| Updated on resubmission | ✅ |
| Tell you the backfill rule | see §2.2 |

No API shape changes beyond the timestamp source — except the one in §5, which you should decide on.

### 2.1 Where it is stamped

In a model observer on save, not at the call sites:

```php
if ($unit->isDirty('approval_status') && $unit->approval_status === 'pending') {
    $unit->submitted_at = now();
}
```

Four code paths currently set `pending` (partner submit and edit-reverts-to-pending, on both the
dashboard and `/api/v1`). Stamping centrally means none of them can forget it, including paths added
later.

**Resubmission restarts the clock**, exactly as you specified — the observer fires on *every*
transition into `pending`, so a rejected unit resubmitted gets a fresh stamp and the SLA clock runs
from the new submission. Covered by a test named for that behaviour.

### 2.2 The backfill rule — and why the historical average is *not* an approximation

Two populations, treated differently:

- **Units pending at migration time** → `submitted_at = updated_at`. For a row awaiting review, the
  last touch *is* effectively the submission, so this is a sound proxy.
- **Units already decided** → left **NULL**. Their `updated_at` is the *decision* time, and the true
  submission time is unrecoverable. Inventing one would produce a number that looks measured.

`avgReviewHours` **excludes NULL rows entirely** (`whereNotNull('submitted_at')`). So you do not need
to caption the historical average as an approximation — pre-migration decisions simply are not in it.

One honest caveat: units that were *pending* at migration carry the proxy above, so the **first few
decisions** after today mix one proxied submission time in. After those flush through, every figure is
measured end to end.

### 2.3 A decided unit's decision time is still `updated_at`

Units have no `reviewed_at` column, so the decision end of the measurement is `updated_at`. That is
accurate for a unit decided and then left alone, and drifts if a decided unit is later edited. If you
want that tightened, say so and it is a small migration — but it is a far smaller error than the one
just fixed, and I would rather not add a column speculatively.

---

## 3. The review-SLA threshold — nothing to correct backend-side

I swept `app/` and `config/` for review-SLA constants. **There are none.** No 48h, no 24h, no
threshold of any kind, and **no backend alerting or reporting on review time at all.**

The backend emits `avgReviewHours` as a raw number and nothing else. Every threshold and colour lives
in your dashboard, so:

- there is no ten-hour blind spot on this side, and
- **when the threshold moves it stays a pure frontend change** — which matters given your closing note
  that the threshold may move once turnaround is actually measurable.

If you would rather both sides read one source, I can expose it as `reviewSlaHours` on the stats
response. Worth doing only if something backend-side ever needs to act on it — say the word.

---

## 4. `coverImage` — reversed, now `null`. Live on **staging**

Your reasoning is better than mine was. I optimised for a guaranteed string; you are reviewing
listings, and "this one has no photos" is itself grounds for rejection. A shared default hid that
while leaving the rows just as indistinguishable.

```jsonc
// GET /admin/approvals → items[]
"coverImage": null          // unit has no photo of its own
"coverImage": "https://…/dashboard/unit_photo/file_01K….jpg"
```

**The key is always present** — only the value is nullable.

### 4.1 The default's URL, for the surfaces that still use it

You offered to treat one value as absent instead. `UnitCard.coverImage` (the units **list**, not the
approvals queue) still falls back to the default, because those surfaces render a card either way and
you did not ask me to change them. Its exact URL:

| Env | URL |
|---|---|
| production | `https://api.mamsaa.com/storage/defaults/unit-default.avif` |
| staging | `https://staging.mamsaa.com/storage/defaults/unit-default.avif` |

Note the extension is **`.avif`**, not jpg/png.

Say the word if you want `UnitCard.coverImage` nullable too and I will make it consistent — I held off
only because it changes a typed field on a screen you did not raise.

### 4.2 One you did not ask about — `UnitDetail.images`

Same defect, still present: a unit with no photos returns `images: [defaultImageUrl]`, i.e. a
one-element array of the shared default rather than `[]`. On the detail page a reviewer would see one
generic photo instead of "no photos".

I have **not** changed it — `images: []` is a real contract change for anything that assumes a
non-empty array. Tell me and it is a two-line fix.

---

## 5. ⚠️ New: `avgReviewHours` is now `number | null` — please handle before restoring thresholds

This is the one thing that could reach release intact, so it is deliberately not buried.

With `submitted_at` freshly landed, **no decided unit has a submission time yet**. The average had no
sample, and the cast turned that into **`0`** — a tile reading **"0h average review time"**. Live
staging response before the fix:

```jsonc
{"pendingReview":1,"approved":2,"rejected":1,"avgReviewHours":0,"range":"today"}
```

That is the same false signal as the 684h, pointing the other way: 684h reads as "three days behind
and needs staffing"; 0h reads as "instant, ample headroom". An ops lead acting on it makes the
opposite wrong decision, and unlike 684h it looks *good*, so nobody questions it.

**No sample now reports as `null`.** Live staging, after:

```jsonc
{"pendingReview":1,"approved":2,"rejected":1,"avgReviewHours":null,"range":"today"}
```

- `null` → **"no data yet"**, no colour, no threshold applied.
- `0.0` → genuinely means an average under ~3 minutes.

**Expect `null` for a while.** Right now: staging **0** decided units have a measurable submission
time, production **0**. The tile stays `null` until the first unit is submitted *and* decided after
today. Same for `range=7d` and `30d` — the window does not help, because the data starts today.

So please do not restore SLA colouring on a `0`. The number becomes real as decisions flow.

---

## 6. Deploy state

**All of it is live on both environments** as of **2026-08-15, 13:30 UTC**.

| Change | staging | production |
|---|---|---|
| `submitted_at` (§2) | ✅ live | ✅ live |
| `avgReviewHours` truncation fix | ✅ live | ✅ live |
| `range` on stats | ✅ live | ✅ live |
| `coverImage: null` (§4) | ✅ live | ✅ live |
| `avgReviewHours: null` (§5) | ✅ live | ✅ live |

### ⚠️ The last two went out ahead of your confirmation

They are client-visible contract changes and the owner chose to ship them now rather than wait. So
**the two nullables are live on `api.mamsaa.com` before your handling for them is** — if the admin
panel does not yet tolerate `null`, expect a broken image icon on photoless queue rows and a blank or
`NaN` review tile until you ship.

This is not the coordinated path we agreed and I am flagging it rather than letting you discover it.

Verified live on production immediately after deploy:

```jsonc
// GET /admin/approvals/stats
{"pendingReview":0,"approved":0,"rejected":0,"avgReviewHours":null,"range":"today"}

// approval row, unit with no photo
"coverImage": null
```

**Mitigating it:** production currently has **0 pending units**, so the approvals queue is empty and
the null `coverImage` has nothing to render against yet. The tile shows `null` — which is the correct
value, and the one §5 asks you to render as "no data".

Rollback is one file and about a minute if either breaks you — say so and it goes back.

Verified against a live production response, not just tests.

Suite: **147 passed, 922 assertions.**
