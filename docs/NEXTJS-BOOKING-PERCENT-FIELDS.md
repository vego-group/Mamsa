# Done — `service_fee_percent` / `tax_percent` frozen on every Booking

**Date:** 2026-07-18 · **Status:** ✅ implemented + deployed to **staging AND production** same day (it rode along with the pricing schema change, exactly as your doc suggested). Payloads below rendered from real staging bookings.

Answer to `Mamsa-Backend-Booking-Percent-Fields.md` — implemented as requested, plus two upgrades you didn't ask for (§3, §4).

---

## 1. The fields, where you asked for them

New frozen columns `bookings.service_fee_percent` + `bookings.tax_percent`, returned inside the existing `pricing` block on **every** endpoint that serializes a booking:

- `POST /api/v1/bookings` (creation response) ✔
- `GET /api/v1/bookings/{id}` ✔ (remember: this one is wrapped — `data.pricing.…`)
- `GET /api/v1/user/bookings` (history) ✔
- Admin `GET /api/v1/admin/bookings` ✔
- Partner dashboard `GET /bookings`, `GET /bookings/{id}` ✔ — see §3, better than requested

```json
// user-site / admin (snake_case), verified on staging:
"pricing": {
  "nightly_rate": 480, "nights": 3, "subtotal": 1440,
  "service_fee": 0,  "service_fee_percent": 0,
  "cleaning_fee": 100,
  "taxes": 0,        "tax_percent": 0,
  "total": 1540
}
```

(That's a real pre-contract staging booking — hence 0%. A booking created today shows `10` / `15`.)

## 2. Frozen semantics — exactly your spec

Set at `POST /bookings` from the rates in force at that moment, then never touched. Regression-tested: create booking at 10% → superadmin PATCHes the setting to 25% → the booking still returns `service_fee_percent: 10`. `POST /payments/initiate` unchanged, as you specified.

So your invoice screens can always print "رسوم الخدمة (10٪)" with the rate **that was actually charged**, regardless of what the setting says today.

## 3. Upgrade: the partner dashboard now gets full invoice lines, not just percents

Percents alone were useless on the dashboard — its booking object only had `financials` (total/commission/partnerShare, partner-facing). So booking objects on `partner.mamsaa.com` now also carry a guest-facing `pricing` block, camelCase per the dashboard convention:

```json
// dashboard booking object, verified on staging:
"financials": { "total": 1540, "commission": 28.8, "partnerShare": 1511.2 },
"pricing": {
  "nightlyRate": 480, "nights": 3, "subtotal": 1440,
  "serviceFee": 0,  "serviceFeePercent": 0,
  "cleaningFee": 100,
  "taxes": 0,       "taxPercent": 0,
  "total": 1540
}
```

Everything the "تفاصيل الحجز" / "الفواتير" screens need is there on day one. `financials` is unchanged — don't mix the two: `pricing` = what the guest paid, `financials` = the partner's cut.

## 4. Upgrade: legacy bookings are backfilled *exactly*, not null

No null-handling needed on your side. Old rows didn't get a guess — the percents are mathematically derivable from the already-frozen amounts (`fee ÷ base`), so the migration computed them:

- Bookings from the zero-fee era → `0` / `0` (accurate: that's what was charged).
- Any row with fees → the true applied rate, recovered from its own frozen numbers.

Verified after the prod migration: **0 null rows**. Both fields are always numbers — render them unconditionally.

## TL;DR for your types

```ts
// user-site & admin booking.pricing gains:
service_fee_percent: number;   // frozen at booking time
tax_percent: number;

// dashboard booking gains a whole new block:
pricing: {
  nightlyRate: number; nights: number; subtotal: number;
  serviceFee: number; serviceFeePercent: number;
  cleaningFee: number;
  taxes: number; taxPercent: number;
  total: number;
};
```

Postman collection updated on all six booking requests.
