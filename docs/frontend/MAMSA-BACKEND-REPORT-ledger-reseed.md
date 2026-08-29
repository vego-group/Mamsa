# Staging ledger rebuilt — before/after, dump, and the check after it

**From:** backend · **Date:** 2026-08-29 · **Re:** `REPLY_5_TO_BACKEND` §1 / `REPLY_6_TO_BACKEND` §3
**Environment:** **staging only.** Production untouched — it has no bookings, payouts or ledger entries.

The owner authorised it. Both commands ran, the check after them is clean, and the report you asked
for is below.

---

## 1. Target, stated before anything was touched

```
connection : mysql
database   : u184390120_mamsa_stg_db
app env    : local
```

The command printed this and verified it against its own allow-list before doing anything. A dry run
went first and confirmed it changed nothing.

---

## 2. Before

```
entries   earning       payout        adjustment   payouts   net balance
73        193,649.50    -87,800.00    -1,935.20    1         103,914.30
```

## 3. Dump — taken inside the run, before the first delete

```
storage/app/private/ledger-dumps/20260829-113053.json     35,855 bytes

  taken_at                 2026-08-29T11:30:53+03:00
  database                 u184390120_mamsa_stg_db
  ledger entries captured  73
  payouts captured          1
  booking payout links     22
```

All 73 entries, the payout, and every `bookings.payout_id` link are in the file — a full restore is
possible from it.

## 4. Rebuild

```
Ledger, payouts and booking payout links cleared.
Re-posted 52 / 52 completed booking(s) from frozen shares.
```

**52 of 52 — nothing skipped.** The skip reporting you asked for had nothing to report, which is the
right outcome and now a stated one rather than an assumed one.

## 5. After the rebuild

```
entries   earning       payout   adjustment   payouts   net balance
52        177,043.50    0.00     0.00         0         177,043.50
```

The earning total moved **193,649.50 → 177,043.50**. That drop is the point of the exercise: the old
figures were computed at the 98% share, the new ones come from each booking's own frozen 90%.

## 6. Payout scenario

```
partner                 phone           earned      paid out    balance     above floor?
شريك سيناريو التحويل     +966599000777   7,500.00    5,000.00    2,500.00    yes
```

2,500 clear of the 2,000 floor, with one executed payout behind it — so both the floor and a
completed monthly cycle have live coverage again. Deliberately not zero: a balance of exactly zero
would not tell you whether the arithmetic works.

## 7. Final state

```
earning   53 entries    184,543.50
payout     1 entry       -5,000.00
payouts    1
net                     179,543.50
partners with a balance  5
```

## 8. Consistency check, run after the rebuild

```
checked 67 / 67 booking(s)   skipped 0
✓ every checked row adds up: commission + partner share === subtotal
```

Every booking checked, none skipped, none broken.

## 9. Staging still serving

```
GET /api/v1/units                    200
GET /api/v1/units/2/blocked-dates    200
```

The availability fixture from earlier is intact — unit 2, confirmed booking 2026-10-05 → 2026-10-10.

---

## 10. What this changes for you

The partner dashboard now has staging figures at **90%**, so what you test against matches what the
code does. Balances, the payout floor and the monthly cycle all have real data behind them again.

Both commands are committed and repeatable. When staging drifts, they can be run again — that was
the argument for making them commands rather than a one-off, and it was yours.

**Nothing was deployed to production.** The commands refuse to run there by construction, and the
only piece of this that would be useful there — the skipped counter in the consistency check — is
still waiting on the owner along with the rest of round 4.
