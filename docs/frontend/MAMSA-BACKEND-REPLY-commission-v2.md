# Commission 10% — mostly already shipped, with three deviations from the spec

**From:** backend · **Date:** 2026-08-28 · **Re:** `BACKEND_COMMISSION_RATE_CHANGE_v2_2026-08-28.md`

**The rate change went live on 2026-08-27**, on the owner's instruction, before this v2 arrived. So
this is a reconciliation rather than an implementation plan: what already matches, what I did
differently and why, and the two items still open.

⚠️ **Your §8 approval gate and the production deploy have already crossed.** The owner said "deploy"
and I did. Nothing is waiting on the gate — but if the gate was meant to bind the owner too, that
needs settling between you; I can roll the rate back to 2% with an env change in a minute.

---

## 1. What already matches your spec

| your spec | status |
|---|---|
| 10% platform / 90% partner | ✅ live |
| commission on `base` **before VAT**, never on `gross` | ✅ — and pinned by a test |
| guest price unchanged | ✅ — 1150 stays 1150 |
| rate snapshot on the booking | ✅ — `commission_rate` + `commission_amount`, since 2026-07-12 |
| Mamsa-owned units skip the split | ✅ — platform keeps the whole net base |
| reports aggregate **per row**, never `SUM(total) × rate` | ✅ — fixed 2026-08-27, see §4 |
| no parallel-rates period | ✅ |
| ZATCA / Moyasar / payout floor / ledger immutability untouched | ✅ |

Your worked example, verified on the live config:

```
base 1000  →  guest 1150 · VAT 150 · commission 100 · partner 900
invariant  commission + partnerShare + vat === gross   ✅
```

---

## 2. Three deviations, and why

### 2.1 The config lives in `config/booking.php`, not a new `config/mamsa.php`

You specified `config/mamsa.php` → `platform_commission_rate` / `PLATFORM_COMMISSION_RATE`.

It's at **`config/booking.php` → `commission_rate`** (env `BOOKING_COMMISSION_RATE`), which is where
`tax_rate` already lives. The two rates that decide how a booking's money is split sit next to each
other, and a new single-key file would separate them for no gain.

Rename it if you want the name in the spec — it's a mechanical change and I'll do it on request.
I'd rather not churn a working key.

### 2.2 I did **not** backfill production, because there is nothing there

Your §3.1 proposes `DEFAULT 0.1000` to backfill every row. Production has **0 bookings** — the
default would apply to nothing. I've left the column as `decimal(5,4) default 0`.

**Staging is backfilled**, as you asked (§3.2). All 67 rows are now `0.1000`:

```
before: rates 0, 0.02, 0.1  ·  22 rows with no frozen amount
after:  rates 0.1           ·  0 rows with no frozen amount
sample: subtotal 900 → commission 90, partner 810   (invariant holds)
```

Mamsa-owned units were backfilled to rate `1.0` (platform keeps everything), not `0.0` as your §4.3
suggests. `0.0` would mean "no commission" — the opposite of what happens. The row is still obvious
in reports because the rate is the extreme value rather than an ordinary one.

### 2.3 I kept a **separate constant** for imputing pre-freeze rows

`Booking::LEGACY_COMMISSION_RATE = 0.02` still exists, deliberately, and this is the one place I'd
push back hardest.

It is **not** a second live rate. It reconstructs commission for a booking whose
`commission_amount` was never frozen. After the staging backfill **it fires on nothing** — but the
day someone inserts a row without a rate, the alternative to imputing is reporting that booking's
commission as **zero**, which is a silent understatement of platform revenue.

Your §3.1 suggests dropping the column default so a forgetful INSERT "fails loudly". I agree with
the goal and think the constant serves it better: a loud failure only helps if someone is watching
the insert, whereas the fallback keeps the *reports* correct either way. Both can coexist and I'm
happy to add the NOT NULL if you want the belt as well as the braces — see §5 for the one cost.

---

## 3. Your §5 — `commissionRate` on the API

Added, following each surface's existing convention rather than forcing one spelling:

| surface | field | notes |
|---|---|---|
| `/api/v1/bookings`, `/bookings/{id}` | `commission_rate` | snake_case, like `commission_amount` beside it. **Admin/owner only** — a guest never sees the platform's margin |
| partner dashboard bookings | `commissionRate` | camelCase, next to `commission` |
| `/admin/bookings/{id}` | `commissionRate` | camelCase |

`/api/v1` is snake_case everywhere; renaming one field to camelCase there would make it the only
one. If you specifically need `commissionRate` on the v1 guest surface too, say so.

**Not added:** `/partner/ledger` and `/partner/payouts/summary`. A ledger entry is a **money
movement**, not a booking — it stores an amount, and several entries have no single rate behind them
(a payout covers many bookings; an adjustment has none). Putting a rate there would be inventing
one. The rate is on the booking each entry points at.

---

## 4. Your §6 — reports are already per-row

This was fixed on 2026-08-27, and it was a real bug at the time: **three** admin aggregates computed
a partner's commission as `SUM(subtotal) × one flat rate`. Proven on staging by adding a single 10%
booking — the old expression under-reported by **800 SAR on that booking alone**.

They now sum `Booking::commissionExpr()` per row.

```sql
-- what every aggregate uses now
SUM(CASE WHEN commission_amount > 0 THEN commission_amount
         ELSE ROUND(COALESCE(subtotal,0) * <legacy rate>, 2) END)
```

Measured on staging after the backfill: per-row total **20,233.41** — identical to
`SUM(subtotal) × 10%`, exactly as you predicted for a single-rate dataset. The point is that it
stays right when that stops being true.

---

## 5. §10 — your three questions

**1. Cached or materialised commission aggregates needing invalidation?**
**No.** Every commission figure is computed at request time from `bookings`. There is no
materialised view, no summary table, and no cached total. Nothing to invalidate.

**2. How many places compute commission outside a single function?**

There is no `splitCommission()` — the single point is **`App\Support\Pricing::breakdown()`**, which
is the only thing that reads the live rate, and only at booking creation.

Everything else reads what was frozen. Complete list:

| category | count | correct? |
|---|---|---|
| computes from the **live rate** | 1 — `Pricing::breakdown()` | ✅ the single point |
| aggregates **per row** via `Booking::commissionExpr()` | 10 files | ✅ |
| per-booking fallback `frozen ?: subtotal × legacy` | 6 sites | ✅ per-row, never on a total |
| applies **one rate to a total** | **0** | ✅ — was 3, fixed 2026-08-27 |

The 6 fallback sites are `MapsSpec`, `Dashboard\ReportController`, `NewBooking`,
`FreezeLegacyCommission`, `CancellationPresenter`, `Dashboard\BookingPresenter`. Each is
`frozen > 0 ? frozen : impute` for one booking — none multiplies a sum. I also switched
`Admin\CancellationController` from a bare `sum('commission_amount')` to the shared expression today,
so an unfrozen row counts instead of reading as zero.

**3. Time estimate:** the rate change is done. What remains is §6 below — roughly an hour, most of it
the ledger decision rather than code.

---

## 6. Two things I have **not** done

### 6.1 The staging ledger still holds the old 98% shares

Your §3.2 says to delete and regenerate. I stopped, because the table isn't only earnings:

```
earning      50 entries   +193,649.50 SAR   ← computed at 98%
payout        1 entry      -87,800.00 SAR
adjustment   22 entries     -1,935.20 SAR
```

Regenerating earnings at 90% lowers the credited total while leaving a recorded **payout of 87,800**
against it. That can push a balance negative and would make the partner dashboard show a wallet that
never adds up — a worse state than stale-but-consistent numbers.

Three options, your call:

1. **Wipe all three types and regenerate earnings only** — cleanest, but the payout history goes.
2. **Regenerate earnings, keep payout/adjustments** — fastest, may show a negative balance.
3. **Leave it.** The bookings are correct; only the wallet view is stale.

Say which and it's a few minutes. I didn't guess because it's visible on a screen a partner uses.

### 6.2 §4.4 — commission on refunds

There is nothing to split. Commission is only realised when a booking reaches `completed`
(`BookingEarningObserver` → `PartnerWalletService::recordEarning`), and a cancelled booking never
gets there — so no commission was taken and no partner share was credited. There is no reversal path
because nothing needs reversing.

If you want commission prorated on a **partial** refund of an already-completed stay, that is new
behaviour rather than a rate change, and it needs the ledger design decided first (a reversal entry
breaks the immutability rule you restated in §3.2). Tell me if it's wanted and I'll spec it
separately.

---

## 7. Your §9 test gates

| gate | |
|---|---|
| new booking returns the rate | ✅ `commission_rate` on the resource |
| invariant holds at 0.10 and at the Mamsa-owned extreme | ✅ tested both |
| base 1000 → 100 / 900 / 150 / 1150 | ✅ tested exactly |
| Mamsa-owned → partner gets nothing, platform keeps the base | ✅ tested |
| partial refund splits at the booking's rate | ⛔ n/a — §6.2 |
| reports use `SUM(base × rate)` not `SUM(base) × rate` | ✅ §4 |
| the calculation refuses to run without a rate | ⚠️ see below |

On the last one: `Pricing::breakdown()` cannot run without a rate — it reads config, which has a
default. Making a **missing column value** fail loudly means `NOT NULL` with no default, and the
cost is that every direct `Booking::create()` in seeders and tests must pass a rate or blow up. I'd
take that trade if you want it; it's your call, not mine to impose.

**Your fixtures note was right:** two tests asserting 2% failed the moment the rate changed, which is
exactly how they earned their keep. Both now assert the live rate, and a third asserts that the live
and legacy constants are **not** equal — so nobody can quietly collapse them.

**Backend suite: green.** No production deploy has happened for anything in this document beyond the
rate itself, which was already live before it arrived.
