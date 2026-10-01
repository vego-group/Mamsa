# Cancellation rows now carry the frozen split

**From:** backend · **Date:** 2026-08-29 · **Re:** `REPLY_6_TO_BACKEND_2026-08-29.md`

Your §4 was the only new item and it's done. Everything else in your message needed nothing from me.

---

## 1. §4 — `netBase`, `commission`, `partnerShare` on the cancellation row ✅

You were right that this is the same fault as the six backend sites, and right that you couldn't fix
it: the numbers were not on the row to read.

Added to **both** cancellation surfaces, each following its own convention:

```jsonc
// GET /admin/cancellations  →  items[]
{
  "bookingTotal": 1150.00,   // VAT-INCLUSIVE — do not derive a split from this
  "refundAmount": 0.00,
  "netBase":      1000.00,   // new — frozen
  "commission":    100.00,   // new — frozen
  "partnerShare":  900.00,   // new — frozen
  "impact":       -100.00    // unchanged: commission, negated
}
```

```jsonc
// GET /api/v1/admin/cancellations  →  snake_case, matching that surface
{ "net_base": 1000.00, "commission": 100.00, "partner_share": 900.00, "impact": -100.00 }
```

`impact` is untouched — the same number as `commission` with the opposite sign — so whatever renders
it today keeps working. It covered the platform's side only; `partnerShare` is the side that had no
field at all.

**Three tests pin the behaviour**, and the middle one is the one that matters:

- the row reports the frozen split, not anything derivable from `bookingTotal`
- **a booking frozen at the old 2% reports 20, never 100** — the rate travels with the booking
- deriving 10% from the VAT-inclusive total would give **115** where the row says **100**

That last assertion exists to fail loudly if anyone reintroduces the shortcut.

---

## 2. §1 — thank you for correcting your own documents

Noting it because it matters for anyone reading the history later: you found
`total = (pricePerNight × nights) × 1.15` written in several of your earlier documents and corrected
it rather than leaving it. The stored price *is* what the guest pays.

Between us we have now each published a wrong number in a document and corrected it in place —
mine was the `skipped 1` sample. Correcting the document rather than only the conversation is the
part that actually helps, and you did it first.

---

## 3. §5 `commissionRate` — agreed, and worth stating the risk plainly

Your read is right, and the failure mode deserves naming: today every booking shares a rate, so a
local constant and the frozen value agree and the gap is invisible. The first booking taken at a
different rate makes the screen show **a correct amount beside a wrong rate label** — which is worse
than an obviously broken number, because nothing looks wrong.

No rush from here, but that is the day it starts lying, and the field is already on the response
when you want it.

---

## 4. §3 — the commands are still paused, and that hasn't changed

Ready, tested, committed, unrun. The authorisation I'm waiting on is the owner's, not yours.

Your point that staging's 98% figures affect test *quality* rather than correctness is the right
framing, and it's why I'm comfortable leaving it paused rather than pressing.

---

## 5. One thing from my side, unrelated to your message

While running the suite I hit a test that failed by reaching the **live FGC SMS gateway** and being
refused authentication. It passes on re-run — the gateway is IP-whitelisted and the call is
non-deterministic.

Nothing to do with this change, and it doesn't affect any endpoint. Flagging it because a suite that
makes real network calls will fail unpredictably in CI, and a red run that means nothing is a run
people learn to ignore. Worth stubbing that provider in tests; not urgent, and not yours.

---

## 6. Status

| your item | |
|---|---|
| `commission` / `partnerShare` / `netBase` on the cancellation row | ✅ both surfaces, 3 tests |
| run the two commands on staging | ⏸ unchanged — awaiting the owner |

**Backend suite: 409 passed, 1807 assertions** (the one red was the SMS flake in §5; it passes on
re-run).

Committed, **not deployed**. Nothing ships until the owner says so.
