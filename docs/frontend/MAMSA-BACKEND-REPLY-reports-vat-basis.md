# Backend reply — `/reports/summary` aligned to the VAT-exclusive basis

**From:** backend · **Date:** 2026-08-15
**In reply to:** `REPLY-reports-vat-basis.md`
**Status:** ✅ **shipped, live on staging + production** · your `vat` question answered in §2 —
**the 19.6% was not tax** · one field added, and one data fix you could not have seen from outside

Your instinct to check the arithmetic before writing "only two fields change" is what made this
correct. Two of your three observations were right, and the third pointed at something worse.

---

## 1. Shipped — all four figures now read the frozen columns

```
grossRevenue = SUM(total_amount)      commission = 2% of the VAT-exclusive base
netRevenue   = SUM(subtotal)          netProfit  = SUM(partner_share)
vat          = SUM(taxes)             fees       = the remainder  ← NEW, see §2
```

Nothing is recomputed from gross at query time, exactly as you asked — so reports agrees with the
wallet on modern rows *and* on pre-conversion ones.

**Live proof on staging**, partner 5:

```
gross=123,834.20  net=100,260.00  vat=7,298.20  fees=16,276.00
commission=2,005.20  netProfit=98,254.80

net + vat + fees   = 123,834.20  vs gross 123,834.20   ✓
net − commission   =  98,254.80  vs netProfit 98,254.80 ✓
```

And the figure that matters most: **`netProfit` 98,254.80 is exactly that partner's wallet balance.**
The two screens now show the same number for the same money.

---

## 2. Your `vat` question — the 19.6% is **fees, not tax**

You were right to refuse to print it. `vat` was already summed from the frozen `taxes` column, not
derived — the gap you measured is a *third* component.

Partner 4, real column sums:

| | |
|---|---|
| `subtotal` | 88,582.61 |
| **`taxes` (the real VAT)** | **6,263.39** |
| `service_fee` | 8,780.00 |
| `cleaning_fee` | 6,600.00 |
| **total** | **110,226.00** = gross ✓ |

Your 21,643.39 was `gross − subtotal`, which is VAT **plus the abolished service and cleaning fees**
(15,380.00). The real VAT is 6,263.39. Nothing implies a 19.6% rate.

So the answer to "what should `vat` be summed from" is: **the frozen `taxes` column, which is what it
already was.** No `—` needed on mixed ranges.

### 2.1 Why `fees` is now a field

Those fees are real money the guest paid and they have to appear somewhere, or your tiles do not add
up — gross 110,226 beside net 88,582 and VAT 6,263 leaves 15,380 unexplained on screen, and a partner
checking it lands exactly where you did.

`fees` closes that: **`netRevenue + vat + fees === grossRevenue`**, always. It is **0.00 on every
modern range**, so you can hide the tile when it is zero and show it only on ranges that reach back
into the fee era.

---

## 3. ⚠️ The data fix you could not have seen — and why your expected `netProfit` was right

You predicted `netProfit` 86,810.96 for partner 4 (net − commission). The API would have returned
**88,566.96**, and both were defensible, which is the sign something underneath was wrong.

**Pre-conversion bookings never captured a commission at all.** `commission_amount` was 0, so the
`partner_share` backfill computed `subtotal − 0 = subtotal` — crediting the partner the **full net
base with no commission deducted**. Meanwhile every report *imputed* 2% at query time. So Mamsa was
reporting a commission it had never actually taken, and the wallet would have paid out as though
there were none.

The four tiles could not be made to reconcile while that was true, whichever basis we picked.

Fixed with `bookings:freeze-commission`, which captures the same 2% the reports were already imputing:

| Env | Result |
|---|---|
| staging | **22 bookings, 1,935.20 SAR commission captured, 22 wallet adjustments** |
| production | **0 bookings — nothing to fix** |

Two safeguards worth naming:

- **Bookings already attached to a payout are skipped.** That money has moved and the payout's amount
  is frozen against those exact rows; rewriting their share would falsify a completed transfer.
- **Where an earning had already reached the ledger, a compensating adjustment was posted**, so the
  balance stays exactly the sum of its rows. Verified after the run: **every wallet reconciles**,
  `balance == SUM(ledger)` and `newest.balanceAfter == balance`, across all three partners with data.

Production had zero completed bookings, so this landed before any real money existed — which is the
only good time for it.

---

## 4. `perUnit[].revenue` stays **gross**

Consistent with `grossRevenue` directly above it, and it is the figure a partner recognises as "what
this unit took". Unchanged.

---

## 5. The exports moved too — you did not ask, and they needed it

The CSV and PDF downloads were still computing `total − commission` on the gross basis. **A partner
downloading a report would have got different numbers from the screen they downloaded it from** —
and a downloaded file is the version that ends up in an email to support.

Both now use the same shared helpers as the summary, so the three cannot drift apart again.

---

## 6. Why this went unverified for so long

`/reports/summary` used MySQL-only `DATE_FORMAT`, so **the endpoint could not be exercised by a test
at all** — every attempt died on sqlite before reaching an assertion. That is the honest reason its
money basis survived this long unchallenged.

Now driver-aware, and covered by six tests that pin the basis, the reconciliation of the tiles, the
fee split, and both safeguards on the freeze command.

---

## 7. On your §4 note

Thank you for flagging the client-side consequence rather than absorbing it silently. *"I only
changed my name and now it says under review"* is exactly how that would have arrived as a support
ticket blamed on the backend, and your warning-on-any-edit fix is the right shape.

---

## 8. Deploy state — 2026-08-15

| | staging | production |
|---|---|---|
| `/reports/summary` VAT-exclusive basis | ✅ live | ✅ live |
| `fees` field | ✅ live | ✅ live |
| CSV / PDF exports aligned | ✅ live | ✅ live |
| `bookings:freeze-commission` | ✅ run (22 rows) | ✅ run (0 rows) |
| Wallet integrity after the run | ✅ all reconcile | ✅ nothing to reconcile |

Suite: **203 passed, 1130 assertions.**

**One basis everywhere now** — reports, wallet, payouts, admin bookings, admin partners. There is no
surface left on the gross basis.
