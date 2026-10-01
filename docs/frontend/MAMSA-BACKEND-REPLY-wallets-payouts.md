# Backend reply — wallets & payouts

**From:** backend · **Date:** 2026-08-16
**In reply to:** `BACKEND-REQUEST-wallets-payouts.md`
**Status:** ✅ **all three endpoints shipped, live on staging** · §3.3 was already done before your
note arrived · one of your two confirmations is right for the wrong reason, and it matters

Your §0 is the most useful thing anyone has sent this project. Probing for `401` vs `404` vs `405`
and reading the difference is what turned "the verify button doesn't work" into "we are knocking on
the wrong door" — and it found a **404 that had silently made bank verification impossible**, which
explains the empty payout run on production far better than "nobody has reviewed them".

---

## 1. ✅ `GET /admin/wallets/stats` — shipped

Your diagnosis was exactly right, and it is worth naming because it is the nastiest failure shape
there is: `/admin/wallets/stats` matched `wallets/{partnerId}` with `partnerId = "stats"` and answered
`NOT_FOUND` **after** authentication. Alive to an unauthenticated probe, dead for a signed-in admin,
silent in the console. Now registered **before** `wallets/{partnerId}`, with a comment saying why so
it does not get reordered back.

```jsonc
{
  "totalAvailable": 103914.30, "totalPending": 766.96,
  "eligibleCount": 1,          "eligibleAmount": 98254.80,
  "belowMinimumCount": 0,      "bankUnverifiedCount": 0,
  "bankMissingCount": 5,       "negativeBalanceCount": 0,

  // additive — see §1.1
  "alreadyPaidCount": 1, "suspendedCount": 0, "nothingPayableCount": 0,
  "partnersCount": 7, "currency": "SAR", "minimumPayout": 2000
}
```

That is the **live staging body**, not an example. Permission `wallets.view`, so finance sees it too.

You asked that the counts come from the same eligibility service as `/admin/payouts/ineligible` —
they do, and `eligibleAmount` comes from the same `payable()` the run actually pays. A test asserts
the tiles against the run rather than against a hand-computed figure, so the property you cared about
is pinned rather than promised.

### 1.1 Three counts you did not ask for

Your eight fields cover four of the six ineligibility reasons. Without the rest, the row does not add
up — and a row of counts that does not sum to the partner count is a row an accountant stops
trusting on sight.

- **`alreadyPaidCount`** — done for the cycle. On staging today this is `1`, so it is not hypothetical.
- **`suspendedCount`** — `partner_suspended`.
- **`nothingPayableCount`** — eligible on balance, but with **no unpaid finished stay to attach the
  money to**. `/admin/payouts/eligible` drops these, so the tile must too; otherwise the count offers
  a row the run will not list. This is the one that would have made your tiles and your table
  disagree by one.

`eligibleCount + belowMinimum + bankUnverified + bankMissing + negativeBalance + alreadyPaid +
suspended + nothingPayable === partnersCount`, always. Check it on screen if you like — a test does.

---

## 2. ✅ `GET /admin/payouts?periodMonth=YYYY-MM` — shipped

You offered us the cheap option and we took it, plus two things that fell out for free.

```
GET /admin/payouts?periodMonth=2026-07&partnerId=&status=&page=&pageSize=&search=
```

```jsonc
{
  "items": [{
    "id": "pay_1", "reference": "PO-2026-08-0001",
    "partnerId": "prt_4", "partnerName": "محمد الشريك الفردي",
    "periodMonth": "2026-07", "amount": 87800, "bookingsCount": 22,
    "currency": "SAR", "status": "paid", "paidAt": "2026-08-15T16:26:18Z",
    "bankReference": "FT-STAGING-0001",
    "ibanMasked": "••••0000", "bankName": "مصرف الراجحي", "note": null
  }],
  "total": 1, "page": 1, "pageSize": 10,
  "totalAmount": 87800, "totalBookingsCount": 22
}
```

Also live staging. Default sort `paidAt` descending; `sortBy` accepts `paidAt`, `amount`,
`periodMonth`.

- **`totalAmount` covers the whole filter, not the page** — closing a month is the entire reason this
  endpoint exists, and a per-page total would be a trap. It **excludes `reversed` rows**, because that
  money came back; reversed rows still appear in `items`, so the list and the total answer different
  questions on purpose.
- **`ibanMasked` / `bankName` / `note`** — a transfer record without a destination is half a record
  when a payment is disputed. `recentPayouts` on the wallet detail now returns the **identical shape**,
  built by the same function, so you do not need two renderers. Its rows gained these three fields and
  `partnerName`; nothing was removed.
- **A malformed `periodMonth` returns `422 VALIDATION_ERROR`**, not an empty list. `2026-7` matching
  nothing would render as "we paid nobody in July", and a wrong answer to a reconciliation question is
  worse than an error.

You said you do not need `GET /admin/payouts/{id}`, a booking breakdown or a timeline. None were built.

---

## 3. Your three decisions

### 3.1 ✅ `POST /admin/partners/{id}/reactivate` — shipped

`{ ok: true }`, permission `partners.manage`. Clears `suspension_reason` as well as flipping the flag,
which was your reason for wanting it.

Two things worth knowing:

- **It refuses a `pending` partner with `409 CONFLICT`.** An invited partner who never completed KYC
  is also `is_active: false`, and a general "activate" would put them live without a review. It only
  moves `approved + suspended → approved + active`. An already-active partner is a `409` too.
- **`suspensionReason` is now readable on `GET /admin/partners/{id}`.** It has been recorded on every
  suspension since the endpoint was written and **never surfaced anywhere** — so an admin opening a
  suspended partner could not see why they were suspended, which is the single thing they opened the
  page for. Your interim "reactivation happens on the users screen" copy can now name the reason.

### 3.2 ✅ Reversal — no endpoint built

Agreed, and your reasoning is better than my offer was. It stays an operator command. `status:
"reversed"` keeps rendering wherever payouts appear, and §2's `totalAmount` already excludes it.

### 3.3 ⚠️ `/reports/summary` — this shipped **yesterday**, before your note

It went live on staging **and production on 2026-08-15**. Your documents crossed in flight — the
partner-dashboard team sent the same go-ahead and it was built against theirs. **That is your deploy
date for the discontinuity notice.**

Three corrections you need before you render it, because your §3.3 formula does not match what shipped:

**1. It is not `gross ÷ 1.15`.** It is the sum of the frozen per-booking `subtotal` column. Those
differ on pre-conversion rows: for partner 4, `110,226.00 ÷ 1.15 = 95,848.70` but the frozen sum is
`88,582.61` — a **7,266.09 gap**. Deriving from gross would agree with the wallet on modern bookings
and drift on old ones, which is the failure mode hardest to notice.

**2. The field is `vat`, not `vatCollected`.** If you are reading `vatCollected` you are rendering
your empty state over a populated field.

**3. There is a new `fees` field.** Historical rows carry the abolished service and cleaning fees, so
`netRevenue + vat` does **not** reach `grossRevenue` on old ranges. `fees` closes it:

```
netRevenue + vat + fees === grossRevenue     always
netRevenue − commission === netProfit        always
```

It is `0.00` on every modern range, so hide the tile when zero. Without it, a partner reading gross
110,226 beside net 88,582 and VAT 6,263 finds 15,380 unexplained — and reading that gap as tax implies
a 19.6% rate, which is what the partner dashboard team flagged.

The full detail is in `MAMSA-BACKEND-REPLY-reports-vat-basis.md`, re-issued with this.

---

## 4. Your two confirmations

### 4.1 `Idempotency-Key` — right call, wrong reason

**Confirmed: nothing server-side reads it on `/admin/payouts/record`.** `bankReference` is the
idempotency key, enforced by a uniqueness check that returns `409 DUPLICATE_BANK_REFERENCE`. Dropping
the header changes nothing. Good.

But the reasoning needs correcting, because you may avoid other custom headers on the strength of it:

> *a custom header forces a CORS preflight the API does not advertise — which would fail the request in
> the browser before it was ever made*

`config/cors.php` sets **`'allowed_headers' => ['*']`**. The preflight would have passed. And
`Idempotency-Key` is **in live use today** on the partner dashboard's host-cancel endpoint, from a
browser — so it is proven, not theoretical. Send custom headers when you need them.

### 4.2 `verifiedBy` — confirmed, with two things that null it

`POST /admin/wallets/{partnerId}/bank/verify` stamps `verified_by_admin_id` from the session on
**every** verification. `verifiedBy` is that admin's name. Your "older record — approver not stored"
copy is exactly right for the historical `null`s, and it will not appear on new ones.

Two ways a populated value legitimately returns to `null`, both correct:

- **Rejection clears it** — a rejected account has no approver.
- **Any partner edit to the IBAN *or the holder name* clears it**, because verification drops on
  resubmission. The holder name counts: a bank refuses a transfer whose beneficiary name does not
  match, so finance verified that name as much as the number. Your warning-on-any-edit fix already
  covers the client side of this.

---

## 5. ✅ The 38h is already gone — you have a stale copy

Corrected on **2026-08-15**, the same day, in response to `BACKEND-CORRECTION-sla-48h.md`.
`MAMSA-FRONTEND-ADMIN-APPROVALS-SCREEN.md` §3.1 and its checklist both read **48h, amber at 24h**, and
so does `MAMSA-BACKEND-REPLY-approvals-submitted-at.md`. Re-issued with this reply so you are working
from the current file.

You were right to chase it. That document is what an agent builds the approvals UI from, so left
alone it would have re-shipped 38h *after* your correction — and the mistake would have looked like it
originated with us.

---

## 6. One thing you did not ask about, found on the way

`GET /admin/bookings/{id}` imputed an unfrozen commission from **gross**, while the commission total
on the stats row directly above that table imputed from the **subtotal**. Same legacy stay, `23.00` on
the row and `20.00` in the total.

That is precisely the "two permanently disagreeing numbers" you argued against in §3.3, sitting inside
one screen. Both now read the subtotal, matching `Booking::commissionExpr()` and everything else.

---

## 7. Deploy state — 2026-08-16

| | staging | production |
|---|---|---|
| `GET /admin/wallets/stats` | ✅ live | ⏳ awaiting your go-ahead |
| `GET /admin/payouts?periodMonth=` | ✅ live | ⏳ awaiting your go-ahead |
| `POST /admin/partners/{id}/reactivate` | ✅ live | ⏳ awaiting your go-ahead |
| `suspensionReason` on partner detail | ✅ live | ⏳ |
| `recentPayouts` shared row shape | ✅ live | ⏳ |
| Admin booking commission basis fix | ✅ live | ⏳ |
| `/reports/summary` VAT-exclusive basis | ✅ live | ✅ **live since 2026-08-15** |

Suite: **229 passed, 1239 assertions.**

Production is one command away and deliberately not run: these are additive, but the booking
commission fix changes a displayed number on legacy rows, and you asked to be told a date rather than
discover it. **Name the day and it goes.**
