# Backend update — 2026-07-18 (pricing wave)

One-page summary for the Next.js teams of everything that shipped today. **All of it is live on staging AND production.** Import the fresh Postman collection (`Mamsa-API.postman_collection.json`, 130 requests) — it carries all the changes below.

---

## What changed, per endpoint

### 1. `POST /api/v1/units/{id}/availability` — now returns the checkout breakdown
When `available: true`, a `pricing` object is included: `nights, nightly_rate, subtotal, service_fee, service_fee_percent, cleaning_fee, taxes, tax_percent, total`. Render it verbatim — never compute money in JS. `total × 100` is always an exact halalas integer.

### 2. Unit objects — new mandatory cleaning-fee field
- User site: `cleaning_fee` (snake_case) on every unit — always present, `0` if unset.
- Partner dashboard: `cleaningFee` (camelCase) — echoed on every unit, **accepted in `POST /units` / `PATCH /units/{id}`** (numeric ≥ 0, optional, null ⇒ 0). Add it to the wizard's pricing step.

### 3. NEW `GET/PATCH /api/v1/admin/platform-settings`
`{ service_fee_percent, tax_percent }`. GET: any admin. PATCH: **SuperAdmin only** (plain Admin → 403), body = `{ service_fee_percent }` only — sending `tax_percent` → 422 (it's the legal VAT rate, read-only everywhere). This powers the superadmin settings screen.

### 4. Pricing formula — now the approved contract
`tax = (subtotal + cleaning_fee + service_fee) × 15%` (VAT on the full invoice). Service fee is the superadmin-set percent (currently 10). **Production changed today**: it previously charged no fees/VAT at all — new bookings are ~26.5% higher than the bare subtotal. Not a bug.

### 5. Booking objects — frozen percent fields + dashboard invoice block
- Every booking's `pricing` block now includes `service_fee_percent` + `tax_percent`, **frozen at booking creation** — later settings changes never alter them. Legacy rows were backfilled exactly (zero-fee era → 0). Never null.
- Partner-dashboard bookings gained a full camelCase `pricing` block (guest invoice lines) alongside the unchanged partner-facing `financials`.
- Reminder: `GET /api/v1/bookings/{id}` is resource-wrapped → `data.pricing.…`; the `POST /bookings` response is unwrapped.

## Action items by surface

| Surface | To build |
|---|---|
| mamsaa.com checkout | Render ملخص السعر from availability `pricing`; after `POST /bookings` re-render from the booking's frozen block; percent labels from the `*_percent` fields |
| partner.mamsaa.com | `cleaningFee` input in the property wizard; (later) invoice/booking-details screens read the new `pricing` block |
| superadmin dashboard | Settings screen on `platform-settings` — tax field locked, save gated to SuperAdmin |

## Detailed docs (all in your Downloads too)

| Doc | Covers |
|---|---|
| `NEXTJS-PRICING-IMPLEMENTATION.md` | Build guide per surface, TS interfaces, error tables, staging test checklist |
| `NEXTJS-PRICING-FIELDS-ANSWERS.md` | Answers to your 4 pricing questions (snapshot semantics, rounding proof) |
| `NEXTJS-BOOKING-PERCENT-FIELDS.md` | The frozen percent fields + dashboard invoice block, verified payloads |
| `NEXTJS-API-FLOWS.md` | Master flow map — updated with all of the above |

Test on `https://staging.mamsaa.com` (fixed OTP `111222`). If you PATCH the service fee while testing, restore it to `10` — staging is shared.
