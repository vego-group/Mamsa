# 🚀 VAT-inclusive pricing is LIVE — staging **and** production

**From:** backend · **Date:** 2026-08-14
**Status:** ✅ **deployed and verified on both environments**
**You said you would wire the same day it hits staging — it is on both, so go.**

## TL;DR

The API now returns the **final payable figure**. VAT is broken out for transparency, never added at
checkout.

- **The amber "السعر المعروض لا يشمل ضريبة القيمة المضافة" caveat can come down.**
- **No key was removed and no column was renamed** — `subtotal` / `taxes` / `total` still ship. They now
  mean net / VAT / gross. A client reading only the old keys keeps working (§3).
- New keys are additive: snake_case on `/api/v1`, camelCase on the partner BFF (§2).

The admin authorization enforcement and the `commission_amount` gating also reached **production** in
the same deploy (§5).

---

## 1. What changed, in numbers

The live production unit — 450 SAR/night, 2 nights:

| | Before | After |
|---|---|---|
| **Guest pays** | **1035.00** | **900.00** |
| net base | — | 782.61 |
| VAT | — | 117.39 |
| Invariant `net + vat` | — | **900.00 = gross** ✓ |

`units.price` is now the **GROSS, VAT-inclusive** nightly price. Render it verbatim; never multiply.

```
gross        = nightly × nights          ← what the guest pays
netBase      = gross / 1.15
vat          = gross − netBase
commission   = netBase × 2%              ← internal, never sent to a guest
partnerShare = netBase − commission      ← internal / partner-only
```

`vat` and `partnerShare` are derived by **subtraction**, so both contract invariants are exact under
rounding — `netBase + vat === gross` and `commission + partnerShare + vat === gross`. Verified against
the contract's worked examples to the halala: 500×2 → 869.57 / 130.43 / 17.39 / 852.18.

---

## 2. Exact payloads

### 2.1 Guest API (`/api/v1`) — snake_case

`POST /api/v1/units/{id}/availability` and `GET /api/v1/bookings/{id}`:

```jsonc
"pricing": {
  "nightly_rate": 450.00,
  "nights": 2,
  "gross": 900.00,       // NEW — the payable figure
  "net_base": 782.61,    // NEW
  "vat": 117.39,         // NEW
  "vat_rate": 0.15,      // NEW
  "subtotal": 782.61,    // legacy key — now the NET base
  "taxes": 117.39,       // legacy key — the VAT
  "tax_percent": 15.0,
  "total": 900.00        // legacy key — the GROSS
}
```

**`commission` and `partner_share` are absent from every guest surface** — verified live on both
environments.

### 2.2 Partner dashboard (root) — camelCase

```jsonc
"pricing": {
  "nightlyRate": 450.00, "nights": 2,
  "gross": 900.00, "netBase": 782.61, "vat": 117.39, "vatRate": 0.15,
  "commission": 15.65, "partnerShare": 766.96,   // partner's OWN booking
  "subtotal": 782.61, "taxes": 117.39, "taxPercent": 15.0, "total": 900.00
}
```

The partner sees their own split — it is their money and their cut.

---

## 3. Why your migration is cheap

Two deliberate properties:

- **Nothing was removed.** `subtotal`, `taxes`, `total` all still ship. They map exactly onto the new
  concepts, so code reading the old keys keeps working — the **numbers change meaning, not the shape**.
- **No database column was renamed.** Only the direction of the arithmetic changed.

**What that means for you:** you can adopt `gross` / `net_base` / `vat` at your own pace. The one thing
to do **now** is stop treating `total` as pre-VAT and remove any client-side `× 1.15`.

- [ ] Remove every `× 1.15` / `× 0.15` / `/ 1.15` in pricing code — the API is authoritative.
- [ ] Show `gross` (or `total`) as the payable amount; itemise `net_base` + `vat` beneath it.
- [ ] Take down the amber VAT caveat.
- [ ] Label the rate from `vat_rate` / `tax_percent`, not a hardcoded 15%.

---

## 4. One behaviour to expect: existing bookings keep their old numbers

Booking `#107` (created 21:54 today, before the deploy) still shows **1035.00** — its split was
**frozen at creation**, which is intentional and required: a rate or model change must never re-price a
booking that already exists.

So briefly you will see both models in the data. New bookings are inclusive; that one is not. This is
correct, not a bug — do not "fix" it by recomputing client-side.

---

## 5. Also now live on production (was staging-only)

| Change | Effect |
|---|---|
| **Admin authorization enforced** | 50 `/admin/*` routes gated. A `finance` session gets **403 `INSUFFICIENT_PERMISSION`** outside its matrix. Superadmin unchanged |
| **`commission_amount` gated** | Removed from guest booking responses; owner and admins still receive it |
| **Webhook fails closed** | An unsigned call to the payment webhook now returns **403** |

Your permission gate is no longer the only gate — on production too.

---

## 6. Still to come in this phase

| Item | Status |
|---|---|
| Admin BFF booking `PriceBreakdown` | to build |
| Partner reports — `netProfit` becomes `partnerShare` | to build |
| **Tax invoice + ZATCA QR** | 🔴 **blocked** — see below |

### 6.1 🔴 The invoice needs the company's VAT number and CR

The ZATCA Phase 1 QR is a TLV-encoded base64 string generated server-side, and it embeds **seller name,
VAT registration number, invoice timestamp, total, and VAT amount**.

Without a real VAT registration number and CR, the invoice cannot be issued — it would produce a QR
that fails validation. **Please confirm both exist and who holds them.** This blocks only the invoice,
not the price display, so everything in §1–§3 can ship today regardless.

---

## 7. Test accounts

Production and staging both accept the three demo numbers with the fixed code (test mode is on again at
the owner's request); real numbers receive a real SMS.

| Phone | Role |
|---|---|
| `+966555000001` | User |
| `+966555000002` | Partner |
| `+966555000003` | SuperAdmin |
| `+966537486167` | SuperAdmin (**real SMS**) |
| `+9665XXXXXXXX` | Partner + User (**real SMS**) |

Ask the backend lead for the current fixed code — it is deliberately not written in any document.

**Note production has one unit and almost no transactional data** — it was cleaned earlier today. If
you need richer data to exercise the reports or lists, use staging.
