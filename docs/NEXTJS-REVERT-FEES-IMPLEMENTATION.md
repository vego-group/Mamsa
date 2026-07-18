# Frontend implementation guide — Fee revert (final pricing: subtotal + 15% VAT)

**Date:** 2026-07-18 · **Backend status:** ✅ live on **staging AND production** — payloads below are real responses. This is the build-side companion to `NEXTJS-REVERT-FEES.md` (the decision/answers doc). It replaces `NEXTJS-PRICING-IMPLEMENTATION.md` from this morning.

**The one rule is unchanged:** the backend computes all money; you only render it. If your code never computed fees itself, this whole revert is mostly *deleting* things.

---

## 1. User website (mamsaa.com) — checkout

### 1.1 The quote (only shape you'll ever see for new bookings)

```
POST /api/v1/units/{id}/availability          (public, no auth)
body: { "start_date": "2026-09-01", "end_date": "2026-09-04" }
```

```json
// verified on PROD, unit 1:
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

```ts
interface AvailabilityResponse {
  available: boolean;
  pricing?: QuotePricing;            // present ONLY when available === true
}
interface QuotePricing {
  nights: number;
  nightly_rate: number;
  subtotal: number;                  // nightly_rate × nights
  taxes: number;                     // subtotal × 15%
  tax_percent: number;               // for the label — الضريبة (15٪)
  total: number;                     // what Moyasar will charge, exactly
}
```

### 1.2 ملخص السعر — now 3 rows

| Line | Source |
|---|---|
| `{nightly_rate} ر.س × {nights} ليال` | `subtotal` |
| الضريبة ({tax_percent}٪) | `taxes` |
| **الإجمالي** | `total` |

**Delete** the رسوم الخدمة and رسوم التنظيف rows and any `service_fee*` / `cleaning_fee` reads from the quote — those keys are simply absent now. Same for any "+ رسوم تنظيف" hint on unit cards: `unit.cleaning_fee` no longer exists on unit objects.

### 1.3 Booking flow (unchanged mechanics)

Quote → `POST /bookings` (re-render from its frozen `pricing`) → `POST /payments/initiate` (`amount_halalas = total × 100`, exact). `tax_percent` is frozen on the booking, so a booking's invoice always shows the rate that applied when it was made.

### 1.4 ⚠️ Booking history screens — fee keys are OPTIONAL, not gone

62 real production bookings (Jun 30 – Jul 6) charged fees. On those rows — and only those — the booking `pricing` block *additionally* contains the frozen `service_fee`, `service_fee_percent`, `cleaning_fee`, so the lines still sum to `total`. Type them optional and render the rows conditionally:

```ts
interface BookingPricing extends QuotePricing {
  service_fee?: number;              // historical bookings only
  service_fee_percent?: number;
  cleaning_fee?: number;
}
// render: if (p.service_fee) → show "رسوم الخدمة (X٪)" row; same for cleaning_fee
```

Do **not** hardcode the 3-row layout in "تفاصيل الحجز" — build the rows from which keys exist, and the sum always matches `total`. (Reminder: `GET /api/v1/bookings/{id}` is wrapped → `data.pricing.…`.)

## 2. Partner dashboard (partner.mamsaa.com)

- **Remove the `cleaningFee` input** from the add/edit property wizard (pricing step) and drop the field from unit types — it's no longer echoed on any unit object.
- **No emergency:** if a build with the field is already deployed, it keeps working — the backend silently ignores `cleaningFee` in `POST /units` / `PATCH /units/{id}` (no 422). Remove it at your own pace.
- Booking objects: the camelCase `pricing` block stays — standing keys `nightlyRate, nights, subtotal, taxes, taxPercent, total`; `serviceFee`/`serviceFeePercent`/`cleaningFee` appear only on historical fee-era bookings (same optional-keys rule as §1.4). `financials` (total/commission/partnerShare) unchanged.

## 3. Superadmin dashboard

**Cancel the settings screen** — nothing is editable anymore:

- `PATCH /api/v1/admin/platform-settings` no longer exists → **405**.
- `GET /api/v1/admin/platform-settings` still works, read-only: `{ "success": true, "data": { "tax_percent": 15 } }` — keep it only if you want to display the VAT rate somewhere; no `service_fee_percent` in the payload.

## 4. Migration checklist for your codebase

1. Types: quote pricing → 6 keys (§1.1); booking pricing → + 3 *optional* fee keys (§1.4).
2. Checkout summary: delete the two fee rows.
3. Unit card/detail: delete any `cleaning_fee` usage.
4. Wizard: remove the `cleaningFee` field.
5. Superadmin: drop the settings form (keep at most a read-only VAT display).
6. Search for `service_fee`, `cleaning_fee`, `serviceFee`, `cleaningFee` project-wide — the only survivors should be the optional-key rendering in booking-details/invoice components.

## 5. Verify on staging

| Check | Expect |
|---|---|
| Quote unit 2, 3 nights @450 | `subtotal 1350, taxes 202.5, total 1552.5`, no fee keys |
| Create booking from quote | identical frozen block, `tax_percent: 15` |
| `payments/initiate` | `amount_halalas = total × 100` |
| Old seeded booking with fees | fee rows render, lines sum to total |
| Settings GET / PATCH | `{ tax_percent: 15 }` / **405** |

Postman collection (129 requests) already reflects all of this — re-import from your Downloads.
