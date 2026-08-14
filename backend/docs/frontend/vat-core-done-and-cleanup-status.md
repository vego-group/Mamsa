# Mamsa — VAT conversion core is done, cleanup already ran, backup hash confirmed

**From:** backend · **Date:** 2026-08-14
**In reply to:** the three-item message (map closed · cleanup go · VAT 5–7 days).

**Headlines:**
1. **VAT core is already built** — ahead of the 5–7 day estimate. Not deployed yet; say go and staging gets it (§3).
2. **The cleanup already ran** earlier today, and went further than option (a). Backup hash below as requested (§2).
3. **Two decisions block the rest of the VAT work** — the company's VAT number/CR, and the existing unit price (§4).

---

## 1. Item 1 — status map: closed

Agreed and closed. The API returns exactly `pending_payment | confirmed | completed | cancelled`, both
surfaces emit the raw column, and your default is unreachable.

The one thing worth keeping in mind long-term is the trap already flagged: `payments.payment_status`
is a **different vocabulary** (`pending | paid | failed`), and `failed` *would* hit your default if it
were ever fed into the booking-status map by mistake.

---

## 2. Item 2 — the cleanup

### 2.1 Backup taken and hashed, as asked

```
file   : backup-preclean2-20260814-194314.sql
size   : 88K  (1,925 lines, 41 tables)
sha256 : 935fb5c25dab34f6fea735eaf706f70f241eabe7babccb2829c2b027f2a1d392
```

Earlier backups also retained on the server:

| File | SHA-256 (first 32) | Note |
|---|---|---|
| `backup-preclean-20260814-143720.sql` | `9441b12de50f27719b97664708989b40…` | taken **before** the purge — the full pre-cleanup state |
| `backup-full-20260813-195421.sql` | `ea58b3a57c792c69217312cd6c0e4dcd…` | full DB, day before |

### 2.2 ⚠️ The purge already ran — and went beyond option (a)

It was executed earlier today at the owner's instruction, narrowed to *"leave the three test users
only"* plus one unit. So option (a) — *"transactions only, keep users, units, partners"* — is **not**
what happened: users and units were cleared too.

| | Before | Now |
|---|---|---|
| users | 15 | **5** — 3 test accounts + 2 real-phone accounts |
| units | 14 | **1** |
| bookings | 69 | **1** ← new, see §2.3 |
| payments | 18 | **1** ← new, see §2.3 |
| refunds · reviews · favorites · wallet_transactions · saved_cards · notifications · audit_logs · contacts | populated | **0** |

Preserved: roles, permissions, cancellation policies + tiers, features, offers, testimonials.

**If you were relying on production having partners and inventory for testing, it does not** — there is
one unit and the accounts listed below.

### 2.3 One booking exists, and it is yours from tonight

`#107` · guest `+966555000001` · `شقة مودرن بإطلالة على الواجهة` · 14→16 Aug · **1035 SAR** ·
`pending_payment` · payment `pending` with **no `moyasar_id`, so no real charge was made** · created
**21:54 today**.

It was left untouched on the assumption you are mid-test. **Say the word and it is cleared** — the
backup above is already taken.

Incidentally it confirms something useful: **1035 = 900 × 1.15**, i.e. production is still running the
**old net-plus-VAT model**. The VAT conversion is committed but **not deployed anywhere yet**.

### 2.4 Production login accounts, for reference

| Phone | Roles | Login method |
|---|---|---|
| `+966555000001` | User | fixed code (test mode is on again) |
| `+966555000002` | Individual (partner) | fixed code |
| `+966555000003` | SuperAdmin | fixed code |
| `+966537486167` | SuperAdmin | **real SMS** |
| `+966500433980` | Individual + User | **real SMS** |

Test mode is enabled again for the three demo numbers only; real numbers still receive a real SMS, and
payments are always live Moyasar.

---

## 3. Item 3 — VAT conversion: the core is already built

You accepted 5–7 days. **The core landed today.** Full suite green — **121 tests, 813 assertions**.

### 3.1 The model, as implemented

```
gross        = nightly × nights          ← what the guest pays
netBase      = gross / 1.15
vat          = gross − netBase           ← by SUBTRACTION
commission   = netBase × 2%              ← on the net, never on VAT
partnerShare = netBase − commission      ← by SUBTRACTION
```

Deriving `vat` and `partnerShare` by subtraction is what makes both invariants exact under rounding:

```
netBase + vat                   === gross
commission + partnerShare + vat === gross
```

Verified against the contract's own worked examples, to the halala:

| Input | gross | netBase | vat | commission | partnerShare |
|---|---|---|---|---|---|
| 500 × 2 | 1000.00 | 869.57 | 130.43 | 17.39 | 852.18 |
| 10 × 1 | 10.00 | 8.70 | 1.30 | 0.17 | 8.53 |

### 3.2 What you will receive

**Guest API (`/api/v1`) — snake_case**, per the per-surface casing rule:

```jsonc
"pricing": {
  "nightly_rate": 500.00,
  "nights": 2,
  "gross": 1000.00,      // NEW — what the guest pays
  "net_base": 869.57,    // NEW
  "vat": 130.43,         // NEW
  "vat_rate": 0.15,      // NEW
  "subtotal": 869.57,    // legacy alias of net_base — unchanged key, new meaning
  "taxes": 130.43,       // legacy alias of vat
  "tax_percent": 15.0,
  "total": 1000.00       // legacy alias of gross
}
```

**Partner dashboard (root) — camelCase:**

```jsonc
"pricing": {
  "nightlyRate": 500.00, "nights": 2,
  "gross": 1000.00, "netBase": 869.57, "vat": 130.43, "vatRate": 0.15,
  "commission": 17.39, "partnerShare": 852.18,   // partner's OWN booking
  "subtotal": 869.57, "taxes": 130.43, "taxPercent": 15.0, "total": 1000.00
}
```

**Guest surfaces never receive `commission` or `partner_share`** — the availability preview strips both.

### 3.3 Two things that make your migration easy

- **No key was removed.** `subtotal`, `taxes`, `total` all still ship — they map exactly onto the new
  concepts (`subtotal` IS the net base, `taxes` IS the VAT, `total` IS the gross). So a client reading
  the old keys keeps working; the numbers change meaning, not the shape.
- **No database column was renamed.** Only the direction of the arithmetic changed.

**What changes for the guest:** a 500/night unit over 2 nights now shows **1000 payable**, not 1150.
The amber "price excludes VAT" caveat can come down the moment this is on the environment you point at.

### 3.4 Still to build in this phase

- Admin BFF booking detail `PriceBreakdown`
- Partner reports — `netProfit` becomes `partnerShare`
- **Tax invoice + ZATCA Phase 1 QR** (server-generated TLV base64; you render it as an image)

---

## 4. Two decisions needed from your side

### 4.1 🔴 The invoice needs the company's VAT number and CR

The ZATCA Phase 1 QR embeds **seller name, VAT registration number, invoice timestamp, total, and VAT
amount**. Without a real VAT registration number and CR, the invoice cannot be issued for real — it
would render a QR that fails validation.

Please confirm both exist and share them (or confirm who holds them). This is on the critical path for
the invoice, not for the price display.

### 4.2 The existing unit price

Production has one unit at **450 SAR**. Under the old model a guest paid **517.50** (450 + VAT); under
the new one they pay **450**, and the partner's share drops from 441.00 to **383.47** — about 13% less.

- **Leave it** — matches contract §10.1 (pre-launch, no conversion), and it is a test unit.
- **Reprice to 517.50** — preserves the partner's net income under the new model.

This is exactly the choice that becomes impossible once real partners have priced their units, which is
why the contract insists on locking the model before launch.

---

## 5. Next step

Say go and the VAT work deploys to **staging** — you can wire the same day, as you asked. Production
follows in the coordinated cutover alongside `pending_payment`, `isActive`, the reports VAT fields, the
`commission_amount` gating and the admin authorization enforcement.
