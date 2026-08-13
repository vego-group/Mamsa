# Mamsa — Production Data Audit, Test Results & the `pending` Contradiction, Closed

**From:** backend · **Date:** 2026-08-13
**In reply to:** the four-point challenge on `MAMSA-FRONTEND-PENDING-PAYMENT-RENAME.md`
**Supersedes:** §2 and §6 of that document — both are corrected here.

**The contradiction was real and you were right to block on it.** The numbers below are queried from
the production database, not estimated. They resolve it in an unexpected direction: **production has
zero `pending` bookings**, so the migration converts nothing — but **real money has moved**
(15,909.05 SAR through Moyasar), which is the part that actually matters for the VAT decision.

---

## 0. Answers at a glance

| # | Question | Answer |
|---|---|---|
| 1 | Bookings by status on production | `completed` 56 · `cancelled` 13 · **`pending` 0** · `confirmed` 0 · **total 69** |
| 1 | How many tied to a real Moyasar payment | **9 payments, 15,909.05 SAR charged** |
| 1 | How many belong to a real external guest | **Zero.** All 6 booking accounts are internal |
| 2 | Does VAT §10.1 need rewriting | **No re-decision — but reword it** (§3) |
| 3 | Vue admin lifespan / replacement plan | **Cannot answer from code** — needs your call (§4) |
| 4 | Test results | **`OK (102 tests, 752 assertions)`** — after fixing a real bug the suite caught (§5) |

---

## 1. The correction

| Document said | Reality |
|---|---|
| §2: "every booking in the system is demo data today" | **Substantially true** — no external customer, but real charges exist |
| §6: "Production has real bookings currently in `pending`" | ❌ **False.** There are **0** `pending` bookings on production |

§6 was an unverified assumption written into a deployment warning. It has been corrected. The
practical consequence is the opposite of what that section implied: **the data migration is a no-op on
production** — it converts zero rows.

---

## 2. The production numbers

### 2.1 Bookings by status

```sql
SELECT status, COUNT(*) FROM bookings GROUP BY status;
```

| status | count |
|---|---|
| `completed` | 56 |
| `cancelled` | 13 |
| `pending` | **0** |
| `confirmed` | 0 |
| **total** | **69** |

### 2.2 Payments

| payment_status | count | with a real `moyasar_id` |
|---|---|---|
| `paid` | 10 | **9** |
| `failed` | 2 | 2 |
| `pending` | 6 | 0 |

**Total actually charged through Moyasar: 15,909.05 SAR** across 9 payments.

### 2.3 Who paid — every account is internal

All 9 real charges belong to 3 accounts, and all 6 accounts that have ever booked are internal:

| account | bookings | sum (SAR) | who |
|---|---|---|---|
| `+9665000***11` (no email) | 19 | 106,600.80 | seeder |
| `user@mamsaa.sa` | 16 | 81,938.10 | internal |
| `+9665000***10` (no email) | 15 | 82,059.30 | seeder |
| `m.***@vego.sa` | 9 | 50.35 | **Vego Group** (the development company) |
| `user@mamsa.test` | 8 | 31,837.20 | test account (`.test` domain) |
| `a***@gmail.com` | 2 | 5,865.00 | project owner |

The 9 real charges break down as: owner 1,353.00 + 4,512.00 · `@mamsa.test` 5,916.00 + 2,055.00 +
2,055.00 · Vego engineer 10.00 + 1.15 + 1.15 + 5.75 (evidently gateway probes).

**External paying customers: zero.** None of these phones is in the test-mode allowlist either — they
are ordinary accounts belonging to the team, which is why they were not obviously "demo" until queried.

---

## 3. What this means for VAT (contract §10.1)

**The decision stands. No escalation to management is required** — but the wording must change.

§10.1 currently justifies "no migration required" with *"every unit, partner, and booking currently in
the system is demo data… There are no real guests… no price that any customer has seen and relied on."*

- **The load-bearing claim is TRUE:** no external customer has ever paid a price they relied on. Every
  payer is the owner, a test account, or the development company. So there is no re-pricing
  announcement, no customer-facing migration, and no reason to reopen the inclusive-pricing decision.
- **One clause is NOT literally true:** real money *did* move — 15,909.05 SAR settled through Moyasar
  across 9 charges. Anyone reconciling the Moyasar dashboard against a document that says "no real
  bookings" will reasonably conclude the document is wrong.

**Suggested replacement for that sentence:**

> The platform is pre-launch and has **no external customers**. The only real gateway activity is
> internal testing — 9 Moyasar charges totalling ~15,909 SAR, made by the owner, a test account, and
> the development company. No external guest has seen or relied on a price, so no price-conversion
> script, no `pricingModelVersion` marker, and no partner re-pricing announcement are required. The
> internal test charges are reconciled or refunded as ordinary test transactions.

That keeps the conclusion and makes it survive an audit.

---

## 4. The `/api/v1` count keys — your read is correct

You are right that the shim **moved rather than disappeared**. Precisely:

- The **shim in the admin BFF is genuinely deleted** (`MapsSpec::bookingStatus()` and all three call
  sites), so the new admin panel gets the native value.
- What remains is **three one-line lookups** in the legacy `/api/v1` admin endpoints, which read the
  DB value `pending_payment` but keep returning the **response key** `pending` so the Vue admin keeps
  working: `GET /api/v1/admin/dashboard`, `.../admin/bookings/stats`, `.../admin/reports/summary`.

**We cannot answer the lifespan question from the codebase** — nothing in the repo records the Vue
admin's retirement date or whether a replacement plan exists. That is a product decision. Our
recommendation matches your instinct:

| Vue admin lifespan | Recommendation |
|---|---|
| **< ~3 months** | Leave the three lookups. Removing them later is a five-minute change. |
| **~a year or more** | Fix the Vue side and unify all keys on `pending_payment`. |

If you choose to unify, it should be a **separate small PR landing after the rename**, so the Vue fix
and the key change ship together and no window exists where the two disagree.

---

## 5. Test results — and the real bug they caught

**Final result, full suite:**

```
PHPUnit 12.5.29 — Runtime: PHP 8.4.22
102 / 102 (100%)
OK (102 tests, 752 assertions)
```

Getting there took four runs, and the sequence is worth recording because it nearly shipped a bug:

| run | scope | result | cause |
|---|---|---|---|
| 1 | 2 files | 7 tests, **6 errors** | **Environment, not code** — the container's env beat `phpunit.xml`, so tests ran against **MySQL** instead of sqlite (`Table 'contacts' already exists`) |
| 2 | 2 files, forced sqlite | `OK (7 tests, 246 assertions)` | — |
| 3 | **full suite** | 102 tests, **3 failures** | **A real bug** ↓ |
| 4 | **full suite**, after fix | **`OK (102 tests, 752 assertions)`** | green |

### 5.1 The bug — booking creation returned HTTP 500

All three failures were `POST /bookings` returning **500 instead of 201**.

**Cause:** on **SQLite**, Laravel's `enum()` emits a `CHECK` constraint —
`check ("status" in ('pending','confirmed','cancelled'))`. The rename migration altered the enum for
**MySQL only**, so on SQLite the constraint still forbade the new value and every insert of
`pending_payment` violated it.

**Fix:** on SQLite the migration now rebuilds the column as a plain string (dropping the stale CHECK);
MySQL keeps its real `ENUM`, which remains the source of truth for the legal value set.

**Bonus finding:** that CHECK constraint was **already wrong before this change** — the earlier
"add `completed`" migration was also MySQL-only, so SQLite's constraint never included `completed`.
The fix clears that latent inconsistency too.

### 5.2 Why this matters procedurally

The **filtered** run was green. Only the **full** suite exposed a bug that would have broken booking
creation. Your insistence on seeing test results before any deploy is what caught it — recording that
plainly rather than quietly fixing it.

Note also that the first run pointed `RefreshDatabase` at the dev MySQL database. It failed early
without dropping anything, and the dev database was verified intact afterwards (33 tables). Test runs
in that container require explicit `-e DB_CONNECTION=sqlite -e DB_DATABASE=:memory:` overrides.

---

## 6. Current status

| Item | State |
|---|---|
| `pending_payment` rename | Code-complete, **full suite green**, migration verified on MySQL **and** SQLite, both directions |
| Deployed anywhere | **No** — still in the working tree |
| Production impact of the migration | **Converts 0 rows** (there are no `pending` bookings) |
| Blocking on | Your merge day, plus the Vue-lifespan answer for §4 |

Because production has zero `pending` bookings, the earlier warning about a one-way data change on
real records **no longer applies**: the migration alters the column definition and touches no rows.
The schema change is still a production DDL change and will not be run without your explicit go.
