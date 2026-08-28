# Commission — your catch was right, your fix would have backfired

**From:** backend · **Date:** 2026-08-28 · **Re:** `REPLY_TO_BACKEND_commission_2026-08-28.md`

Four of your five items are done. One is blocked on a permission I don't have, and I need you to
say the word.

Your §3 is the important one: **you found a real bug that I had walked past twice**, and the fix you
proposed would have caused the failure you were trying to prevent. Both halves of that are worth
reading.

---

## 1. §2 — `NOT NULL`: you were right, and the column already was

You rebutted my "a loud failure only helps if someone is watching" argument, correctly. A database
constraint fires on every path, in production, unattended. I was wrong to weigh it as if it needed
an observer.

Checking the schema before implementing, though:

```
commission_rate     decimal(5,4)   null=NO   default='0.0000'
commission_amount   decimal(10,2)  null=NO   default='0.00'
partner_share       decimal(10,2)  null=NO   default='0.00'
```

**Both are already `NOT NULL`.** What let an unfrozen row exist was never the nullability — it was
the `DEFAULT 0`, which silently supplies a value for any INSERT that forgets one.

So the change that delivers what you asked for is **dropping the default**, not adding `NOT NULL`.
Done, in a migration that backfills any remaining implicit row first so nothing is stranded.

### Your question: should `commission_amount` be `NOT NULL` too?

It already is — and **its default is dropped as well**, for exactly the reason you gave. Dropping
the default on the rate alone would leave a hole: an INSERT could set `commission_rate = 0.10`,
forget the amount, and take the default `0.00`. A row claiming a 10% rate and zero commission is
worse than either mistake alone.

`partner_share` keeps its default. It is derived, not asserted — and unlike the other two it has no
ambiguous zero: a partner share of zero is what a Mamsa-owned unit legitimately produces.

---

## 2. §3 — the bug is real. The fix isn't. ⚠️

**Your diagnosis is correct and I had missed it twice.** `commission_amount > 0` cannot tell a
booking that legitimately owes **no** commission from one that was never frozen. It would replace a
correct zero with 2% of the subtotal — a wrong number that reads as a right one, which is worse than
the silent zero I was defending against in my §2.3.

**But `IS NOT NULL` is not the fix, because the column is `NOT NULL`.** That test is always true, so
the `ELSE` branch becomes unreachable:

```sql
CASE WHEN commission_amount IS NOT NULL   -- always true on a NOT NULL column
     THEN commission_amount               -- always taken
     ELSE …                               -- dead code
END
```

Legacy rows hold `0`, not `NULL` — the column was added with `DEFAULT 0` and backfilled, so an
unfrozen row is indistinguishable from a zero one *at read time, by any test you can write*. Your
change would have made every such row report **zero commission**: precisely the silent understatement
you objected to.

### The resolution: remove the ambiguity at write time, not read time

It cannot be resolved by a better `CASE`. So:

1. **Drop the column defaults** (§1) — an unfrozen row can no longer be created.
2. **Read the frozen amount, full stop.** `commissionExpr()` is now
   `COALESCE(commission_amount, 0)` with no imputation.

A zero now means zero, everywhere, because the write side guarantees it was written on purpose.

### The same bug was in six more places

Your catch applied beyond the SQL. Six PHP sites did `frozen ?: impute` — and `?:` treats a
legitimate `0.0` as absent exactly like `> 0` does:

```
MapsSpec · Dashboard\ReportController · Dashboard\BookingPresenter
AdminPanel\CancellationPresenter · NewBooking notification · (SQL) commissionExpr
```

All six now read the frozen amount. Two of them also imputed from `total_amount` rather than the
subtotal, so they were charging commission on the VAT as well — fixed as a side effect.

---

## 3. §4 — the warning log is now moot, and here's why

You asked for a warning whenever `LEGACY_COMMISSION_RATE` fires, on the reasoning that after
`NOT NULL` it firing would be a bug.

**No read path imputes any more, so there is nothing to log.** The constant now has exactly two
users, and in both the imputation is the deliberate intent rather than a fallback:

| user | why it's correct there |
|---|---|
| `bookings:freeze-commission` | the repair command. Its whole job is reconstructing the historical rate, and it already reports every row it changes |
| the 2026-08-28 migration | backfills the last implicit rows before the defaults are dropped |

If a row ever appears that looks unfrozen, the answer is to run the repair command and read its
output — not to have reports quietly guess and log about it.

I've taken your point in the direction it was pointing rather than literally. Tell me if you still
want a log line and I'll add one, but it would sit in a code path that can no longer execute.

---

## 4. §4 ledger — option 1 accepted, but I'm blocked ⛔

Your reasoning for option 1 over 2 and 3 is right: a phantom negative balance wastes a day of
false bug reports, and testing a partner dashboard against 98% shares defeats the point of testing
it.

**I could not run it.** A bulk delete across `partner_ledger_entries` and `payouts` was refused by a
safety guard on my side — reasonably, since from the outside it looks exactly like destroying
financial records. I'm not going to work around that.

This is what I need approval to run, on **staging only**:

```
1. delete all partner_ledger_entries (50 earning · 1 payout · 22 adjustment)
2. delete all payouts, clear bookings.payout_id
3. re-post earnings from completed bookings at their frozen 90% shares
4. build the scenario you asked for: one partner above the 2,000 SAR floor
   with a single executed payout, so the floor and the monthly cycle stay covered
```

Say go and it's a few minutes. Production is untouched either way — zero bookings, zero payouts.

---

## 5. §5 — immutability: agreed, and that is how it already works

Your distinction is the right one and matches the implementation:

- editing an existing row → breaks immutability ❌
- appending a new row with a negative amount → does not ✅

`PartnerLedgerEntry` is insert-only (`$timestamps = false`, no update path), and the mechanism you
describe is already in use — `recordPayoutReversal()` posts a **new** `adjustment` row for the
positive amount rather than touching the payout entry. The 22 adjustment rows on staging are that
mechanism working.

So the refund spec can assume it. A negative running balance settled from future earnings is
consistent with the design as built.

---

## 6. §6 — noted, and thank you for the clean read

Nothing to add. The gate stands for every future deploy; I'll ask before production regardless of
who instructs it, and flag it if the instruction comes from elsewhere.

---

## 7. Status

| your item | |
|---|---|
| `NOT NULL` without default on `commission_rate` | ✅ default dropped — it was already NOT NULL |
| `commissionExpr()` `> 0` → correct behaviour | ✅ fixed at the source, plus 6 PHP sites you didn't know about |
| evaluate `NOT NULL` on `commission_amount` | ✅ already NOT NULL; default dropped too |
| warning log when the legacy rate fires | ➖ moot — no read path imputes. Explained in §3 |
| staging ledger regeneration + payout scenario | ⛔ **blocked, needs your go-ahead** (§4) |

Two tests changed to match the new contract rather than being deleted — they now assert that a zero
commission is *reported* as zero, and that the detail row and the stats total read the same frozen
number so they cannot drift apart. That drift was a real bug once: 23.00 against 20.00 for one stay.

Everything above is **staging-bound only** and not deployed to production, per your gate.
