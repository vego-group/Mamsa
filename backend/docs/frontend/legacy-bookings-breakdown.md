# Mamsa — Pre-conversion bookings: display the breakdown

**From:** backend · **Date:** 2026-08-15
**Verified:** production, 2026-08-15 — rendered from the live booking, not inferred.

## Answer: **display them.** No "breakdown unavailable" note needed.

Old bookings return `net_base` and `vat` exactly like new ones, and the numbers are correct.

---

## 1. Booking #107, as the API actually returns it

```jsonc
"pricing": {
  "nightly_rate": 450.00,
  "nights": 2,
  "gross": 1035.00,
  "net_base": 900.00,
  "vat": 135.00,
  "vat_rate": 0.15,
  "subtotal": 900.00,   // legacy alias of net_base
  "taxes": 135.00,      // legacy alias of vat
  "tax_percent": 15.0,
  "total": 1035.00      // legacy alias of gross
}
```

**900 + 135 = 1035** — the `net_base + vat === gross` invariant holds on pre-conversion bookings too.

The partner surface returns the same figures in camelCase (`netBase`, `vat`, plus `commission` and
`partnerShare`).

---

## 2. Why it works

The old model **already** stored the net base in `subtotal` and the VAT in `taxes`. What changed on
14 Aug was only how those numbers are *derived*:

| | Old (VAT added on top) | New (VAT carved out) |
|---|---|---|
| `subtotal` / `net_base` | `nightly × nights` | `gross / 1.15` |
| `taxes` / `vat` | `subtotal × 15%` | `gross − net_base` |
| `total` / `gross` | `subtotal + taxes` | `nightly × nights` |

The **meaning** of each column never changed — only the direction of the arithmetic. So a booking frozen
under either model carries an internally consistent triple, and the same serialiser renders both.

`partner_share` was backfilled for old rows as well: #107 holds **882.00** (= 900 − 18 commission).

---

## 3. ⚠️ The one thing that will bite you

For **pre-conversion** bookings, `nightly_rate × nights ≠ gross`:

```
#107:   450 × 2 = 900        but gross = 1035
new:    450 × 2 = 900        and gross = 900     ✅ equal by definition
```

Two concrete consequences:

- **Do not validate or recompute** `nightly_rate × nights === gross`. It is true for every booking made
  after 14 Aug and false for every booking made before it. An assertion or a defensive "recalculate to
  be safe" will fail on exactly the legacy rows you are trying to display.
- **Be deliberate about the per-night figure you show.** Dividing `gross / nights` for #107 gives
  **517.50** — the gross-equivalent nightly rate — while the row stores **450.00**, the net rate it was
  actually priced at. Both are defensible to display; what is not defensible is two screens disagreeing
  because one divides and the other reads the field.

**Simplest safe rule: render the stored figures, compute nothing.**

---

## 4. What this means for the invoice page (Phase 3)

Since the breakdown is present on every booking regardless of age, the invoice/receipt layout needs
**no conditional branch** for legacy bookings. `net_base`, `vat`, `vat_rate` and `gross` are always
there.

The only visible difference is that an older booking's total exceeds `nightly_rate × nights` — which is
correct, because that is what it charged at the time. If you show a "price per night" line on the
invoice, §3 applies: pick the stored rate or the derived one, and use the same choice everywhere.

---

## 5. Summary

| Question | Answer |
|---|---|
| Does the API return `net_base` / `vat` for pre-14-Aug bookings? | **Yes**, and they are correct |
| Should you show a "breakdown unavailable" note? | **No** — not needed |
| Does the invariant hold on old bookings? | **Yes** — 900 + 135 = 1035 |
| Is `partner_share` populated on old rows? | **Yes** — backfilled (#107 = 882.00) |
| Anything to avoid? | **Do not recompute.** `nightly_rate × nights ≠ gross` on legacy rows (§3) |
