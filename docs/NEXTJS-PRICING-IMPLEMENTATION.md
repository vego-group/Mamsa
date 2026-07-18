# Frontend implementation guide — Pricing (Cleaning Fee / Service Fee / VAT)

**Date:** 2026-07-18 · **Backend status:** ✅ live on `https://staging.mamsaa.com` **and** `https://api.mamsaa.com` (prod, verified same-day) — every payload in this doc was captured from real responses. Build and test against staging.

Companion docs: `NEXTJS-PRICING-FIELDS-ANSWERS.md` (the "why" — answers to your 4 questions), `NEXTJS-API-FLOWS.md` (where these calls sit in the full flows).

**The one rule:** the backend computes all money; the frontend only *renders* it. Never multiply, add, or round prices in JS — every screen below gets its numbers from an API response.

---

## 1. User website (mamsaa.com) — checkout page

### 1.1 Quote: availability now returns the whole breakdown

```
POST /api/v1/units/{id}/availability          (public, no auth)
body: { "start_date": "2026-09-01", "end_date": "2026-09-04" }
```

```json
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

```ts
interface AvailabilityResponse {
  available: boolean;
  pricing?: PriceBreakdown;          // present ONLY when available === true
}
interface PriceBreakdown {
  nights: number;
  nightly_rate: number;
  subtotal: number;                  // nightly_rate × nights
  service_fee: number;
  service_fee_percent: number;       // for the line label
  cleaning_fee: number;              // per-unit, may be 0 — always render the line? see 1.2
  taxes: number;                     // 15% of (subtotal + cleaning + service)
  tax_percent: number;               // for the line label
  total: number;                     // what Moyasar will charge, exactly
}
```

### 1.2 Rendering the ملخص السعر

| Line | Source | Label example |
|---|---|---|
| `{nightly_rate} ر.س × {nights} ليال` | `subtotal` | `1350` |
| رسوم الخدمة ({service_fee_percent}٪) | `service_fee` | `135` |
| رسوم التنظيف | `cleaning_fee` | `200` — hide the row if `0`, your call |
| الضريبة ({tax_percent}٪) | `taxes` | `252.75` |
| **الإجمالي** | `total` | **`1937.75`** |

- Format with a locale formatter for *display only* (`Intl.NumberFormat`), never for math.
- `unit.cleaning_fee` also appears on `GET /api/v1/units/{id}` (snake_case on the user-site API, always present, default 0) if you want to show "+ رسوم تنظيف 200 ر.س" on the unit card **before** dates are chosen. After dates are chosen, the `pricing` block is the truth.

### 1.3 Booking + payment sequence (unchanged endpoints, new certainty)

```
POST /units/{id}/availability   → render pricing (above)
POST /bookings                  → 201; response embeds the FROZEN breakdown — re-render from it
POST /payments/initiate         → amount_halalas === frozen total × 100, exactly
```

- After `POST /bookings`, switch the summary to the **booking response's** breakdown fields (same names, snake_case). They are frozen — a superadmin changing the service fee between quote and payment can no longer affect this booking, so you never need a "price changed" warning after booking creation.
- There is **no rounding delta** to handle: every line is a 2-decimal value server-side and `total × 100` is an exact halalas integer. If you ever see the checkout total ≠ Moyasar charge, that's a bug — report it, don't patch it client-side.

## 2. Partner dashboard (partner.mamsaa.com) — add/edit property wizard

`cleaningFee` (camelCase here — dashboard convention) is now accepted and echoed on every unit object.

```
POST  /units          body may include: "cleaningFee": 150
PATCH /units/{id}     same field, same rules
GET   /units, /units/{id}   → every unit now has "cleaningFee": number   (0 if unset)
```

Rules (server-enforced, mirror them in the form):
- numeric, `>= 0`, optional — omitted or `null` ⇒ stored as `0`
- Sits naturally in the pricing step next to `pricePerNight`. Suggested field: "رسوم التنظيف (اختياري)" with helper text "تُضاف مرة واحدة لكل حجز".
- It's a normal editable field: like any edit, PATCHing it on an **approved** unit sends the unit back to review (`pending`) — existing wizard behavior, nothing new to build, but worth a hint in the UI.
- No migration worries: all existing units have `cleaningFee: 0`.

Display: you can show the guest-side estimate anywhere in the dashboard using the unit's own numbers, but for real totals always use booking objects (they carry the frozen values).

## 3. Superadmin dashboard — new settings screen (شاشة الإعدادات)

```
GET   /api/v1/admin/platform-settings           (Bearer — any admin)
→ { "success": true, "message": "", "data": { "service_fee_percent": 10, "tax_percent": 15 } }

PATCH /api/v1/admin/platform-settings           (Bearer — SuperAdmin ONLY)
body: { "service_fee_percent": 12.5 }
→ 200 { "success": true, "message": "تم تحديث الإعدادات", "data": { "service_fee_percent": 12.5, "tax_percent": 15 } }
```

Build:
- **Two fields, one editable.** `service_fee_percent`: number input, 0–100, step 0.5 or free decimal. `tax_percent`: read-only/disabled with a lock hint ("نسبة قانونية — لا يمكن تعديلها"). **Never include `tax_percent` in the PATCH body** — the backend rejects the whole request with 422 if present.
- **Gate the edit UI by role:** a plain `Admin` GET works, but PATCH returns **403** — hide/disable the save button unless the logged-in admin is SuperAdmin. (403 body is the standard error shape; don't parse its text.)
- On save success, re-render from `data` (source of truth).
- Show an inline note: "التعديل يسري فورًا على الحجوزات الجديدة فقط — الحجوزات القائمة لا يتغير سعرها" (that's the frozen-snapshot behavior, verified).

Error handling table:

| Status | Meaning | UI |
|---|---|---|
| 200 | saved | success toast + re-render from `data` |
| 403 | not SuperAdmin | shouldn't happen if UI gated; show "غير مصرح" |
| 422 | out of range, or `tax_percent` was sent | show field error from `errors.service_fee_percent` |

## 4. Test checklist (staging)

1. Unit 2, `2026-09-01 → 2026-09-04`: expect `subtotal 1350`, `service_fee 135`, `taxes` = 15% of (1350 + cleaning + 135), `total` matching the sum. ✔ backend-verified
2. Create a booking from that quote → booking response breakdown identical to the quote.
3. `payments/initiate` → `amount_halalas` = total × 100 exactly.
4. Dashboard: create/edit a unit with `cleaningFee: 150` → echoed back; new quotes for that unit include it.
5. Superadmin PATCH 12.5 → immediate new quote shows 12.5%; **restore to 10 when done** (shared staging).
6. Admin (non-super) PATCH → 403. PATCH with `tax_percent` → 422.
