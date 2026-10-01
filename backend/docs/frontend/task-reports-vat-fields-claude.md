# Task: wire the reports VAT fields (Claude Code — Next.js frontend repos)

**For:** a Claude Code agent working in the **admin panel** and **partner dashboard** repos.
**Date:** 2026-08-13
**Status:** ✅ **live on staging** · ⏳ production pending the coordinated flip.

## TL;DR

The two fields your reports page was showing as `undefined` now exist. **You do not have to wait for the
VAT conversion phase** — the values are already correct under the current pricing model, and they will
stay correct after the flip without any rewiring.

**The field names differ per surface. This is intentional, per contract.**

| Surface | Endpoint | New fields |
|---|---|---|
| Partner dashboard | `GET /reports/summary` | `netRevenue`, **`vat`** |
| Admin panel | `GET /admin/reports/summary` | `netRevenue`, **`vatCollected`** |

Do not normalise them to one name across repos — each surface follows its own contract section
(§6.1 partner, §5.4 admin).

---

## 1. Shapes

### 1.1 Partner — `GET /reports/summary?from=&to=`

```jsonc
{
  "grossRevenue": 123834.20,   // unchanged — guest-paid total
  "netRevenue":   116536.00,   // NEW — revenue net of VAT
  "vat":            7298.20,   // NEW — VAT collected
  "bookingsCount":     23,
  "commission":        70.00,
  "netProfit":     123764.20,
  "revenueByMonth":  [ … ],
  "bookingsByMonth": [ … ],
  "perUnit":         [ … ]
}
```

⚠️ **`from` and `to` are required** on this endpoint. Omitting them returns
`400 { error: { code: "VALIDATION", fields: { from: "…", to: "…" } } }` — not an empty result. The admin
endpoint does **not** require them (it takes an optional `range`).

### 1.2 Admin — `GET /admin/reports/summary?range=`

```jsonc
{
  "totalRevenue":   239337.45,  // unchanged
  "netRevenue":     225891.00,  // NEW
  "vatCollected":    13446.45,  // NEW
  "totalCommission":  3876.70,
  "totalBookings":       …,
  "revenueSeries":     [ … ],
  "revenueByCity":     [ … ],
  "bookingStatusSlices": [ … ],
  "bookingVolume":     [ … ],
  "occupancySeries":   [ … ],
  "occupancyAverage":    …,
  "topPartners":       [ … ]
}
```

Everything else is untouched — this is purely additive, so nothing you already render changes.

---

## 2. The invariant — assert it

Both surfaces reconcile exactly:

```ts
// partner
netRevenue + vat === grossRevenue
// admin
netRevenue + vatCollected === totalRevenue
```

Verified live on staging: `116536 + 7298.2 = 123834.2` and `225891 + 13446.45 = 239337.45`.

- [ ] Add this as a test assertion. It is the cheapest possible guard against a future VAT change
      silently double-counting or dropping tax.

---

## 3. Why this survives the VAT flip — no rewiring later

The backend derives both fields as **`total − taxes`**, not by summing the pre-tax subtotal. That choice
is what makes them durable:

| Model | How it works out |
|---|---|
| **Today** (VAT added on top) | `total = subtotal + taxes` → `total − taxes` = the net base ✅ |
| **After the inclusive flip** | `gross = total`, VAT carved out of it → `total − taxes` = `netBase` ✅ |

It is also correct for the historical bookings that carried cleaning/service fees, where `subtotal`
alone was **not** the taxable base.

**Consequence for you:** wire these fields once, now. When the VAT conversion phase lands, the numbers
change meaning underneath but the field names and the invariant hold. **No migration on your side.**

- [ ] Do **not** compute VAT client-side from `grossRevenue` — read the fields.
- [ ] Do **not** treat `netRevenue` as "profit". Partner profit is `netProfit` (revenue minus
      commission); `netRevenue` is revenue net of *tax*. They answer different questions.

---

## 4. Admin: do not sum VAT into a revenue tile

Per contract, admin revenue KPIs are stated **net of VAT**, with VAT shown separately.

- [ ] `vatCollected` gets its own tile or line — never added into a revenue total.
- [ ] If a "revenue" headline currently uses `totalRevenue` (gross), decide deliberately whether it
      should now show `netRevenue`. Tax collected on behalf of the authority is not revenue.

---

## 5. Environments

| | Status |
|---|---|
| **Staging** | ✅ live now — switch the reports page off mocks |
| **Production** | ⏳ holds until you name the day |

Production is deliberately waiting. Suggest bundling it with the `pending_payment` frontend release and
the `isActive` change so there is **one** coordinated cutover rather than three.

Until then the production reports endpoints return the old shape — so if your page renders against
production before the flip, `netRevenue`/`vat` will still be `undefined` there. Guard with optional
chaining, or keep the reports page on staging until the cutover.

---

## 6. Checklist

- [ ] Partner reports read `netRevenue` + `vat`; admin reads `netRevenue` + `vatCollected` (§0)
- [ ] Names **not** normalised across repos — contract names per surface
- [ ] `from`/`to` always sent to the partner endpoint (§1.1)
- [ ] Reconciliation invariant asserted in a test (§2)
- [ ] No client-side VAT arithmetic (§3)
- [ ] `netRevenue` not conflated with `netProfit` (§3)
- [ ] Admin: VAT shown separately, never summed into revenue (§4)
- [ ] Optional chaining until production ships, or reports page pinned to staging (§5)
