# Mamsa — Confirmed: VAT is on BOTH environments · and the invoice page call

**From:** backend · **Date:** 2026-08-15
**Verified:** both servers, 2026-08-15 — every claim below was re-checked live before writing.

## TL;DR — three things for you

1. **VAT-inclusive pricing is live on staging AND production.** Your change affects real guests
   immediately — **ship the same day** (§1).
2. **Keep the invoice page closed.** Ship a clearly-labelled **receipt** instead — a tax invoice with a
   placeholder QR is worse than a missing feature (§3). This is the decision you asked for.
3. **No real partner was affected** by the price-model change — the one production unit belongs to a
   test account (§2).

Wiring details (payloads, keys, casing) are unchanged and already in
`MAMSA-FRONTEND-VAT-IS-LIVE-WIRE-TODAY.md`. This file only covers what was still open.

---

## 1. Deployment: both environments — and an apology for the confusion

| Environment | VAT-inclusive? | 450 × 2 nights |
|---|---|---|
| `staging.mamsaa.com` | ✅ live | gross **900** · net 782.61 · vat 117.39 |
| `api.mamsaa.com` (**production**) | ✅ live | gross **900** · net 782.61 · vat 117.39 |

**So it is the second of your two scenarios:** real guests already see the new prices, and your side
should ship today rather than waiting for a production window.

**On the two contradictory documents** — both were accurate when written, one before the deploy and one
after, and neither was timestamped or marked as superseding the other. That is a backend documentation
failure, not an ambiguity you should have had to resolve. Every status claim from here carries the
environment and the time it was verified.

**Practical consequence right now:** production is serving VAT-inclusive prices while your build still
shows the amber "does not include VAT" caveat. That caveat is now **wrong** — it under-states nothing,
but it tells guests the price will grow at checkout when it will not. Worth prioritising the takedown.

---

## 2. What you will see in the data

### 2.1 The price drop is real and intended

The single production unit went from **517.50** payable (450 + VAT) to **450.00** payable. That is the
whole point of the conversion, not a pricing bug.

### 2.2 No real partner lost income

The unit belongs to `+966555000002` — one of the three **test accounts** (no email, created for
testing). So the ~13% partner-share reduction affects nobody real, and there is no partner to notify.

Recorded for later, because it will matter with real listings: when real partners exist, either they
set gross prices from the start, or every existing price is converted (× 1.15) **and the partner is
told before it takes effect**. Never silently.

### 2.3 Pre-deploy bookings keep their old numbers — do not "fix" this

Booking `#107` (created 21:54 on 14 Aug, before the deploy) still shows **1035.00**. Its split was
**frozen at creation**, which is required: a pricing-model change must never re-price a booking that
already exists.

So you will briefly see both models in the data. **Render whatever the API returns** — do not recompute
client-side to make them agree.

---

## 3. 🔴 The invoice page — recommendation: keep it closed

You asked whether to open the invoice screen with a placeholder QR or keep it locked until the real QR
ships. **Keep it locked.**

**Why:** a tax invoice is a legal document. One carrying a placeholder QR is **not a valid tax
invoice**, and a guest cannot tell the difference. If they download it, file it, or submit it for a VAT
reclaim, the failure surfaces later — at the tax authority rather than in your UI, and by then it has
your branding on it.

### 3.1 The middle path, if you need something shippable now

Ship a **booking receipt** — explicitly labelled as a receipt, **not** a tax invoice:

- Title it plainly (e.g. **إيصال حجز** / "Booking receipt"), never "فاتورة ضريبية".
- Show the breakdown that is **already live**: `gross`, `net_base`, `vat`, `vat_rate`, nights, unit.
- **No QR**, and no VAT registration number — their absence is what keeps it honest.
- Optionally note that a tax invoice will be available separately.

That is genuinely useful to a guest, ships today with data you already have, and claims no compliance
status it lacks. Swap in the real invoice when the QR lands — the layout largely carries over.

### 3.2 Timeline for the real invoice

| Piece | Estimate |
|---|---|
| TLV encoder + base64 + tests | 0.5 d |
| Invoice endpoint, numbering, bilingual fields | 1.0 d |
| Credit-note variant (refunds) | 0.5 d |
| **Total** | **~2 days** |

**But the estimate is not the constraint — the data is.** The QR encodes the seller's real VAT
registration number, and it cannot be meaningfully tested against a fake one, because the entire
purpose of the QR is that a ZATCA reader validates those exact values.

**Blocked on four company records** (none exist in the codebase; all are yours or the owner's to
supply):

| Field | Format |
|---|---|
| VAT registration number | 15 digits, starts and ends with `3` |
| Commercial Registration (CR) | 10 digits |
| Company address | Arabic free text |
| Legal seller name | exactly as registered |

The clock on the ~2 days starts when those arrive. Nothing else in the VAT phase is blocked by them.

---

## 4. Everything else in the VAT phase

| Item | Status |
|---|---|
| Guest price display (`gross`/`net_base`/`vat`) | ✅ **live on both** |
| Partner booking split (camelCase incl. `partnerShare`) | ✅ live on both |
| Reports `netRevenue` / `vat` / `vatCollected` | ✅ live on both |
| Guest surfaces stripped of `commission` / `partner_share` | ✅ live on both |
| Admin authorization enforcement (50 routes) | ✅ live on both |
| Admin BFF booking `PriceBreakdown` | to build |
| Partner reports `netProfit` → `partnerShare` | to build |
| **Tax invoice + ZATCA QR** | 🔴 blocked on §3.2 |

---

## 5. Checklist

- [ ] Take down the amber "price excludes VAT" caveat — it is now inaccurate (§1)
- [ ] Ship the VAT-inclusive display today; production is already serving it (§1)
- [ ] Remove any client-side `× 1.15`
- [ ] Leave pre-deploy bookings showing their frozen numbers (§2.3)
- [ ] Keep the invoice route closed; ship a labelled **receipt** if you need something now (§3)
- [ ] Chase the VAT number, CR, address and legal seller name — they start the invoice clock (§3.2)
