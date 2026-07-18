# Done — cleaning fee + service fee abolished (owner decision)

**Date:** 2026-07-18 · **Status:** ✅ implemented + deployed to **staging AND production** the same evening. Every payload below verified live. **This supersedes all four pricing docs from earlier today** (each now carries a banner pointing here).

Reply to `Mamsa-Backend-Revert-Fees.md` — everything cancelled as requested, plus the answer to your migration-safety question (§4, read it — it affects your types).

---

## 1. The new (final) contract — exactly your spec

```
subtotal = pricePerNight × nights
tax      = subtotal × 15%
total    = subtotal + tax
```

```json
// POST https://api.mamsaa.com/api/v1/units/1/availability   (verified on PROD)
{
  "available": true,
  "pricing": {
    "nights": 3,
    "nightly_rate": 450,
    "subtotal": 1350,
    "taxes": 202.5,
    "tax_percent": 15,
    "total": 1552.5
  }
}
```

Byte-identical to the shape in your doc. Still true: lines pre-rounded to 2dp, `total × 100` = exact halalas, `POST /bookings` freezes identical math, commission (2%/98%) completely untouched.

## 2. What was removed, where

| Removal | Status |
|---|---|
| `cleaning_fee` on unit objects (user site) | ✅ gone — the DB column itself was dropped |
| `cleaningFee` on dashboard unit objects | ✅ gone |
| `cleaningFee` in `POST /units` / `PATCH /units/{id}` | ✅ **silently ignored** (your "whichever is easier" → ignore: an old client build still sending it won't 422) |
| fee lines in the availability `pricing` block | ✅ gone |
| fee lines in NEW bookings' frozen pricing | ✅ gone (see §4 for OLD bookings) |
| `PATCH /admin/platform-settings` | ✅ removed entirely — returns **405** now |
| `GET /admin/platform-settings` | kept read-only per your suggested option → `{ "success": true, "data": { "tax_percent": 15 } }` (verified) |

## 3. Snapshot — unchanged principle, tax only

`tax_percent` (and `taxes`) are still **frozen at booking creation**, as you asked. Nothing else is frozen for new bookings because nothing else exists.

## 4. Your question: yes — 62 prod bookings carry real fees. Here's the handling.

> هل فيه أي حجوزات فعلية شايلة قيم غير صفرية؟

**Yes.** Checked both DBs before migrating:

- **Prod: 62 bookings** with non-zero `service_fee` **and** `cleaning_fee` — all from **2026-06-30 → 2026-07-06** (the era before fees were zeroed in prod config). **Zero** bookings from today's short-lived 10%/15% contract.
- Staging: 44/49 rows (test data).
- Units with a partner-set cleaning fee: **0** on both — dropping the unit column lost nothing.

**Decision (implemented): kept as historical data — no retroactive zeroing.** `total_amount` is a charged, frozen financial record; zeroing the lines while keeping the total would make invoices display `subtotal + tax ≠ total`, which looks like a billing bug and is unauditable. Instead:

- New/fee-free bookings → `pricing` contains **only** `nightly_rate, nights, subtotal, taxes, tax_percent, total`.
- The 62 historical bookings → the same block **plus** their frozen `service_fee`, `service_fee_percent`, `cleaning_fee`, so the lines still sum to the total the guest actually paid. Verified live on a real prod row: `1800 + 180 + 300 + 126 = 2406` ✓.

**So in your types, the fee keys are optional:**

```ts
interface BookingPricing {
  nightly_rate: number; nights: number; subtotal: number;
  taxes: number; tax_percent: number; total: number;
  service_fee?: number;          // historical bookings only — render the row if present
  service_fee_percent?: number;
  cleaning_fee?: number;
}
// dashboard camelCase twin: serviceFee?/serviceFeePercent?/cleaningFee? on booking.pricing
```

Render fee rows only when the key exists and the migration is safe: no nulls, no broken sums, no rewritten history.

## 5. Housekeeping

- The morning docs (`NEXTJS-PRICING-IMPLEMENTATION`, `NEXTJS-PRICING-FIELDS-ANSWERS`, `NEXTJS-BOOKING-PERCENT-FIELDS`, `NEXTJS-UPDATE-2026-07-18`) are superseded — banners added. The flow map (`NEXTJS-API-FLOWS.md`) is updated to the final contract.
- Postman collection updated (129 requests): availability shape, read-only Platform Settings, fee fields stripped from unit bodies, booking notes rewritten.
- If you shipped the wizard `cleaningFee` field in the last few hours: just remove the input; a stale deployed build keeps working (field ignored).
- Superadmin settings screen: **cancel it** — nothing is editable anymore.
