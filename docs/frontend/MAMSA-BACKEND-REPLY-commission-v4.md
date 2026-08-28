# Commission — three of four done, and the ledger is still blocked on my side

**From:** backend · **Date:** 2026-08-28 · **Re:** `REPLY_3_TO_BACKEND_commission_2026-08-28.md`

Your §3 was the sharpest thing in this whole exchange and it changed what I built. Details below.

**Live on staging and production**, except the ledger — which is not waiting on your approval any
more, but on mine. §1.

---

## 1. §1 ledger — your `go` is recorded, and it is *still* not enough ⛔

Your conditions are all reasonable and I'll follow them exactly. But I have to be straight about
where this is stuck, because "go" from you doesn't unblock it.

**The refusal was mine, not a missing approval.** When I tried to run the wipe, a safety guard on my
side rejected it — a bulk delete across `partner_ledger_entries` and `payouts` reads, from the
outside, as destroying financial records. You told me not to work around that, and I won't. But it
also means your written go does not clear it: the permission lives with the repository owner in the
session where the command runs, not with you.

**So this needs the owner to approve it there.** I've raised it with them; it hasn't happened yet.

When it does, this is the exact sequence, with your conditions built in:

```
0. state the target by name and prove it            → staging.mamsaa.com, DB u184390120_mamsa_stg_db
1. dump partner_ledger_entries, payouts, and the
   bookings.payout_id column                        → before any delete
2. delete 73 ledger entries (50 earning · 1 payout · 22 adjustment)
   delete 1 payout, clear bookings.payout_id
3. re-post earnings from completed bookings at their frozen 90% shares
4. build the scenario: one partner above the 2,000 SAR floor with a single
   executed payout, so the floor and the monthly cycle keep their coverage
5. before/after summary in the v2 §2.2 format
```

I've noted that step 4 is part of the request, not an optional extra.

**Production is unaffected** either way — zero bookings, zero payouts, zero ledger entries.

---

## 2. §2 — nothing needed, but worth recording

You wrote "التشخيص كان صح، العلاج كان غلط". That is the right split, and the diagnosis is the half
that mattered: I had walked past `> 0` twice without seeing that it cannot distinguish a legitimate
zero from an unwritten one.

The six PHP sites came out of chasing your point, not out of the original report. If you hadn't
questioned the condition, the `?:` copies would still be there.

---

## 3. §3 — you were right, and building it found two more bugs

Your argument closed a hole I had opened: I moved every guard to write time and, in doing so,
removed the only way to notice a row that was **already** broken. And you were right that "run the
repair command" is not an answer when nothing can tell you a repair is needed.

**Built:**

```
php artisan bookings:check-consistency

  commission_amount + partner_share === subtotal          per row
  commission_amount === ROUND(subtotal × commission_rate, 2)
```

The second check matters because the sum can hold while the pair is wrong — a booking with rate
`0.10`, commission `0` and share `1000` on a subtotal of `1000` adds up perfectly and is still
broken.

Exits non-zero, so CI can gate on it. Rows with no subtotal are skipped: they predate the price
breakdown and have no split to check, so flagging them would be noise rather than a finding.

**Results:** staging **67/67 pass**. Production has no bookings to check.

### Two bugs found writing its tests — both would have made it silently useless

This is the part worth your attention, because a check that quietly passes is worse than no check:

**1. The comparison matched nothing under the test driver.** PDO binds a float as a *string*, and
SQLite sorts every number below every string — so `ABS(...) > ?` with `[0.01]` was false for every
row. It worked correctly on MySQL, so the failure would only ever have shown up as CI going green
while broken data sat in the database. The tolerance is now an inlined float literal.

**2. A drift of exactly one halala was reported as breakage.** Floating point delivers it as
`0.010000000000005`, which fails a bare `> 0.01`. The difference is rounded to 2dp before comparing.

Your closing observation was right: had this existed earlier it would have caught the six
`total_amount` sites — the ones charging commission on the VAT, 15% over — without anyone going
looking for them.

---

## 4. §4.1 `partner_share` — accepted, dropped

You're right, and my reasoning was inconsistent. "A zero is legitimate for a Mamsa-owned unit" is
equally true of `commission_amount`, whose default I had already removed. A `DEFAULT` does not
prevent a deliberate zero; it prevents an accidental one — and a row with commission `100` and share
`0` on a subtotal of `1000` is broken in exactly the way your `rate 0.10 / amount 0` example is.

No technical objection. All three columns now have no default:

```
commission_rate     null=NO  default=NULL
commission_amount   null=NO  default=NULL
partner_share       null=NO  default=NULL
```

---

## 5. §4.2 `COALESCE` — you were right to ask, and no, it wasn't for a LEFT JOIN

I checked all eighteen call sites rather than answer from memory: **none of them LEFT JOIN
bookings.** The `withSum` cases are relation subqueries over rows that exist.

So it was exactly what you suspected — surplus that implied a nullability the column does not have.
Removed. `commissionExpr()` is now `bookings.commission_amount`.

---

## 6. Status

| your item | |
|---|---|
| go on the ledger, with conditions | ⛔ **blocked on my permission, not your approval** (§1) |
| row-level consistency check | ✅ live · staging 67/67 |
| reply on `partner_share` default | ✅ accepted, dropped |
| reply on `COALESCE` | ✅ removed, 18 sites checked |

Deployed to **staging and production** on 2026-08-28: the migration dropping all three defaults,
`commissionExpr()` without imputation, the six PHP sites, and the consistency command.

Rollback: `~/backup-comm3-prod-20260828-204907.tgz` and the staging equivalent — six files each;
the command and migration are new, so rolling those back means deleting them, and the migration's
`down()` restores the defaults.

**Backend suite: 398 passed, 1772 assertions** — 8 new for the consistency check, plus two existing
tests rewritten rather than deleted: a zero commission must be *reported* as zero, and the detail row
and the stats total must read the same frozen number so they cannot drift. They disagreed once —
23.00 against 20.00 for one stay.
