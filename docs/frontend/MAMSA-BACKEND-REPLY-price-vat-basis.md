# `price_per_night` is VAT-INCLUSIVE — your assumption is correct

**From:** backend · **Date:** 2026-08-29
**Re:** "هل `price_per_night` المخزّن شامل الـ VAT ولا قبله؟"
**For:** all three Next.js apps

**Short answer: inclusive.** Dividing by 1.15 to get the net base is exactly right, and it is what
the backend itself does. Verified in the code and against a live production quote.

---

## 1. What the code does

The parameter is named for what it is, and the stored `units.price` is what gets passed to it:

```php
Pricing::breakdown((float) $unit->price, $nights, …)

public static function breakdown(float $nightlyGross, int $nights, …)
{
    $gross   = round($nightlyGross * $nights, 2);
    $netBase = round($gross / (1 + $vatRate), 2);   // ← VAT carved OUT — your /1.15
    $vat     = round($gross - $netBase, 2);         // ← by SUBTRACTION, see §3
    …
}
```

So the stored price is the **final price to the guest**. Nothing is added at checkout — no service
fee, no cleaning fee (both abolished 2026-07-18), and the VAT is already inside the number.

---

## 2. Verified on production, not just read

Unit 35, stored at **360 SAR**, quoted for three nights:

```
GET  /api/v1/units/35                       price: 360
POST /api/v1/units/35/availability          2027-03-01 → 2027-03-04

  total    (guest pays)   1080.00     = 360 × 3, nothing on top
  subtotal (net base)      939.13     = 1080 / 1.15      ← your derivation
  taxes    (VAT)           140.87

  939.13 + 140.87 = 1080.00
```

---

## 3. ⚠️ Don't compute the VAT yourself if you can avoid it

`netBase` comes from a division and `vat` from a **subtraction** — deliberately, so that

```
netBase + vat === gross
```

holds *exactly* rather than approximately. If you compute `gross × 0.15` independently you will
occasionally land a halala away from what the invoice says, because two roundings of the same number
do not have to agree.

Read the values instead of deriving them:

| you need | read |
|---|---|
| what the guest pays | `pricing.total` |
| the pre-VAT base | `pricing.subtotal` |
| the VAT | `pricing.taxes` |
| the rate, if you must display it | `tax_percent` on the unit resource — never hardcode 15 |

`POST /units/{id}/availability` returns all of these already computed, and they are the same numbers
`POST /bookings` freezes onto the booking. That is the point of the endpoint: the money math happens
once, on the server.

Deriving `netBase` yourself for a *display* estimate before the quote comes back is fine — that is
what you are already doing, and it is correct. Just don't use your own figure once the server has
given you one.

---

## 4. The partner side is worth saying out loud

A partner typing **360** into their dashboard is setting **what the guest pays**, not what they
earn. At the current 10% commission:

```
guest pays        360.00   per night
  VAT              46.96   → ZATCA
  net base        313.04
    commission     31.30   → Mamsa (10% of the net base, never of the VAT)
    partner        281.74  per night
```

The gap between the number they type and the number they receive is large enough to be worth
stating in the partner UI, if it isn't already. `commission` and `partnerShare` come back per booking
on the dashboard, and `commissionRate` alongside them.

---

## 5. Related contract facts, so nobody re-derives them

- **VAT is 15%**, uniform, and `tax_percent` on the unit resource exposes it so no client hardcodes
  it.
- **Mamsa is the VAT supplier of record.** The VAT never reaches a partner's wallet.
- **Commission is charged on the net base, never on the gross.** `gross × 10%` would be 115 on a
  1,000 base instead of 100, and would break the invariant.
- **Every money figure on a booking is frozen at creation.** A later rate change never re-prices an
  existing booking — read the stored values rather than recomputing from today's config.
