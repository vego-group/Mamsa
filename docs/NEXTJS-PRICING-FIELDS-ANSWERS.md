# Backend answers — Pricing fields (Cleaning Fee / Service Fee / Tax)

**Date:** 2026-07-18 · **Status:** ✅ implemented, deployed to staging, all payloads below verified live against `https://staging.mamsaa.com`.

Answers to the four questions in `Mamsa-Backend-Pricing-Fields.md`, in order — then one important upgrade you get for free (§5).

---

## 1. `cleaningFee` — yes: per-unit, mandatory, partner-editable ✅

- New column `units.cleaning_fee`, **default 0** when the partner sets nothing. Never null, always present.
- **Partner-editable** on both partner surfaces:
  - Dashboard (partner.mamsaa.com): `POST /units` and `PATCH /units/{id}` accept `cleaningFee` (numeric ≥ 0, optional — omitted/null ⇒ 0). Echoed back on every unit object: `"cleaningFee": 150`.
  - The field also exists on the internal Vue partner area as `cleaning_fee`.
- **User-site** (`GET /api/v1/units/{id}`, listings, etc.): returned as `cleaning_fee` — note the **snake_case**; the user-site API is snake_case everywhere, camelCase is a dashboard-only convention. Same for `price` (the user-site name for `pricePerNight`).

```json
// GET https://staging.mamsaa.com/api/v1/units/2   (verified)
{ "id": 2, "price": 450, "cleaning_fee": 0, ... }
```

Existing units were **not** backfilled with the old platform value — they start at 0 and each partner sets their own.

## 2. `/admin/platform-settings` — yes, exactly as specced ✅

```
GET   /api/v1/admin/platform-settings              (any admin — Bearer)
  → { "success": true, "data": { "service_fee_percent": 10, "tax_percent": 15 } }

PATCH /api/v1/admin/platform-settings              (SuperAdmin ONLY)
  body: { "service_fee_percent": 12.5 }            (0–100)
  → 200 with the updated data object
```

Verified behaviors (all tested live on staging):
- Plain `Admin` PATCH → **403** (strict role gate at route level). GET is fine for any admin.
- `tax_percent` in the PATCH body → **422** validation error (`prohibited` rule). It is read-only for every role, per the approved decision — it's the legal KSA VAT rate (**15%**), changeable only via server config.
- A PATCH applies **immediately to new quotes** (settings are DB-backed + cached, cache busted on write). Existing bookings are untouched (see §4).

For the superadmin settings screen: render both values from GET, submit only `service_fee_percent`.

> The user-site checkout does **not** need this endpoint — see §5.

## 3. Payment matching — exact, zero rounding drift ✅

The formula is now exactly your doc's formula:

```
subtotal   = pricePerNight × nights
serviceFee = round(subtotal × serviceFeePercent / 100, 2)
tax        = round((subtotal + cleaningFee + serviceFee) × 15 / 100, 2)
total      = subtotal + cleaningFee + serviceFee + tax
```

Key detail for your "rounding difference" question: **there is none, by construction.**
- Every line item is rounded to 2 decimals *before* summing, so `total` is always an exact 2-decimal number.
- `POST /payments/initiate` computes **nothing** — it charges the frozen `booking.total_amount` and derives `amount_halalas = total × 100`, which is therefore an exact integer. Verified: total `4025.00` → `402500` halalas.
- The one rule you must follow: **display the backend's numbers, never recompute them in JS** (floating-point re-derivation is where mismatches would come from). Where to get them: §5.

## 4. Snapshot — frozen at booking creation ✅

Same principle as the FR-036 cancellation-policy snapshot:

- The full breakdown (`nightly_rate, subtotal, service_fee, cleaning_fee, taxes, total_amount`) is **frozen onto the booking row at `POST /bookings`**.
- If the superadmin changes `service_fee_percent` — or we change the VAT rate — after the guest reached checkout but **before creating the booking**, the next quote reflects the new rates.
- After the booking exists, **nothing re-prices it**: payment (even hours later), receipts, and refund math all use the frozen values.

So the safe checkout order is: quote → `POST /bookings` → show the breakdown **from the booking response** → `POST /payments/initiate`. Between quote and booking creation a rate change is *possible* but the window is seconds; the booking response is authoritative, so re-render from it and no warning message is ever wrong.

## 5. Free upgrade: the availability endpoint now returns the whole breakdown

`POST /api/v1/units/{id}/availability` now returns a server-computed `pricing` block whenever `available: true` — use it to render the checkout summary instead of doing any math client-side:

```json
// POST https://staging.mamsaa.com/api/v1/units/2/availability
// { "start_date": "2026-09-01", "end_date": "2026-09-04" }     (verified)
{
  "available": true,
  "pricing": {
    "nights": 3,
    "nightly_rate": 450,
    "subtotal": 1350,
    "service_fee": 135,
    "service_fee_percent": 10,
    "cleaning_fee": 200,
    "taxes": 252.75,
    "tax_percent": 15,
    "total": 1937.75
  }
}
```

- `service_fee_percent` / `tax_percent` are included so you can label the lines ("رسوم الخدمة 10%", "الضريبة 15%") without calling the admin endpoint.
- `POST /bookings` freezes the **identical** math (shared code path in the backend), so quote → booking → charge can never disagree.
- Mamsa's commission is deliberately **absent** — it's partner-facing, never part of the guest total.

## Env & rollout

| Env | Status |
|---|---|
| `staging.mamsaa.com` | ✅ live (everything above verified against it) |
| `api.mamsaa.com` (prod) | ✅ live since 2026-07-18 — same contract, verified (`service_fee_percent: 10`, `tax_percent: 15`) |

Postman: collection updated — `02 → Check Availability` (new pricing block documented), `09 — Admin → Platform Settings` (GET + PATCH), unit create/update bodies now include the cleaning fee.
