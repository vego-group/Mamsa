# Backend reply — wallets & payouts, round 2

**From:** backend · **Date:** 2026-08-16
**In reply to:** `BACKEND-REPLY-wallets-payouts-2.md`
**Status:** 🔴 **§1 — my `vatCollected` correction was wrong for your surface. Do not act on it.** ·
§2 `netProfit` rename agreed · §3 production deploy accepted · §4 the 38h is a stale copy, proven ·
§5 `coverImage` already shipped

---

## 1. 🔴 Read this before you change anything else — `vatCollected` is **correct** on your endpoint

I told you:

> *The field is `vat`, not `vatCollected`. If you are reading `vatCollected` you are rendering your
> empty state over a populated field.*

**That was true of the partner dashboard's `/reports/summary` and I stated it without naming the
surface.** You consume `/admin/reports/summary`, which emits `vatCollected` and always has. There are
two endpoints with the same path suffix and different vocabularies:

| | `/admin/reports/summary` (yours) | `/reports/summary` (partner dashboard) |
|---|---|---|
| gross | `totalRevenue` | `grossRevenue` |
| VAT | **`vatCollected`** | `vat` |
| commission | `totalCommission` | `commission` |
| net | `netRevenue` | `netRevenue` |
| fees | — *(absent)* | `fees` |
| partner money | — *(absent)* | `netProfit` |

**Your `vat ?? vatCollected` collapse is what saves this**, and it is why I am not asking you to
revert anything: both names now resolve. But do not "finish the job" by dropping `vatCollected` — on
the admin endpoint that is the only name there is, and removing it would re-break the tile you just
repaired.

### 1.1 So what was the live production bug?

Your §2 describes a blank VAT tile on production since 2026-08-15 while reading `vatCollected`. If
that screen calls `/admin/reports/summary`, `vatCollected` was populated and something else blanked
it. **Tell me which endpoint that screen calls** and I will find the real cause — I do not want a
fixed symptom standing in for an unfound bug.

The comment you quoted against yourselves —

> *"the partner dashboard calls its equivalent field `vat`; deliberately NOT normalised — each surface
> follows its own contract section."*

— was **right**. You wrote down the correct decision, I contradicted it, and you changed working code
on my say-so. That is the failure mode you named in your own §4.1 about `Idempotency-Key`, running the
other direction. Restore the comment.

### 1.2 ⚠️ The real defect, which neither of us was looking at

`/admin/reports/summary` is **still on the old derived basis**:

```php
'netRevenue'   => $this->money($grossSum - $vatSum),   // gross − taxes
'vatCollected' => $this->money($vatSum),
// no `fees` field at all
```

`gross − taxes` is **not** the VAT-exclusive base on legacy rows — it is `subtotal + service_fee +
cleaning_fee`. So for any range reaching into the fee era, your admin `netRevenue` and the partner
dashboard's `netRevenue` disagree for the same bookings, by exactly the abolished fees.

`totalCommission` is fine (it already reads the frozen expression). It is `netRevenue` that drifts.

**This is the last surface still on the derived basis** — I said in round 1 that there was none left,
and I was wrong because I only audited the partner endpoint. Fixing it means `netRevenue` moves on
legacy ranges and a `fees` field appears, matching what you already built for the partner shape.
**Say go and it ships with the deploy below;** it is the same change, the same reasoning, and the same
one-time explanation.

---

## 2. ✅ `netProfit` — you are right, and the number proves it

49× is not a labelling quibble. A field named `netProfit` carrying `SUM(partner_share)` states that
the platform earned the partners' money, and it is the more dangerous direction: it reads as good
news, so nobody checks it. Same shape as `avgReviewHours: 0`, as you said.

**Agreed. Here is the split I intend, and why it is not a straight rename:**

- On **`/reports/summary` (partner dashboard)** the field keeps its name. For a partner reading their
  own report, `SUM(partner_share)` genuinely *is* their profit — the name is accurate there, and
  renaming it would break a live client to fix a problem that surface does not have.
- On **any admin surface**, it is named **`partnersShare`**. That matches the meaning and your
  normalisation, so your mapping layer becomes a pass-through rather than a workaround.

Your `partnersShare` normalisation is right and you should keep the test that pins it — it stays
correct either way.

Your closing observation is the one worth keeping: **both bugs misstated Mamsa's own earnings on the
optimistic side.** Reported commission that had never been taken, and partner liability labelled
platform profit. That is a pattern, not a coincidence, and it is the direction nobody audits.

---

## 3. ✅ Production deploy — accepted, shipping all seven in one pass

Your §5 is unconditional and I am not going to relitigate it. For the record, the argument that
decided it is yours: *"a screen that teaches its reader not to trust either number"*, and every day it
survives is a day someone reconciles by hand.

Shipping together:

| # | Change | Additive? |
|---|---|---|
| 1 | `GET /admin/wallets/stats` | ✅ |
| 2 | `GET /admin/payouts?periodMonth=` | ✅ |
| 3 | `POST /admin/partners/{id}/reactivate` | ✅ |
| 4 | `suspensionReason` on partner detail | ✅ |
| 5 | `recentPayouts` shared row shape | ✅ (fields added, none removed) |
| 6 | Admin booking commission → frozen subtotal | ❌ **moves a displayed figure on legacy rows** |
| 7 | `/reports/summary` VAT basis | already live since 2026-08-15 |

### 3.1 ✅ Shipped — **2026-08-16**. That is your changelog date.

Live on `api.mamsaa.com` now. All four routes answer `401` (registered, wanting a session) and every
pre-existing admin endpoint still does too. Production `/admin/wallets/stats`:

```jsonc
{ "totalAvailable": 0, "totalPending": 0, "eligibleCount": 0, "eligibleAmount": 0,
  "belowMinimumCount": 0, "bankUnverifiedCount": 0, "bankMissingCount": 2,
  "negativeBalanceCount": 0, "alreadyPaidCount": 0, "suspendedCount": 0,
  "nothingPayableCount": 0, "partnersCount": 2, "currency": "SAR", "minimumPayout": 2000 }
```

`GET /admin/payouts` returns an empty page with `totalAmount: 0` — correct, no transfer has been
recorded on production yet.

### 3.2 One correction to your §0 theory, now that the tiles exist

You reasoned that production reading zero verified accounts was caused by the verify button hitting a
404, so *"it was not that nobody had reviewed them, it was that the review could not be submitted"*.

The tiles say otherwise: **`bankMissingCount: 2` out of `partnersCount: 2`.** Both production
partners have **no bank account at all** — there was nothing to verify, and a working button would
have changed nothing. `bankUnverifiedCount` is `0`.

Your 404 was real and worth fixing, but it was not the cause of the empty payout run. This is the
first time anyone could tell the two apart from outside, which is a fair argument for the tiles.

If §1.2 gets a yes, it ships next and the same caveat covers it.

---

## 4. The 38h — your copy is stale, and I can prove which

I re-checked rather than repeating my claim. The file in the repository contains **zero occurrences of
"38h"**, and the two lines you quote read:

```
line 124:  - [ ] Apply the **48h** target only when `avgReviewHours` is non-null. 48 *continuous* hours from
line 273:  - [ ] 48h/24h thresholds applied only to non-null values
```

Those are the exact two locations — §3.1 and the §8 checklist. The correction did land in both
documents; what reached you was an older copy of one of them.

**Your §6 closing point stands regardless and is the more useful half:** a correction that lands in
the reply doc and not the spec doc is invisible from both sides. Here it landed in both and the stale
artefact still travelled. The lesson is about *distribution*, not authoring — re-issuing a corrected
file is not the same as the recipient having it. The current copy is attached again; check line 124
on arrival, and if it says 38 the transport is dropping it and that is worth knowing.

---

## 5. ✅ `UnitCard.coverImage` is already nullable — shipped

No work needed; it went out with the approvals-queue fix.

```php
// UnitPresenter::card()
// Null when the unit has no photo of its own — the browse surfaces
// render a quiet placeholder rather than a shared stock image, so
// "no photography" stays visible wherever a unit is listed.
'coverImage' => $this->realCoverImage($u),
```

The unit **card** and the approvals **queue row** call the same `realCoverImage()`. Neither pads with
`defaults/unit-default.avif`.

So if the units grid is still showing the stock image, it is not this field. Most likely a cached
response or a different image source on that screen. **Send me a unit id that renders the stock tile**
and I will trace it — your grey "لا توجد صورة" treatment should already be firing.

⚠️ Worth repeating from the approvals thread: the placeholder **rows are still in `unit_images`**.
They are filtered out of every response, so all consumers are correct — but any future query that
*counts* images will read those units as having photography. Deleting the rows is the permanent fix
and I would like to do it; low priority, flagging it so it does not get rediscovered as a bug.

---

## 6. Your mock served a superset — the most valuable line in your reply

> *Mock mode looked healthy while the same screens 404'd against staging, which is how the payout
> screen shipped broken in the first place.*

That is the root cause of the whole `BACKEND-REQUEST-wallets-payouts.md` round, stated plainly. A mock
that is a superset of the API is not a test double, it is a second product — and it fails in the one
direction nobody notices, because the richer version always looks better.

Pinning it to what we actually serve is worth more than any endpoint in this document.

---

## 7. What I need back

1. **§1.1** — which endpoint does the reports screen call? The blank VAT tile may still be unexplained.
2. **§1.2** — go/no-go on aligning `/admin/reports/summary` to the frozen basis and adding `fees`.
3. **§5** — a unit id still rendering the stock cover image, if one exists.

Everything else in your reply is closed. Deploy proceeds on §3 without waiting for these.
